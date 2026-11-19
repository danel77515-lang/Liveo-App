<?php
declare(strict_types=1);

/**
 * Eventos de Liveo (sistema manual).
 *
 * Se cargan desde el panel de administración, a mano o subiendo un CSV
 * (ver importer.php). Cada evento tiene deporte, competición, equipos, inicio,
 * estado, minuto (solo mientras está en vivo) y escudos opcionales.
 *
 * Su id público es "manual-<n>", y las señales (enlaces m3u8) se agregan con
 * el mismo mecanismo de siempre (event_sources).
 */

/** Crea / actualiza las tablas de eventos (es seguro repetirlo). */
function ensure_manual_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS manual_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sport VARCHAR(80) NOT NULL DEFAULT \'\',
        league VARCHAR(160) NOT NULL DEFAULT \'\',
        home VARCHAR(120) NOT NULL,
        away VARCHAR(120) NOT NULL DEFAULT \'\',
        title VARCHAR(200) NOT NULL DEFAULT \'\',
        start_ts INT UNSIGNED NOT NULL,
        state_mode VARCHAR(12) NOT NULL DEFAULT \'auto\',
        active TINYINT(1) NOT NULL DEFAULT 1,
        minute VARCHAR(12) NULL,
        home_logo VARCHAR(500) NULL,
        away_logo VARCHAR(500) NULL,
        time_tbc TINYINT(1) NOT NULL DEFAULT 0,
        duration_min SMALLINT UNSIGNED NULL,
        import_key VARCHAR(40) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_start (start_ts),
        UNIQUE KEY uq_import (import_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Instalaciones anteriores: agrega lo que falte.
    $have = [];
    $awayDefault = null;
    foreach ($pdo->query('SHOW COLUMNS FROM manual_events')->fetchAll() as $c) {
        $have[(string)$c['Field']] = true;
        if ((string)$c['Field'] === 'away') {
            $awayDefault = $c['Default'];
        }
    }
    $add = [
        'minute'     => 'VARCHAR(12) NULL',
        'home_logo'  => 'VARCHAR(500) NULL',
        'away_logo'  => 'VARCHAR(500) NULL',
        'time_tbc'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        'duration_min' => 'SMALLINT UNSIGNED NULL',
        'import_key' => 'VARCHAR(40) NULL',
    ];
    foreach ($add as $col => $def) {
        if (!isset($have[$col])) {
            $pdo->exec('ALTER TABLE manual_events ADD COLUMN `' . $col . '` ' . $def);
        }
    }
    $idx = $pdo->query("SHOW INDEX FROM manual_events WHERE Key_name = 'uq_import'")->fetchAll();
    if (!$idx) {
        $pdo->exec('ALTER TABLE manual_events ADD UNIQUE KEY uq_import (import_key)');
    }
    // El visitante pasa a ser opcional (eventos de un solo título: ciclismo, F1…).
    if ($awayDefault === null) {
        $pdo->exec("ALTER TABLE manual_events MODIFY away VARCHAR(120) NOT NULL DEFAULT ''");
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS team_logos (
        name_key VARCHAR(120) NOT NULL PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        url VARCHAR(500) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = true;
}

/** Nombre de equipo -> clave para comparar (minúsculas, sin acentos ni signos). */
function team_key(string $name): string
{
    $from = 'áàäâãåéèëêíìïîóòöôõúùüûñçÁÀÄÂÃÅÉÈËÊÍÌÏÎÓÒÖÔÕÚÙÜÛÑÇ';
    $to   = 'aaaaaaeeeeiiiiooooouuuuncAAAAAAEEEEIIIIOOOOOUUUUNC';
    $chars = preg_split('//u', $from, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $repl  = str_split($to);
    $map = [];
    foreach ($chars as $i => $ch) {
        $map[$ch] = $repl[$i] ?? $ch;
    }
    $t = strtr($name, $map);
    $t = strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? $t;
    return substr(trim($t), 0, 120);
}

/** URL base pública de Liveo (http://host/Liveo), sacada de la petición actual. */
function liveo_public_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
        $host = 'localhost';
    }
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = preg_replace('#/(api|admin)$#', '', $dir) ?? $dir;
    $dir = preg_replace('#/public$#', '', $dir) ?? $dir;
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($dir === '.' ? '' : $dir, '/');
}

/** Escudo guardado -> URL que entiende la app (los subidos son relativos: uploads/logos/…). */
function logo_public_url(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') {
        return '';
    }
    if (strncmp($stored, 'uploads/', 8) === 0) {
        return liveo_public_base() . '/' . $stored;
    }
    return $stored;
}

