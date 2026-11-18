<?php
declare(strict_types=1);

/**
 * Importación de eventos desde CSV (panel de administración → Importar CSV).
 *
 * Acepta el formato del calendario de ejemplo:
 *   fecha, dia, hora_Argentina_ART, deporte, competicion, evento,
 *   estado_horario, detalle, fuente
 * y también columnas opcionales:
 *   local, visitante, escudo_local, escudo_visitante, estado, minuto
 *
 * Reglas:
 * - "evento" con formato «A vs B» se separa en local y visitante; si no, es un
 *   evento de un solo título (carrera, etapa, jornada…).
 * - Sin hora, el evento queda con «hora a confirmar» (no arranca solo).
 * - Volver a subir el mismo CSV actualiza los eventos en lugar de duplicarlos
 *   (clave: fecha + competición + evento). No pisa el estado, el minuto, la
 *   visibilidad ni una hora ya confirmada a mano.
 */

// XAMPP trae mbstring; esto solo evita un error fatal si estuviera desactivada.
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null): string
    {
        $c = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($c, $start, $length));
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int
    {
        return count(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}

/** Encabezado -> minúsculas, sin acentos, con guiones bajos. */
function csv_header_key(string $h): string
{
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
    $h = strtolower(trim($h));
    $h = strtr($h, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
    ]);
    $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;
    return trim($h, '_');
}

/** Nombre canónico de una columna, o null si se ignora. */
function csv_canonical_column(string $key): ?string
{
    if ($key === 'estado_horario') {
        return 'estado_horario';
    }
    if ($key === 'hora_fin') {
        return 'hasta';
    }
    if (strncmp($key, 'hora', 4) === 0 || $key === 'time') {
        return 'hora';
    }
    $map = [
        'fecha' => 'fecha', 'date' => 'fecha',
        'deporte' => 'deporte', 'sport' => 'deporte',
        'competicion' => 'liga', 'competencia' => 'liga', 'liga' => 'liga', 'league' => 'liga', 'torneo' => 'liga',
        'evento' => 'evento', 'partido' => 'evento', 'titulo' => 'evento', 'title' => 'evento', 'event' => 'evento',
        'local' => 'local', 'equipo_local' => 'local', 'home' => 'local',
        'visitante' => 'visitante', 'equipo_visitante' => 'visitante', 'away' => 'visitante',
        'escudo_local' => 'escudo_local', 'logo_local' => 'escudo_local', 'home_logo' => 'escudo_local',
        'escudo_visitante' => 'escudo_visitante', 'logo_visitante' => 'escudo_visitante', 'away_logo' => 'escudo_visitante',
        'estado' => 'estado', 'state' => 'estado', 'status' => 'estado',
        'minuto' => 'minuto', 'minute' => 'minuto',
        'hasta' => 'hasta', 'fin' => 'hasta', 'hora_fin' => 'hasta',
        'duracion_min' => 'duracion', 'duracion' => 'duracion',
        'equipo' => 'equipo', 'team' => 'equipo', 'nombre' => 'equipo',
        'escudo' => 'escudo', 'logo' => 'escudo', 'url' => 'escudo',
    ];
    return $map[$key] ?? null;
}

/** Lee un CSV (UTF-8 o Windows-1252, coma o punto y coma) -> filas asociativas. */
function csv_read_rows(string $path, ?string &$error = null): array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        $error = 'El archivo está vacío o no se pudo leer.';
        return [];
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        $raw = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'Windows-1252') : utf8_encode($raw);
    }
    $firstLine = strtok($raw, "\n") ?: '';
    $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $header = fgetcsv($fh, 0, $delim, '"', '');
    if (!is_array($header)) {
        fclose($fh);
        $error = 'No se encontró la fila de encabezados.';
        return [];
    }
    $cols = [];
    foreach ($header as $i => $h) {
        $canon = csv_canonical_column(csv_header_key((string)$h));
        if ($canon !== null && !in_array($canon, $cols, true)) {
            $cols[$i] = $canon;
        }
    }
    $rows = [];
    while (($line = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($line === [null] || count(array_filter($line, function ($x) { return trim((string)$x) !== ''; })) === 0) {
            continue;
        }
        $row = [];
        foreach ($cols as $i => $name) {
            $row[$name] = trim((string)($line[$i] ?? ''));
        }
        $rows[] = $row;
        if (count($rows) > 5000) {
            $error = 'El CSV supera las 5000 filas; se leyeron las primeras 5000.';
            break;
        }
    }
    fclose($fh);
    return $rows;
}

