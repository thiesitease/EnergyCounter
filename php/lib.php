<?php
/**
 * Zählerbuch SB85 – gemeinsame Funktionen für den Betrieb auf einem Webhosting-Paket.
 *
 * Bewusst ohne Composer, ohne Framework und ohne Datenbank: Es läuft alles innerhalb
 * eines normalen PHP-Requests, es wird kein Prozess gestartet und kein Port geöffnet.
 * Daten liegen als JSON-Dateien im Datenverzeichnis.
 *
 * Benötigt: PHP 7.4+, Erweiterungen json + zlib. cURL wird genutzt, wenn vorhanden,
 * sonst greift ein Fallback über Stream-Wrapper (allow_url_fopen).
 */

const ZB_TZ = 'Europe/Berlin';
const ZB_DAY_MS = 86400000;
const ZB_API = 'https://api.anthropic.com/v1/messages';

date_default_timezone_set(ZB_TZ);
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

/** Kürzen, auch wenn mbstring auf dem Host fehlt. */
function zb_cut($s, $len)
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/* ------------------------------------------------------------------ Konfiguration */

function zb_cfg($key, $default = null)
{
    static $conf = null;
    if ($conf === null) {
        $conf = [];
        $file = __DIR__ . '/config.php';
        if (is_file($file)) {
            $loaded = include $file;
            if (is_array($loaded)) {
                $conf = $loaded;
            }
        }
        $envMap = [
            'ANTHROPIC_API_KEY' => 'api_key',
            'ZB_PASSWORD'       => 'password',
            'ZB_MODEL'          => 'model',
            'ZB_EFFORT'         => 'effort',
            'ZB_DATA'           => 'data_dir',
        ];
        foreach ($envMap as $env => $key2) {
            $val = getenv($env);
            if ($val !== false && $val !== '') {
                $conf[$key2] = $val;
            }
        }
    }
    if (array_key_exists($key, $conf) && $conf[$key] !== '' && $conf[$key] !== null) {
        return $conf[$key];
    }
    return $default;
}

function zb_data_dir()
{
    $dir = zb_cfg('data_dir', __DIR__ . '/data');
    return rtrim(str_replace('\\', '/', $dir), '/');
}

function zb_model()
{
    return (string) zb_cfg('model', 'claude-opus-5');
}