/** Carpeta donde se guardan los escudos subidos. */
function logo_dir(): string
{
    return dirname(__DIR__) . '/uploads/logos';
}

/** Guarda el escudo de un equipo para siempre (todos sus partidos, presentes y futuros). */
function remember_team_logo(PDO $pdo, string $name, string $url): void
{
    $key = team_key($name);
    $url = trim($url);
    if ($key === '' || $url === '') {
        return;
    }
    $pdo->prepare('REPLACE INTO team_logos (name_key, name, url) VALUES (?, ?, ?)')
        ->execute([$key, substr(trim($name), 0, 120), substr($url, 0, 500)]);
}

/**
 * Guarda una imagen subida como escudo del equipo.
 * Devuelve ['ok' => bool, 'url' => 'uploads/logos/…', 'error' => string].
 */
function save_team_logo_upload(PDO $pdo, string $teamName, array $file): array
{
    $key = team_key($teamName);
    if ($key === '') {
        return ['ok' => false, 'url' => '', 'error' => 'Nombre de equipo vacío.'];
    }
    if ((int)($file['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        return ['ok' => false, 'url' => '', 'error' => 'No se pudo recibir el archivo.'];
    }
    if ((int)$file['size'] > 1536 * 1024) {
        return ['ok' => false, 'url' => '', 'error' => 'La imagen supera 1,5 MB.'];
    }
    $info = @getimagesize((string)$file['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
    if ($ext === null) {
        return ['ok' => false, 'url' => '', 'error' => 'Solo se aceptan imágenes PNG, JPG, WEBP o GIF.'];
    }
    $dir = logo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'url' => '', 'error' => 'No se pudo crear la carpeta uploads/logos.'];
    }
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $key), '-');
    $name = $slug . '-' . substr((string)sha1_file((string)$file['tmp_name']), 0, 8) . '.' . $ext;
    if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'url' => '', 'error' => 'No se pudo guardar la imagen en uploads/logos.'];
    }
    // Borra el escudo subido anterior del mismo equipo.
    $st = $pdo->prepare('SELECT url FROM team_logos WHERE name_key = ?');
    $st->execute([$key]);
    $old = (string)($st->fetchColumn() ?: '');
    if (strncmp($old, 'uploads/logos/', 14) === 0 && basename($old) !== $name) {
        @unlink($dir . '/' . basename($old));
    }
    return ['ok' => true, 'url' => 'uploads/logos/' . $name, 'error' => ''];
}

/** [clave => url] de los escudos guardados por equipo (una consulta por petición). */
function team_logo_map(?PDO $pdo = null): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $pdo = $pdo ?: db();
    if (!$pdo) {
        return $map;
    }
    try {
        foreach ($pdo->query('SELECT name_key, url FROM team_logos')->fetchAll() as $r) {
            $map[(string)$r['name_key']] = (string)$r['url'];
        }
    } catch (Throwable $e) {
        // Tabla aún no creada.
    }
    return $map;
}

/** Estados que se pueden elegir en el panel. */
function manual_state_options(): array
{
    return [
        'auto'      => 'Automático por horario',
        'scheduled' => 'Programado',
        'live'      => 'En vivo',
        'finished'  => 'Finalizado',
        'postponed' => 'Postergado',
        'cancelled' => 'Cancelado',
    ];
}

