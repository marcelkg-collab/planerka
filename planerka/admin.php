<?php
// ─────────────────────────────────────────────────────────────
//  Планёрка — консоль администратора. Только из командной строки, с правами веб-сервера
//  (иначе созданные файлы веб-сервер не сможет менять):   sudo -u www-data php admin.php <команда>
//
//    init                    создать папку данных, ключи, ПУСТОЙ список задач и исполнителей, ключи уведомлений
//    password <пароль>       пароль Планёрки — им исполнители входят в приложение (от 6 знаков)
//    add <id> <Имя>          добавить исполнителя; id — латиница/цифры/дефис, 2–24 знака, это адрес /planerka/<id>
//    rename <id> <Имя>       переименовать исполнителя
//    remove <id>             убрать исполнителя (его открытые задачи вернутся во «Входящие»)
//    owner <id>              постановщик: удаляет любые задачи и чистит историю, получает ответы исполнителей
//    list                    исполнители и их личные ссылки (ссылка содержит ключ — пересылать только своим)
//    keys                    ключи доступа team и sync (не публиковать)
//    client-password <пароль> пароль внешнего клиента администратора (выдаёт ключ sync) — нужен только для такого клиента
//
//  Любая правка списка поднимает номер версии данных — открытые приложения подхватят её сами за секунду.
// ─────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
$DIR = PL_DATA;

function say($s) { fwrite(STDOUT, $s . "\n"); }
function fail($s) { fwrite(STDERR, 'Ошибка: ' . $s . "\n"); exit(1); }
function jread($f) { $v = @json_decode(@file_get_contents($f), true); return is_array($v) ? $v : null; }
function jwrite($f, $data) {
    $tmp = $f . '.tmp' . getmypid();
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) === false) fail('не удалось записать ' . $f);
    @chmod($tmp, 0660);
    if (!rename($tmp, $f)) fail('не удалось заменить ' . $f);
}
function ulen($s) { return preg_match_all('/./u', (string)$s); }
function need_init($dir) { if (!is_file($dir . '/keys.json') || !is_file($dir . '/tasks.json')) fail('Планёрка ещё не создана: php admin.php init'); }
function id_check($id) {
    if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $id)) fail('id — латиница в нижнем регистре, цифры и дефис, 2–24 знака (например ivan или anna-k)');
    if (in_array($id, ['api', 'index', 'dial', 'manifest', 'planerka', 'h'], true)) fail('id «' . $id . '» занят служебным адресом, выберите другой');
}
function name_check($n) {
    $n = trim((string)$n);
    if ($n === '') fail('укажите имя');
    if (ulen($n) > 40) fail('имя длиннее 40 знаков');
    return $n;
}
// правка tasks.json под той же блокировкой, что у api.php, с подъёмом версии (rev)
function with_db($dir, $fn) {
    $lock = fopen($dir . '/lock', 'c');
    if (!$lock) fail('нет доступа к папке данных ' . $dir);
    flock($lock, LOCK_EX);
    $db = jread($dir . '/tasks.json');
    if (!$db || !isset($db['tasks']) || !is_array($db['tasks'])) $db = ['rev' => 0, 'tasks' => [], 'people' => [], 'ev' => [], 'owner' => ''];
    if (!isset($db['people']) || !is_array($db['people'])) $db['people'] = [];
    $msg = $fn($db);
    $db['rev'] = (int)$db['rev'] + 1;
    jwrite($dir . '/tasks.json', $db);
    flock($lock, LOCK_UN); fclose($lock);
    return $msg;
}
function link_of($id, $team) { return PL_SITE . '/planerka/' . ($id !== '' ? $id : '') . '?k=' . $team; }

$cmd = isset($argv[1]) ? $argv[1] : '';
$a2 = isset($argv[2]) ? $argv[2] : null;
$a3 = isset($argv[3]) ? implode(' ', array_slice($argv, 3)) : null;