function zb_effort()
{
    $e = strtolower((string) zb_cfg('effort', 'low'));
    return in_array($e, ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $e : 'low';
}

function zb_has_key()
{
    return zb_cfg('api_key', '') !== '';
}

/* ------------------------------------------------------------------ Speicher */

function zb_path($name)
{
    return zb_data_dir() . '/' . $name;
}

function zb_load($name, $default)
{
    $p = zb_path($name);
    if (!is_file($p)) {
        return $default;
    }
    $raw = @file_get_contents($p);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function zb_save($name, $data)
{
    $dir = zb_data_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $target = zb_path($name);
    $tmp    = $target . '.tmp' . getmypid();
    $json   = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function zb_readings()
{
    $rs = zb_load('readings.json', []);
    usort($rs, function ($a, $b) {
        $x = isset($a['t']) ? $a['t'] : 0;
        $y = isset($b['t']) ? $b['t'] : 0;
        return $x < $y ? -1 : ($x > $y ? 1 : 0);
    });
    return $rs;
}

function zb_config()
{
    return zb_load('config.json', []);
}

function zb_meters()
{
    $c = zb_config();
    return isset($c['meters']) && is_array($c['meters']) ? $c['meters'] : [];
}

function zb_location()
{
    $c = zb_config();
    if (isset($c['location']) && is_array($c['location'])
        && isset($c['location']['lat'], $c['location']['lon'])) {
        return $c['location'];
    }
    return null;
}

function zb_writable()
{
    $dir = zb_data_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return is_dir($dir) && is_writable($dir);
}

/* ------------------------------------------------------------------ Fotoarchiv */

function zb_photo_root()
{
    return zb_data_dir() . '/photos';
}

function zb_valid_id($id)
{
    return (bool) preg_match('/^[A-Za-z0-9_\-]{1,64}$/', (string) $id);
}

function zb_valid_photo($name)
{
    return (bool) preg_match('/^[A-Za-z0-9_\-]{1,48}\.jpg$/', (string) $name);
}

/** Speichert ein Foto zu einer Ablesung und gibt den Dateinamen zurück. */
function zb_save_photo($id, $meterKey, $binary)
{
    if (!zb_valid_id($id) || !preg_match('/^[A-Za-z0-9_\-]{1,32}$/', (string) $meterKey)) {
        return null;
    }
    $dir = zb_photo_root() . '/' . $id;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    for ($n = 1; $n <= 99; $n++) {
        $name = $meterKey . '-' . $n . '.jpg';
        $path = $dir . '/' . $name;
        if (!file_exists($path)) {
            return @file_put_contents($path, $binary) === false ? null : $name;
        }
    }
    return null;
}

function zb_photo_path($id, $name)
{
    if (!zb_valid_id($id) || !zb_valid_photo($name)) {
        return null;
    }
    $path = zb_photo_root() . '/' . $id . '/' . $name;
    return is_file($path) ? $path : null;
}

function zb_delete_photo($id, $name)
{
    $p = zb_photo_path($id, $name);
    return $p ? @unlink($p) : false;
}

/** Alle Fotos einer Ablesung entfernen (beim Löschen der Ablesung). */
function zb_delete_photos($id)
{
    if (!zb_valid_id($id)) {
        return;
    }
    $dir = zb_photo_root() . '/' . $id;
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array) glob($dir . '/*.jpg') as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

/** Belegter Platz des Fotoarchivs in Bytes, für die Statusanzeige. */
function zb_photo_usage()
{
    $bytes = 0;
    $count = 0;
    foreach ((array) glob(zb_photo_root() . '/*/*.jpg') as $f) {
        $bytes += (int) @filesize($f);
        $count++;
    }
    return ['bytes' => $bytes, 'count' => $count];
}

/* ------------------------------------------------------------------ Anmeldung */

function zb_password()
{
    return (string) zb_cfg('password', '');
}

/** Zweites Passwort, das nur zum Ansehen berechtigt. Leer = kein Lesezugang. */
function zb_password_view()
{
    return (string) zb_cfg('password_readonly', '');
}

/** Cookie-Wert je Rolle. Die Ableitung für "edit" bleibt unverändert,
 *  damit bestehende Anmeldungen weiter gelten. */
function zb_token($role = 'edit')
{
    return $role === 'view'
        ? hash_hmac('sha256', 'zb-auth-v1-view', zb_password_view())
        : hash_hmac('sha256', 'zb-auth-v1', zb_password());
}

function zb_request_header($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }
    if (isset($_SERVER['REDIRECT_' . $key])) {
        return (string) $_SERVER['REDIRECT_' . $key];
    }
    return '';
}

/**
 * Rolle des Aufrufers: "edit" (darf alles), "view" (darf nur lesen) oder null.
 * Das Bearbeiten-Passwort hat Vorrang, falls beide gleich gesetzt sind.
 */
function zb_role()
{
    $edit = zb_password();
    $view = zb_password_view();
    if ($edit === '') {
        return null; // ohne gesetztes Passwort bleibt die API zu
    }
    $cookie = isset($_COOKIE['zb_auth']) ? (string) $_COOKIE['zb_auth'] : '';
    $header = zb_request_header('X-ZB-Auth');
    if ($header === '') {
        $auth = zb_request_header('Authorization');
        if (stripos($auth, 'bearer ') === 0) {
            $header = trim(substr($auth, 7));
        }
    }
    if ($cookie !== '' && hash_equals(zb_token('edit'), $cookie)) {
        return 'edit';
    }
    if ($header !== '' && hash_equals($edit, $header)) {
        return 'edit';
    }
    if ($view !== '') {
        if ($cookie !== '' && hash_equals(zb_token('view'), $cookie)) {
            return 'view';
        }
        if ($header !== '' && hash_equals($view, $header)) {
            return 'view';
        }
    }
    return null;
}

function zb_authed()
{
    return zb_role() !== null;
}

function zb_can_edit()
{
    return zb_role() === 'edit';
}

function zb_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower(zb_request_header('X-Forwarded-Proto')) === 'https') {
        return true;
    }
    return false;
}

function zb_set_auth_cookie($role = 'edit')
{
    $opts = [
        'expires'  => time() + 365 * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => zb_is_https(),
    ];
    setcookie('zb_auth', zb_token($role), $opts);
}

/* ------------------------------------------------------------------ HTTP-Client */

function zb_http($method, $url, $headers = [], $body = null, $timeout = 60)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'zaehlerbuch/1.0',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            return [0, '', $err !== '' ? $err : 'Verbindung fehlgeschlagen'];
        }
        return [$status, $out, ''];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", array_merge($headers, ['User-Agent: zaehlerbuch/1.0'])),
        'content'       => $body,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) {
        return [0, '', 'Verbindung fehlgeschlagen (allow_url_fopen/cURL prüfen)'];
    }
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    return [$status, $out, ''];
}

function zb_get_json($url, $timeout = 45)
{
    list($status, $body, $err) = zb_http('GET', $url, ['Accept: application/json'], null, $timeout);
    if ($err !== '') {
        return [null, $err];
    }
    if ($status < 200 || $status >= 300) {
        return [null, 'HTTP ' . $status];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [null, 'Antwort nicht lesbar'];
    }
    return [$data, ''];
}

/* ------------------------------------------------------------------ Wetter (Open-Meteo) */

function zb_weather()
{
    $w = zb_load('weather.json', []);
    if (!isset($w['daily']) || !is_array($w['daily'])) {
        $w['daily'] = [];
    }
    return $w;
}