function manual_state_label(string $state): string
{
    $map = [
        'scheduled' => 'Programado',
        'live'      => 'En vivo',
        'finished'  => 'Finalizado',
        'postponed' => 'Postergado',
        'cancelled' => 'Cancelado',
    ];
    return $map[$state] ?? 'Programado';
}

/** Minutos que dura un evento (final automático). Por defecto 135. */
function manual_duration_minutes(?PDO $pdo = null): int
{
    if ($pdo === null) {
        $pdo = db();
    }
    $min = 135;
    if ($pdo) {
        try {
            $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
            $st->execute(['manual_event_duration']);
            $v = $st->fetchColumn();
            if ($v !== false && is_numeric($v)) {
                $min = (int)$v;
            }
        } catch (Throwable $e) {
            // Sin tabla de ajustes: se usa el valor por defecto.
        }
    }
    return max(30, min(720, $min));
}

/** Minutos que dura un partido de básquet (NBA, etc.). Por defecto 180. */
function manual_basket_duration_minutes(?PDO $pdo = null): int
{
    static $cache = null;
    if ($pdo === null) {
        $pdo = db();
    }
    if ($cache !== null) {
        return $cache;
    }
    $min = 180;
    if ($pdo) {
        try {
            $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
            $st->execute(['manual_event_duration_basket']);
            $v = $st->fetchColumn();
            if ($v !== false && is_numeric($v)) {
                $min = (int)$v;
            }
        } catch (Throwable $e) {
            // Sin tabla de ajustes: se usa el valor por defecto.
        }
    }
    $cache = max(60, min(720, $min));
    return $cache;
}

/** true si el deporte/liga es básquet (NBA, baloncesto, basketball…). */
function manual_is_basketball(string $sport, string $league = ''): bool
{
    $t = mb_strtolower($sport . ' ' . $league, 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    return preg_match('/basq|basket|baloncesto|\bnba\b|wnba|euroliga|euroleague|\bfiba\b|liga nacional de basq/', $t) === 1;
}

/** Duración automática según el deporte (si no hay una propia del evento). */
function manual_sport_duration_minutes(string $sport, string $league, int $globalMin, ?PDO $pdo = null): int
{
    if (manual_is_basketball($sport, $league)) {
        return manual_basket_duration_minutes($pdo);
    }
    return $globalMin;
}

/** Convierte una fila de manual_events al formato de evento de la API. */
function manual_row_to_event(array $r, int $durationMin, int $now): array
{
    $start = (int)$r['start_ts'];
    // Duración propia del evento (columna «hasta» del CSV); si no, la global.
    // Sin duración propia: la del deporte (el básquet dura más que el fútbol) o la global.
    $own = (int)($r['duration_min'] ?? 0);
    $durationMin = $own > 0
        ? $own
        : manual_sport_duration_minutes((string)($r['sport'] ?? ''), (string)($r['league'] ?? ''), $durationMin);
    $end = $start + $durationMin * 60;
    $tbc = !empty($r['time_tbc']);
    $mode = (string)$r['state_mode'];
    if ($mode === 'auto' || !array_key_exists($mode, manual_state_options())) {
        $mode = 'auto';
        // Sin hora confirmada no se puede arrancar solo.
        $state = ($tbc || $now < $start) ? 'scheduled' : ($now < $end ? 'live' : 'finished');
    } else {
        $state = $mode;
    }

    $home = (string)$r['home'];
    $away = (string)$r['away'];
    $sport = (string)$r['sport'];
    $league = (string)$r['league'];
    $title = trim((string)$r['title']);

    $ev = [
        'id'        => 'manual-' . (int)$r['id'],
        'manual'    => true,
        'sport'     => $sport,
        'league'    => $league !== '' ? $league : ($sport !== '' ? $sport : 'Evento'),
        'home'      => $home,
        'away'      => $away,
        'title'     => $title !== '' ? $title : ($away !== '' ? $home . ' vs ' . $away : $home),
        'kickoff'   => $start,
        'end'       => $end,
        'status'    => manual_state_label($state),
        'live'      => $state === 'live',
        // true = el estado no depende del horario: la app no lo cambia sola.
        'live_lock' => $mode !== 'auto' || $tbc,
        'time_tbc'  => $tbc,
    ];
    if ($away === '') {
        $ev['single_title'] = true;
    }
    if (in_array($state, ['finished', 'postponed', 'cancelled'], true)) {
        $ev['state_label'] = manual_state_label($state);
    }
    // El minuto solo tiene sentido mientras el evento está en vivo.
    $minute = trim((string)($r['minute'] ?? ''));
    if ($state === 'live' && $minute !== '') {
        $ev['minute'] = $minute;
    }
    // Escudos: el del evento manda; si no, el guardado para ese equipo.
    $logos = team_logo_map();
    $hl = trim((string)($r['home_logo'] ?? ''));
    $al = trim((string)($r['away_logo'] ?? ''));
    if ($hl === '' && $home !== '') {
        $hl = $logos[team_key($home)] ?? '';
    }
    if ($al === '' && $away !== '') {
        $al = $logos[team_key($away)] ?? '';
    }
    if ($hl !== '') {
        $ev['home_logo'] = logo_public_url($hl);
    }
    if ($al !== '') {
        $ev['away_logo'] = logo_public_url($al);
    }
    return $ev;
}

/** Ejecuta una consulta de eventos y los convierte al formato de la API. */
function manual_query_events(string $sql, array $params): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        ensure_manual_schema($pdo);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $dur = manual_duration_minutes($pdo);
        $now = time();
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = manual_row_to_event($r, $dur, $now);
        }
        return $out;
    } catch (Throwable $e) {
        // La base aún no está instalada: la API responde vacía.
        return [];
    }
}

