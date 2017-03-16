<?php // Конфигурация standalone приложения ВК для автоматичиского постинга

define('VK_GROUP_ID', ''); // ID группы ВК

// ACCESS TOKEN можно получить по ссылке
// CLIENT_ID - ID приложения
// oauth.vk.com/authorize?client_id=[CLIENT_ID]&scope=photos,wall,offline&redirect_uri=http://oauth.vk.com/blank.html&display=page&response_type=token
define('VK_ACCESS_TOKEN', '');

define('DIRECTORY', dirname(__FILE__).'/'); // Директория из-под которой работаем
define('DIRECTORY_WATERMARK', DIRECTORY . 'watermark.png'); // Водяной знак (лого группы)

define('DB_FILE', DIRECTORY . 'posts.db'); // Файл БД
define('DB_NAME', 'posts'); // Таблица БД

define('LOG_FILE', DIRECTORY . 'error.log'); // Лог

define('TIMEOUT', '90'); // Таймаут на выполнение скрипта

// Группы, из который берутся записи
$groups = array(
    '',
);

// Для автозапуска через CRON можно воспользоваться следующим кодом:
// 0 * * * * /opt/php5.6/bin/php /home/c/vk/minecraft/parser.php