function zb_weather_public()
{
    $w   = zb_weather();
    $out = [];
    foreach ($w['daily'] as $day => $v) {
        $out[$day] = is_array($v) ? (isset($v['mean']) ? $v['mean'] : null) : $v;
    }
    $loc = zb_location();
    return [
        'daily'   => $out,
        'updated' => isset($w['updated']) ? $w['updated'] : null,
        'name'    => isset($w['name']) ? $w['name'] : ($loc && isset($loc['name']) ? $loc['name'] : ''),
        'stale'   => zb_weather_stale(),
        'cursor'  => isset($w['cursor']) ? $w['cursor'] : null,
    ];
}

function zb_weather_stale()
{
    $loc = zb_location();
    if (!$loc) {
        return false;
    }
    $w = zb_weather();
    if (!isset($w['lat']) || abs((float) $w['lat'] - (float) $loc['lat']) > 0.0001
        || abs((float) $w['lon'] - (float) $loc['lon']) > 0.0001) {
        return true;
    }
    if (!empty($w['cursor'])) {
        return true;
    }
    return (time() - (int) (isset($w['updated']) ? $w['updated'] : 0)) > 6 * 3600;
}

function zb_fetch_daily($base, $lat, $lon, $params)
{
    $q = array_merge([
        'latitude'  => $lat,
        'longitude' => $lon,
        'daily'     => 'temperature_2m_mean,temperature_2m_min,temperature_2m_max',
        'timezone'  => ZB_TZ,
    ], $params);
    list($data, $err) = zb_get_json($base . '?' . http_build_query($q));
    if ($err !== '') {
        return [null, $err];
    }
    $d   = isset($data['daily']) ? $data['daily'] : [];
    $out = [];
    if (!empty($d['time'])) {
        foreach ($d['time'] as $i => $day) {
            $mean = isset($d['temperature_2m_mean'][$i]) ? $d['temperature_2m_mean'][$i] : null;
            if ($mean === null) {
                continue;
            }
            $out[$day] = [
                'mean' => $mean,
                'min'  => isset($d['temperature_2m_min'][$i]) ? $d['temperature_2m_min'][$i] : null,
                'max'  => isset($d['temperature_2m_max'][$i]) ? $d['temperature_2m_max'][$i] : null,
            ];
        }
    }
    return [$out, ''];
}

/**
 * Holt genau EINEN Block Wetterdaten und kehrt sofort zurück. Der Browser ruft die
 * Funktion so lange auf, bis 'done' true ist – damit bleibt jeder Request kurz und
 * es läuft nichts im Hintergrund.
 */
function zb_weather_step()
{
    $loc = zb_location();
    if (!$loc) {
        return ['done' => true, 'message' => 'Kein Standort hinterlegt'];
    }
    $w    = zb_weather();
    $same = isset($w['lat'])
        && abs((float) $w['lat'] - (float) $loc['lat']) < 0.0001
        && abs((float) $w['lon'] - (float) $loc['lon']) < 0.0001;
    if (!$same) {
        $w = ['lat' => $loc['lat'], 'lon' => $loc['lon'], 'daily' => [], 'cursor' => null, 'updated' => 0];
    }
    $w['name'] = isset($loc['name']) ? $loc['name'] : '';

    $today      = date('Y-m-d');
    $archiveEnd = date('Y-m-d', time() - 7 * 86400);

    $cursor = isset($w['cursor']) ? $w['cursor'] : null;
    if (!$cursor) {
        $rs    = zb_readings();
        $first = $rs ? date('Y-m-d', (int) ($rs[0]['t'] / 1000)) : date('Y-m-d', time() - 400 * 86400);
        if (!empty($w['daily'])) {
            $have   = array_keys($w['daily']);
            sort($have);
            $lastHave = end($have);
            $resume   = date('Y-m-d', strtotime($lastHave) - 10 * 86400);
            $cursor   = $resume > $first ? $resume : $first;
        } else {
            $cursor = $first;
        }
    }

    if ($cursor <= $archiveEnd) {
        $end = date('Y-m-d', strtotime($cursor) + 5 * 365 * 86400);
        if ($end > $archiveEnd) {
            $end = $archiveEnd;
        }
        list($chunk, $err) = zb_fetch_daily('https://archive-api.open-meteo.com/v1/archive',
            $loc['lat'], $loc['lon'], ['start_date' => $cursor, 'end_date' => $end]);
        if ($err !== '') {
            return ['done' => true, 'error' => $err];
        }
        $w['daily'] = array_merge($w['daily'], $chunk);
        ksort($w['daily']);
        $w['cursor'] = date('Y-m-d', strtotime($end) + 86400);
        zb_save('weather.json', $w);
        return ['done' => false, 'days' => count($w['daily']), 'progress' => $cursor . ' – ' . $end];
    }

    list($recent, $err) = zb_fetch_daily('https://api.open-meteo.com/v1/forecast',
        $loc['lat'], $loc['lon'], ['past_days' => 14, 'forecast_days' => 1]);
    if ($err !== '') {
        return ['done' => true, 'error' => $err];
    }
    foreach ($recent as $day => $v) {
        if ($day <= $today) {
            $w['daily'][$day] = $v;
        }
    }
    ksort($w['daily']);
    $w['cursor']  = null;
    $w['updated'] = time();
    zb_save('weather.json', $w);
    return ['done' => true, 'days' => count($w['daily'])];
}

