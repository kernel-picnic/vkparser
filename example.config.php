<?php // Конфигурация standalone приложения ВК для автоматичиского постинга

define('VK_GROUP_ID', ''); // ID группы ВК

// ACCESS TOKEN можно получить по ссылке
// CLIENT_ID - ID приложения
// oauth.vk.com/authorize?client_id=[CLIENT_ID]&scope=photos,wall,offline&redirect_uri=http://oauth.vk.com/blank.html&display=page&response_type=token
define('VK_ACCESS_TOKEN', '');

define('DIRECTORY', dirname(__FILE__) . '/'); // Директория из-под которой работаем
define('DIRECTORY_WATERMARK', DIRECTORY . 'watermark.png'); // Водяной знак (лого группы)

define('DB_FILE', DIRECTORY . 'posts.db'); // Файл БД
define('DB_NAME', 'posts'); // Таблица БД

define('LOG_FILE', DIRECTORY . 'error.log'); // Файл для записи логов

define('TIMEOUT', '90'); // Таймаут на выполнение скрипта

define('USE_WATERMARK', false); // Накладывать водяной знак или нет

define('ONLY_CRON', false); // Разрешать запуск скрипта не только через CRONTAB

// Группы, из которых берутся записи
$groups = array(
    '',
);

// Для автозапуска через CRON можно воспользоваться следующим кодом:
// 0 * * * * /opt/php5.6/bin/php /path/to/file/parser.class.php