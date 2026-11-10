<?php
// Страница Планёрки и личные ссылки исполнителей: /planerka/<имя> (например /planerka/ivan?k=<ключ>).
// Веб-сервер ведёт ВСЕ адреса /planerka/* на этот файл (front controller). Путь после /planerka берётся
// из PATH_INFO, а если его нет — из REQUEST_URI. /planerka/api.php тоже идёт через этот файл и отдаётся обработчику.
require_once __DIR__ . '/config.php';
$pi = isset($_SERVER['PATH_INFO']) ? (string)$_SERVER['PATH_INFO'] : '';
if ($pi === '' || $pi === '/') $pi = (string)parse_url(isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
// у одних серверов в пути есть /planerka и /index.php (встроенный php -S, Apache), у других — уже нет (прокси, срезающий префикс)
if ($pi === '/planerka' || strpos($pi, '/planerka/') === 0) $pi = (string)substr($pi, 9);
if ($pi === '/index.php' || strpos($pi, '/index.php/') === 0) $pi = (string)substr($pi, 10);
if ($pi === '/') $pi = '';
$dirData = PL_DATA;
if ($pi === '/api.php') { require __DIR__ . '/api.php'; exit; }

// Служебные файлы приложения: сервис-воркер, значки, постер, манифест (для установки на экран «Домой» с ключом в ссылке)
if ($pi === '/sw.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /planerka/');
    header('Cache-Control: no-cache');
    readfile(__DIR__ . '/sw.js'); exit;
}
// /planerka/dial?n=+996... — открыть набор номера (кнопка «Позвонить» в уведомлении). Данных здесь нет.
if ($pi === '/dial') {
    $n = isset($_GET['n']) ? preg_replace('/[^0-9+]/', '', (string)$_GET['n']) : '';
    header('Content-Type: text/html; charset=utf-8');
    $h = htmlspecialchars($n, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Позвонить</title>'
       . '<style>body{margin:0;font:600 20px system-ui,sans-serif;background:#FBF8F2;color:#0E1626;display:grid;place-items:center;min-height:100vh;padding:16px}a{display:block;padding:18px 26px;border-radius:16px;background:#0B3FD0;color:#fff;text-decoration:none;text-align:center}</style></head><body>'
       . ($h !== '' ? '<a href="tel:' . $h . '">Позвонить ' . $h . '</a><script>location.href="tel:' . $h . '";</script>' : 'Номер не указан')
       . '</body></html>';
    exit;
}
if ($pi === '/icon-192.png' || $pi === '/icon-512.png' || $pi === '/badge.png') {
    header('Content-Type: image/png'); header('Cache-Control: public, max-age=86400');
    readfile(__DIR__ . '/assets/' . substr($pi, 1)); exit;
}
// постер превью ссылки (WhatsApp, Telegram): 1200×630, открывается напрямую — 200 и image/png
if ($pi === '/og.png') {
    header('Content-Type: image/png'); header('Cache-Control: public, max-age=86400');
    readfile(__DIR__ . '/assets/og.png'); exit;
}
function key_ok($dir, $k) {
    $keys = @json_decode(@file_get_contents($dir . '/keys.json'), true);
    return is_array($keys) && !empty($keys['team']) && is_string($k) && $k !== '' && hash_equals((string)$keys['team'], $k);
}
if ($pi === '/manifest.json') {
    $k = isset($_GET['k']) ? (string)$_GET['k'] : '';
    $p = isset($_GET['p']) && preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $_GET['p']) ? $_GET['p'] : '';
    if (!key_ok($dirData, $k)) { http_response_code(401); exit; }
    $start = '/planerka/' . ($p !== '' ? $p . '?k=' . $k : '?k=' . $k);
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode(['name' => 'Планёрка', 'short_name' => 'Планёрка', 'id' => '/planerka/' . $p, 'start_url' => $start, 'scope' => '/planerka/',
        'display' => 'standalone', 'background_color' => '#FBF8F2', 'theme_color' => '#FBF8F2', 'lang' => 'ru',
        'icons' => [['src' => '/planerka/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => '/planerka/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
$html = @file_get_contents(__DIR__ . '/app.html');
if ($html === false) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); echo 'Планёрка не собрана: запустите python build.py'; exit; }

// Личная ссылка: в превью (WhatsApp) и заголовке вкладки — имя исполнителя. Данных, кроме имени, здесь нет.
$slug = trim($pi, '/');
if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $slug)) {
    $db = @json_decode(@file_get_contents($dirData . '/tasks.json'), true);
    $people = (is_array($db) && isset($db['people']) && is_array($db['people'])) ? $db['people'] : [];
    foreach ($people as $p) {
        if (isset($p['id']) && $p['id'] === $slug) {
            $n = htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8');
            $html = str_replace('<title>Планёрка</title>', '<title>Планёрка · ' . $n . '</title>', $html);
            $html = str_replace('<meta property="og:title" content="Планёрка">', '<meta property="og:title" content="Планёрка · ' . $n . '">', $html);
            $html = str_replace('<meta property="og:url" content="%%SITE%%/planerka/">',
                '<meta property="og:url" content="%%SITE%%/planerka/' . $slug . '">', $html);
            break;
        }
    }
}
// манифест для «На экран Домой»: ссылка с ключом, когда ключ в адресе верный
if ($slug !== '' && isset($_GET['k']) && key_ok($dirData, (string)$_GET['k'])) {
    $mf = '<link rel="manifest" href="/planerka/manifest.json?p=' . $slug . '&k=' . rawurlencode((string)$_GET['k']) . '">';
    $html = str_replace('<title>', $mf . "\n<title>", $html);
}
// абсолютные адреса превью (og:url, og:image) — из config.php
$html = str_replace('%%SITE%%', htmlspecialchars(PL_SITE, ENT_QUOTES, 'UTF-8'), $html);
echo $html;
