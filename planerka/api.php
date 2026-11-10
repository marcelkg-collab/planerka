<?php
// Планёрка: общее хранилище задач (PHP 7.0-совместимо, без расширений).
// Данные: папка PL_DATA из config.php — {tasks.json, keys.json, auth.json, push.json, backups/}. В git не кладутся.
//
// Два ключа, два права:
//   team — открывает Планёрку: читать, добавлять, менять, назначать исполнителя, удалять;
//   sync — ключ администратора (внешнее приложение-клиент): pull (прочитать все задачи), sync (записать
//          изменения и список исполнителей), info (выдаёт ключ team для личных ссылок), remind, pw.*.
//
// Список исполнителей и пароль ведёт администратор: консольный admin.php (работает с файлами напрямую)
// или внешний клиент с ключом sync (op=sync с полем people). Двусторонний обмен с внешним клиентом:
//   • задача, созданная во внешнем клиенте, приходит событием upsert (src='todos', srcId = id у клиента);
//   • задача, созданная здесь (src=null), забирается клиентом через pull;
//   • правки идут по полям: клиент шлёт только изменённые поля, поэтому не затирает чужие изменения.

// Встраивание: другой PHP-код может подключить этот файл изнутри, заранее задав папку данных ($GLOBALS['PLANERKA_DIR_FORCE']),
// тело запроса ($GLOBALS['PLANERKA_INPUT']) и доверие (константы PLANERKA_TRUSTED / PLANERKA_TRUSTED_ADMIN) — ключ тогда не нужен.
require_once __DIR__ . '/config.php';
$DIR = isset($GLOBALS['PLANERKA_DIR_FORCE']) ? (string)$GLOBALS['PLANERKA_DIR_FORCE'] : PL_DATA;
putenv('PLANERKA_DIR=' . $DIR);   // фоновые процессы (уведомления) работают с данными этого же дома
function pl_input() { return isset($GLOBALS['PLANERKA_INPUT']) ? (string)$GLOBALS['PLANERKA_INPUT'] : (string)file_get_contents('php://input'); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($a, $code = 200) {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}
function cut($s, $n) {
    $s = trim((string)$s);
    if (function_exists('mb_substr')) return mb_substr($s, 0, $n, 'UTF-8');
    $a = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return $a ? implode('', array_slice($a, 0, $n)) : '';
}
// номер контрагента: только цифры, +, скобки, дефис и пробел; до 32 знаков
function phone_ok($v) { return substr(trim(preg_replace('/[^0-9+()\-\s]/', '', (string)$v)), 0, 32); }
function due_ok($v) { return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null; }
function pri_ok($v) { $v = (int)$v; return ($v >= 1 && $v <= 4) ? $v : 4; }
function id_ok($v) { return (is_string($v) && preg_match('/^[a-z0-9]{4,40}$/', $v)) ? $v : null; }
function slug_ok($v) { return (is_string($v) && preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $v) && !in_array($v, ['api', 'index'], true)) ? $v : null; }

function default_people() { return []; }   // исполнителей нет, пока их не добавит администратор (php admin.php add …)
function people_ids($people) { $r = []; foreach ($people as $p) $r[$p['id']] = 1; return $r; }
// пункты задачи: [{t: текст, d: выполнен}], до 50 штук; принимает массив или JSON-строку
function items_ok($v) {
    if (is_string($v)) $v = @json_decode($v, true);
    $r = [];
    if (!is_array($v)) return $r;
    foreach ($v as $x) {
        if (!is_array($x) || !isset($x['t']) || !is_string($x['t'])) continue;
        $t = cut($x['t'], 200);
        if ($t === '') continue;
        $r[] = ['t' => $t, 'd' => !empty($x['d'])];
        if (count($r) >= 50) break;
    }
    return $r;
}
function who_ok($v, $db) { $ids = people_ids($db['people']); return (is_string($v) && isset($ids[$v])) ? $v : null; }

$keys = @json_decode(@file_get_contents($DIR . '/keys.json'), true);
if (!is_array($keys) || empty($keys['team'])) out(['error' => 'not configured'], 503);