/** "2026-11-21" o "21/11/2026" -> "2026-11-21", o null. */
function csv_parse_date(string $v): ?string
{
    $v = trim($v);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m) === 1) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})/', $v, $m) === 1) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** "16:45", "9:00", "16:45 hs" -> "16:45", o null si no hay hora. */
function csv_parse_time(string $v): ?string
{
    if (preg_match('/^\s*(\d{1,2})[:.hH](\d{2})/', $v, $m) !== 1) {
        return null;
    }
    $h = (int)$m[1];
    $mi = (int)$m[2];
    return ($h <= 23 && $mi <= 59) ? sprintf('%02d:%02d', $h, $mi) : null;
}

/** Texto del estado -> clave interna, o null si está vacío / no se reconoce. */
function csv_parse_state(string $v): ?string
{
    $k = csv_header_key($v);
    $map = [
        'auto' => 'auto', 'automatico' => 'auto',
        'programado' => 'scheduled', 'scheduled' => 'scheduled',
        'en_vivo' => 'live', 'vivo' => 'live', 'live' => 'live',
        'finalizado' => 'finished', 'finished' => 'finished',
        'postergado' => 'postponed', 'postponed' => 'postponed',
        'cancelado' => 'cancelled', 'cancelled' => 'cancelled',
    ];
    return $map[$k] ?? null;
}

/**
 * Duración en minutos de un evento: columna «hasta» (hora de fin, HH:MM; si es
 * menor que la de inicio cruza la medianoche) o «duracion_min». null = usar la
 * duración general del panel.
 */
function csv_duration_minutes(?string $start, string $until, string $minutes): ?int
{
    if (preg_match('/^\s*(\d{1,4})\s*$/', $minutes, $m) === 1 && (int)$m[1] >= 1) {
        return min(720, (int)$m[1]);
    }
    $end = csv_parse_time($until);
    if ($start === null || $end === null) {
        return null;
    }
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    $d = ($eh * 60 + $em) - ($sh * 60 + $sm);
    if ($d <= 0) {
        $d += 1440;
    }
    return min(720, $d);
}

/** "A vs B" / "A vs. B" -> [A, B]; si no hay rivales, null. */
function csv_split_teams(string $evento): ?array
{
    if (preg_match('/^(.+?)\s+vs\.?\s+(.+)$/iu', $evento, $m) === 1) {
        return [trim($m[1]), trim($m[2])];
    }
    return null;
}

/** Filas informativas (ventanas, períodos, jornadas sin partidos): no son eventos. */
function csv_is_info_row(array $row, bool $hasTeams, bool $hasTime): bool
{
    if ($hasTeams || $hasTime) {
        return false;
    }
    $k = csv_header_key((string)($row['estado_horario'] ?? ''));
    return preg_match('/no_aplica|por_confirmar|no_individualizados|no_volcados|segun_fixture/', $k) === 1;
}

function csv_clean_url(string $u): string
{
    $u = trim($u);
    return preg_match('#^https?://\S{4,490}$#i', $u) === 1 ? $u : '';
}

/**
 * Importa las filas a manual_events.
 * Devuelve [nuevos, actualizados, omitidos (informativas), errores[]].
 */