/** Eventos visibles cuyo inicio cae en [$from, $to). */
function manual_events_between(int $from, int $to): array
{
    return manual_query_events(
        'SELECT * FROM manual_events WHERE active = 1 AND start_ts >= ? AND start_ts < ? ORDER BY start_ts ASC, id ASC',
        [$from, $to]
    );
}

/** Eventos de un día (AAAA-MM-DD, en la zona horaria configurada). */
function manual_events_for_date(string $date): array
{
    $tz = new DateTimeZone((string)liveo_config()['timezone']);
    $from = (new DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp();
    $to = (new DateTimeImmutable($date . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
    return manual_events_between($from, $to);
}

/** Eventos desde hoy (00:00) hasta dentro de $days días. */
function manual_events_upcoming(int $days): array
{
    $tz = new DateTimeZone((string)liveo_config()['timezone']);
    $today = new DateTimeImmutable('today', $tz);
    $from = $today->getTimestamp();
    $to = $today->modify('+' . max(1, min(120, $days)) . ' days')->getTimestamp();
    return manual_events_between($from, $to);
}

/** Eventos que están en vivo ahora. */
function manual_events_live(): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $now = time();
    // El SQL solo preselecciona (con la duración más larga posible); el corte exacto
    // por deporte lo hace manual_row_to_event() y el filtro de abajo.
    $dur = max(manual_duration_minutes($pdo), manual_basket_duration_minutes($pdo));
    return array_values(array_filter(manual_query_events(
        "SELECT * FROM manual_events WHERE active = 1 AND (state_mode = 'live'
            OR (state_mode = 'auto' AND time_tbc = 0 AND start_ts <= ? AND start_ts + COALESCE(NULLIF(duration_min, 0), ?) * 60 > ?))
         ORDER BY start_ts ASC, id ASC",
        [$now, $dur, $now]
    ), function ($ev) {
        return !empty($ev['live']);
    }));
}

/** Un evento por su id público ("manual-12"), o null. */
function manual_event_by_key(string $key): ?array
{
    if (!preg_match('/^manual-(\d{1,10})$/', $key, $m)) {
        return null;
    }
    $list = manual_query_events('SELECT * FROM manual_events WHERE id = ? AND active = 1', [(int)$m[1]]);
    return $list[0] ?? null;
}