// Вход ПАРОЛЕМ вместо ключа. Два пароля: team_pw — пароль Планёрки для исполнителей (выдаёт ключ team),
// pw — пароль внешнего клиента администратора (выдаёт ключ sync). Задаёт администратор (php admin.php password …);
// хранится только хэш (password_hash). Вход по паролю выдаёт ключ — дальше приложение работает с ключом.
// Защита от подбора: 8 неудачных попыток с одного адреса или 40 всего за 15 минут — пауза. IP не храним, только отпечаток.
$in0 = json_decode(pl_input(), true);
if (is_array($in0) && isset($in0['op']) && $in0['op'] === 'pw.login') {
    $ipRaw = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : '';
    if ($ipRaw === '') $ipRaw = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
    $ipf = substr(sha1('planerka|' . $ipRaw), 0, 16);
    $limF = $DIR . '/pwlimit.json'; $t0 = time();
    $L = @json_decode(@file_get_contents($limF), true); if (!is_array($L)) $L = [];
    $tot = 0;
    foreach ($L as $kk => $vv) { $L[$kk] = array_values(array_filter((array)$vv, function ($x) use ($t0) { return $x > $t0 - 900; })); if (!$L[$kk]) unset($L[$kk]); else $tot += count($L[$kk]); }
    if ((isset($L[$ipf]) && count($L[$ipf]) >= 8) || $tot >= 40) out(['error' => 'too many'], 429);
    $A = @json_decode(@file_get_contents($DIR . '/auth.json'), true);
    $forTeam = isset($in0['for']) && $in0['for'] === 'planerka';   // пароль Планёрки (исполнители) или пароль клиента администратора
    $field = $forTeam ? 'team_pw' : 'pw';
    if (!is_array($A) || empty($A[$field])) out(['error' => 'no password'], 409);
    $pw = isset($in0['password']) ? (string)$in0['password'] : '';
    if ($pw !== '' && password_verify($pw, $A[$field])) out($forTeam ? ['team' => $keys['team']] : ['sync' => $keys['sync']]);
    $L[$ipf][] = $t0;
    @file_put_contents($limF, json_encode($L)); @chmod($limF, 0660);
    sleep(1);
    out(['error' => 'wrong'], 403);
}
$k = isset($_SERVER['HTTP_X_KEY']) ? (string)$_SERVER['HTTP_X_KEY'] : '';
$isTeam = defined('PLANERKA_TRUSTED') || ($k !== '' && hash_equals((string)$keys['team'], $k));
$isSync = defined('PLANERKA_TRUSTED_ADMIN') || ($k !== '' && !empty($keys['sync']) && hash_equals((string)$keys['sync'], $k));
function alog($dir, $line) {
    $f = $dir . '/access.log';
    if (@filesize($f) > 200000) @rename($f, $f . '.old');
    @file_put_contents($f, date('c') . ' ' . $line . ' ua=' . substr(preg_replace('/\s+/', ' ', isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 70) . "\n", FILE_APPEND);
}
if (!$isTeam && !$isSync) { alog($DIR, 'DENIED keylen=' . strlen($k)); out(['error' => 'auth'], 401); }

$in = json_decode(pl_input(), true);
if (!is_array($in)) $in = ['op' => 'list'];
$op = isset($in['op']) ? (string)$in['op'] : 'list';
$adminOps = ['sync', 'info', 'pull', 'pw.status', 'pw.set', 'hist', 'hist.del', 'remind'];
$bothOps = ['hist', 'hist.del'];   // история завершённых: клиент администратора (ключ sync) и Планёрка (ключ team)
if (!in_array($op, $bothOps, true)) {
    if (in_array($op, $adminOps, true) && !$isSync) out(['error' => 'auth'], 403);
    if (!in_array($op, $adminOps, true) && !$isTeam) out(['error' => 'auth'], 403);
}
if ($op === 'info') out(['team' => $keys['team']]);
if ($op === 'pw.status') {
    $A = @json_decode(@file_get_contents($DIR . '/auth.json'), true);
    out(['set' => is_array($A) && !empty($A['pw']), 'teamSet' => is_array($A) && !empty($A['team_pw'])]);
}
if ($op === 'pw.set') {
    $new = isset($in['password']) ? (string)$in['password'] : '';
    if (preg_match_all('/./u', $new) < 6) out(['error' => 'short'], 400);
    $A = @json_decode(@file_get_contents($DIR . '/auth.json'), true);
    if (!is_array($A)) $A = [];
    if (isset($in['for']) && $in['for'] === 'planerka') {
        // пароль Планёрки задаёт и меняет только владелец ключа sync (администратор); старый пароль не нужен
        $A['team_pw'] = password_hash($new, PASSWORD_DEFAULT); $A['team_at'] = date('c');
    } else {
        if (!empty($A['pw'])) {
            $old = isset($in['old']) ? (string)$in['old'] : '';
            if (!password_verify($old, $A['pw'])) out(['error' => 'old'], 403);
        }
        $A['pw'] = password_hash($new, PASSWORD_DEFAULT); $A['at'] = date('c');
    }
    $af = $DIR . '/auth.json'; $tmp = $af . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($A));
    @chmod($tmp, 0660); rename($tmp, $af);
    out(['ok' => true]);
}

