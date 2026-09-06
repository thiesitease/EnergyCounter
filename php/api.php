<?php
/**
 * Zählerbuch SB85 – API. Alles über einen Einstiegspunkt mit ?p=<aktion>,
 * damit keine URL-Rewrites nötig sind. Nur POST und GET, weil manche
 * Hosting-Umgebungen PUT und DELETE blockieren.
 */
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function zb_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function zb_input()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$p      = isset($_GET['p']) ? (string) $_GET['p'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

/* ---------------------------------------------------------------- offene Endpunkte */

if ($p === 'health') {
    $limitBefore = (int) ini_get('max_execution_time');
    @set_time_limit(180);
    $limitAfter = (int) ini_get('max_execution_time');
    zb_out([
        'ok'         => true,
        'php'        => PHP_VERSION,
        'writable'   => zb_writable(),
        'data_dir'   => basename(zb_data_dir()),
        'api_key'    => zb_has_key(),
        'password'   => zb_password() !== '',
        'readonly_pw'=> zb_password_view() !== '',
        'curl'       => function_exists('curl_init'),
        'zlib'       => function_exists('gzdeflate'),
        'model'      => zb_model(),
        'readings'   => count(zb_readings()),
        'https'      => zb_is_https(),
        'photos'     => zb_photo_usage(),
        'limits'     => [
            'max_execution_time'  => $limitBefore,
            'raisable'            => $limitAfter > $limitBefore || $limitAfter === 0,
            'max_execution_after' => $limitAfter,
            'post_max_size'       => ini_get('post_max_size'),
            'memory_limit'        => ini_get('memory_limit'),
        ],
    ]);
}

if ($p === 'login') {
    if ($method !== 'POST') {
        zb_out(['error' => 'method'], 405);
    }
    if (zb_password() === '') {
        zb_out(['ok' => false, 'error' => 'no_password',
            'message' => 'In config.php ist kein Passwort gesetzt. Ohne Passwort bleibt die App gesperrt.'], 503);
    }
    $in = zb_input();
    $pw = isset($in['password']) ? (string) $in['password'] : '';
    if (hash_equals(zb_password(), $pw)) {
        zb_set_auth_cookie('edit');
        zb_out(['ok' => true, 'role' => 'edit']);
    }
    if (zb_password_view() !== '' && hash_equals(zb_password_view(), $pw)) {
        zb_set_auth_cookie('view');
        zb_out(['ok' => true, 'role' => 'view']);
    }
    usleep(400000);
    zb_out(['ok' => false], 401);
}

/* ---------------------------------------------------------------- ab hier mit Anmeldung */

$role = zb_role();
if ($role === null) {
    zb_out([
        'error'   => 'unauthorized',
        'message' => zb_password() === ''
            ? 'In config.php ist kein Passwort gesetzt. Ohne Passwort bleibt die App gesperrt.'
            : 'Bitte anmelden.',
    ], 401);
}

// Alles, was Daten verändert, ist dem Bearbeiten-Passwort vorbehalten.
$schreibend = ['save-reading', 'delete-reading', 'save-config', 'save-photo', 'delete-photo',
               'recognize', 'weather-step'];
if (in_array($p, $schreibend, true) && $role !== 'edit') {
    zb_out(['error' => 'readonly_mode',
            'message' => 'Dieser Zugang darf nur ansehen, nicht ändern.'], 403);
}

switch ($p) {

    case 'state':
        zb_out([
            'readings' => zb_readings(),
            'config'   => zb_config(),
            'weather'  => zb_weather_public(),
            'features' => [
                'photo'    => zb_has_key(),
                'model'    => zb_model(),
                'writable' => zb_writable(),
                'role'     => $role,
                'canEdit'  => $role === 'edit',
            ],
        ]);
        break;

    case 'weather':
        zb_out(zb_weather_public());
        break;

    case 'weather-step':
        if ($method !== 'POST') {
            zb_out(['error' => 'method'], 405);
        }
        if (!zb_writable()) {
            zb_out(['error' => 'readonly', 'message' => 'Das Datenverzeichnis ist nicht beschreibbar.'], 500);
        }
        @set_time_limit(120);
        $step             = zb_weather_step();
        $step['weather']  = zb_weather_public();
        zb_out($step);
        break;

    case 'geocode':
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        if ($q === '') {
            zb_out([]);
        }
        list($data, $err) = zb_get_json('https://geocoding-api.open-meteo.com/v1/search?'
            . http_build_query(['name' => $q, 'count' => 6, 'language' => 'de', 'format' => 'json']));
        if ($err !== '') {
            zb_out(['error' => 'geocode', 'message' => $err], 502);
        }
        $out = [];
        foreach ((isset($data['results']) ? $data['results'] : []) as $r) {
            $out[] = [
                'name'    => isset($r['name']) ? $r['name'] : '',
                'admin'   => isset($r['admin1']) ? $r['admin1'] : '',
                'country' => isset($r['country']) ? $r['country'] : '',
                'lat'     => isset($r['latitude']) ? $r['latitude'] : null,
                'lon'     => isset($r['longitude']) ? $r['longitude'] : null,
            ];
        }
        zb_out($out);
        break;

    case 'save-reading':
        if ($method !== 'POST') {
            zb_out(['error' => 'method'], 405);
        }
        if (!zb_writable()) {
            zb_out(['error' => 'readonly', 'message' => 'Das Datenverzeichnis ist nicht beschreibbar (Rechte auf 0775 setzen).'], 500);
        }
        $in = zb_input();
        $id = isset($in['id']) ? (string) $in['id'] : '';
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $id)) {
            zb_out(['error' => 'invalid_id'], 400);
        }
        if (!isset($in['t']) || !is_numeric($in['t']) || !isset($in['v']) || !is_array($in['v'])) {
            zb_out(['error' => 'invalid_reading'], 400);
        }
        $doc = ['id' => $id, 't' => (int) $in['t'], 'v' => [], 'src' => isset($in['src']) ? (string) $in['src'] : 'manual'];
        foreach ($in['v'] as $k => $v) {
            if (is_numeric($v) && preg_match('/^[A-Za-z0-9_\-]{1,32}$/', (string) $k)) {
                $doc['v'][$k] = 0 + $v;
            }
        }
        if (!empty($in['n']) && is_array($in['n'])) {
            foreach ($in['n'] as $k => $v) {
                if ($v !== '' && preg_match('/^[A-Za-z0-9_\-]{1,32}$/', (string) $k)) {
                    $doc['n'][$k] = zb_cut((string) $v, 500);
                }
            }
        }
        if (!empty($in['chg']) && is_array($in['chg'])) {
            foreach ($in['chg'] as $k => $v) {
                if ($v && preg_match('/^[A-Za-z0-9_\-]{1,32}$/', (string) $k)) {
                    $doc['chg'][$k] = true;
                }
            }
        }
        // Fotos: Liste aus {f: Dateiname, m: Zählerschlüssel}
        if (!empty($in['ph']) && is_array($in['ph'])) {
            foreach ($in['ph'] as $entry) {
                if (is_array($entry) && !empty($entry['f']) && zb_valid_photo($entry['f'])
                    && zb_photo_path($id, $entry['f'])) {
                    $doc['ph'][] = ['f' => $entry['f'],
                        'm' => preg_match('/^[A-Za-z0-9_\-]{1,32}$/', (string) ($entry['m'] ?? '')) ? $entry['m'] : ''];
                }
            }
        }
        foreach (['created', 'edited'] as $k) {
            if (isset($in[$k]) && is_numeric($in[$k])) {
                $doc[$k] = (int) $in[$k];
            }
        }
        $rs  = zb_load('readings.json', []);
        $new = [];
        foreach ($rs as $r) {
            if (!isset($r['id']) || $r['id'] !== $id) {
                $new[] = $r;
            }
        }
        $new[] = $doc;
        usort($new, function ($a, $b) { return $a['t'] <=> $b['t']; });
        if (!zb_save('readings.json', $new)) {
            zb_out(['error' => 'write_failed', 'message' => 'Speichern fehlgeschlagen.'], 500);
        }
        zb_out(['ok' => true]);
        break;

    case 'delete-reading':
        if ($method !== 'POST') {
            zb_out(['error' => 'method'], 405);
        }
        $in = zb_input();
        $id = isset($in['id']) ? (string) $in['id'] : '';
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $id)) {
            zb_out(['error' => 'invalid_id'], 400);
        }
        $rs  = zb_load('readings.json', []);
        $new = [];
        foreach ($rs as $r) {
            if (!isset($r['id']) || $r['id'] !== $id) {
                $new[] = $r;
            }
        }
        if (!zb_save('readings.json', $new)) {
            zb_out(['error' => 'write_failed'], 500);
        }
        zb_delete_photos($id);
        zb_out(['ok' => true]);
        break;

    case 'save-photo':
        if ($method !== 'POST') {
            zb_out(['error' => 'method'], 405);
        }
        if (!zb_writable()) {
            zb_out(['error' => 'readonly', 'message' => 'Das Datenverzeichnis ist nicht beschreibbar.'], 500);
        }
        @set_time_limit(120);
        $in = zb_input();
        $id = isset($in['id']) ? (string) $in['id'] : '';
        $mk = isset($in['meter']) ? (string) $in['meter'] : 'sonstige';
        if (!zb_valid_id($id)) {
            zb_out(['error' => 'invalid_id'], 400);
        }
        $img = isset($in['image']) ? (string) $in['image'] : '';
        if (strpos($img, 'data:') === 0) {
            $comma = strpos($img, ',');
            $img   = $comma === false ? '' : substr($img, $comma + 1);
        }
        $bin = base64_decode(preg_replace('/\s+/', '', $img), true);
        if ($bin === false || strlen($bin) < 500) {
            zb_out(['error' => 'no_image', 'message' => 'Kein Bild empfangen.'], 400);
        }
        if (strlen($bin) > 6 * 1024 * 1024) {
            zb_out(['error' => 'too_large', 'message' => 'Bild zu groß.'], 413);
        }
        $name = zb_save_photo($id, $mk, $bin);
        if ($name === null) {
            zb_out(['error' => 'write_failed', 'message' => 'Foto konnte nicht gespeichert werden.'], 500);
        }
        zb_out(['ok' => true, 'file' => $name, 'bytes' => strlen($bin)]);
        break;

    case 'delete-photo':
        if ($method !== 'POST') {
            zb_out(['error' => 'method'], 405);
        }
        $in   = zb_input();
        $id   = isset($in['id']) ? (string) $in['id'] : '';
        $file = isset($in['file']) ? (string) $in['file'] : '';
        if (!zb_valid_id($id) || !zb_valid_photo($file)) {
            zb_out(['error' => 'invalid_id'], 400);
        }
        zb_delete_photo($id, $file);
        $rs = zb_load('readings.json', []);
        foreach ($rs as &$r) {
            if (isset($r['id']) && $r['id'] === $id && !empty($r['ph'])) {
                $r['ph'] = array_values(array_filter($r['ph'], function ($e) use ($file) {
                    return !isset($e['f']) || $e['f'] !== $file;
                }));
                if (!$r['ph']) {
                    unset($r['ph']);
                }
            }
        }
        unset($r);
        zb_save('readings.json', $rs);
        zb_out(['ok' => true]);
        break;

    case 'photo':
        $id   = isset($_GET['id']) ? (string) $_GET['id'] : '';
        $file = isset($_GET['f']) ? (string) $_GET['f'] : '';
        $path = zb_photo_path($id, $file);
        if ($path === null) {
            zb_out(['error' => 'not_found'], 404);
        }
        header_remove('Content-Type');
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit;

    case 'save-config':
        if ($method !== 'POST') {
            zb_out(['error' => 'method'], 405);
        }
        $in = zb_input();
        if (!isset($in['meters']) || !is_array($in['meters'])) {
            zb_out(['error' => 'invalid_config'], 400);
        }
        if (!zb_save('config.json', $in)) {
            zb_out(['error' => 'write_failed', 'message' => 'Speichern fehlgeschlagen.'], 500);
        }
        zb_out(['ok' => true]);
        break;

    case 'recognize':
        if ($method !== 'POST') {
            zb_out(['error' => 'method'], 405);
        }
        @set_time_limit(180);
        $in  = zb_input();
        $img = isset($in['image']) ? (string) $in['image'] : '';
        if (strpos($img, 'data:') === 0) {
            $comma = strpos($img, ',');
            $img   = $comma === false ? '' : substr($img, $comma + 1);
        }
        $img = preg_replace('/\s+/', '', $img);
        if ($img === '' || strlen($img) < 100) {
            zb_out(['error' => 'no_image', 'message' => 'Kein Bild empfangen.'], 400);
        }
        if (strlen($img) > 8 * 1024 * 1024) {
            zb_out(['error' => 'too_large', 'message' => 'Bild zu groß.'], 413);
        }
        $media = isset($in['media_type']) ? (string) $in['media_type'] : 'image/jpeg';
        if (!in_array($media, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $media = 'image/jpeg';
        }
        $result = zb_recognize($img, $media, isset($in['prompt']) ? (string) $in['prompt'] : '');
        zb_out($result, isset($result['error']) ? 502 : 200);
        break;

    case 'export.xlsx':
        @set_time_limit(120);
        $xlsx = zb_build_xlsx();
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Zaehlerbuch_' . date('Y-m-d') . '.xlsx"');
        header('Content-Length: ' . strlen($xlsx));
        echo $xlsx;
        exit;

    default:
        zb_out(['error' => 'not_found'], 404);
}
