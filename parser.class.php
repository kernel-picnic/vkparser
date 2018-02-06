<?php

/*
 * Класс для автоматического постинга всего в паблик ВК
 * Автор - Антон Тройнин (@kernelpicnic). Все права защищены и т.д. и т.п.
 */
class VKparser
{
    function __construct()
    {
        if (!is_file('config.php'))
        {
            die('Файл с настройками не найден. Вероятно, у вас есть example.config.php. ' .
                'Заполните его своими данными и переименуйте в config.php.');
        }

        include 'config.php';   // Конфигурация скрипта
        include 'vk.class.php'; // Класс для взаимодействия с API вконтакте

        // Открытие этого файла только cron'ом
        if (ONLY_CRON && (!isset($_SERVER['argv'][0]) && $_SERVER['argv'][0] != '--cron'))
        {
            exit;
        }

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
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS " . DB_NAME . " (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `hash` TEXT,
                `group` TEXT,
                `message` TEXT,
                `attachment` TEXT,
                `date` TEXT
            )
        ");

        // Инициализируем класс работы с API
        $this->vk = new vk(VK_ACCESS_TOKEN);
        $this->owner = '-' . $groups[array_rand($groups)];
    }

    /**
     * Получаем случайный пост из одной из доступных групп, указанных в настройках
     *
     * @return array возвращаем всю обработанную информацию о посте или же
     * false, если нечего не было найдено или произошла ошибка
     */
    public function get_post()
    {
        $post = $this->vk->method('wall.get', array(
            //'captcha_sid' => '601516643537',
            //'captcha_key' => 'dqnn2h',
            'owner_id' => $this->owner, // Рандомная группа из списка
            'offset'   => rand(1, 5),   // Один из 5 последних постов
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
        else
        {
            return false;
        }
    }

    /**
     * Обработка - убираем ненужную информацию, сохраняем изображения, накладываем
     * водяной знак и другие полезные процедуры
     *
     * @param  $post необработанный пост
     * @return array обработанный пост
     */
    private function process_post($post)
    {
        $output = new stdClass();
        // Химичим с текстом, чтобы убрать все теги <br>
        $output->text = preg_replace('#<br\s*?/?>#i', '\n', $post->text);
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
                    $this->grab_image($item->photo->src_big);

                    // Проверяем, была ли уже такая картинка
                    $output->hash = md5_file(DIRECTORY . 'image.jpg');
                    $rows = $this->db->querySingle("
                        SELECT COUNT(*) FROM " . DB_NAME . " WHERE hash LIKE '%$output->hash%'
                    ");

                    if ($rows !== 0)
                    {
                        return false;
                    }

                    // Накладываем водяной знак, если разрешено в настройках
                    if (USE_WATERMARK) {
                        $this->apply_watermark(DIRECTORY . 'image.jpg');
                    }

                    // Вначале получаем адрес сервера для сохранения картинки
                    $server = $this->vk->method(
                        'photos.getWallUploadServer',
                        array('group_id' => VK_GROUP_ID)
                    );

                    // Подготовка к сохранению
                    $data['file'] = new CURLFile(DIRECTORY . 'image.jpg');
                    // Отправляем файл на сервер
                    $ch = curl_init($server->response->upload_url);

                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

                    $json = json_decode(curl_exec($ch));

                    curl_close($ch);

                    // Сохраняем картинку на сервер ВК и получаем её ID
                    $saved_photo = $this->vk->method('photos.saveWallPhoto', array(
                        'group_id' => VK_GROUP_ID,
                        'server'   => $json->{'server'},
                        'photo'    => $json->{'photo'},
                        'hash'     => $json->{'hash'}
                    ));

                    $output->attach .= 'photo' . $saved_photo->response[0]->owner_id .
                                       '_' . $saved_photo->response[0]->pid . ', ';
                }
                elseif (isset($item->audio))
                {
                    $output->attach .= 'audio' . $item->audio->owner_id .
                                       '_' . $item->audio->aid . ', ';
                }
                elseif (isset($item->doc))
                {
                    // Проверяем, была ли уже такая гифка
                    $output->hash .= md5_file($item->doc->url);
                    $rows = $this->db->querySingle("
                        SELECT COUNT(*) FROM " . DB_NAME . " WHERE hash LIKE '%$output->hash%'
                    ");

                    if ($rows !== 0)
                    {
                        return false;
                    }

                    $output->attach .= 'doc' . $item->doc->owner_id . '_' . $item->doc->did . ', ';
                }
                elseif (isset($item->video))
                {
                    // Если весь пост относится к видео, то расходимся как в море корабли
                    return false;
                }
            }

            return $output;
        }
    }

    /**
     * Отправка полностью готового поста ВК в группу по указанному VK_GROUP_ID 
     * 
     * @param  array $data - массив с данными о посте
     */
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
        $this->db->exec("
            INSERT INTO " . DB_NAME . " ('hash', 'group', 'message', 'attachment', 'date') 
            VALUES ('$data->hash', '$this->owner', '$data->text', '$data->attach', '$time')
        ");

        return true;
    }

    /**
     * Получение документа с помощью cURL
     *
     * @param  $url URL документа
     * @return any полученный документ
     */
    private function grab_image($url)
    {
        $ch = curl_init ($url);

        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);

        $raw = curl_exec($ch);
        curl_close($ch);

        if (file_exists(DIRECTORY . 'image.jpg'))
        {
            unlink(DIRECTORY . 'image.jpg');
        }

        $fp = fopen(DIRECTORY . 'image.jpg', 'x');

        fwrite($fp, $raw);
        fclose($fp);
    }

    /**
     * Наложение водяного знака на изображение
     *
     * @param  $img_file используемое изображение
     * @param  $filetype нужный ным формат на выходе
     * @param  $watermark изображение водяного знака
     */
    private function apply_watermark($img_file, $filetype = 'jpg', $watermark = DIRECTORY_WATERMARK)
    {
        // Размеры картинки
        $image   = GetImageSize($img_file);
        $xImg    = $image[0];
        $yImg    = $image[1];
        // Размеры водяного знака
        $offset  = GetImageSize($watermark);
        $xOffset = $image[0]/2 - $offset[0]/2;
        $yOffset = $image[1]/3.5 - $offset[1]/2;

        // Формат картинки
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
        imagecopyresampled($img, $r, $xDest, $yDest, 0, 0, $x, $y, $x, $y);

        switch ($filetype)
        {
            case 'jpg':
            case 'jpeg':
                imagejpeg($img, $img_file, 100);
                imagejpeg($img, $img_file, 100);
            break;
            case 'gif':
                imagegif($img, $img_file);
            break;
            case 'png':
                imagepng($img, $img_file);
            break;
        }

        imagedestroy($r);
        imagedestroy($img);
    }
}

$vkparser = new VKparser();

$post_info = '';
while (!$post_info)
{
    $post_info = $vkparser->get_post();
    // Пауза между получением нового поста
    sleep(5);
}

$vkparser->send_post($post_info);