function manual_import_rows(PDO $pdo, array $rows, bool $skipInfo = true): array
{
    ensure_manual_schema($pdo);
    $tz = new DateTimeZone((string)liveo_config()['timezone']);
    $res = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'rows' => count($rows)];

    $find = $pdo->prepare('SELECT id, time_tbc, start_ts FROM manual_events WHERE import_key = ?');
    $ins = $pdo->prepare(
        'INSERT INTO manual_events (sport, league, home, away, title, start_ts, state_mode, active, minute, home_logo, away_logo, time_tbc, duration_min, import_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($rows as $n => $row) {
            $line = $n + 2; // fila 1 = encabezados
            $date = csv_parse_date((string)($row['fecha'] ?? ''));
            if ($date === null) {
                $res['errors'][] = 'Fila ' . $line . ': fecha inválida («' . ($row['fecha'] ?? '') . '»).';
                continue;
            }
            $evento = (string)($row['evento'] ?? '');
            $home = (string)($row['local'] ?? '');
            $away = (string)($row['visitante'] ?? '');
            if ($home === '' && $evento !== '') {
                $teams = csv_split_teams($evento);
                if ($teams) {
                    [$home, $away] = $teams;
                } else {
                    $home = $evento; // evento de un solo título
                    $away = '';
                }
            }
            if ($home === '') {
                $res['errors'][] = 'Fila ' . $line . ': falta el evento o el equipo local.';
                continue;
            }
            $time = csv_parse_time((string)($row['hora'] ?? ''));
            if ($skipInfo && csv_is_info_row($row, $away !== '', $time !== null)) {
                $res['skipped']++;
                continue;
            }
            $tbc = $time === null ? 1 : 0;
            $dur = csv_duration_minutes($time, (string)($row['hasta'] ?? ''), (string)($row['duracion'] ?? ''));
            try {
                $start = (new DateTimeImmutable($date . ' ' . ($time ?? '00:00') . ':00', $tz))->getTimestamp();
            } catch (Throwable $e) {
                $res['errors'][] = 'Fila ' . $line . ': no se pudo calcular la hora.';
                continue;
            }
            $sport = mb_substr((string)($row['deporte'] ?? ''), 0, 80);
            $league = mb_substr((string)($row['liga'] ?? ''), 0, 160);
            $home = mb_substr($home, 0, 120);
            $away = mb_substr($away, 0, 120);
            $title = ''; // el título se arma solo: «Local vs Visitante» o el nombre del evento
            $state = csv_parse_state((string)($row['estado'] ?? ''));
            $minute = mb_substr(trim((string)($row['minuto'] ?? '')), 0, 12);
            $hl = csv_clean_url((string)($row['escudo_local'] ?? ''));
            $al = csv_clean_url((string)($row['escudo_visitante'] ?? ''));
            $key = sha1($date . '|' . team_key($league) . '|' . team_key($evento !== '' ? $evento : $home . ' vs ' . $away));

            $find->execute([$key]);
            $cur = $find->fetch();
            if ($cur) {
                // Una hora ya confirmada no se pierde si el CSV todavía no la trae.
                if ($tbc === 1 && (int)$cur['time_tbc'] === 0) {
                    $start = (int)$cur['start_ts'];
                    $tbc = 0;
                }
                $set = 'sport = ?, league = ?, home = ?, away = ?, title = ?, start_ts = ?, time_tbc = ?';
                $par = [$sport, $league, $home, $away, $title, $start, $tbc];
                if ($dur !== null) {
                    $set .= ', duration_min = ?';
                    $par[] = $dur;
                }
                if ($state !== null) {
                    $set .= ', state_mode = ?';
                    $par[] = $state;
                }
                if ($minute !== '') {
                    $set .= ', minute = ?';
                    $par[] = $minute;
                }
                if ($hl !== '') {
                    $set .= ', home_logo = ?';
                    $par[] = $hl;
                }
                if ($al !== '') {
                    $set .= ', away_logo = ?';
                    $par[] = $al;
                }
                $par[] = (int)$cur['id'];
                $pdo->prepare('UPDATE manual_events SET ' . $set . ' WHERE id = ?')->execute($par);
                $res['updated']++;
            } else {
                $ins->execute([
                    $sport, $league, $home, $away, $title, $start, $state ?? 'auto',
                    $minute !== '' ? $minute : null,
                    $hl !== '' ? $hl : null,
                    $al !== '' ? $al : null,
                    $tbc, $dur, $key,
                ]);
                $res['new']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $res;
}

/** CSV de escudos por equipo: columnas «equipo» y «escudo» (o «url»). */
function logos_import_rows(PDO $pdo, array $rows): int
{
    ensure_manual_schema($pdo);
    $st = $pdo->prepare('REPLACE INTO team_logos (name_key, name, url) VALUES (?, ?, ?)');
    $n = 0;
    foreach ($rows as $r) {
        $name = trim((string)($r['equipo'] ?? $r['local'] ?? ''));
        $url = csv_clean_url((string)($r['escudo'] ?? ''));
        if ($name === '' || $url === '' || team_key($name) === '') {
            continue;
        }
        $st->execute([team_key($name), mb_substr($name, 0, 120), $url]);
        $n++;
    }
    return $n;
}

/** Plantilla descargable para el panel. */
function csv_template(): string
{
    return "\xEF\xBB\xBF" . implode("\n", [
        'fecha,hora,hasta,deporte,competicion,evento,escudo_local,escudo_visitante,estado,minuto',
        '2026-11-21,12:00,13:55,Fútbol,Premier League,Chelsea vs Leeds United,https://ejemplo.com/chelsea.png,https://ejemplo.com/leeds.png,,',
        '2026-11-22,,,Ciclismo,UCI WorldTour,Santos Tour Down Under - etapa 1,,,,',
        '2026-11-23,22:00,23:55,Fútbol,Liga Profesional,Boca Juniors vs River Plate,,,en vivo,34',
    ]) . "\n";
}