// Присутствие: приложение исполнителя открыто / свёрнуто / закрыто. Отдельный файл, версию задач не трогает.
function app_build() { $b = @file_get_contents(__DIR__ . '/build.txt'); return $b ? trim($b) : ''; }
function pres_load($dir) { $p = @json_decode(@file_get_contents($dir . '/presence.json'), true); return is_array($p) ? $p : []; }
function ms_now() { return (int)(microtime(true) * 1000); }
if ($op === 'ping') {
    $q = @json_decode(@file_get_contents($DIR . '/tasks.json'), true);
    $ids = (is_array($q) && isset($q['people']) && is_array($q['people'])) ? people_ids($q['people']) : [];
    $by = (isset($in['by']) && is_string($in['by']) && isset($ids[$in['by']])) ? $in['by'] : null;
    $st = isset($in['s']) ? (string)$in['s'] : '';
    if (!$by || !in_array($st, ['open', 'bg', 'closed'], true)) out(['error' => 'bad'], 400);
    $pf = $DIR . '/presence.json';
    $lk = @fopen($pf . '.lock', 'c'); if ($lk) flock($lk, LOCK_EX);
    $p = pres_load($DIR);
    $pid = isset($in['pid']) ? substr(preg_replace('/[^a-z0-9]/', '', (string)$in['pid']), 0, 24) : '';
    $ct = isset($in['t']) ? (int)$in['t'] : 0;
    $old = isset($p[$by]) ? $p[$by] : null;
    if ($old && $pid !== '' && isset($old['pid']) && $old['pid'] === $pid && $ct > 0 && isset($old['t']) && $ct < $old['t']) out(['ok' => true, 'stale' => true]);
    $p[$by] = ['s' => $st, 'at' => ms_now(), 'pid' => $pid, 't' => $ct];
    $tmp = $pf . '.tmp' . getmypid(); file_put_contents($tmp, json_encode($p)); @chmod($tmp, 0660); rename($tmp, $pf);
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    out(['ok' => true]);
}
// Быстрый опрос: если версия данных совпадает с присланной since, отвечаем коротко и без блокировки
if (($op === 'list' || $op === 'pull') && isset($in['since'])) {
    $q = @json_decode(@file_get_contents($DIR . '/tasks.json'), true);
    if (is_array($q) && isset($q['rev']) && (int)$q['rev'] === (int)$in['since']) out(['rev' => (int)$q['rev'], 'same' => true, 'presence' => pres_load($DIR), 'now' => ms_now(), 'build' => app_build()]);
}

if (!(($op === 'list' || $op === 'pull') && isset($in['since']))) alog($DIR, ($isSync ? 'sync-key ' : 'team-key ') . $op . (isset($in['upsert']) ? ' up=' . count((array)$in['upsert']) : '') . (isset($in['remove']) ? ' rm=' . count((array)$in['remove']) : ''));

// Напоминание исполнителю о незавершённой задаче — для клиента администратора (только ключ sync).
// check=true — проверка: ТО ЖЕ уведомление приходит постановщику (owner), чтобы он увидел его своими глазами.
// Отправка синхронная: ответ говорит, сколько устройств приняли уведомление (честнее, чем «поставлено в очередь»).
// Блокировку задач здесь не берём: push-send.php сам ненадолго берёт её, чтобы прочитать подписки.
// Ответ всегда 200 с ok/why — это не ошибки сервера, а то, что надо показать человеку.
if ($op === 'remind') {
    $q = @json_decode(@file_get_contents($DIR . '/tasks.json'), true);
    if (!is_array($q) || !isset($q['tasks']) || !is_array($q['tasks'])) out(['ok' => false, 'why' => 'notask']);
    if (!isset($q['people']) || !is_array($q['people'])) $q['people'] = default_people();
    $owner = (isset($q['owner']) && is_string($q['owner'])) ? $q['owner'] : '';
    $id = id_ok(isset($in['id']) ? $in['id'] : null);
    $t = null;
    foreach ($q['tasks'] as $x) if ($id !== null && $x['id'] === $id) { $t = $x; break; }
    if (!$t || !empty($t['del']) || !empty($t['gone'])) out(['ok' => false, 'why' => 'notask']);
    if (!empty($t['done'])) out(['ok' => false, 'why' => 'done']);
    $who = who_ok(isset($t['assignee']) ? $t['assignee'] : null, $q);
    if (!$who) out(['ok' => false, 'why' => 'noassignee']);
    if (is_file($DIR . '/sandbox.flag')) out(['ok' => false, 'why' => 'sandbox']);   // песочница: напоминания не отправляются
    $check = !empty($in['check']);
    $to = $check ? $owner : $who;
    $store = @json_decode(@file_get_contents($DIR . '/push.json'), true);
    $dev = 0;
    if (is_array($store) && isset($store['subs']) && is_array($store['subs'])) foreach ($store['subs'] as $x) if (($x['person'] ?? '') === $to) $dev++;
    if (!$dev) out(['ok' => false, 'why' => 'nosub', 'to' => $to]);
    // защита от двойного нажатия и потока напоминаний: одно и то же — не чаще раза в минуту (проверка — раз в 10 с).
    // Учитываются только доставленные: если не дошло, повторить можно сразу.
    $win = $check ? 10000 : 60000;
    $rk = $t['id'] . '|' . $to . ($check ? '|check' : '');
    $rf = $DIR . '/remind.json';
    $now = ms_now();
    $rl = @fopen($rf . '.lock', 'c'); if ($rl) flock($rl, LOCK_EX);
    $R = @json_decode(@file_get_contents($rf), true); if (!is_array($R)) $R = [];
    foreach ($R as $kk => $vv) if ($vv < $now - 86400000) unset($R[$kk]);
    if (isset($R[$rk]) && $now - $R[$rk] < $win) {
        if ($rl) { flock($rl, LOCK_UN); fclose($rl); }
        out(['ok' => false, 'why' => 'pause', 'wait' => (int)ceil(($win - ($now - $R[$rk])) / 1000), 'to' => $to]);
    }
    $R[$rk] = $now;
    $tmp = $rf . '.tmp' . getmypid(); file_put_contents($tmp, json_encode($R)); @chmod($tmp, 0660); rename($tmp, $rf);
    // файл замка закрываем ДО запуска внешнего процесса: он наследует открытые файлы (грабли 09.10.2026)
    if ($rl) { flock($rl, LOCK_UN); fclose($rl); }
    $php = PL_PHP_CLI;
    $n = ['type' => 'remind', 'person' => $to, 'about' => $who, 'task' => $t['id'], 'title' => $t['t'], 'check' => $check];
    $lines = []; $rc = 0;
    @exec(escapeshellcmd($php) . ' ' . escapeshellarg(__DIR__ . '/push-send.php') . ' ' . escapeshellarg(base64_encode(json_encode($n, JSON_UNESCAPED_UNICODE))) . ' 2>/dev/null', $lines, $rc);
    $res = null;
    foreach ($lines as $l) if (strpos($l, 'RESULT ') === 0) $res = @json_decode(substr($l, 7), true);
    $sent = is_array($res) && isset($res['sent']) ? (int)$res['sent'] : 0;
    if ($sent < 1) {
        // не дошло — снимаем отметку, чтобы можно было сразу повторить
        $rl = @fopen($rf . '.lock', 'c'); if ($rl) flock($rl, LOCK_EX);
        $R = @json_decode(@file_get_contents($rf), true);
        if (is_array($R) && isset($R[$rk]) && $R[$rk] === $now) { unset($R[$rk]); $tmp = $rf . '.tmp' . getmypid(); file_put_contents($tmp, json_encode($R)); @chmod($tmp, 0660); rename($tmp, $rf); }
        if ($rl) { flock($rl, LOCK_UN); fclose($rl); }
    }
    alog($DIR, 'remind ' . ($check ? 'check ' : '') . $t['id'] . ' to=' . $to . ' devices=' . $dev . ' sent=' . $sent);
    out(['ok' => $sent > 0, 'why' => $sent > 0 ? null : 'fail', 'to' => $to, 'devices' => $dev, 'sent' => $sent, 'at' => $now]);
}

