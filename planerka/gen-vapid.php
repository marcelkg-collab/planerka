<?php
// Один раз на сервере: создаёт ключи VAPID (подпись уведомлений). Повторный запуск ничего не меняет.
// Нужен PHP 7.3+ с openssl. Обычно ключи создаёт уже `php admin.php init`; этот файл — если там openssl не было.
//   php gen-vapid.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
require __DIR__ . '/lib-webpush.php';
$DIR = PL_DATA;
$f = $DIR . '/vapid.json';
if (is_file($f)) { echo "уже есть\n"; exit(0); }
list($k, $pub) = wp_ec_new();
openssl_pkey_export($k, $pem);
file_put_contents($f, json_encode(['pem' => $pem, 'pub' => wp_b64u($pub), 'subject' => PL_SITE . '/planerka/']));
@chmod($f, 0660);
echo "создано\n";
