<?php
// Web Push без сторонних библиотек: VAPID (RFC 8292) + шифрование полезной нагрузки aes128gcm (RFC 8291).
// Нужен PHP 7.3+ с openssl и curl. Веб-PHP может быть без них — отправляет внешний PHP (push-send.php, PL_PHP_CLI).

function wp_b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function wp_b64u_dec($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

// сырая точка 65 байт -> PEM открытого ключа P-256
function wp_pub_pem($raw65) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
// скаляр d + точка -> PEM закрытого ключа P-256
function wp_priv_pem($d32, $pub65) {
    $der = hex2bin('30770201010420') . $d32 . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub65;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}
// новая пара ключей: [ресурс ключа, открытая точка 65 байт, скаляр d]
function wp_ec_new() {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($k);
    $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return [$k, $pub, str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT)];
}

// Шифрование по RFC 8291. Возвращает тело запроса (salt | rs | idlen | keyid | шифртекст).
// asKey/asPub/salt задаются только в тестах (вектор из RFC).
function wp_encrypt($payload, $uaPub65, $authSecret, $asKey = null, $asPub = null, $salt = null) {
    if (!$asKey) { list($asKey, $asPub) = wp_ec_new(); }
    if ($salt === null) $salt = random_bytes(16);
    $uaKey = openssl_pkey_get_public(wp_pub_pem($uaPub65));
    $secret = openssl_pkey_derive($uaKey, $asKey, 32);
    if ($secret === false) return null;
    $keyInfo = "WebPush: info\0" . $uaPub65 . $asPub;
    $ikm = hash_hkdf('sha256', $secret, 32, $keyInfo, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = $payload . "\x02"; // последняя (единственная) запись
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) return null;
    return $salt . pack('N', 4096) . chr(strlen($asPub)) . $asPub . $ct . $tag;
}
// обратная операция (для самопроверки в тестах): получатель с закрытым ключом ua
function wp_decrypt($body, $uaKey, $uaPub65, $authSecret) {
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPub = substr($body, 21, $idlen);
    $rest = substr($body, 21 + $idlen);
    $ct = substr($rest, 0, -16); $tag = substr($rest, -16);
    $asKey = openssl_pkey_get_public(wp_pub_pem($asPub));
    $secret = openssl_pkey_derive($asKey, $uaKey, 32);
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPub65 . $asPub, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt($ct, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $plain === false ? null : rtrim($plain, "\x02");
}

// подпись ES256: DER -> сырые r|s (64 байта)
function wp_der_to_raw($der) {
    $o = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7f) : 2;
    $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl);
    $o2 = $o + 2 + $rl;
    $sl = ord($der[$o2 + 1]); $s = substr($der, $o2 + 2, $sl);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return $r . $s;
}
function wp_vapid_jwt($audience, $subject, $privPem, $ttl = 43200) {
    $h = wp_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = wp_b64u(json_encode(['aud' => $audience, 'exp' => time() + $ttl, 'sub' => $subject]));
    $der = '';
    openssl_sign($h . '.' . $c, $der, openssl_pkey_get_private($privPem), OPENSSL_ALGO_SHA256);
    return $h . '.' . $c . '.' . wp_b64u(wp_der_to_raw($der));
}

// Отправка одной подписки. $sub: endpoint, p256dh (b64url), auth (b64url). $vapid: pem, pub (b64url), subject.
// Возвращает [код HTTP, текст ошибки]. 404/410 означают, что подписка мертва.
function wp_send($sub, $payloadJson, $vapid) {
    $u = parse_url($sub['endpoint']);
    if (empty($u['scheme']) || $u['scheme'] !== 'https' || empty($u['host'])) return [0, 'bad endpoint'];
    $aud = 'https://' . $u['host'];
    $body = wp_encrypt($payloadJson, wp_b64u_dec($sub['p256dh']), wp_b64u_dec($sub['auth']));
    if ($body === null) return [0, 'encrypt failed'];
    $jwt = wp_vapid_jwt($aud, $vapid['subject'], $vapid['pem']);
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapid['pub'],
            'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream',
            'TTL: 86400', 'Urgency: high',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $err !== '' ? $err : substr((string)$resp, 0, 160)];
}