/* ------------------------------------------------------------------ Foto-Erkennung */

function zb_recognize($imageB64, $mediaType, $prompt)
{
    if (!zb_has_key()) {
        return ['error' => 'no_api_key', 'message' => 'Auf dem Server ist kein API-Schlüssel hinterlegt (config.php).'];
    }
    $schema = [
        'type'       => 'object',
        'properties' => [
            'meter'      => ['type' => 'string', 'description' => 'key of the known meter, or "unknown"'],
            'id_text'    => ['type' => 'string', 'description' => 'serial number as printed, or empty'],
            // Hinweis: Im Schema der Anthropic-API sind bei "number" keine
            // minimum/maximum-Angaben erlaubt. Die Grenzen stehen deshalb im Text,
            // und die Seite begrenzt den Wert zusätzlich auf 0 bis 1.
            'reading'    => ['type' => ['number', 'null'], 'description' => 'counter value as a decimal number in the meter unit, null if unreadable'],
            'confidence' => ['type' => 'number', 'description' => 'how certain the reading is, between 0 and 1'],
            'comment'    => ['type' => 'string', 'description' => 'short German note, or empty'],
        ],
        'required'             => ['meter', 'id_text', 'reading', 'confidence', 'comment'],
        'additionalProperties' => false,
    ];
    $payload = [
        'model'      => zb_model(),
        'max_tokens' => 4000,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $imageB64]],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]],
        'output_config' => ['effort' => zb_effort(), 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        'fallbacks'     => 'default',
    ];
    $headers = [
        'content-type: application/json',
        'x-api-key: ' . zb_cfg('api_key'),
        'anthropic-version: 2023-06-01',
        'anthropic-beta: server-side-fallback-2026-07-01',
    ];

    // Etwas unter dem PHP-Zeitlimit bleiben, damit noch eine saubere Fehlermeldung
    // zurückkommt, statt dass der Aufruf mittendrin abgeschnitten wird.
    $budget = (int) ini_get('max_execution_time');
    $timeout = $budget > 0 ? max(20, $budget - 10) : 110;

    list($status, $body, $err) = zb_http('POST', ZB_API, $headers, json_encode($payload), $timeout);

    // Kennt der Endpunkt die Fallback-Beta nicht, ohne sie erneut versuchen.
    if ($status === 400 && strpos((string) $body, 'fallback') !== false) {
        unset($payload['fallbacks']);
        array_pop($headers);
        list($status, $body, $err) = zb_http('POST', ZB_API, $headers, json_encode($payload), $timeout);
    }
    if ($err !== '') {
        if (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) {
            return ['error' => 'timeout', 'message' => 'Die Erkennung hat länger gedauert, als der Webserver zulässt ('
                . $timeout . ' s). Foto noch einmal senden oder den Stand von Hand eintragen.'];
        }
        return ['error' => 'network', 'message' => 'Keine Verbindung zur Anthropic-API: ' . $err];
    }
    $data = json_decode($body, true);
    if ($status === 401 || $status === 403) {
        return ['error' => 'auth', 'message' => 'API-Schlüssel ungültig oder ohne Berechtigung.'];
    }
    if ($status === 429) {
        return ['error' => 'rate_limited', 'message' => 'Zu viele Anfragen, bitte kurz warten.'];
    }
    if ($status < 200 || $status >= 300) {
        $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $status);
        return ['error' => 'api', 'message' => 'API-Fehler: ' . $msg];
    }
    if (isset($data['stop_reason']) && $data['stop_reason'] === 'refusal') {
        return ['error' => 'refused', 'message' => 'Claude hat dieses Bild abgelehnt.'];
    }
    $text = '';
    foreach ((isset($data['content']) ? $data['content'] : []) as $block) {
        if (isset($block['type'], $block['text']) && $block['type'] === 'text') {
            $text = $block['text'];
            break;
        }
    }
    $result = json_decode($text, true);
    if (!is_array($result)) {
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $result = json_decode($m[0], true);
        }
    }
    if (!is_array($result)) {
        return ['error' => 'invalid_json', 'message' => 'Antwort nicht lesbar.', 'text' => zb_cut($text, 400)];
    }
    $result['usage'] = [
        'input_tokens'  => isset($data['usage']['input_tokens']) ? $data['usage']['input_tokens'] : null,
        'output_tokens' => isset($data['usage']['output_tokens']) ? $data['usage']['output_tokens'] : null,
        'model'         => isset($data['model']) ? $data['model'] : zb_model(),
    ];
    return $result;
}

/* ------------------------------------------------------------------ Auswertung (für den Export) */