$lock = fopen($DIR . '/lock', 'c');
if (!$lock) out(['error' => 'storage'], 500);
flock($lock, LOCK_EX);

$file = $DIR . '/tasks.json';
$db = @json_decode(@file_get_contents($file), true);
if (!is_array($db) || !isset($db['tasks']) || !is_array($db['tasks'])) $db = ['rev' => 0, 'tasks' => []];
if (!isset($db['people']) || !is_array($db['people'])) $db['people'] = default_people();
// Журнал статистики: события «выполнено» (d) и «отказ» (x) пишутся при сохранении, сравнением с тем, что было до запроса.
// Считается с момента statsFrom; события удалённых задач (флаг xs) в статистику не входят.
if (!isset($db['ev']) || !is_array($db['ev'])) $db['ev'] = [];
function ledger_snap($db) {
    $r = [];
    foreach ($db['tasks'] as $t) $r[$t['id']] = ['done' => !empty($t['done']), 'nd' => (isset($t['declines']) && is_array($t['declines'])) ? count($t['declines']) : 0];
    return $r;
}
$ORIG = ledger_snap($db);

function find_by(&$db, $field, $val) {
    if ($val === null) return -1;
    foreach ($db['tasks'] as $i => $t) if (isset($t[$field]) && $t[$field] === $val) return $i;
    return -1;
}
function ledger(&$db) {
    global $ORIG;
    if (empty($db['statsFrom'])) $db['statsFrom'] = (int)(microtime(true) * 1000);
    foreach ($db['tasks'] as $t) {
        $id = $t['id'];
        $o = isset($ORIG[$id]) ? $ORIG[$id] : ['done' => false, 'nd' => 0];
        $done = !empty($t['done']);
        if ($done && !$o['done'] && !empty($t['assignee'])) $db['ev'][] = ['id' => $id, 'p' => $t['assignee'], 't' => 'd', 'at' => (int)(!empty($t['doneAt']) ? $t['doneAt'] : microtime(true) * 1000)];
        if (!$done && $o['done']) $db['ev'] = array_values(array_filter($db['ev'], function ($e) use ($id) { return !($e['id'] === $id && $e['t'] === 'd'); }));
        $dl = (isset($t['declines']) && is_array($t['declines'])) ? array_values($t['declines']) : [];
        for ($k = $o['nd']; $k < count($dl); $k++) if (!empty($dl[$k]['by']) && !empty($dl[$k]['at'])) $db['ev'][] = ['id' => $id, 'p' => $dl[$k]['by'], 't' => 'x', 'at' => (int)$dl[$k]['at']];
    }
    $ORIG = ledger_snap($db);
}
function save_db(&$db, $dir, $file) {
    $db['rev'] = (int)$db['rev'] + 1;
    ledger($db);
    // удалённые и снятые давно (30+ дней) убираем совсем; события удалённых уходят вместе с ними,
    // а у выполненных, убранных очисткой во внешнем клиенте, остаются в статистике
    $lim = time() - 30 * 86400;
    $drop = [];
    $db['tasks'] = array_values(array_filter($db['tasks'], function ($t) use ($lim, &$drop) {
        if (empty($t['del']) && empty($t['gone'])) return true;
        if (!isset($t['mt']) || $t['mt'] / 1000 > $lim) return true;
        if (!empty($t['xs'])) $drop[$t['id']] = 1;
        return false;
    }));
    $old = (time() - 3 * 366 * 86400) * 1000;
    $db['ev'] = array_values(array_filter($db['ev'], function ($e) use ($drop, $old) { return !isset($drop[$e['id']]) && $e['at'] > $old; }));
    $tmp = $file . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($db, JSON_UNESCAPED_UNICODE));
    @chmod($tmp, 0660);
    rename($tmp, $file);
    // копия за сутки, хранится 30 дней
    $bk = $dir . '/backups';
    if (!is_dir($bk)) @mkdir($bk, 0770, true);
    $f = $bk . '/tasks-' . date('Ymd') . '.json';
    if (!file_exists($f)) {
        @copy($file, $f);
        foreach ((array)glob($bk . '/tasks-*.json') as $old) if (filemtime($old) < time() - 30 * 86400) @unlink($old);
    }
}
function norm($t, $db) {
    $ids = people_ids($db['people']);
    // исполнитель удалён из списка — задача снова во Входящих
    if (!empty($t['assignee']) && !isset($ids[$t['assignee']])) $t['assignee'] = null;
    return $t;
}
function visible($db) {
    $r = [];
    $limit = time() - 60 * 86400;
    foreach ($db['tasks'] as $t) {
        if (!empty($t['del']) || !empty($t['gone'])) continue;
        if (!empty($t['done']) && !empty($t['doneAt']) && $t['doneAt'] / 1000 < $limit) continue;
        $t = norm($t, $db);
        unset($t['srcId'], $t['srcDone'], $t['mt']);
        $r[] = $t;
    }
    return $r;
}
function reply($db) { global $OWNER, $DIR; out(['rev' => (int)$db['rev'], 'tasks' => visible($db), 'people' => $db['people'], 'owner' => $OWNER, 'presence' => pres_load($DIR), 'now' => ms_now(), 'build' => app_build()]); }