if ($cmd === 'init') {
    if (!is_dir($DIR) && !@mkdir($DIR, 0770, true)) fail('не удалось создать папку данных ' . $DIR);
    @chmod($DIR, 0770);
    // страховка: если папку всё же положили в веб-корень, Apache её не отдаст
    if (!is_file($DIR . '/.htaccess')) @file_put_contents($DIR . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_file($DIR . '/keys.json')) { jwrite($DIR . '/keys.json', ['team' => bin2hex(random_bytes(16)), 'sync' => bin2hex(random_bytes(16))]); say('ключи доступа созданы'); }
    else say('ключи доступа уже есть — не трогаю');
    if (!is_file($DIR . '/tasks.json')) { jwrite($DIR . '/tasks.json', ['rev' => 0, 'tasks' => [], 'people' => [], 'ev' => [], 'owner' => '']); say('пустой список задач создан (исполнителей нет)'); }
    else say('список задач уже есть — не трогаю');
    if (is_file($DIR . '/vapid.json')) say('ключи уведомлений уже есть');
    elseif (function_exists('openssl_pkey_new') && function_exists('openssl_pkey_derive')) {
        require __DIR__ . '/lib-webpush.php';
        list($k, $pub) = wp_ec_new();
        openssl_pkey_export($k, $pem);
        jwrite($DIR . '/vapid.json', ['pem' => $pem, 'pub' => wp_b64u($pub), 'subject' => PL_SITE . '/planerka/']);
        say('ключи уведомлений (VAPID) созданы');
    } else say('ВНИМАНИЕ: у этого PHP нет openssl — ключи уведомлений не созданы. Запустите: ' . PL_PHP_CLI . ' gen-vapid.php');
    say('Папка данных: ' . $DIR);
    say('Дальше: php admin.php password <пароль>, php admin.php add <id> <Имя>, php admin.php owner <id>, php admin.php list');
    exit(0);
}
if ($cmd === 'password' || $cmd === 'client-password') {
    need_init($DIR);
    if ($a2 === null || ulen($a2) < 6) fail('пароль — не короче 6 знаков');
    $A = jread($DIR . '/auth.json'); if (!$A) $A = [];
    if ($cmd === 'password') { $A['team_pw'] = password_hash($a2, PASSWORD_DEFAULT); $A['team_at'] = date('c'); }
    else { $A['pw'] = password_hash($a2, PASSWORD_DEFAULT); $A['at'] = date('c'); }
    jwrite($DIR . '/auth.json', $A);
    say($cmd === 'password' ? 'Пароль Планёрки задан: исполнители входят им на ' . PL_SITE . '/planerka/' : 'Пароль клиента администратора задан');
    exit(0);
}
if ($cmd === 'add' || $cmd === 'rename') {
    need_init($DIR);
    id_check($a2); $nm = name_check($a3);
    say(with_db($DIR, function (&$db) use ($cmd, $a2, $nm) {
        foreach ($db['people'] as $i => $p) if ($p['id'] === $a2) {
            if ($cmd === 'add') fail('исполнитель ' . $a2 . ' уже есть (переименовать: rename)');
            $db['people'][$i]['name'] = $nm; return 'Переименован: ' . $a2 . ' → ' . $nm;
        }
        if ($cmd === 'rename') fail('исполнителя ' . $a2 . ' нет');
        if (count($db['people']) >= 20) fail('не больше 20 исполнителей');
        $db['people'][] = ['id' => $a2, 'name' => $nm];
        return 'Добавлен исполнитель: ' . $nm . ' (' . $a2 . ')';
    }));
    exit(0);
}
if ($cmd === 'remove') {
    need_init($DIR);
    id_check($a2);
    say(with_db($DIR, function (&$db) use ($a2) {
        $was = count($db['people']);
        $db['people'] = array_values(array_filter($db['people'], function ($p) use ($a2) { return $p['id'] !== $a2; }));
        if (count($db['people']) === $was) fail('исполнителя ' . $a2 . ' нет');
        if (isset($db['owner']) && $db['owner'] === $a2) $db['owner'] = '';
        return 'Убран: ' . $a2 . ' (его открытые задачи снова во «Входящих»)';
    }));
    exit(0);
}
if ($cmd === 'owner') {
    need_init($DIR);
    id_check($a2);
    say(with_db($DIR, function (&$db) use ($a2) {
        $ok = false; foreach ($db['people'] as $p) if ($p['id'] === $a2) $ok = true;
        if (!$ok) fail('сначала добавьте исполнителя: php admin.php add ' . $a2 . ' <Имя>');
        $db['owner'] = $a2;
        return 'Постановщик: ' . $a2;
    }));
    exit(0);
}
if ($cmd === 'list' || $cmd === 'keys') {
    need_init($DIR);
    $k = jread($DIR . '/keys.json');
    if ($cmd === 'keys') { say('team: ' . $k['team']); say('sync: ' . $k['sync']); exit(0); }
    $db = jread($DIR . '/tasks.json');
    $A = jread($DIR . '/auth.json');
    say('Вход по паролю: ' . PL_SITE . '/planerka/' . (!empty($A['team_pw']) ? '' : '   (пароль ещё не задан: php admin.php password …)'));
    $people = isset($db['people']) && is_array($db['people']) ? $db['people'] : [];
    if (!$people) { say('Исполнителей нет: php admin.php add <id> <Имя>'); exit(0); }
    $own = isset($db['owner']) ? $db['owner'] : '';
    foreach ($people as $p) say(str_pad($p['id'], 14) . ' ' . $p['name'] . ($p['id'] === $own ? ' (постановщик)' : '') . "\n               " . link_of($p['id'], $k['team']));
    if ($own === '') say('Постановщик не назначен: php admin.php owner <id>');
    exit(0);
}
say("Команды: init | password <пароль> | add <id> <Имя> | rename <id> <Имя> | remove <id> | owner <id> | list | keys | client-password <пароль>");
exit($cmd === '' || $cmd === 'help' ? 0 : 1);
