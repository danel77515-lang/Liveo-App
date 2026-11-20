<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
api_headers();

/** Ruta relativa a /Liveo/api, sin depender de PATH_INFO. */
function route_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uri = rawurldecode(is_string($uri) ? $uri : '/');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base !== '' && stripos($uri, $base) === 0) {
        $uri = substr($uri, strlen($base));
    }
    $uri = trim($uri, '/');
    if (stripos($uri, 'index.php') === 0) {
        $uri = trim(substr($uri, 9), '/');
    }
    return $uri;
}

function require_method(string $method, array $allowed): void
{
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        respond(['success' => false, 'error' => 'Método no permitido'], 405);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = route_path();

try {
    // ---- Estado --------------------------------------------------------
    if ($path === '' || $path === 'health') {
        require_method($method, ['GET']);
        $dbStatus = 'unavailable';
        $pdo = db();
        if ($pdo) {
            try {
                $pdo->query('SELECT 1 FROM api_cache LIMIT 1');
                $dbStatus = 'ok';
            } catch (Throwable $e) {
                $dbStatus = 'not_installed';
            }
        }
        respond([
            'success'     => true,
            'application' => 'Liveo',
            'version'     => LIVEO_VERSION,
            'php'         => PHP_VERSION,
            'database'    => $dbStatus,
            'source'      => 'manual',
            'time'        => date('c'),
        ]);
    }

    // ---- Anuncios informativos (barra deslizante) -----------------------
    if ($path === 'announcements') {
        require_method($method, ['GET']);
        $a = announcements_load();
        respond([
            'success' => true,
            'enabled' => $a['enabled'],
            'items'   => $a['enabled'] ? $a['items'] : [],
        ]);
    }

    // ---- Deportes (nombre + ícono para el filtro del inicio) -----------
    if ($path === 'sports') {
        require_method($method, ['GET']);
        respond(['success' => true, 'items' => sports_catalog_load()]);
    }

    // ---- Agenda --------------------------------------------------------
    if ($path === 'schedule') {
        require_method($method, ['GET']);
        $date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
        if (!valid_date($date)) {
            respond(['success' => false, 'error' => 'Fecha inválida (use AAAA-MM-DD)'], 400);
        }
        respond(['success' => true, 'server_time' => time(), 'date' => $date, 'events' => attach_links(manual_events_for_date($date))]);
    }

    // ---- Próximos (desde hoy, N días) ------------------------------------
    if ($path === 'upcoming') {
        require_method($method, ['GET']);
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 14;
        respond(['success' => true, 'server_time' => time(), 'events' => attach_links(manual_events_upcoming($days))]);
    }

    // ---- En vivo -------------------------------------------------------
    if ($path === 'live') {
        require_method($method, ['GET']);
        respond(['success' => true, 'server_time' => time(), 'events' => attach_links(manual_events_live())]);
    }

    // ---- Detalle de evento ----------------------------------------------
    if (preg_match('#^events/([A-Za-z0-9_.-]{1,80})$#', $path, $m)) {
        require_method($method, ['GET']);
        $mev = manual_event_by_key($m[1]);
        if ($mev === null) {
            respond(['success' => false, 'error' => 'Evento no disponible'], 404);
        }
        respond(['success' => true, 'event' => attach_links([$mev])[0]]);
    }

    // ---- Favoritos (por dispositivo) --------------------------------------
    if ($path === 'favorites') {
        require_method($method, ['GET', 'POST', 'DELETE']);
        $pdo = db();
        if (!$pdo) {
            respond(['success' => false, 'error' => 'Base de datos no disponible'], 503);
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : [];
        $device = (string)($body['device'] ?? ($_GET['device'] ?? ''));
        $eventId = (string)($body['event_id'] ?? ($_GET['event_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{8,120}$/', $device)) {
            respond(['success' => false, 'error' => 'device inválido (8-120 caracteres: letras, números, _ y -)'], 400);
        }
        try {
            if ($method === 'GET') {
                $st = $pdo->prepare('SELECT event_id FROM favorites WHERE device_key = ? ORDER BY id DESC');
                $st->execute([$device]);
                respond(['success' => true, 'favorites' => array_column($st->fetchAll(), 'event_id')]);
            }
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $eventId)) {
                respond(['success' => false, 'error' => 'event_id inválido'], 400);
            }
            if ($method === 'POST') {
                $pdo->prepare('INSERT IGNORE INTO favorites (device_key, event_id) VALUES (?, ?)')->execute([$device, $eventId]);
                respond(['success' => true]);
            }
            $pdo->prepare('DELETE FROM favorites WHERE device_key = ? AND event_id = ?')->execute([$device, $eventId]);
            respond(['success' => true]);
        } catch (PDOException $e) {
            respond(['success' => false, 'error' => 'Base de datos no instalada. Abre /Liveo/setup.php'] + error_detail($e), 503);
        }
    }

    respond(['success' => false, 'error' => 'Endpoint no encontrado'], 404);
} catch (Throwable $e) {
    respond(['success' => false, 'error' => 'No se pudo obtener información deportiva'] + error_detail($e), 502);
}