// применить к задаче только присланные поля
// Исполнитель назначен/снят/сменён. Жизненный цикл: new (ждёт ответа) -> accepted; отказ возвращает задачу во «Входящие».
// Назначил сам себе (actor == исполнитель) — принято сразу.
function set_assignee(&$t, $new, $actor, $now) {
    $old = $t['assignee'] ?? null;
    if ($new === $old) return;
    $t['assignee'] = $new;
    if ($new === null) { $t['status'] = null; $t['assignedAt'] = null; $t['acceptedAt'] = null; return; }
    $t['assignedAt'] = $now;
    if ($actor !== null && $actor === $new) { $t['status'] = 'accepted'; $t['acceptedAt'] = $now; }
    else { $t['status'] = 'new'; $t['acceptedAt'] = null; }
}
function apply_fields(&$t, $f, $db, $now, $actor = null) {
    if (isset($f['t'])) { $v = cut($f['t'], 200); if ($v !== '') $t['t'] = $v; }
    if (array_key_exists('desc', $f)) $t['desc'] = cut($f['desc'], 2000);
    if (array_key_exists('phone', $f)) $t['phone'] = phone_ok($f['phone']);
    if (array_key_exists('items', $f)) $t['items'] = items_ok($f['items']);
    if (array_key_exists('due', $f)) $t['due'] = due_ok($f['due']);
    if (isset($f['pri'])) $t['pri'] = pri_ok($f['pri']);
    if (array_key_exists('assignee', $f)) set_assignee($t, who_ok($f['assignee'], $db), $actor, $now);
    // время выполнения меняется только при НАСТОЯЩЕЙ смене отметки (повторная отправка того же значения его не сдвигает)
    if (array_key_exists('done', $f) && (bool)$f['done'] !== !empty($t['done'])) { $t['done'] = (bool)$f['done']; $t['doneAt'] = $t['done'] ? $now : null; }
    $t['mt'] = $now;
}
function new_task($id, $title, $f, $db, $now, $actor = null) {
    $t = ['id' => $id, 't' => $title, 'desc' => '', 'due' => null, 'pri' => 4, 'done' => false, 'doneAt' => null,
          'phone' => '', 'items' => [], 'assignee' => null, 'status' => null, 'assignedAt' => null, 'acceptedAt' => null, 'declines' => [], 'src' => null, 'created' => $now];
    apply_fields($t, $f, $db, $now, $actor);
    $t['t'] = $title;
    return $t;
}

$changed = false;
$now = (int)(microtime(true) * 1000);
$notes = [];   // кому и о какой задаче отправить уведомление после сохранения
$OWNER = (isset($db['owner']) && is_string($db['owner'])) ? $db['owner'] : '';   // постановщик (php admin.php owner …): может удалять любые задачи, ответы исполнителей уведомляют его

