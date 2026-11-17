<?php
declare(strict_types=1);

/**
 * Eventos propios de Liveo: se crean a mano desde el panel de administración
 * (deporte, competición, equipos, título, inicio y estado) y se mezclan con los
 * de Matchora en /api/live, /api/schedule y /api/events/{id}.
 *
 * Su id público es "manual-<n>", así que las señales (enlaces) se agregan con
 * el mismo mecanismo que los demás eventos.
 */

/** Crea la tabla de eventos propios si no existe (es seguro repetirlo). */
function ensure_manual_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS manual_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sport VARCHAR(80) NOT NULL DEFAULT \'\',
        league VARCHAR(160) NOT NULL DEFAULT \'\',
        home VARCHAR(120) NOT NULL,
        away VARCHAR(120) NOT NULL,
        title VARCHAR(200) NOT NULL DEFAULT \'\',
        start_ts INT UNSIGNED NOT NULL,
        state_mode VARCHAR(12) NOT NULL DEFAULT \'auto\',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_start (start_ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
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

/** Minutos que dura un evento propio (final automático). Por defecto 135. */
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
    $end = $start + $durationMin * 60;
    $mode = (string)$r['state_mode'];
    if ($mode === 'auto' || !array_key_exists($mode, manual_state_options())) {
        $mode = 'auto';
        $state = $now < $start ? 'scheduled' : ($now < $end ? 'live' : 'finished');
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
        'title'     => $title !== '' ? $title : $home . ' vs ' . $away,
        'kickoff'   => $start,
        'end'       => $end,
        'status'    => manual_state_label($state),
        'live'      => $state === 'live',
        // true = el estado se fijó a mano: la app no lo cambia por horario.
        'live_lock' => $mode !== 'auto',
    ];
    if (in_array($state, ['finished', 'postponed', 'cancelled'], true)) {
        $ev['state_label'] = manual_state_label($state);
    }
    return $ev;
}

/** Eventos propios visibles cuyo inicio cae en [$from, $to). */
function manual_events_between(int $from, int $to): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        $st = $pdo->prepare('SELECT * FROM manual_events WHERE active = 1 AND start_ts >= ? AND start_ts < ? ORDER BY start_ts ASC');
        $st->execute([$from, $to]);
        $dur = manual_duration_minutes($pdo);
        $now = time();
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = manual_row_to_event($r, $dur, $now);
        }
        return $out;
    } catch (Throwable $e) {
        // La tabla aún no existe: la API sigue funcionando solo con Matchora.
        return [];
    }
}

/** Eventos propios de un día (AAAA-MM-DD, en la zona horaria configurada). */
function manual_events_for_date(string $date): array
{
    $from = strtotime($date . ' 00:00:00');
    $to = strtotime($date . ' 00:00:00 +1 day');
    if ($from === false || $to === false) {
        return [];
    }
    return manual_events_between($from, $to);
}

/** Eventos propios que están en vivo ahora. */
function manual_events_live(): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        $dur = manual_duration_minutes($pdo);
        $now = time();
        $st = $pdo->prepare(
            "SELECT * FROM manual_events WHERE active = 1 AND (state_mode = 'live'
                OR (state_mode = 'auto' AND start_ts <= ? AND start_ts + ? > ?))
             ORDER BY start_ts ASC"
        );
        $st->execute([$now, $dur * 60, $now]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $ev = manual_row_to_event($r, $dur, $now);
            if (!empty($ev['live'])) {
                $out[] = $ev;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Un evento propio por su id público ("manual-12"), o null. */
function manual_event_by_key(string $key): ?array
{
    if (!preg_match('/^manual-(\d{1,10})$/', $key, $m)) {
        return null;
    }
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT * FROM manual_events WHERE id = ? AND active = 1');
        $st->execute([(int)$m[1]]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        return manual_row_to_event($r, manual_duration_minutes($pdo), time());
    } catch (Throwable $e) {
        return null;
    }
}
