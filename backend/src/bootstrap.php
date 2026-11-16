<?php
declare(strict_types=1);

if (!defined('LIVEO_VERSION')) {
    define('LIVEO_VERSION', '1.0.5');
}

/** Configuración con valores por defecto aptos para XAMPP. */
function liveo_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $defaults = [
        'db_host'          => '127.0.0.1',
        'db_port'          => 3306,
        'db_name'          => 'liveo',
        'db_user'          => 'root',
        'db_pass'          => '',
        'lan_ip'           => '192.168.54.109',
        'timezone'         => 'America/Argentina/Buenos_Aires',
        'provider_base'    => 'https://matchora.to/api/v1/',
        'provider_timeout' => 15,
        'ssl_verify'       => true,
        'ca_bundle'        => '',
        'cache_ttl'        => ['schedule' => 120, 'live' => 30, 'channels' => 600, 'event' => 60],
        'debug'            => true,
    ];
    $local = [];
    $file = __DIR__ . '/config.local.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $local = $loaded;
        }
    }
    $cfg = array_replace($defaults, $local);
    @date_default_timezone_set((string)$cfg['timezone']);
    return $cfg;
}

// Inicializa la zona horaria en cuanto se carga el archivo.
liveo_config();

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function api_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function respond(array $data, int $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function error_detail(Throwable $e): array
{
    return !empty(liveo_config()['debug']) ? ['detail' => $e->getMessage()] : [];
}

// ---------------------------------------------------------------------------
// Base de datos (opcional: la API funciona sin ella, solo sin caché)
// ---------------------------------------------------------------------------

function db()
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $pdo = null;
    if (!extension_loaded('pdo_mysql')) {
        $GLOBALS['liveo_db_error'] = 'Falta la extensión pdo_mysql';
        return $pdo;
    }
    $c = liveo_config();
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['db_host'],
            (int)$c['db_port'],
            $c['db_name']
        );
        $pdo = new PDO($dsn, (string)$c['db_user'], (string)$c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 3,
        ]);
    } catch (Throwable $e) {
        $GLOBALS['liveo_db_error'] = $e->getMessage();
        $pdo = null;
    }
    return $pdo;
}

