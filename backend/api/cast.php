<?php
declare(strict_types=1);

/**
 * Puente para Chromecast.
 *
 * El televisor pide la señal a Liveo (misma red que el celular) y Liveo se la pide al servidor original
 * con el Referer y el User-Agent guardados en el panel, agregando CORS. Así se puede transmitir
 * cualquier fuente HLS (m3u8), incluso las que el Chromecast no podría abrir directamente.
 *
 *   /api/cast.php?s=ID                       lista principal de la señal (ID = id del enlace en el panel)
 *   /api/cast.php?s=ID&u=BASE64URL&k=FIRMA   listas, segmentos y claves que salen de esa lista (firmados)
 */
require dirname(__DIR__) . '/src/bootstrap.php';

while (ob_get_level() > 0) {
    @ob_end_clean();
}
@ini_set('zlib.output_compression', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type, Origin, Accept');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Content-Type');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function cast_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

function cast_secret(PDO $pdo): string
{
    $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $st->execute(['cast_secret']);
    $v = $st->fetchColumn();
    if (is_string($v) && strlen($v) >= 32) {
        return $v;
    }
    $v = bin2hex(random_bytes(24));
    $pdo->prepare('REPLACE INTO app_settings (setting_key, setting_value) VALUES (?, ?)')->execute(['cast_secret', $v]);
    return $v;
}

function cast_b64e(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function cast_b64d(string $s): string
{
    $r = base64_decode(strtr($s, '-_', '+/'));
    return is_string($r) ? $r : '';
}

function cast_sig(int $sid, string $url, string $secret): string
{
    return substr(hash_hmac('sha256', $sid . '|' . $url, $secret), 0, 32);
}

/** Convierte una referencia de una lista (relativa o absoluta) en una URL absoluta. */
function cast_resolve(string $base, string $ref): string
{
    $ref = trim($ref);
    if (preg_match('#^https?://#i', $ref) === 1) {
        return $ref;
    }
    $b = parse_url($base);
    if (!is_array($b) || empty($b['scheme']) || empty($b['host'])) {
        return '';
    }
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (strpos($ref, '//') === 0) {
        return $b['scheme'] . ':' . $ref;
    }
    $frag = strpos($ref, '#');
    if ($frag !== false) {
        $ref = substr($ref, 0, $frag);
    }
    $query = '';
    $q = strpos($ref, '?');
    if ($q !== false) {
        $query = substr($ref, $q);
        $ref = substr($ref, 0, $q);
    }
    $basePath = isset($b['path']) && $b['path'] !== '' ? $b['path'] : '/';
    if ($ref === '') {
        return $origin . $basePath . $query;
    }
    if ($ref[0] === '/') {
        $path = $ref;
    } else {
        $dir = substr($basePath, 0, (int)strrpos($basePath, '/') + 1);
        $path = ($dir === '' ? '/' : $dir) . $ref;
    }
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '.') {
            continue;
        }
        if ($seg === '..') {
            if (count($out) > 1) {
                array_pop($out);
            }
            continue;
        }
        $out[] = $seg;
    }
    $path = implode('/', $out);
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return $origin . $path . $query;
}

/** Evita que una lista maliciosa use este puente para entrar a equipos de la red interna. */
function cast_host_allowed(string $url, string $srcUrl): bool
{
    $u = parse_url($url);
    if (!is_array($u) || empty($u['host'])) {
        return false;
    }
    if (!in_array(strtolower((string)($u['scheme'] ?? '')), ['http', 'https'], true)) {
        return false;
    }
    $host = strtolower((string)$u['host']);
    $s = parse_url($srcUrl);
    if (is_array($s) && isset($s['host']) && strtolower((string)$s['host']) === $host) {
        return true;
    }
    if ($host === 'localhost' || substr($host, -6) === '.local') {
        return false;
    }
    $ip = trim($host, '[]');
    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    return true;
}

/** Reescribe una lista m3u8 para que todo (listas, segmentos, claves) vuelva a pasar por este puente. */
function cast_rewrite(string $text, string $base, int $sid, string $secret, string $srcUrl): string
{
    $self = (string)($_SERVER['SCRIPT_NAME'] ?? '/api/cast.php');
    $wrap = function (string $ref) use ($base, $sid, $secret, $srcUrl, $self): string {
        $ref = trim($ref);
        if ($ref === '') {
            return $ref;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref) === 1 && preg_match('#^https?://#i', $ref) !== 1) {
            return $ref; // data:, etc.
        }
        $abs = cast_resolve($base, $ref);
        if ($abs === '' || !cast_host_allowed($abs, $srcUrl)) {
            return $ref;
        }
        return $self . '?s=' . $sid . '&u=' . cast_b64e($abs) . '&k=' . cast_sig($sid, $abs, $secret);
    };
    $lines = preg_split('/\r\n|\n|\r/', $text);
    if (!is_array($lines)) {
        return $text;
    }
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === '') {
            continue;
        }
        if ($t[0] === '#') {
            if (stripos($t, 'URI="') !== false) {
                $new = preg_replace_callback(
                    '/URI="([^"]*)"/i',
                    function (array $m) use ($wrap): string {
                        return 'URI="' . $wrap($m[1]) . '"';
                    },
                    $line
                );
                $lines[$i] = is_string($new) ? $new : $line;
            }
            continue;
        }
        $lines[$i] = $wrap($t);
    }
    return implode("\n", $lines) . "\n";
}