function zb_factor($m)
{
    if (empty($m['factors'])) {
        return 1.0;
    }
    $zz = isset($m['factors']['zz']) ? (float) $m['factors']['zz'] : 1.0;
    $bw = isset($m['factors']['bw']) ? (float) $m['factors']['bw'] : 1.0;
    return ($zz ?: 1.0) * ($bw ?: 1.0);
}

function zb_out_unit($m)
{
    if (!empty($m['outUnit'])) {
        return $m['outUnit'];
    }
    return isset($m['unit']) ? $m['unit'] : '';
}

function zb_price_segments($m)
{
    $segs = [];
    foreach ((isset($m['prices']) ? $m['prices'] : []) as $p) {
        if (empty($p['from'])) {
            continue;
        }
        $t = strtotime($p['from'] . ' 00:00:00');
        if ($t === false) {
            continue;
        }
        $segs[] = [$t * 1000.0, isset($p['price']) ? (float) $p['price'] : 0.0];
    }
    usort($segs, function ($a, $b) { return $a[0] <=> $b[0]; });
    return $segs;
}

function zb_cost_between($m, $a, $b, $amount)
{
    $segs = zb_price_segments($m);
    if (!$segs || $b <= $a) {
        return 0.0;
    }
    $cost = 0.0;
    $n    = count($segs);
    for ($i = 0; $i < $n; $i++) {
        $s = max($a, $segs[$i][0]);
        $e = $i + 1 < $n ? min($b, $segs[$i + 1][0]) : $b;
        if ($e > $s) {
            $cost += $amount * ($e - $s) / ($b - $a) * $segs[$i][1];
        }
    }
    return $cost;
}

function zb_series($readings, $m)
{
    $key   = $m['key'];
    $f     = zb_factor($m);
    $pts   = [];
    $addon = 0.0;
    $prev  = null;
    foreach ($readings as $r) {
        if (!isset($r['v'][$key]) || !is_numeric($r['v'][$key])) {
            continue;
        }
        $raw = (float) $r['v'][$key];
        $chg = !empty($r['chg'][$key]);
        if ($chg && $prev !== null) {
            $addon = $prev;
        }
        $cumRaw = $raw + $addon;
        $pts[]  = [
            't'    => $r['t'],
            'raw'  => $raw,
            'cum'  => $cumRaw * $f,
            'chg'  => $chg,
            'note' => isset($r['n'][$key]) ? $r['n'][$key] : '',
        ];
        $prev = $cumRaw;
    }
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        $days              = ($pts[$i]['t'] - $pts[$i - 1]['t']) / ZB_DAY_MS;
        $pts[$i]['days']   = $days;
        $pts[$i]['amount'] = $pts[$i]['cum'] - $pts[$i - 1]['cum'];
        $pts[$i]['rate']   = $days > 0.02 ? $pts[$i]['amount'] / $days : null;
        $pts[$i]['cost']   = zb_cost_between($m, $pts[$i - 1]['t'], $pts[$i]['t'], $pts[$i]['amount']);
    }
    return $pts;
}

function zb_tolerance($m, $days)
{
    return (isset($m['tolerance']) ? (float) $m['tolerance'] : 0)
        + (isset($m['tolPerDay']) ? (float) $m['tolPerDay'] : 0) * $days;
}

/**
 * Abgleich eines Kontrollzählers mit seinem Hauptzähler: Verbrauch je Abschnitt
 * auf beiden Zählern, nur aus Ablesungen, in denen beide Werte stehen.
 */
function zb_control_rows($readings, $c, $m)
{
    $out  = [];
    $prev = null;
    foreach ($readings as $r) {
        $vc = isset($r['v'][$c['key']]) ? $r['v'][$c['key']] : null;
        $vm = isset($r['v'][$m['key']]) ? $r['v'][$m['key']] : null;
        if (!is_numeric($vc) || !is_numeric($vm)) {
            continue;
        }
        $chg = !empty($r['chg'][$c['key']]) || !empty($r['chg'][$m['key']]);
        if ($prev !== null && !$chg) {
            $days = ($r['t'] - $prev['t']) / ZB_DAY_MS;
            if ($days > 0.02) {
                $dc    = ($vc - $prev['vc']) * zb_factor($c);
                $dm    = ($vm - $prev['vm']) * zb_factor($m);
                $out[] = ['t0' => $prev['t'], 't1' => $r['t'], 'days' => $days, 'dc' => $dc, 'dm' => $dm,
                          'diff' => $dc - $dm, 'tol' => zb_tolerance($c, $days)];
            }
        }
        $prev = ['t' => $r['t'], 'vc' => $vc, 'vm' => $vm];
    }
    return $out;
}

