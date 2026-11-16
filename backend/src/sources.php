<?php
declare(strict_types=1);

/**
 * Enlaces m3u8 propios de Liveo.
 *
 * Los eventos los genera Matchora; aquí solo se agregan enlaces (fuentes)
 * a esos eventos desde el panel /Liveo/admin/. La API los adjunta a cada
 * evento en el campo "links".
 */

/** Crea las tablas necesarias si no existen (es seguro repetirlo). */
function ensure_sources_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS event_sources (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id VARCHAR(80) NOT NULL,
        label VARCHAR(60) NOT NULL,
        url VARCHAR(1000) NOT NULL,
        referer VARCHAR(500) NULL,
        user_agent VARCHAR(300) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        home VARCHAR(120) NULL,
        away VARCHAR(120) NULL,
        league VARCHAR(160) NULL,
        kickoff INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_event (event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = true;
}

/** Enlaces activos por evento: [event_id => [[id,label,url,referer,user_agent], ...]] */
function sources_for_events(array $eventIds): array
{
    $ids = [];
    foreach ($eventIds as $id) {
        if (is_scalar($id) && (string)$id !== '') {
            $ids[(string)$id] = true;
        }
    }
    $ids = array_keys($ids);
    if (!$ids) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            'SELECT id, event_id, label, url, referer, user_agent FROM event_sources
             WHERE active = 1 AND event_id IN (' . $marks . ')
             ORDER BY sort_order ASC, id ASC'
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $item = [
                'id'    => (int)$row['id'],
                'label' => (string)$row['label'],
                'url'   => (string)$row['url'],
            ];
            if ($row['referer'] !== null && $row['referer'] !== '') {
                $item['referer'] = (string)$row['referer'];
            }
            if ($row['user_agent'] !== null && $row['user_agent'] !== '') {
                $item['user_agent'] = (string)$row['user_agent'];
            }
            $out[(string)$row['event_id']][] = $item;
        }
        return $out;
    } catch (Throwable $e) {
        // Tabla aún no creada: la API sigue funcionando sin enlaces propios.
        return [];
    }
}

/** Agrega "links" (enlaces m3u8 del panel) a cada evento normalizado. */
function attach_links(array $events): array
{
    $ids = [];
    foreach ($events as $e) {
        if (isset($e['id'])) {
            $ids[] = (string)$e['id'];
        }
    }
    $map = sources_for_events($ids);
    foreach ($events as $i => $e) {
        $events[$i]['links'] = $map[(string)($e['id'] ?? '')] ?? [];
    }
    return $events;
}

/**
 * Comprueba una lista HLS desde el servidor.
 * Devuelve ['ok' => bool, 'http' => int, 'note' => string].
 */
function probe_m3u8(string $url, ?string $referer = null, ?string $userAgent = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'note' => 'Falta la extensión curl de PHP.'];
    }
    $c = liveo_config();
    $verify = !empty($c['ssl_verify']);
    $buf = '';
    $headers = ['Accept: */*'];
    if ($referer !== null && $referer !== '') {
        $headers[] = 'Referer: ' . $referer;
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => ($userAgent !== null && $userAgent !== '') ? $userAgent : 'Liveo/' . LIVEO_VERSION,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_WRITEFUNCTION  => function ($handle, $chunk) use (&$buf) {
            $buf .= $chunk;
            // Con 256 KB sobra para leer cualquier lista; se corta la descarga.
            return strlen($buf) > 262144 ? 0 : strlen($chunk);
        },
    ];
    if (!empty($c['ca_bundle'])) {
        $opts[CURLOPT_CAINFO] = (string)$c['ca_bundle'];
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($buf === '') {
        return ['ok' => false, 'http' => $http, 'note' => $err !== '' ? 'Sin respuesta: ' . $err : 'El servidor no devolvió contenido.'];
    }
    if ($http >= 400) {
        return ['ok' => false, 'http' => $http, 'note' => 'El servidor respondió HTTP ' . $http . '. Revisa la URL, el Referer o el User-Agent.'];
    }
    $head = ltrim($buf, "\xEF\xBB\xBF \t\r\n");
    if (strncmp($head, '#EXTM3U', 7) !== 0) {
        return ['ok' => false, 'http' => $http, 'note' => 'La respuesta no es una lista HLS (no empieza con #EXTM3U).'];
    }
    $master = strpos($buf, '#EXT-X-STREAM-INF') !== false;
    $ended = strpos($buf, '#EXT-X-ENDLIST') !== false;
    if ($master) {
        $note = 'Lista HLS válida (maestra con varias calidades).';
    } elseif ($ended) {
        $note = 'Lista HLS válida, pero es un video grabado (tiene #EXT-X-ENDLIST), no una transmisión en vivo.';
    } else {
        $note = 'Lista HLS válida y en vivo.';
    }
    return ['ok' => true, 'http' => $http, 'note' => $note . ' (HTTP ' . $http . ')'];
}
