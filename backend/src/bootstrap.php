<?php
declare(strict_types=1);

if (!defined('LIVEO_VERSION')) {
    define('LIVEO_VERSION', '1.0.34');
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
        'ssl_verify'       => true,
        'ca_bundle'        => '',
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
require_once __DIR__ . '/sports_catalog.php';
require_once __DIR__ . '/importer.php';