/** Mittlere Außentemperatur und Gradtagzahl (20/15) für einen Zeitraum in ms. */
function zb_temp_stats($daily, $aMs, $bMs)
{
    if (!$daily || $bMs <= $aMs) {
        return [null, null, 0];
    }
    $d   = strtotime(date('Y-m-d', (int) ($aMs / 1000)));
    $end = strtotime(date('Y-m-d', (int) ($bMs / 1000)));
    $sum = 0.0;
    $n   = 0;
    $gtz = 0.0;
    while ($d <= $end) {
        $key = date('Y-m-d', $d);
        if (isset($daily[$key])) {
            $v = is_array($daily[$key]) ? $daily[$key]['mean'] : $daily[$key];
            if ($v !== null) {
                $sum += $v;
                $n++;
                if ($v < 15) {
                    $gtz += 20 - $v;
                }
            }
        }
        $d = strtotime('+1 day', $d);
    }
    if ($n === 0) {
        return [null, null, 0];
    }
    return [$sum / $n, $gtz, $n];
}

/* ------------------------------------------------------------------ XLSX-Export (ohne Bibliothek) */

function zb_col_letter($index)
{
    $s = '';
    $i = $index;
    while ($i > 0) {
        $r = ($i - 1) % 26;
        $s = chr(65 + $r) . $s;
        $i = intdiv($i - 1 - $r, 26);
    }
    return $s === '' ? 'A' : $s;
}

/** Tage seit 1970-01-01 aus einem bürgerlichen Datum (ohne calendar-Erweiterung). */
function zb_days_from_civil($y, $m, $d)
{
    $y -= $m <= 2 ? 1 : 0;
    $era = intdiv($y >= 0 ? $y : $y - 399, 400);
    $yoe = $y - $era * 400;
    $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
    $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
    return $era * 146097 + $doe - 719468;
}

/** Unix-Sekunden -> Excel-Seriennummer in lokaler Zeit. */
function zb_serial($ts)
{
    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);
    $d = (int) date('j', $ts);
    $days = zb_days_from_civil($y, $m, $d) + 25569;
    $frac = ((int) date('G', $ts) * 3600 + (int) date('i', $ts) * 60 + (int) date('s', $ts)) / 86400;
    return $days + $frac;
}

