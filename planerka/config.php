<?php
// ─────────────────────────────────────────────────────────────
//  Планёрка — настройки сервера. Единственный файл, который правят под новый сервер.
//  Любое значение можно задать и переменной окружения (она важнее файла).
// ─────────────────────────────────────────────────────────────

// Внешний адрес сайта, БЕЗ слеша в конце и без /planerka. Нужен для превью ссылок
// (og:url, og:image — только абсолютные) и для подписи уведомлений (VAPID).
if (!defined('PL_SITE')) define('PL_SITE', rtrim(getenv('PLANERKA_SITE') ?: 'https://example.com', '/'));

// Папка данных: tasks.json, keys.json, auth.json, push.json, vapid.json, журналы, backups/.
// ДОЛЖНА лежать ВНЕ веб-корня (там ключи доступа). Веб-сервер должен иметь право писать в неё.
if (!defined('PL_DATA')) define('PL_DATA', getenv('PLANERKA_DIR') ?: dirname(__DIR__) . '/planerka-data');

// PHP для отправки уведомлений (push-send.php). Нужен PHP 7.3+ с расширениями openssl и curl.
// Веб-PHP может быть и без них — уведомления отправляет этот внешний процесс.
if (!defined('PL_PHP_CLI')) define('PL_PHP_CLI', getenv('PLANERKA_PHP') ?: 'php');

// Часовой пояс: «сегодня», «просрочено» в напоминаниях, даты в журналах.
if (!defined('PL_TZ')) define('PL_TZ', getenv('PLANERKA_TZ') ?: 'Asia/Bishkek');
date_default_timezone_set(PL_TZ);
