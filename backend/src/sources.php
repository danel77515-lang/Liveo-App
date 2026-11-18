<?php
declare(strict_types=1);

/**
 * Enlaces m3u8 propios de Liveo.
 *
 * Los eventos son propios (manual-N, cargados a mano o por CSV desde el panel
 * /Liveo/admin/); aquí se guardan sus enlaces (fuentes). La API los adjunta a
 * cada evento en el campo "links".
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
        kind VARCHAR(8) NOT NULL DEFAULT \'hls\',
        embed TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_event (event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Instalaciones anteriores: tipo de enlace (hls = m3u8, iframe = página web embebida).
    if (!$pdo->query("SHOW COLUMNS FROM event_sources LIKE 'kind'")->fetch()) {
        $pdo->exec("ALTER TABLE event_sources ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'hls'");
    }
    // Código <iframe> limpio del enlace (para que la app lo cargue tal cual).
    if (!$pdo->query("SHOW COLUMNS FROM event_sources LIKE 'embed'")->fetch()) {
        $pdo->exec('ALTER TABLE event_sources ADD COLUMN embed TEXT NULL');
    }
    ensure_manual_schema($pdo);
    // Limpieza de la integración anterior (RockyLive): enlaces y caché de eventos "rl-…".
    try {
        $pdo->exec("DELETE FROM event_sources WHERE event_id LIKE 'rl-%'");
        $pdo->exec("DELETE FROM api_cache WHERE cache_key LIKE 'rl:%' OR cache_key LIKE 'p:%'");
    } catch (Throwable $e) {
        // La caché es opcional.
    }
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
            'SELECT * FROM event_sources
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
                'type'  => (string)$row['kind'] === 'iframe' ? 'iframe' : 'hls',
            ];
            $embed = trim((string)($row['embed'] ?? ''));
            if ($item['type'] === 'iframe' && $embed !== '') {
                $item['embed'] = $embed;
            }
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

/**
 * Lee un código <iframe …> pegado tal cual (con comillas dobles, simples o sin comillas,
 * en varias líneas, con entidades HTML…). Devuelve ['src' => url, 'html' => iframe limpio]
 * o null si no hay src. Solo se conservan atributos conocidos: nada de scripts ni eventos.
 */
function parse_iframe_code(string $raw): ?array
{
    if (preg_match('/<iframe\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/is', $raw, $tag) !== 1) {
        return null;
    }
    $attrs = [];
    preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/s', $tag[1], $mm, PREG_SET_ORDER);
    foreach ($mm as $m) {
        $name = strtolower($m[1]);
        $val = $m[2] ?? '';
        if ($val === '' && isset($m[3]) && $m[3] !== '') {
            $val = $m[3];
        }
        if ($val === '' && isset($m[4]) && $m[4] !== '') {
            $val = $m[4];
        }
        if (!isset($attrs[$name])) {
            $attrs[$name] = html_entity_decode(trim($val), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $src = $attrs['src'] ?? '';
    if ($src === '' || strncasecmp($src, 'about:', 6) === 0 || strncasecmp($src, 'javascript:', 11) === 0) {
        $src = $attrs['data-src'] ?? '';
    }
    $src = trim($src);
    if (strncmp($src, '//', 2) === 0) {
        $src = 'https:' . $src;
    }
    if ($src === '' || preg_match('#^https?://\S+$#i', $src) !== 1) {
        return null;
    }
    $e = function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $allow = trim($attrs['allow'] ?? '');
    if ($allow === '') {
        $allow = 'autoplay; fullscreen; encrypted-media; picture-in-picture';
    }
    $html = '<iframe src="' . $e($src) . '" allow="' . $e($allow) . '" allowfullscreen';
    foreach (['referrerpolicy', 'scrolling', 'frameborder', 'sandbox'] as $k) {
        if (isset($attrs[$k]) && $attrs[$k] !== '' && preg_match('/^[A-Za-z0-9 _\-]{1,120}$/', $attrs[$k]) === 1) {
            $html .= ' ' . $k . '="' . $e($attrs[$k]) . '"';
        }
    }
    $html .= '></iframe>';
    return ['src' => $src, 'html' => $html];
}

/**
 * Interpreta lo que se pega como enlace: una URL (m3u8 o página web) o el código
 * <iframe …> completo (se guarda el iframe y se toma su src como URL).
 * $choice: 'auto' | 'hls' | 'iframe'. Devuelve ['url', 'kind', 'embed', 'err'].
 */
function parse_source_input(string $raw, string $choice = 'auto'): array
{
    $raw = trim($raw);
    $kind = null;
    $embed = '';
    $url = $raw;
    if (stripos($raw, '<iframe') !== false) {
        $ifr = parse_iframe_code($raw);
        if ($ifr === null) {
            return ['url' => '', 'kind' => 'iframe', 'embed' => '', 'err' => 'No encontré un src http(s) válido dentro del código <iframe>.'];
        }
        $url = $ifr['src'];
        $embed = $ifr['html'];
        $kind = 'iframe';
    }
    if (strncmp($url, '//', 2) === 0) {
        $url = 'https:' . $url;
    }
    if ($url === '' || strlen($url) > 1000 || preg_match('#^https?://\S+$#i', $url) !== 1) {
        return ['url' => '', 'kind' => 'hls', 'embed' => '', 'err' => 'Pega una URL que empiece con http:// o https:// (sin espacios, máximo 1000 caracteres) o el código <iframe …>.'];
    }
    if ($kind === null) {
        if ($choice === 'hls' || $choice === 'iframe') {
            $kind = $choice;
        } else {
            $kind = stripos($url, '.m3u8') !== false ? 'hls' : 'iframe';
        }
    }
    return ['url' => $url, 'kind' => $kind, 'embed' => $embed, 'err' => null];
}

/** Comprueba que una página (iframe) responda. */
function probe_page(string $url, ?string $referer = null, ?string $userAgent = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'note' => 'Falta la extensión curl de PHP.'];
    }
    $c = liveo_config();
    $verify = !empty($c['ssl_verify']);
    $headers = ['Accept: text/html,*/*'];
    if ($referer !== null && $referer !== '') {
        $headers[] = 'Referer: ' . $referer;
    }
    $ch = curl_init($url);
    $got = 0;
    $opts = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => ($userAgent !== null && $userAgent !== '') ? $userAgent : 'Mozilla/5.0 (Linux; Android 13) Liveo/' . LIVEO_VERSION,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_WRITEFUNCTION  => function ($handle, $chunk) use (&$got) {
            $got += strlen($chunk);
            return $got > 65536 ? 0 : strlen($chunk);
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
    if ($http === 0) {
        return ['ok' => false, 'http' => 0, 'note' => 'Sin respuesta: ' . ($err !== '' ? $err : 'no se pudo conectar') . '.'];
    }
    if ($http >= 400) {
        return ['ok' => false, 'http' => $http, 'note' => 'La página respondió HTTP ' . $http . '. Revisa la URL o el Referer.'];
    }
    return ['ok' => true, 'http' => $http, 'note' => 'La página responde (HTTP ' . $http . '). En la app se abre dentro del reproductor.'];
}

/** Agrega "links" (enlaces m3u8 / iframe del panel) a cada evento normalizado. */
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
