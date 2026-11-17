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

/**
 * Eventos de Matchora. Si el proveedor falla devuelve [[], $error] para poder
 * mostrar igualmente los eventos propios del panel.
 */
function provider_event_list(string $path, string $ttlKey): array
{
    try {
        $data = provider($path, $ttlKey);
        return [normalize_events(extract_list($data, 'events')), null];
    } catch (Throwable $e) {
        return [[], $e];
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
        $out = [
            'success'     => true,
            'application' => 'Liveo',
            'version'     => LIVEO_VERSION,
            'php'         => PHP_VERSION,
            'database'    => $dbStatus,
            'provider'    => 'Matchora',
            'time'        => date('c'),
        ];
        if (!empty($_GET['deep'])) {
            try {
                provider('live', 'live');
                $out['provider_status'] = 'ok';
            } catch (Throwable $e) {
                $out['provider_status'] = 'error';
                $out += error_detail($e);
            }
        }
        respond($out);
    }

    // ---- Agenda --------------------------------------------------------
    if ($path === 'schedule') {
        require_method($method, ['GET']);
        $date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
        if (!valid_date($date)) {
            respond(['success' => false, 'error' => 'Fecha inválida (use AAAA-MM-DD)'], 400);
        }
        $params = ['date' => $date];
        foreach (['watchable', 'live'] as $k) {
            if (isset($_GET[$k]) && is_scalar($_GET[$k])) {
                $params[$k] = (string)$_GET[$k];
            }
        }
        [$list, $err] = provider_event_list('schedule?' . http_build_query($params), 'schedule');
        $manual = manual_events_for_date($date);
        if ($err !== null && !$manual) {
            throw $err;
        }
        respond(['success' => true, 'server_time' => time(), 'date' => $date, 'events' => attach_links(array_merge($manual, $list))]);
    }

    // ---- En vivo -------------------------------------------------------
    if ($path === 'live') {
        require_method($method, ['GET']);
        [$list, $err] = provider_event_list('live', 'live');
        $manual = manual_events_live();
        if ($err !== null && !$manual) {
            throw $err;
        }
        respond(['success' => true, 'server_time' => time(), 'events' => attach_links(array_merge($manual, $list))]);
    }

    // ---- Canales -------------------------------------------------------
    if ($path === 'channels') {
        require_method($method, ['GET']);
        $data = provider('channels', 'channels');
        $channels = extract_list($data, 'channels');
        $channels = array_values(array_filter($channels, function ($channel) {
            return !is_array($channel) || !is_cricket_event($channel);
        }));
        respond(['success' => true, 'channels' => $channels]);
    }

    // ---- Detalle de evento ----------------------------------------------
    if (preg_match('#^events/([A-Za-z0-9_.-]{1,80})$#', $path, $m)) {
        require_method($method, ['GET']);
        if (strncmp($m[1], 'manual-', 7) === 0) {
            $mev = manual_event_by_key($m[1]);
            if ($mev === null) {
                respond(['success' => false, 'error' => 'Evento no disponible'], 404);
            }
            respond(['success' => true, 'event' => attach_links([$mev])[0]]);
        }
        $data = provider('events/' . rawurlencode($m[1]), 'event');
        $ev = (isset($data['event']) && is_array($data['event'])) ? $data['event'] : $data;
        if (is_excluded_event($ev)) {
            respond(['success' => false, 'error' => 'Evento no disponible'], 404);
        }
        respond(['success' => true, 'event' => attach_links([normalize_event($ev)])[0]]);
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
