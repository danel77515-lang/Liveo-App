<?php
declare(strict_types=1);

if (!defined('LIVEO_VERSION')) {
    define('LIVEO_VERSION', '1.0.16');
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
        // RockyLive API (https://rockylive.st/api/). No necesita clave.
        'rockylive_api'    => 'https://rockylive.st/api/events.php',
        'rockylive_limit'  => 1000,
        'provider_timeout' => 15,
        'ssl_verify'       => true,
        'ca_bundle'        => '',
        'cache_ttl'        => ['events' => 120],
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
// Proveedor: RockyLive API (https://rockylive.st/api/)
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

function is_list_array(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
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

/** Texto en minúsculas, sin signos, para comparar nombres de ligas y títulos. */
function fold_text(string $v): string
{
    $v = strtolower($v);
    $v = preg_replace('/[^a-z0-9]+/', ' ', $v) ?? $v;
    return trim($v);
}

/** Excluye deportes y competiciones no deseadas del catálogo de Liveo. */
function is_excluded_event(array $event): bool
{
    $fields = ['sport', 'sports', 'sport_name', 'sportName', 'category', 'category_name', 'categoryName', 'discipline', 'league', 'competition', 'tournament'];
    $values = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $event)) {
            continue;
        }
        $value = $event[$field];
        if (is_array($value)) {
            $value = pick_name($value);
        }
        if (is_scalar($value)) {
            $values[] = (string)$value;
        }
    }
    $haystack = strtolower(implode(' | ', $values));
    $haystack = preg_replace('/[^a-z0-9]+/', ' ', $haystack) ?? $haystack;
    $blocked = [
        'cricket',
        'nba g league', 'g league',
        'college basketball', 'ncaa division', 'ncaa',
        'wwe', 'world wrestling entertainment',
        'pdc darts', 'darts',
        'nhl', 'national hockey league',
        'aruban division di honor', 'division di honor',
        'guyana gff super league', 'gff super league',
        'american ahl', 'ahl', 'american hockey league',
        'japanese b1 league', 'b1 league',
        'dr congo ligue 1', 'congo ligue 1',
        'cameroonian elite one', 'elite one',
        'mali premiere division', 'premiere division mali',
        'russian vhl', 'vhl', 'russian khl', 'khl',
        'swedish hockey allsvenskan', 'hockey allsvenskan', 'allsvenskan',
        'australian wnbl', 'wnbl',
        'champions hockey league',
        'finnish mestis', 'mestis',
        'german del 2', 'del 2', 'del2',
        'swiss league',
        'danish metal ligaen', 'metal ligaen',
        'scottish lowland league east', 'lowland league east',
        'fighting',
        'mexican pacific league', 'pacific league',
        'japanese we league', 'we league',
        'one championship', 'one',
        'australian nbl', 'nbl',
        'asean club championship',
        'slovenia cup',
        'indian football league',
        'russian vtb united league', 'vtb united league',
        'canadian ohl', 'ohl', 'ontario hockey league',
        'american echl', 'echl',
        'finnish liiga', 'liiga',
    ];
    foreach ($blocked as $name) {
        $needle = preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)) ?? strtolower($name);
        if ($needle === 'one') {
            if (preg_match('/\\bone\\b/', $haystack) === 1) {
                return true;
            }
            continue;
        }
        if ($needle !== '' && strpos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Evento crudo de RockyLive que Liveo no quiere mostrar:
 * canales 24/7, darts, WWE, College Basketball, NBL, Allsvenskan, NHL y el
 * resto de competiciones de la lista de exclusión.
 */
function rocky_is_excluded(array $e): bool
{
    $always = $e['always_live'] ?? false;
    if ($always === true || $always === 1 || $always === '1' || strtolower((string)$always) === 'true') {
        return true;
    }
    $title = fold_text(pick_name($e['title'] ?? ''));
    $league = fold_text(pick_name($e['league'] ?? ''));
    if (preg_match('/\\b24 ?7\\b/', $title . ' ' . $league) === 1 || preg_match('/\\bchannels?\\b/', $league) === 1) {
        return true;
    }
    if (preg_match('/\\bwwe\\b/', $title) === 1 || preg_match('/\\bdarts?\\b/', $title) === 1) {
        return true;
    }
    $slug = strtolower((string)($e['league_slug'] ?? ''));
    if (in_array($slug, ['darts', 'nhl', 'nbl', 'allsvenskan'], true)) {
        return true;
    }
    return is_excluded_event($e);
}

/** "A vs. B" -> [local, visitante]; "A at B" -> [B, A]; sin equipos -> null. */
function rocky_split_title(string $title): ?array
{
    if (preg_match('/^(.+?)\\s+vs\\.?\\s+(.+)$/iu', $title, $m) === 1) {
        return [trim($m[1]), trim($m[2])];
    }
    if (preg_match('/^(.+?)\\s+(?:at|@)\\s+(.+)$/iu', $title, $m) === 1) {
        return [trim($m[2]), trim($m[1])];
    }
    return null;
}

/** Minutos que se considera en vivo un evento tras su inicio. */
function rocky_duration_minutes(string $slug): int
{
    $slug = strtolower($slug);
    if (in_array($slug, ['nba', 'basketball', 'wnba'], true)) {
        return 170;
    }
    if (in_array($slug, ['nfl', 'american-football', 'college-football'], true)) {
        return 220;
    }
    if (in_array($slug, ['baseball', 'mlb'], true)) {
        return 220;
    }
    if (in_array($slug, ['motorsports', 'wrestling'], true)) {
        return 180;
    }
    if (in_array($slug, ['fight', 'ufc', 'boxing'], true)) {
        return 300;
    }
    return 150;
}

/** Evento de RockyLive -> formato de Liveo (sin estado). null si no sirve. */
function rocky_normalize_event(array $e): ?array
{
    $id = $e['id'] ?? null;
    if (!is_scalar($id) || (string)$id === '' || preg_match('/^[0-9]{1,18}$/', (string)$id) !== 1) {
        return null;
    }
    $title = trim(pick_name($e['title'] ?? ''));
    if ($title === '') {
        return null;
    }
    $ts = to_timestamp($e['start_timestamp'] ?? null);
    if ($ts <= 0) {
        $ts = to_timestamp($e['start_utc'] ?? null);
    }
    if ($ts <= 0) {
        return null;
    }
    $embed = $e['embed_url'] ?? '';
    if (!is_string($embed) || preg_match('#^https?://#i', $embed) !== 1) {
        $embed = 'https://rockyembed.st/source/fetch.php?full=' . rawurlencode((string)$id);
    }
    $league = trim(pick_name($e['league'] ?? ''));
    $slug = strtolower(trim((string)($e['league_slug'] ?? '')));
    $teams = rocky_split_title($title);

    $out = [
        'id'        => 'rl-' . (string)$id,
        'title'     => $title,
        'league'    => $league !== '' ? $league : 'Deporte',
        'sport'     => $slug,
        'home'      => $teams ? $teams[0] : $title,
        'away'      => $teams ? $teams[1] : '',
        'kickoff'   => $ts,
        'embed_url' => $embed,
    ];
    if (!$teams) {
        $out['single_title'] = true;
    }
    return $out;
}

/** Agrega estado (programado / en vivo / finalizado) según la hora. */
function rocky_apply_state(array $ev, int $now): array
{
    $start = (int)($ev['kickoff'] ?? 0);
    $end = $start + rocky_duration_minutes((string)($ev['sport'] ?? '')) * 60;
    if ($now < $start) {
        $ev['live'] = false;
        $ev['status'] = 'Programado';
    } elseif ($now < $end) {
        $ev['live'] = true;
        $ev['status'] = 'En vivo';
    } else {
        $ev['live'] = false;
        $ev['status'] = 'Finalizado';
        $ev['state_label'] = 'Finalizado';
    }
    return $ev;
}

/**
 * Eventos de RockyLive ya filtrados y normalizados (sin estado), con caché en
 * MySQL y respaldo con datos vencidos si el proveedor no responde.
 */
function rockylive_base_events(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $c = liveo_config();
    $key = 'rl:events';
    $hit = cache_get($key);
    if ($hit !== null && isset($hit['events']) && is_array($hit['events'])) {
        return $memo = $hit['events'];
    }
    try {
        $limit = max(1, min(1000, (int)$c['rockylive_limit']));
        $url = (string)$c['rockylive_api'];
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query(['limit' => $limit]);
        $data = http_get_json($url);
        if (!isset($data['events']) || !is_array($data['events'])) {
            throw new RuntimeException('Respuesta inválida de RockyLive');
        }
        $events = [];
        foreach ($data['events'] as $raw) {
            if (!is_array($raw) || rocky_is_excluded($raw)) {
                continue;
            }
            $ev = rocky_normalize_event($raw);
            if ($ev !== null && !is_excluded_event($ev)) {
                $events[] = $ev;
            }
        }
        usort($events, function ($a, $b) {
            return ((int)$a['kickoff'] <=> (int)$b['kickoff']) ?: strcmp((string)$a['id'], (string)$b['id']);
        });
        cache_set($key, ['events' => $events], (int)($c['cache_ttl']['events'] ?? 120));
        return $memo = $events;
    } catch (Throwable $e) {
        $stale = cache_get($key, true);
        if ($stale !== null && isset($stale['events']) && is_array($stale['events'])) {
            return $memo = $stale['events'];
        }
        throw $e;
    }
}

/** Todos los eventos de RockyLive con su estado actual. */
function rockylive_events(): array
{
    $now = time();
    return array_map(function ($ev) use ($now) {
        return rocky_apply_state($ev, $now);
    }, rockylive_base_events());
}

/** Un evento por id ("rl-123" o solo "123"). */
function rockylive_event_by_id(string $id): ?array
{
    if (preg_match('/^[0-9]+$/', $id) === 1) {
        $id = 'rl-' . $id;
    }
    foreach (rockylive_events() as $ev) {
        if ((string)$ev['id'] === $id) {
            return $ev;
        }
    }
    return null;
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
require_once __DIR__ . '/manual.php';