function cache_get(string $key, bool $allowStale = false): ?array
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    try {
        $sql = 'SELECT payload FROM api_cache WHERE cache_key = ?' . ($allowStale ? '' : ' AND expires_at > NOW()');
        $st = $pdo->prepare($sql);
        $st->execute([$key]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $data = json_decode((string)$row['payload'], true);
        return is_array($data) ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

function cache_set(string $key, array $data, int $ttl): void
{
    $pdo = db();
    if (!$pdo) {
        return;
    }
    try {
        $sql = sprintf(
            'REPLACE INTO api_cache (cache_key, payload, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL %d SECOND))',
            max(1, $ttl)
        );
        $pdo->prepare($sql)->execute([$key, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
        // La caché es opcional.
    }
}

// ---------------------------------------------------------------------------
// Proveedor (Matchora)
// ---------------------------------------------------------------------------

function http_get_json(string $url): array
{
    $c = liveo_config();
    $verify = !empty($c['ssl_verify']);
    $timeout = max(3, (int)$c['provider_timeout']);
    $ua = 'Liveo/' . LIVEO_VERSION;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: ' . $ua],
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ];
        if (!empty($c['ca_bundle'])) {
            $opts[CURLOPT_CAINFO] = (string)$c['ca_bundle'];
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($body === false) {
            throw new RuntimeException('No se pudo consultar el proveedor: ' . $err);
        }
        if ($code >= 400) {
            throw new RuntimeException('El proveedor respondió HTTP ' . $code);
        }
    } else {
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'header' => "Accept: application/json\r\nUser-Agent: " . $ua . "\r\n"],
            'ssl'  => ['verify_peer' => $verify, 'verify_peer_name' => $verify],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('No se pudo consultar el proveedor');
        }
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Respuesta inválida del proveedor');
    }
    return $data;
}

/** Consulta al proveedor con caché en MySQL y respaldo con datos vencidos. */
function provider(string $path, string $ttlKey = 'schedule'): array
{
    $c = liveo_config();
    $key = 'p:' . md5($path);

    $hit = cache_get($key);
    if ($hit !== null) {
        return $hit;
    }
    try {
        $url = rtrim((string)$c['provider_base'], '/') . '/' . ltrim($path, '/');
        $data = http_get_json($url);
        $ttl = (int)(($c['cache_ttl'][$ttlKey] ?? 60));
        cache_set($key, $data, $ttl);
        return $data;
    } catch (Throwable $e) {
        $stale = cache_get($key, true);
        if ($stale !== null) {
            return $stale;
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Normalización de eventos (tolera distintos nombres de campos)
// ---------------------------------------------------------------------------

function is_list_array(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

function extract_list(array $data, string $key): array
{
    if (isset($data[$key]) && is_array($data[$key])) {
        return array_values($data[$key]);
    }
    if (isset($data['data']) && is_array($data['data'])) {
        $d = $data['data'];
        if (isset($d[$key]) && is_array($d[$key])) {
            return array_values($d[$key]);
        }
        if (is_list_array($d)) {
            return $d;
        }
    }
    return is_list_array($data) ? $data : [];
}

function pick_name($v): string
{
    if (is_array($v)) {
        foreach (['name', 'title', 'short_name', 'shortName'] as $k) {
            if (isset($v[$k]) && is_scalar($v[$k]) && trim((string)$v[$k]) !== '') {
                return trim((string)$v[$k]);
            }
        }
        return '';
    }
    if (is_scalar($v) && !is_bool($v) && trim((string)$v) !== '') {
        return trim((string)$v);
    }
    return '';
}

function to_timestamp($v): int
{
    if ($v === null || $v === '' || is_bool($v) || is_array($v)) {
        return 0;
    }
    if (is_numeric($v)) {
        $n = (int)$v;
        if ($n <= 0) {
            return 0;
        }
        if ($n > 100000000000) {
            $n = intdiv($n, 1000);
        }
        return $n;
    }
    $t = strtotime((string)$v);
    return $t === false ? 0 : $t;
}

function normalize_event(array $e): array
{
    $first = function (array $keys) use ($e) {
        foreach ($keys as $k) {
            if (isset($e[$k]) && $e[$k] !== '' && $e[$k] !== null) {
                return $e[$k];
            }
        }
        return null;
    };

    $norm = [];

    $id = $first(['id', 'event_id', 'eventId', 'slug', 'uuid']);
    if (is_scalar($id)) {
        $norm['id'] = (string)$id;
    }

    $league = pick_name($first(['league', 'competition', 'tournament', 'sport']));
    if ($league !== '') {
        $norm['league'] = $league;
    }
    $home = pick_name($first(['home', 'home_team', 'homeTeam', 'team1']));
    if ($home !== '') {
        $norm['home'] = $home;
    }
    $away = pick_name($first(['away', 'away_team', 'awayTeam', 'team2']));
    if ($away !== '') {
        $norm['away'] = $away;
    }

    $ts = to_timestamp($first(['kickoff', 'start_time', 'startTime', 'start', 'starts_at', 'date', 'time']));
    if ($ts > 0) {
        $norm['kickoff'] = $ts;
    }

    $status = $first(['status', 'state']);
    $statusText = is_array($status) ? pick_name($status) : (is_scalar($status) ? (string)$status : '');
    if ($statusText !== '') {
        $norm['status'] = $statusText;
    }
    $liveFlag = $first(['live', 'is_live', 'isLive']);
    $isLive = ($liveFlag === true || $liveFlag === 1 || $liveFlag === '1' || $liveFlag === 'true')
        || in_array(strtolower($statusText), ['live', 'inplay', 'in_play', 'en vivo'], true);
    $norm['live'] = $isLive;

    $score = $first(['score']);
    if (is_array($score)) {
        $sh = $score['home'] ?? null;
        $sa = $score['away'] ?? null;
        if (is_scalar($sh) && is_scalar($sa)) {
            $norm['score'] = $sh . ' - ' . $sa;
        }
    } elseif (is_scalar($score) && (string)$score !== '') {
        $norm['score'] = (string)$score;
    } else {
        $homeScore = $first(['home_score', 'homeScore']);
        $awayScore = $first(['away_score', 'awayScore']);
        if (is_scalar($homeScore) && is_scalar($awayScore)) {
            $norm['score'] = $homeScore . ' - ' . $awayScore;
        }
    }

    $embed = $first(['embed_url', 'embedUrl', 'embed', 'stream_url', 'player_url']);
    if (!is_string($embed) || $embed === '') {
        $embed = null;
        $streams = $e['streams'] ?? ($e['sources'] ?? null);
        if (is_array($streams)) {
            foreach ($streams as $s) {
                if (!is_array($s)) {
                    continue;
                }
                foreach (['embed_url', 'embedUrl', 'url'] as $k) {
                    if (isset($s[$k]) && is_string($s[$k]) && $s[$k] !== '') {
                        $embed = $s[$k];
                        break 2;
                    }
                }
            }
        }
    }
    if (is_string($embed) && preg_match('#^https?://#i', $embed)) {
        $norm['embed_url'] = $embed;
    }

    $out = $e;
    foreach ($norm as $k => $v) {
        $out[$k] = $v;
    }
    if (!isset($norm['embed_url'])) {
        unset($out['embed_url']);
    }
    return $out;
}

function normalize_events(array $list): array
{
    $out = [];
    foreach ($list as $e) {
        if (is_array($e)) {
            $out[] = normalize_event($e);
        }
    }
    return $out;
}

function valid_date(string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Solo equipos de la red local (127.x, ::1, 10.x, 172.16-31.x, 192.168.x). */
function is_local_request(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

require_once __DIR__ . '/sources.php';
