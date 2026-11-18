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

/** Convierte una fila de manual_events al formato de evento de la API. */
function manual_row_to_event(array $r, int $durationMin, int $now): array
{
    $start = (int)$r['start_ts'];
    // Duración propia del evento (columna «hasta» del CSV); si no, la global.
    $own = (int)($r['duration_min'] ?? 0);
    $durationMin = $own > 0 ? $own : $durationMin;
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
        $ev['home_logo'] = $hl;
    }
    if ($al !== '') {
        $ev['away_logo'] = $al;
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
    $dur = manual_duration_minutes($pdo);
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
