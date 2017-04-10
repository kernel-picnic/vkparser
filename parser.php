<?php if (!isset($_SERVER['argv'][0]) && $_SERVER['argv'][0] != '--cron') exit;

/*
 * Класс для автоматического постинга всего в паблик ВК
 * Автор - Антон Тройнин. Все права защищены и т.д. и т.п.
 */
class VKPoster
{
    function __construct()
    {
        include 'config.php';   // Конфигурация скрипта
        include 'vk.class.php'; // Класс для взаимодействия с API вконтакте

        // Отображаем все ошибки
        error_reporting(E_ALL);
        ini_set('display_errors', TRUE);
        // Логгирование
        ini_set('log_errors', 1);
        ini_set('error_log', LOG_FILE);
        // Лимит выполнения скрипта по времени
        set_time_limit(TIMEOUT);

        // Подключаемся к SQLite. Если БД не существует, то создаём её
        $this->db = new SQLite3(DB_FILE);
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . DB_NAME . " (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `hash` TEXT, `group` TEXT, `message` TEXT, `attachment` TEXT, `date` TEXT)");

        // Инициализируем класс работы с API
        $this->vk = new vk(VK_ACCESS_TOKEN);
        $this->owner = '-' . $groups[array_rand($groups)];
    }

    public function send_post($data)
    {
        $time = time() + (rand(1, 30) * 60);

        $response = $this->vk->method('wall.post', array(
            'owner_id'     => '-' . VK_GROUP_ID,
            'from_group'   => 1,
            'friends_only' => 0,
            'message'      => $data->text,
            'attachments'  => $data->attach,
            'publish_date' => $time
        ));

        // Сохраняем в БД
        $this->db->exec("INSERT INTO " . DB_NAME . " ('hash', 'group', 'message', 'attachment', 'date') VALUES ('$data->hash', '$this->owner', '$data->text', '$data->attach', '$time')");

        return true;
    }

    public function get_post()
    {
        $post = $this->vk->method('wall.get', array(
            //'captcha_sid' => '601516643537',
            //'captcha_key' => 'dqnn2h',
            'owner_id' => $this->owner, // Рандомная группа из списка
            'offset'   => rand(1, 5), // Один из 5 последних постов
            'count'    => '1'
        ));

        if (isset($post->response[1]))
        {
            // Если тип поста copy или в тексте есть ссылки, то
            // скорее всего это рекламный пост - постить не будем
            if ($post->response[1]->post_type === 'copy'
                || preg_match('/(http:\/\/[^\s]+)/', $post->response[1]->text)
                || preg_match('/\[club(.*)]/', $post->response[1]->text))
            {
                return false;
            }

            return $this->process_post($post->response[1]);
        }

        return $post;
    }

    private function process_post($post)
    {
        $output = new stdClass();
        // Химичим с текстом, чтобы убрать все теги <br>
        $output->text = preg_replace('#<br\s*?/?>#i', "\n", $post->text);
        $output->attach = '';
        $output->hash = '';

        // Проверка на наличие прикреплений
        // Собираем их все в одну переменную
        if (isset($post->attachments))
        {
            foreach ($post->attachments as $item)
            {
                if (isset($item->photo))
                {
                    // Сохраняем картинку локально
                    $content = $this->get_doc($item->photo->src_big);
                    file_put_contents(DIRECTORY . 'image.jpg', $content);

                    // Проверяем, была ли уже такая картинка
                    $output->hash = md5_file(DIRECTORY . 'image.jpg');
                    $rows = $this->db->querySingle("SELECT COUNT(*) FROM ".DB_NAME." WHERE hash LIKE '%$output->hash%'");

                    if ($rows !== 0)
                    {
                        return false;
                    }

                    // Вначале получаем адрес сервера для сохранения картинки
                    $server = $this->vk->method(
                        'photos.getWallUploadServer',
                        array('group_id' => VK_GROUP_ID)
                    );

                    // Подготовка к сохранению
                    $data['file'] = new CURLFile(DIRECTORY . 'image.jpg');
                    // Отправляем файл на сервер
                    $ch = curl_init( $server->response->upload_url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                    $json = json_decode(curl_exec($ch));
                    curl_close($ch);

                    // Сохраняем картинку на сервер ВК и получаем её ID
                    $saved_photo = $this->vk->method('photos.saveWallPhoto', array(
                        'gid'    => VK_GROUP_ID,
                        'server' => $json->{'server'},
                        'photo'  => $json->{'photo'},
                        'hash'   => $json->{'hash'}
                    ));

                    $output->attach .= 'photo'.$saved_photo->response[0]->owner_id.'_'.$saved_photo->response[0]->pid.', ';
                }
                elseif (isset($item->audio))
                {
                    $output->attach .= 'audio'.$item->audio->owner_id.'_'.$item->audio->aid.', ';
                }
                elseif (isset($item->doc))
                {
                    // Проверяем, была ли уже такая гифка
                    $output->hash .= md5_file($item->doc->url);
                    $rows = $this->db->querySingle("SELECT COUNT(*) FROM ".DB_NAME." WHERE hash LIKE '%$output->hash%'");

                    if ($rows !== 0)
                    {
                        return false;
                    }

                    $output->attach .= 'doc'.$item->doc->owner_id.'_'.$item->doc->did.', ';
                }
                elseif (isset($item->video))
                {
                    return false; // Если весь пост относится к видео, то валим нахер
                }
            }

            return $output;
        }
    }

    private function get_doc($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSLVERSION,3);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function create_watermark($img_file, $filetype, $watermark)
    {
        # Размеры картинки
        $image   = GetImageSize($img_file);
        $xImg    = $image[0];
        $yImg    = $image[1];
        # Размеры водяного знака
        $offset  = GetImageSize($watermark);
        $xOffset = $image[0]/2 - $offset[0]/2;
        $yOffset = $image[1]/3.5 - $offset[1]/2;

        # Формат картинки
        switch ($image[2])
        {
            case 1:
                $img = imagecreatefromgif($img_file);
            break;
            case 2:
                $img = imagecreatefromjpeg($img_file);
            break;
            case 3:
                $img = imagecreatefrompng($img_file);
            break;
        }

        $r     = imagecreatefrompng($watermark);
        $x     = imagesx($r);
        $y     = imagesy($r);
        $xDest = $xImg - ($x + $xOffset);
        $yDest = $yImg - ($y + $yOffset);

        imageAlphaBlending($img,1);
        imageAlphaBlending($r,1);
        imagesavealpha($img,1);
        imagesavealpha($r,1);
        imagecopyresampled($img,$r,$xDest,$yDest,0,0,$x,$y,$x,$y);

        switch ($filetype)
        {
            case "jpg":
            case "jpeg":
                imagejpeg($img,$img_file,100);
                imagejpeg($img,$img_file,100);
            break;
            case "gif":
                imagegif($img,$img_file);
            break;
            case "png":
                imagepng($img,$img_file);
            break;
        }

        imagedestroy($r);
        imagedestroy($img);
    }
}

$vkposter = new VKPoster();

$post_info = '';
while (!$post_info)
{
    $post_info = $vkposter->get_post();
    sleep(5);
}

$vkposter->send_post($post_info);