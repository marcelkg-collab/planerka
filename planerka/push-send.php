<?php
// Отправка Web Push исполнителю. Запускается внешним PHP (PL_PHP_CLI из config.php) из api.php (в фоне; напоминание
// remind — с ожиданием ответа), а не веб-PHP: веб-PHP может быть без openssl и curl.
//   php push-send.php <base64(json)>   json: {person, task, title, type?, test?}
//   type: assign (новая задача) · accept/decline (ответ исполнителя) · remind (напоминание о незавершённой задаче,
//   about — чья задача; check — проверка: то же напоминание приходит постановщику).
// Последней строкой печатает «RESULT {"sent":N,"tried":M}» — её читает api.php при синхронной отправке (remind).
// Ключи VAPID и подписки: папка данных PL_DATA — {vapid.json, push.json}. Журнал: push.log.
// PLANERKA_PUSH_SINK=<файл> — ТОЛЬКО для тестов: уведомления не отправляются, а дописываются в файл (как будто доставлены).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
require __DIR__ . '/lib-webpush.php';
$DIR = PL_DATA;
$arg = isset($argv[1]) ? json_decode(base64_decode($argv[1]), true) : null;
if (!is_array($arg) || empty($arg['person'])) exit(1);

function plog($dir, $s) {
    $f = $dir . '/push.log';
    if (@filesize($f) > 200000) @rename($f, $f . '.old');
    @file_put_contents($f, date('c') . ' ' . $s . "\n", FILE_APPEND);
}

function result($sent, $tried) { echo 'RESULT ', json_encode(['sent' => $sent, 'tried' => $tried]), "\n"; }
$SINK = getenv('PLANERKA_PUSH_SINK');
// Песочница (файл sandbox.flag в папке данных): уведомления никуда не отправляются, а дописываются в push-sandbox.log.
if (!$SINK && is_file($DIR . '/sandbox.flag')) $SINK = $DIR . '/push-sandbox.log';
$vapid = @json_decode(@file_get_contents($DIR . '/vapid.json'), true);
if (!$SINK && (!is_array($vapid) || empty($vapid['pem']))) { plog($DIR, 'no vapid keys'); result(0, 0); exit(1); }

$lock = fopen($DIR . '/lock', 'c');
flock($lock, LOCK_EX);
$store = @json_decode(@file_get_contents($DIR . '/push.json'), true);
$subs = (is_array($store) && isset($store['subs']) && is_array($store['subs'])) ? $store['subs'] : [];
flock($lock, LOCK_UN);

$db = @json_decode(@file_get_contents($DIR . '/tasks.json'), true);
$name = $arg['person'];
if (is_array($db) && isset($db['people'])) foreach ($db['people'] as $p) if ($p['id'] === $arg['person']) $name = $p['name'];