function cast_content_type(string $url, string $upstream): string
{
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (preg_match('/\.ts$/i', $path) === 1) {
        return 'video/mp2t';
    }
    if (preg_match('/\.aac$/i', $path) === 1) {
        return 'audio/aac';
    }
    if (preg_match('/\.(m4s|mp4|m4v)$/i', $path) === 1) {
        return 'video/mp4';
    }
    return $upstream !== '' ? $upstream : 'application/octet-stream';
}

// ---------------------------------------------------------------------------

if (!function_exists('curl_init')) {
    cast_fail(500, 'Falta la extensión curl de PHP.');
}
$pdo = db();
if (!$pdo instanceof PDO) {
    cast_fail(503, 'Sin base de datos.');
}
$sid = (int)($_GET['s'] ?? 0);
if ($sid <= 0) {
    cast_fail(400, 'Falta la señal (s).');
}
$st = $pdo->prepare('SELECT url, kind, referer, user_agent FROM event_sources WHERE id = ? AND active = 1');
$st->execute([$sid]);
$src = $st->fetch();
if (!is_array($src) || (string)$src['kind'] === 'iframe') {
    cast_fail(404, 'Señal no disponible.');
}
$srcUrl = (string)$src['url'];
$secret = cast_secret($pdo);

$target = $srcUrl;
if (isset($_GET['u'])) {
    $u = cast_b64d((string)$_GET['u']);
    $k = (string)($_GET['k'] ?? '');
    if ($u === '' || !hash_equals(cast_sig($sid, $u, $secret), $k)) {
        cast_fail(403, 'Enlace no válido.');
    }
    $target = $u;
}
if (!cast_host_allowed($target, $srcUrl)) {
    cast_fail(403, 'Destino no permitido.');
}

@set_time_limit(0);
$cfg = liveo_config();
$verify = !empty($cfg['ssl_verify']);

$headers = ['Accept: */*'];
$ref = trim((string)($src['referer'] ?? ''));
if ($ref !== '') {
    $headers[] = 'Referer: ' . $ref;
}
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/^bytes=[0-9,\- ]+$/', $range) === 1) {
    $headers[] = 'Range: ' . $range;
}
$ua = trim((string)($src['user_agent'] ?? ''));
if ($ua === '') {
    $ua = 'Mozilla/5.0 (Linux; Android 13) Liveo/' . LIVEO_VERSION;
}

$state = ['mode' => null, 'buf' => '', 'status' => 0, 'ctype' => '', 'crange' => ''];

$sendHead = function () use (&$state, $target): void {
    http_response_code($state['status'] === 206 ? 206 : 200);
    header('Content-Type: ' . cast_content_type($target, (string)$state['ctype']));
    if ($state['status'] === 206 && $state['crange'] !== '') {
        header('Content-Range: ' . $state['crange']);
    }
};

$opts = [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_USERAGENT      => $ua,
    CURLOPT_SSL_VERIFYPEER => $verify,
    CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$state): int {
        $len = strlen($line);
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $state['status'] = (int)$m[1];
            $state['ctype'] = '';
            $state['crange'] = '';
        } elseif (stripos($line, 'content-type:') === 0) {
            $state['ctype'] = trim(substr($line, 13));
        } elseif (stripos($line, 'content-range:') === 0) {
            $state['crange'] = trim(substr($line, 14));
        }
        return $len;
    },
    CURLOPT_WRITEFUNCTION  => function ($handle, string $chunk) use (&$state, $sendHead): int {
        if ($state['mode'] === null) {
            if ($state['status'] >= 400) {
                $state['mode'] = 'error';
            } else {
                $head = ltrim(substr($chunk, 0, 16), "\xEF\xBB\xBF \t\r\n");
                $state['mode'] = strncmp($head, '#EXTM3U', 7) === 0 ? 'playlist' : 'stream';
                if ($state['mode'] === 'stream') {
                    $sendHead();
                }
            }
        }
        if ($state['mode'] === 'playlist') {
            $state['buf'] .= $chunk;
            return strlen($state['buf']) > 2097152 ? 0 : strlen($chunk);
        }
        if ($state['mode'] === 'error') {
            return strlen($chunk);
        }
        echo $chunk;
        flush();
        return connection_aborted() ? 0 : strlen($chunk);
    },
];
if (!empty($cfg['ca_bundle'])) {
    $opts[CURLOPT_CAINFO] = (string)$cfg['ca_bundle'];
}

$ch = curl_init($target);
curl_setopt_array($ch, $opts);
curl_exec($ch);
$err = curl_error($ch);
$effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if ($state['mode'] === 'playlist') {
    http_response_code(200);
    header('Content-Type: application/vnd.apple.mpegurl');
    echo cast_rewrite((string)$state['buf'], $effective !== '' ? $effective : $target, $sid, $secret, $srcUrl);
    exit;
}
if ($state['mode'] === 'error') {
    cast_fail(502, 'La señal respondió HTTP ' . (int)$state['status'] . '.');
}
if ($state['mode'] === null) {
    cast_fail(502, 'Sin respuesta de la señal' . ($err !== '' ? ': ' . $err : '.'));
}
exit;