// Уведомление исполнителю: внешний PHP (PL_PHP_CLI) в фоне — веб-PHP может быть без openssl и curl
function fire_push($notes, $dir) {
    if (!$notes) return;
    if (is_file($dir . '/sandbox.flag')) return;     // песочница (демо-дом): push в браузеры не уходят
    // Блокировку отпускаем ДО запуска фонового процесса: он наследует открытые файлы и иначе держал бы её вечно
    global $lock;
    if (isset($lock) && is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    $store = @json_decode(@file_get_contents($dir . '/push.json'), true);
    $has = [];
    if (is_array($store) && isset($store['subs'])) foreach ($store['subs'] as $x) $has[$x['person']] = 1;
    $php = PL_PHP_CLI;
    foreach ($notes as $n) {
        if (empty($has[$n['person']])) continue;
        $cmd = escapeshellcmd($php) . ' ' . escapeshellarg(__DIR__ . '/push-send.php') . ' ' . escapeshellarg(base64_encode(json_encode($n, JSON_UNESCAPED_UNICODE))) . ' >/dev/null 2>&1 &';
        @exec($cmd);
    }
}
function note_assign(&$notes, $before, $after, $task) {
    if ($after && $after !== $before && ($task['status'] ?? null) === 'new') $notes[] = ['type' => 'assign', 'person' => $after, 'task' => $task['id'], 'title' => $task['t']];
}

if ($op === 'push.key') {
    $v = @json_decode(@file_get_contents($DIR . '/vapid.json'), true);   // создаёт gen-vapid.php (или admin.php init)
    out(['key' => is_array($v) && isset($v['pub']) ? $v['pub'] : null]);
} elseif ($op === 'push.sub' || $op === 'push.unsub' || $op === 'push.test') {
    $pf = $DIR . '/push.json';
    $store = @json_decode(@file_get_contents($pf), true);
    if (!is_array($store) || !isset($store['subs']) || !is_array($store['subs'])) $store = ['subs' => []];
    $person = isset($in['person']) ? who_ok($in['person'], $db) : null;
    if ($op === 'push.test') {
        if (!$person) out(['error' => 'person'], 400);
        $n = 0; foreach ($store['subs'] as $x) if ($x['person'] === $person) $n++;
        fire_push([['person' => $person, 'task' => null, 'title' => '', 'test' => true]], $DIR);
        out(['ok' => true, 'devices' => $n]);
    }
    if ($op === 'push.unsub') {
        $ep = isset($in['endpoint']) ? (string)$in['endpoint'] : '';
        $store['subs'] = array_values(array_filter($store['subs'], function ($x) use ($ep) { return $x['endpoint'] !== $ep; }));
    } else {
        $sub = (isset($in['sub']) && is_array($in['sub'])) ? $in['sub'] : [];
        $ep = isset($sub['endpoint']) ? (string)$sub['endpoint'] : '';
        $kk = (isset($sub['keys']) && is_array($sub['keys'])) ? $sub['keys'] : [];
        $host = parse_url($ep, PHP_URL_HOST);
        $okHost = $host && preg_match('/(^|\.)(fcm\.googleapis\.com|push\.apple\.com|push\.services\.mozilla\.com|notify\.windows\.com)$/i', $host);
        if (!$person || strpos($ep, 'https://') !== 0 || !$okHost || empty($kk['p256dh']) || empty($kk['auth']) || strlen($ep) > 600) out(['error' => 'bad subscription'], 400);
        $store['subs'] = array_values(array_filter($store['subs'], function ($x) use ($ep) { return $x['endpoint'] !== $ep; }));
        $store['subs'][] = ['person' => $person, 'endpoint' => $ep, 'p256dh' => (string)$kk['p256dh'], 'auth' => (string)$kk['auth'],
                            'at' => date('c'), 'ua' => substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 80)];
        // не больше 5 устройств на человека: старые вытесняются
        $cnt = []; $keep = [];
        foreach (array_reverse($store['subs']) as $x) { $cnt[$x['person']] = ($cnt[$x['person']] ?? 0) + 1; if ($cnt[$x['person']] <= 5) $keep[] = $x; }
        $store['subs'] = array_reverse($keep);
    }
    $tmp = $pf . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($store));
    @chmod($tmp, 0660);
    rename($tmp, $pf);
    out(['ok' => true]);
} elseif ($op === 'stats') {
    // кто сколько выполнил и отклонил: «d» — выполнена (по исполнителю на момент выполнения), «x» — отказ
    // считаем только с момента запуска журнала (statsFrom); удалённые задачи не учитываются
    if (empty($db['statsFrom'])) save_db($db, $DIR, $file);
    $xs = [];
    foreach ($db['tasks'] as $t) if (!empty($t['xs'])) $xs[$t['id']] = 1;
    $ev = [];
    foreach ($db['ev'] as $e) if (!isset($xs[$e['id']]) && $e['at'] >= $db['statsFrom']) $ev[] = ['p' => $e['p'], 't' => $e['t'], 'at' => $e['at']];
    out(['events' => $ev, 'people' => $db['people'], 'from' => (int)$db['statsFrom']]);
} elseif ($op === 'resp') {
    $i = find_by($db, 'id', id_ok(isset($in['id']) ? $in['id'] : null));
    $by = isset($in['by']) ? who_ok($in['by'], $db) : null;
    $act = isset($in['act']) ? (string)$in['act'] : '';
    if ($i < 0 || !$by || ($act !== 'accept' && $act !== 'decline')) reply($db);
    $t = &$db['tasks'][$i];
    // отвечает только назначенный исполнитель, и только пока задача ждёт ответа
    if (($t['assignee'] ?? null) === $by && ($t['status'] ?? null) === 'new') {
        if ($act === 'accept') {
            $t['status'] = 'accepted'; $t['acceptedAt'] = $now; $t['mt'] = $now;
            $notes[] = ['type' => 'accept', 'person' => $OWNER, 'task' => $t['id'], 'title' => $t['t'], 'from' => $by];
        } else {
            $why = cut(isset($in['reason']) ? $in['reason'] : '', 300);
            if ($why === '') { unset($t); reply($db); }
            if (!isset($t['declines']) || !is_array($t['declines'])) $t['declines'] = [];
            $t['declines'][] = ['by' => $by, 'reason' => $why, 'at' => $now];
            $t['assignee'] = null; $t['status'] = null; $t['assignedAt'] = null; $t['acceptedAt'] = null; $t['mt'] = $now;
            $notes[] = ['type' => 'decline', 'person' => $OWNER, 'task' => $t['id'], 'title' => $t['t'], 'from' => $by, 'reason' => $why];
        }
        $changed = true;
    }
    unset($t);
} elseif ($op === 'add') {
    $id = id_ok(isset($in['id']) ? $in['id'] : null);
    $title = cut(isset($in['t']) ? $in['t'] : '', 200);
    if ($title === '') out(['error' => 'empty'], 400);
    // повтор того же запроса (связь оборвалась, приложение отправило ещё раз) дубля не создаёт
    if ($id && find_by($db, 'id', $id) >= 0) reply($db);
    if (!$id) $id = 'p' . substr(md5(uniqid('', true)), 0, 10);
    $by = (isset($in['by']) && is_string($in['by'])) ? $in['by'] : null;
    $nt = new_task($id, $title, $in, $db, $now, $by);   // src=null: задача рождена в Планёрке (внешний клиент заберёт её через pull)
    $nt['cby'] = $by !== null ? who_ok($by, $db) : null;   // кто поставил: он и постановщик (owner) могут удалить
    $db['tasks'][] = $nt;
    note_assign($notes, null, $nt['assignee'], $nt);
    $changed = true;
} elseif ($op === 'upd') {
    $i = find_by($db, 'id', id_ok(isset($in['id']) ? $in['id'] : null));
    $f = (isset($in['f']) && is_array($in['f'])) ? $in['f'] : [];
    if ($i < 0) reply($db);
    $t = &$db['tasks'][$i];
    $was = $t['assignee'] ?? null;
    $by = (isset($in['by']) && is_string($in['by'])) ? $in['by'] : null;
    // удалить (и вернуть удалённую) может постановщик (owner) или тот, кто поставил задачу
    if (isset($f['del']) && $by !== $OWNER && ($by === null || ($t['cby'] ?? null) !== $by)) { unset($t); reply($db); }
    apply_fields($t, $f, $db, $now, $by);
    if (isset($f['del'])) { $t['del'] = (bool)$f['del']; if ($t['del']) $t['xs'] = true; else unset($t['xs']); }
    if (empty($t['del'])) note_assign($notes, $was, $t['assignee'] ?? null, $t);
    unset($t);
    $changed = true;
} elseif ($op === 'hist' || $op === 'hist.del') {
    // История завершённых задач (видят все). Удаление убирает задачу из истории и из статистики (флаг xs);
    // «вернуть» (undo) — возвращает и туда, и туда.
    if ($op === 'hist.del') {
        // из Планёрки удалить может только постановщик (owner); с ключом sync — всегда (это администратор)
        if (!$isSync && (!isset($in['by']) || $in['by'] !== $OWNER)) out(['error' => 'auth'], 403);
        $i = find_by($db, 'id', id_ok(isset($in['id']) ? $in['id'] : null));
        if ($i < 0) out(['error' => 'not found'], 404);
        $t = &$db['tasks'][$i];
        if (!empty($in['undo'])) { $t['del'] = false; unset($t['xs']); }
        else { if (empty($t['done'])) { unset($t); out(['error' => 'not done'], 400); } $t['del'] = true; $t['xs'] = true; }
        $t['mt'] = $now;
        unset($t);
        save_db($db, $DIR, $file);
    }
    $h = [];
    foreach ($db['tasks'] as $t) {
        if (empty($t['done']) || !empty($t['xs']) || !empty($t['del'])) continue;
        $h[] = ['id' => $t['id'], 't' => $t['t'], 'desc' => isset($t['desc']) ? $t['desc'] : '', 'assignee' => isset($t['assignee']) ? $t['assignee'] : null,
                'doneAt' => isset($t['doneAt']) ? $t['doneAt'] : null, 'created' => isset($t['created']) ? $t['created'] : null, 'pri' => isset($t['pri']) ? $t['pri'] : 4];
    }
    usort($h, function ($a, $b) { return ((int)$b['doneAt']) - ((int)$a['doneAt']); });
    out(['ok' => true, 'tasks' => array_slice($h, 0, 1000), 'people' => $db['people']]);
} elseif ($op === 'pull') {
    // клиент администратора забирает всё, включая пометки del/gone: так он узнаёт об удалениях
    $r = []; $limit = time() - 60 * 86400;
    foreach ($db['tasks'] as $t) {
        if (empty($t['del']) && empty($t['gone']) && !empty($t['done']) && !empty($t['doneAt']) && $t['doneAt'] / 1000 < $limit) continue;
        $t = norm($t, $db);
        unset($t['srcDone'], $t['mt']);
        $r[] = $t;
    }
    out(['rev' => (int)$db['rev'], 'tasks' => $r, 'people' => $db['people'], 'presence' => pres_load($DIR), 'now' => ms_now(), 'build' => app_build()]);
} elseif ($op === 'sync') {
    // 1) список исполнителей целиком (если клиент администратора его прислал)
    if (isset($in['people']) && is_array($in['people'])) {
        $pp = []; $seen = [];
        foreach (array_slice($in['people'], 0, 20) as $p) {
            if (!is_array($p)) continue;
            $sid = slug_ok(isset($p['id']) ? $p['id'] : null);
            $nm = cut(isset($p['name']) ? $p['name'] : '', 40);
            if (!$sid || $nm === '' || isset($seen[$sid])) continue;
            $seen[$sid] = 1; $pp[] = ['id' => $sid, 'name' => $nm];
        }
        $db['people'] = $pp;
        $changed = true;
    }
    // 2) изменения задач: upsert {id (у клиента), sid (в Планёрке или null), f (поля)}, remove [{id,sid}]
    $map = [];
    $up = (isset($in['upsert']) && is_array($in['upsert'])) ? $in['upsert'] : [];
    $rm = (isset($in['remove']) && is_array($in['remove'])) ? $in['remove'] : [];
    foreach (array_slice($up, 0, 500) as $u) {
        if (!is_array($u)) continue;
        $src = id_ok(isset($u['id']) ? $u['id'] : null);
        $sid = id_ok(isset($u['sid']) ? $u['sid'] : null);
        $f = (isset($u['f']) && is_array($u['f'])) ? $u['f'] : [];
        if (!$src) continue;
        $i = $sid ? find_by($db, 'id', $sid) : -1;
        if ($i < 0) $i = find_by($db, 'srcId', $src);
        if ($i < 0) {
            $title = cut(isset($f['t']) ? $f['t'] : '', 200);
            if ($title === '') continue;
            $nid = 'p' . substr(md5($src . uniqid('', true)), 0, 10);
            $t = new_task($nid, $title, $f, $db, $now, $OWNER);
            $t['src'] = 'todos'; $t['srcId'] = $src; $t['cby'] = $OWNER;
            $db['tasks'][] = $t;
            note_assign($notes, null, $t['assignee'], $t);
            $map[$src] = $nid;
        } else {
            $t = &$db['tasks'][$i];
            $was = $t['assignee'] ?? null;
            apply_fields($t, $f, $db, $now, $OWNER);
            if (array_key_exists('assignee', $f)) note_assign($notes, $was, $t['assignee'] ?? null, $t);
            if (empty($t['src']) && $src) { /* задача, рождённая в Планёрке, получает id у клиента */ $t['srcId'] = $src; }
            unset($t['gone'], $t['xs']);
            $map[$src] = $t['id'];
            unset($t);
        }
        $changed = true;
    }
    foreach (array_slice($rm, 0, 500) as $r) {
        $src = id_ok(is_array($r) ? (isset($r['id']) ? $r['id'] : null) : $r);
        $sid = is_array($r) ? id_ok(isset($r['sid']) ? $r['sid'] : null) : null;
        $i = $sid ? find_by($db, 'id', $sid) : -1;
        if ($i < 0) $i = find_by($db, 'srcId', $src);
        if ($i >= 0) {
            if (($db['tasks'][$i]['src'] ?? null) === 'todos') $db['tasks'][$i]['gone'] = true;
            else $db['tasks'][$i]['del'] = true;
            // удаление невыполненной задачи убирает её из статистики; очистка выполненных у клиента статистику не трогает
            if (empty($db['tasks'][$i]['done'])) $db['tasks'][$i]['xs'] = true;
            $db['tasks'][$i]['mt'] = $now;
            $changed = true;
        }
    }
    if ($changed) save_db($db, $DIR, $file);
    fire_push($notes, $DIR);
    out(['ok' => true, 'map' => $map]);
} elseif ($op !== 'list') {
    out(['error' => 'bad op'], 400);
}

if ($changed) save_db($db, $DIR, $file);
fire_push($notes, $DIR);
reply($db);