$title = isset($arg['title']) ? (string)$arg['title'] : '';
$type = isset($arg['type']) ? $arg['type'] : 'assign';
$fromName = isset($arg['from']) ? $arg['from'] : '';
if (is_array($db) && isset($db['people'])) foreach ($db['people'] as $p) if ($p['id'] === $fromName) $fromName = $p['name'];
// Напоминание о незавершённой задаче: кому, что за задача, срок, ответ, пункты.
// Проверка (check) приходит постановщику (owner) с тем же текстом и пометкой, чей это вид.
function plural($n, $one, $few, $many) { $m = $n % 100; $k = $n % 10; return ($m >= 11 && $m <= 14) ? $many : ($k === 1 ? $one : (($k >= 2 && $k <= 4) ? $few : $many)); }
function remind_payload($arg, $db, $title) {
    $about = isset($arg['about']) ? (string)$arg['about'] : $arg['person'];
    $aboutName = $about;
    if (is_array($db) && isset($db['people'])) foreach ($db['people'] as $p) if ($p['id'] === $about) $aboutName = $p['name'];
    $tk = null;
    if (is_array($db) && isset($db['tasks'])) foreach ($db['tasks'] as $x) if ($x['id'] === $arg['task']) { $tk = $x; break; }
    $L = [$aboutName . ', напоминание: у вас не завершена задача «' . $title . '».'];
    $due = $tk && !empty($tk['due']) ? (string)$tk['due'] : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        $tz = new DateTimeZone(PL_TZ);
        $today = new DateTime('today', $tz);
        $d = DateTime::createFromFormat('!Y-m-d', $due, $tz);
        $mon = ['янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.'];
        $lab = (int)$d->format('j') . ' ' . $mon[(int)$d->format('n') - 1];
        $days = (int)$today->diff($d)->format('%r%a');
        if ($days < 0) $L[] = 'Срок был ' . $lab . ' — просрочено на ' . (-$days) . ' ' . plural(-$days, 'день', 'дня', 'дней') . '.';
        elseif ($days === 0) $L[] = 'Срок — сегодня.';
        elseif ($days === 1) $L[] = 'Срок — завтра, ' . $lab . '.';
        else $L[] = 'Срок — ' . $lab . '.';
    }
    if ($tk && ($tk['status'] ?? null) === 'new') $L[] = 'Задача ещё не принята — откройте её и ответьте.';
    $items = ($tk && isset($tk['items']) && is_array($tk['items'])) ? $tk['items'] : [];
    if ($items) { $dn = 0; foreach ($items as $it) if (!empty($it['d'])) $dn++; $L[] = 'Пункты: выполнено ' . $dn . ' из ' . count($items) . '.'; }
    return ['title' => 'Планёрка · напоминание', 'body' => implode("\n", $L), 'tag' => (!empty($arg['check']) ? 'remind-check-' : 'remind-') . $arg['task'],
            'url' => '/planerka/' . $arg['person'] . '?task=' . $arg['task'], 'task' => $arg['task'],
            'checkNote' => !empty($arg['check']) ? 'Это проверка: так напоминание увидит ' . $aboutName . '.' : ''];
}
if (!empty($arg['test'])) {
    $payload = ['title' => 'Планёрка', 'body' => $name . ', уведомления включены. Так будут приходить новые задачи.', 'tag' => 'test',
                'url' => '/planerka/' . $arg['person'], 'task' => null];
} elseif ($type === 'accept') {
    $payload = ['title' => 'Планёрка', 'body' => $fromName . ': задача принята — «' . $title . '»', 'tag' => 'resp-' . $arg['task'],
                'url' => '/planerka/' . $arg['person'] . '?task=' . $arg['task'], 'task' => $arg['task']];
} elseif ($type === 'decline') {
    $payload = ['title' => 'Планёрка', 'body' => $fromName . ': отказ от задачи «' . $title . '». Причина: ' . (isset($arg['reason']) ? $arg['reason'] : ''), 'tag' => 'resp-' . $arg['task'],
                'url' => '/planerka/' . $arg['person'] . '?task=' . $arg['task'], 'task' => $arg['task']];
} elseif ($type === 'remind') {
    $payload = remind_payload($arg, $db, $title);
} else {
    $payload = ['title' => 'Планёрка', 'body' => $name . ', у вас новая задача: ' . $title, 'tag' => 'task-' . $arg['task'],
                'url' => '/planerka/' . $arg['person'] . '?task=' . $arg['task'], 'task' => $arg['task']];
}
// Номер контрагента: в тексте уведомления и кнопками «Позвонить» / «WhatsApp» (кнопки показывает Android; iPhone их не поддерживает).
// Местный номер (0555… или 555…) дополняется кодом страны 996 (Кыргызстан) — для другой страны поменять здесь и в src.html (telHref, waHref).
function tel_norm($p) {
    $p = (string)$p; $n = preg_replace('/\D/', '', $p);
    if ($n === '') return '';
    if (strlen($n) === 10 && $n[0] === '0') return '+996' . substr($n, 1);
    if (strlen($n) === 9) return '+996' . $n;
    return '+' . $n;
}
if (!empty($payload['task']) && is_array($db) && isset($db['tasks'])) {
    foreach ($db['tasks'] as $tk) {
        if ($tk['id'] !== $payload['task']) continue;
        $ph = isset($tk['phone']) ? trim((string)$tk['phone']) : '';
        $tn = tel_norm($ph);
        if ($tn !== '') {
            $payload['body'] .= "\nКонтрагент: " . $ph;
            $payload['phone'] = $tn;
            $payload['wa'] = 'https://wa.me/' . ltrim($tn, '+');
            $payload['actions'] = [['action' => 'call', 'title' => 'Позвонить'], ['action' => 'wa', 'title' => 'WhatsApp']];
        }
        break;
    }
}
// пометка проверки — последней строкой, после контрагента
if (isset($payload['checkNote'])) { if ($payload['checkNote'] !== '') $payload['body'] .= "\n" . $payload['checkNote']; unset($payload['checkNote']); }
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
if (getenv('PLANERKA_DRYRUN')) { echo $json, "\n"; exit(0); }   // для проверки: показать уведомление, не отправляя

$dead = [];
$sent = 0; $tried = 0;
foreach ($subs as $s) {
    if (($s['person'] ?? '') !== $arg['person']) continue;
    $tried++;
    if ($SINK) { @file_put_contents($SINK, json_encode(['person' => $arg['person'], 'payload' => $payload], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); $code = 201; $err = ''; }
    else list($code, $err) = wp_send($s, $json, $vapid);
    $host = parse_url($s['endpoint'], PHP_URL_HOST);
    plog($DIR, $arg['person'] . ' ' . $host . ' http=' . $code . ($code >= 300 || $code === 0 ? ' ' . $err : ''));
    if ($code >= 200 && $code < 300) $sent++;
    if ($code === 404 || $code === 410) $dead[$s['endpoint']] = 1;
}
if ($dead) {
    flock($lock, LOCK_EX);
    $store = @json_decode(@file_get_contents($DIR . '/push.json'), true);
    if (is_array($store) && isset($store['subs'])) {
        $store['subs'] = array_values(array_filter($store['subs'], function ($s) use ($dead) { return !isset($dead[$s['endpoint']]); }));
        file_put_contents($DIR . '/push.json', json_encode($store));
    }
    flock($lock, LOCK_UN);
}
result($sent, $tried);
exit($sent > 0 ? 0 : 2);