function zb_xml_escape($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Eine Zelle. $type: 'n' Zahl, 's' Text, 'dt' Datum+Zeit, 'd' Datum.
 */
function zb_cell($ref, $value, $type = null, $bold = false)
{
    if ($value === null || $value === '') {
        return '';
    }
    if ($type === null) {
        $type = is_numeric($value) && !is_string($value) ? 'n' : 's';
    }
    if ($type === 'dt' || $type === 'd') {
        $style = $type === 'dt' ? 2 : 3;
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . round(zb_serial($value), 8) . '</v></c>';
    }
    if ($type === 'n') {
        if (!is_finite((float) $value)) {
            return '';
        }
        $s = $bold ? ' s="1"' : '';
        return '<c r="' . $ref . '"' . $s . '><v>' . (0 + round((float) $value, 6)) . '</v></c>';
    }
    $s = $bold ? ' s="1"' : '';
    return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
        . zb_xml_escape($value) . '</t></is></c>';
}

/**
 * Baut ein Arbeitsblatt. $rows: Liste von Zeilen, jede Zeile eine Liste von
 * [wert, typ] oder skalaren Werten. $widths: Spaltenbreiten.
 */
function zb_sheet_xml($rows, $widths, $headerBold = true)
{
    $cols = '';
    if ($widths) {
        $cols = '<cols>';
        foreach ($widths as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
    }
    $body = '';
    foreach ($rows as $ri => $row) {
        $rn   = $ri + 1;
        $body .= '<row r="' . $rn . '">';
        foreach ($row as $ci => $cell) {
            $ref  = zb_col_letter($ci + 1) . $rn;
            $val  = is_array($cell) ? $cell[0] : $cell;
            $type = is_array($cell) && isset($cell[1]) ? $cell[1] : null;
            $body .= zb_cell($ref, $val, $type, $headerBold && $ri === 0);
        }
        $body .= '</row>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . $cols . '<sheetData>' . $body . '</sheetData></worksheet>';
}

/** Minimaler ZIP-Writer (deflate über zlib), damit keine ZipArchive-Erweiterung nötig ist. */
function zb_zip($files)
{
    $out     = '';
    $central = '';
    $offset  = 0;
    $time    = 0x0000;
    $date    = 0x2100; // 1.1.2000, für reproduzierbare Dateien
    foreach ($files as $name => $content) {
        $crc   = crc32($content);
        $usize = strlen($content);
        $comp  = gzdeflate($content, 6);
        if ($comp === false) {
            $comp   = $content;
            $method = 0;
        } else {
            $method = 8;
        }
        $csize = strlen($comp);
        $local = "\x50\x4b\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', $method)
            . pack('v', $time) . pack('v', $date) . pack('V', $crc)
            . pack('V', $csize) . pack('V', $usize)
            . pack('v', strlen($name)) . pack('v', 0) . $name;
        $out .= $local . $comp;
        $central .= "\x50\x4b\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', $method)
            . pack('v', $time) . pack('v', $date) . pack('V', $crc)
            . pack('V', $csize) . pack('V', $usize)
            . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0) . pack('V', 32) . pack('V', $offset) . $name;
        $offset += strlen($local) + $csize;
    }
    $n = count($files);
    return $out . $central . "\x50\x4b\x05\x06" . pack('v', 0) . pack('v', 0)
        . pack('v', $n) . pack('v', $n) . pack('V', strlen($central)) . pack('V', $offset) . pack('v', 0);
}

function zb_build_xlsx()
{
    $readings = zb_readings();
    $meters   = zb_meters();
    if (!$meters) {
        $keys = [];
        foreach ($readings as $r) {
            foreach ((isset($r['v']) ? $r['v'] : []) as $k => $_) {
                $keys[$k] = true;
            }
        }
        foreach (array_keys($keys) as $k) {
            $meters[] = ['key' => $k, 'name' => $k, 'unit' => '', 'decimals' => 2];
        }
    }
    $weather = zb_weather();
    $daily   = $weather['daily'];
    $loc     = zb_location();

    /* Blatt 1: Zählerstände */
    $head = ['Datum'];
    foreach ($meters as $m) {
        $head[] = $m['name'] . ' (' . (isset($m['unit']) ? $m['unit'] : '') . ')';
    }
    $head[] = 'Ø Außentemp. seit letzter Ablesung °C';
    $head[] = 'Gradtage (20/15) seit letzter Ablesung';
    foreach ($meters as $m) { $head[] = 'Wechsel ' . $m['name']; }
    foreach ($meters as $m) { $head[] = 'Notiz ' . $m['name']; }
    $head[] = 'Quelle';
    $head[] = 'Fotos';
    $rows  = [$head];
    $prevT = null;
    foreach ($readings as $r) {
        $row = [[(int) ($r['t'] / 1000), 'dt']];
        foreach ($meters as $m) {
            $row[] = isset($r['v'][$m['key']]) ? [$r['v'][$m['key']], 'n'] : null;
        }
        list($mean, $gtz) = $prevT !== null ? zb_temp_stats($daily, $prevT, $r['t']) : [null, null, 0];
        $row[] = $mean !== null ? [round($mean, 1), 'n'] : null;
        $row[] = $gtz !== null ? [round($gtz, 1), 'n'] : null;
        foreach ($meters as $m) { $row[] = !empty($r['chg'][$m['key']]) ? ['x', 's'] : null; }
        foreach ($meters as $m) { $row[] = isset($r['n'][$m['key']]) ? [$r['n'][$m['key']], 's'] : null; }
        $row[]  = [isset($r['src']) ? $r['src'] : '', 's'];
        $row[]  = !empty($r['ph']) ? [count($r['ph']), 'n'] : null;
        $rows[] = $row;
        $prevT  = $r['t'];
    }
    $widths = array_merge([18], array_fill(0, count($head) - 1, 16));
    $sheet1 = zb_sheet_xml($rows, $widths);

    /* Blatt 2: Verbrauch je Abschnitt */
    $rows = [['Zähler', 'Von', 'Bis', 'Tage', 'Stand von', 'Stand bis', 'Verbrauch', 'Einheit',
        'Verbrauch/Tag', 'Kosten €', 'Kosten/Tag €', 'Ø Außentemp. °C', 'Gradtage (20/15)',
        'Verbrauch je Gradtag', 'Zählerwechsel', 'Notiz']];
    foreach ($meters as $m) {
        $pts  = zb_series($readings, $m);
        $unit = zb_out_unit($m);
        for ($i = 1, $n = count($pts); $i < $n; $i++) {
            $a = $pts[$i - 1];
            $b = $pts[$i];
            list($mean, $gtz) = zb_temp_stats($daily, $a['t'], $b['t']);
            $rows[] = [
                [$m['name'], 's'],
                [(int) ($a['t'] / 1000), 'dt'],
                [(int) ($b['t'] / 1000), 'dt'],
                [round($b['days'], 2), 'n'],
                [$a['raw'], 'n'],
                [$b['raw'], 'n'],
                [round($b['amount'], 3), 'n'],
                [$unit, 's'],
                $b['rate'] !== null ? [round($b['rate'], 3), 'n'] : null,
                [round($b['cost'], 2), 'n'],
                $b['days'] > 0.02 ? [round($b['cost'] / $b['days'], 3), 'n'] : null,
                $mean !== null ? [round($mean, 1), 'n'] : null,
                $gtz !== null ? [round($gtz, 1), 'n'] : null,
                ($gtz !== null && $gtz > 5) ? [round($b['amount'] / $gtz, 3), 'n'] : null,
                $b['chg'] ? ['x', 's'] : null,
                $b['note'] !== '' ? [$b['note'], 's'] : null,
            ];
        }
    }
    $sheet2 = zb_sheet_xml($rows, [18, 17, 17, 7, 12, 12, 12, 8, 13, 10, 11, 14, 14, 16, 12, 40]);

    /* Blatt 3: Temperaturen */
    $rows = [['Datum', 'Mittel °C', 'Min °C', 'Max °C', '', 'Quelle']];
    $srcNote = 'Open-Meteo (open-meteo.com)' . ($loc ? ', Standort ' . (isset($loc['name']) ? $loc['name'] : '')
        . ' (' . $loc['lat'] . ', ' . $loc['lon'] . ')' : '');
    $first = true;
    foreach ($daily as $day => $v) {
        $ts  = strtotime($day . ' 12:00:00');
        $row = [
            [$ts, 'd'],
            [is_array($v) ? $v['mean'] : $v, 'n'],
            [is_array($v) && isset($v['min']) ? $v['min'] : null, 'n'],
            [is_array($v) && isset($v['max']) ? $v['max'] : null, 'n'],
            null,
            $first ? [$srcNote, 's'] : null,
        ];
        $first  = false;
        $rows[] = $row;
    }
    $sheet3 = zb_sheet_xml($rows, [12, 11, 11, 11, 3, 60]);

    /* Blatt 4: Zähler und Preise */
    $rows = [['Zähler', 'Schlüssel', 'Einheit', 'Ergebnis-Einheit', 'Faktor', 'IDs', 'in Summe', 'Kontrolle von', 'erlaubte Abweichung', 'Preis gültig ab', 'Preis €']];
    foreach ($meters as $m) {
        $prices = !empty($m['prices']) ? $m['prices'] : [[]];
        foreach ($prices as $p) {
            $rows[] = [
                [$m['name'], 's'],
                [$m['key'], 's'],
                [isset($m['unit']) ? $m['unit'] : '', 's'],
                [zb_out_unit($m), 's'],
                [zb_factor($m), 'n'],
                [implode(', ', isset($m['ids']) ? $m['ids'] : []), 's'],
                !empty($m['inTotal']) ? ['x', 's'] : null,
                !empty($m['controlOf']) ? [$m['controlOf'], 's'] : null,
                isset($m['tolerance']) ? [$m['tolerance'], 'n'] : null,
                isset($p['from']) ? [$p['from'], 's'] : null,
                isset($p['price']) ? [$p['price'], 'n'] : null,
            ];
        }
    }
    $sheet4 = zb_sheet_xml($rows, [20, 10, 8, 14, 8, 34, 8, 13, 18, 14, 9]);

    /* Blatt: Zählerabgleich (nur wenn Kontrollzähler eingerichtet sind) */
    $names  = ['Zählerstände', 'Verbrauch'];
    $sheets = [$sheet1, $sheet2];
    $byKey  = [];
    foreach ($meters as $m) {
        $byKey[$m['key']] = $m;
    }
    $rows = [['Kontrollzähler', 'Hauptzähler', 'Von', 'Bis', 'Tage', 'Hauptzähler kWh', 'Kontrollzähler kWh',
        'Abweichung kWh', 'erlaubt kWh', 'in Ordnung']];
    $anyControl = false;
    foreach ($meters as $c) {
        if (empty($c['controlOf']) || !isset($byKey[$c['controlOf']])) {
            continue;
        }
        $anyControl = true;
        $m = $byKey[$c['controlOf']];
        foreach (zb_control_rows($readings, $c, $m) as $r) {
            $rows[] = [
                [$c['name'], 's'], [$m['name'], 's'],
                [(int) ($r['t0'] / 1000), 'dt'], [(int) ($r['t1'] / 1000), 'dt'],
                [round($r['days'], 2), 'n'],
                [round($r['dm'], 3), 'n'], [round($r['dc'], 3), 'n'],
                [round($r['diff'], 3), 'n'], [round($r['tol'], 3), 'n'],
                [abs($r['diff']) <= $r['tol'] ? 'ja' : 'NEIN', 's'],
            ];
        }
    }
    if ($anyControl) {
        $names[]  = 'Zählerabgleich';
        $sheets[] = zb_sheet_xml($rows, [20, 20, 17, 17, 7, 16, 18, 15, 12, 11]);
    }
    $names[]  = 'Temperaturen';
    $sheets[] = $sheet3;
    $names[]  = 'Zähler';
    $sheets[] = $sheet4;

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="DD.MM.YYYY\ HH:MM"/>'
        . '<numFmt numFmtId="165" formatCode="DD.MM.YYYY"/></numFmts>'
        . '<fonts count="2"><font><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><sz val="10"/><name val="Arial"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Standard" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $wbSheets = '';
    $wbRels   = '';
    $overrides = '';
    foreach ($names as $i => $n) {
        $id        = $i + 1;
        $wbSheets .= '<sheet name="' . zb_xml_escape($n) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        $wbRels   .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $id . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $styleRelId = count($names) + 1;

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $wbSheets . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $wbRels
            . '<Relationship Id="rId' . $styleRelId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => $styles,
    ];
    foreach ($sheets as $i => $xml) {
        $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $xml;
    }
    return zb_zip($files);
}
