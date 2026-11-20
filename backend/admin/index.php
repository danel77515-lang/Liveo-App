<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

if (!is_local_request()) {
    http_response_code(403);
    echo 'El panel de administración solo está disponible desde la red local.';
    exit;
}

session_name('liveo_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// ---------------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------------

function flash(string $kind, string $msg): void
{
    $_SESSION['flash'][] = [$kind, $msg];
}

function go(string $qs = ''): void
{
    $base = strtok((string)($_SERVER['REQUEST_URI'] ?? 'index.php'), '?');
    header('Location: ' . $base . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_t" value="' . h(csrf()) . '">';
}

function get_setting(PDO $pdo, string $key): ?string
{
    $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('REPLACE INTO app_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
}

function fmt_ts($ts): string
{
    $ts = (int)$ts;
    return $ts > 0 ? date('d/m H:i', $ts) : '--';
}

function contains_ci(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }
    return function_exists('mb_stripos')
        ? mb_stripos($haystack, $needle) !== false
        : stripos($haystack, $needle) !== false;
}

/** ¿Valor de escudo válido? URL http(s) o una imagen ya subida (uploads/logos/…). */
function logo_value_ok(string $v): bool
{
    return csv_clean_url($v) !== '' || preg_match('#^uploads/logos/[a-z0-9-]+\.(png|jpg|webp|gif)$#', $v) === 1;
}

/** [clave => nombre] de los equipos que aparecen en los eventos. */
function known_team_names(PDO $pdo): array
{
    $out = [];
    $sql = "SELECT home AS t FROM manual_events WHERE away <> '' UNION SELECT away FROM manual_events WHERE away <> ''";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $name = trim((string)$name);
        $key = team_key($name);
        if ($key !== '' && !isset($out[$key])) {
            $out[$key] = $name;
        }
    }
    return $out;
}

/** Vista pedida (cualquier valor desconocido cae en la lista de eventos). */
function admin_view(): string
{
    $v = (string)($_GET['v'] ?? 'manual');
    return in_array($v, ['event', 'linked', 'manual', 'manual_new', 'config', 'import', 'logos', 'leagues', 'announcements', 'aliases', 'sports'], true) ? $v : 'manual';
}

function valid_event_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $id);
}

/** Lee y valida los campos de un enlace del formulario. */
function read_source_fields(): array
{
    $f = [
        'label'      => trim((string)($_POST['label'] ?? '')),
        'url'        => '',
        'kind'       => 'hls',
        'embed'      => '',
        'referer'    => trim((string)($_POST['referer'] ?? '')),
        'user_agent' => trim((string)($_POST['user_agent'] ?? '')),
        'err'        => null,
    ];
    // Acepta una URL m3u8, la URL de una página web o el código <iframe …> completo.
    $p = parse_source_input((string)($_POST['url'] ?? ''), (string)($_POST['kind'] ?? 'auto'));
    $f['url'] = $p['url'];
    $f['kind'] = $p['kind'];
    $f['embed'] = (string)($p['embed'] ?? '');
    if (strlen($f['label']) > 60) {
        $f['err'] = 'El nombre del enlace admite hasta 60 caracteres.';
    } elseif ($p['err'] !== null) {
        $f['err'] = $p['err'];
    } elseif ($f['referer'] !== '' && (strlen($f['referer']) > 500 || !preg_match('#^https?://\S+$#i', $f['referer']))) {
        $f['err'] = 'El Referer debe ser una URL http(s) válida.';
    } elseif (strlen($f['user_agent']) > 300 || preg_match('/[\r\n]/', $f['user_agent'])) {
        $f['err'] = 'El User-Agent no es válido (máximo 300 caracteres, una sola línea).';
    }
    return $f;
}

function probe_message(array $r): array
{
    return [$r['ok'] ? 'ok' : 'bad', ($r['ok'] ? '✔ ' : '✖ ') . $r['note']];
}

/** Lee y valida los campos de un evento del formulario. */
function read_manual_fields(): array
{
    $f = [
        'sport'      => trim((string)($_POST['sport'] ?? '')),
        'league'     => trim((string)($_POST['league'] ?? '')),
        'home'       => trim((string)($_POST['home'] ?? '')),
        'away'       => trim((string)($_POST['away'] ?? '')),
        'title'      => trim((string)($_POST['title'] ?? '')),
        'start'      => trim((string)($_POST['start'] ?? '')),
        'state_mode' => (string)($_POST['state_mode'] ?? 'auto'),
        'minute'     => trim((string)($_POST['minute'] ?? '')),
        'duration'   => max(0, min(720, (int)($_POST['duration'] ?? 0))),
        'home_logo'  => trim((string)($_POST['home_logo'] ?? '')),
        'away_logo'  => trim((string)($_POST['away_logo'] ?? '')),
        'time_tbc'   => !empty($_POST['time_tbc']) ? 1 : 0,
        'start_ts'   => 0,
        'err'        => null,
    ];
    $len = function (string $x): int {
        return function_exists('mb_strlen') ? mb_strlen($x) : strlen($x);
    };
    if (!array_key_exists($f['state_mode'], manual_state_options())) {
        $f['state_mode'] = 'auto';
    }
    $ts = $f['start'] !== '' ? strtotime($f['start']) : false;
    if ($f['home'] === '') {
        $f['err'] = 'Escribe el equipo local (o el nombre del evento si no hay rivales).';
    } elseif ($len($f['home']) > 120 || $len($f['away']) > 120) {
        $f['err'] = 'Los nombres de los equipos admiten hasta 120 caracteres.';
    } elseif ($len($f['sport']) > 80) {
        $f['err'] = 'El deporte admite hasta 80 caracteres.';
    } elseif ($len($f['league']) > 160) {
        $f['err'] = 'La competición admite hasta 160 caracteres.';
    } elseif ($len($f['title']) > 200) {
        $f['err'] = 'El título admite hasta 200 caracteres.';
    } elseif ($len($f['minute']) > 12) {
        $f['err'] = 'El minuto admite hasta 12 caracteres (ej. 34, 45+2, Descanso).';
    } elseif (($f['home_logo'] !== '' && csv_clean_url($f['home_logo']) === '') || ($f['away_logo'] !== '' && csv_clean_url($f['away_logo']) === '')) {
        $f['err'] = 'Los escudos deben ser URLs http(s) válidas (o dejarse vacíos).';
    } elseif ($ts === false || $ts < 946684800 || $ts > 4102444800) {
        $f['err'] = 'Elige una fecha y hora de inicio válidas.';
    } else {
        // Con «hora a confirmar» solo cuenta la fecha (queda a las 00:00).
        $f['start_ts'] = $f['time_tbc'] ? (int)strtotime(date('Y-m-d', (int)$ts) . ' 00:00:00') : (int)$ts;
    }
    return $f;
}

/** Formulario de alta / edición de un evento propio. */
function manual_form_html(PDO $pdo, array $v, int $mid, int $dur): string
{
    $sports = ['Fútbol', 'Básquet', 'Tenis', 'Béisbol', 'Fútbol americano', 'Rugby', 'Voleibol', 'Automovilismo', 'Motociclismo', 'Ciclismo', 'Boxeo', 'MMA', 'Hockey', 'Golf', 'Otro'];
    $leagues = [];
    try {
        foreach ($pdo->query("SELECT DISTINCT sport FROM manual_events WHERE sport <> '' ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_COLUMN) as $x) {
            if (!in_array($x, $sports, true)) {
                $sports[] = (string)$x;
            }
        }
        $leagues = $pdo->query("SELECT DISTINCT league FROM manual_events WHERE league <> '' ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $leagues = [];
    }
    $uid = 'm' . $mid;
    ob_start();
    ?>
    <div class="card">
      <h2><?= $mid > 0 ? 'Datos del evento' : 'Nuevo evento' ?></h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="a" value="manual_save">
        <input type="hidden" name="mid" value="<?= (int)$mid ?>">
        <div class="grid">
          <label>Deporte<input name="sport" list="sports-<?= h($uid) ?>" value="<?= h((string)($v['sport'] ?? '')) ?>" maxlength="80" placeholder="Fútbol"></label>
          <label>Competición<input name="league" list="leagues-<?= h($uid) ?>" value="<?= h((string)($v['league'] ?? '')) ?>" maxlength="160" placeholder="Liga Profesional"></label>
        </div>
        <datalist id="sports-<?= h($uid) ?>"><?php foreach ($sports as $x): ?><option value="<?= h((string)$x) ?>"><?php endforeach; ?></datalist>
        <datalist id="leagues-<?= h($uid) ?>"><?php foreach ($leagues as $x): ?><option value="<?= h((string)$x) ?>"><?php endforeach; ?></datalist>
        <div class="grid">
          <label>Equipo local (o nombre del evento)<input name="home" value="<?= h((string)($v['home'] ?? '')) ?>" maxlength="120" required></label>
          <label>Equipo visitante<input name="away" value="<?= h((string)($v['away'] ?? '')) ?>" maxlength="120" placeholder="vacío = evento sin rivales (etapa, carrera…)"></label>
        </div>
        <div class="grid">
          <label>Escudo local (URL, opcional)<input name="home_logo" value="<?= h((string)($v['home_logo'] ?? '')) ?>" maxlength="500" placeholder="https://…/escudo.png"></label>
          <label>Escudo visitante (URL, opcional)<input name="away_logo" value="<?= h((string)($v['away_logo'] ?? '')) ?>" maxlength="500" placeholder="https://…/escudo.png"></label>
        </div>
        <p class="mut" style="margin-top:-4px">El escudo que pegues se guarda para ese equipo y se usa en todos sus partidos (presentes y futuros). Para subir imágenes ve a <a class="link" href="?v=logos">Escudos</a>.</p>
        <label>Título (opcional; si lo dejas vacío se usa «Local vs Visitante»)<input name="title" value="<?= h((string)($v['title'] ?? '')) ?>" maxlength="200"></label>
        <div class="grid">
          <label>Inicio<input type="datetime-local" name="start" id="start-<?= h($uid) ?>" value="<?= h((string)($v['start'] ?? '')) ?>" required></label>
          <label>Finalización automática<input type="datetime-local" id="end-<?= h($uid) ?>" class="auto-end" disabled>
            <span class="mut">Se calcula automáticamente <?= (int)$dur ?> minutos después del inicio (<?= (int)manual_basket_duration_minutes($pdo) ?> si es básquet). Puedes cambiar estas duraciones en <a class="link" href="?v=config">Configuración</a>.</span></label>
        </div>
        <label style="color:var(--txt)"><input type="checkbox" name="time_tbc" value="1" <?= !empty($v['time_tbc']) ? 'checked' : '' ?>> Hora a confirmar (solo cuenta la fecha; no arranca solo)</label>
        <label>Duración en minutos (opcional; vacío = la general de Configuración)
          <input type="number" name="duration" min="0" max="720" value="<?= (int)($v['duration'] ?? 0) > 0 ? (int)$v['duration'] : '' ?>" placeholder="115">
        </label>
        <div class="grid">
        <label>Minuto (solo se ve mientras está en vivo)
          <input name="minute" value="<?= h((string)($v['minute'] ?? '')) ?>" maxlength="12" placeholder="34, 45+2, Descanso">
        </label>
        <label>Estado
          <select name="state_mode">
            <?php foreach (manual_state_options() as $k => $lab): ?>
              <option value="<?= h($k) ?>" <?= ((string)($v['state_mode'] ?? 'auto')) === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        </div>
        <div class="actions">
          <button type="submit"><?= $mid > 0 ? 'Guardar cambios' : 'Crear evento' ?></button>
        </div>
      </form>
      <script>
      (function () {
        var s = document.getElementById('start-<?= h($uid) ?>');
        var e = document.getElementById('end-<?= h($uid) ?>');
        var baseMins = <?= (int)$dur ?>;
        var basketMins = <?= (int)manual_basket_duration_minutes($pdo) ?>;
        var f = s.form;
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function isBasket() {
          var t = ((f.elements['sport'] ? f.elements['sport'].value : '') + ' ' + (f.elements['league'] ? f.elements['league'].value : '')).toLowerCase();
          t = t.normalize ? t.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : t;
          return /basq|basket|baloncesto|\bnba\b|wnba|euroliga|euroleague|\bfiba\b/.test(t);
        }
        function upd() {
          if (!s.value) { e.value = ''; return; }
          var d = new Date(s.value);
          if (isNaN(d.getTime())) { e.value = ''; return; }
          var own = f.elements['duration'] ? parseInt(f.elements['duration'].value, 10) : 0;
          var mins = own > 0 ? own : (isBasket() ? basketMins : baseMins);
          d = new Date(d.getTime() + mins * 60000);
          e.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
        ['input', 'change'].forEach(function (ev) {
          s.addEventListener(ev, upd);
          ['sport', 'league', 'duration'].forEach(function (n) {
            if (f.elements[n]) { f.elements[n].addEventListener(ev, upd); }
          });
        });
        upd();
      })();
      </script>
    </div>
    <?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Estado: base de datos, contraseña y sesión
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = db();
$fatal = null;
if (!$pdo) {
    $fatal = 'No hay conexión con MySQL: ' . (string)($GLOBALS['liveo_db_error'] ?? 'sin detalle') . '. Inicia MySQL en XAMPP y abre ../setup.php.';
} else {
    try {
        ensure_sources_schema($pdo);
    } catch (Throwable $e) {
        $fatal = 'No se pudieron preparar las tablas: ' . $e->getMessage();
    }
}

$hash = null;
if (!$fatal) {
    $hash = get_setting($pdo, 'admin_password_hash');
}
$needsSetup = !$fatal && ($hash === null || $hash === '');
$logged = !empty($_SESSION['liveo_admin']);

// ---------------------------------------------------------------------------
// Acciones (POST)
// ---------------------------------------------------------------------------

if ($method === 'POST' && !$fatal) {
    $a = (string)($_POST['a'] ?? '');

    if ($needsSetup) {
        if ($a === 'setup') {
            $p1 = (string)($_POST['password'] ?? '');
            $p2 = (string)($_POST['password2'] ?? '');
            if (strlen($p1) < 8) {
                flash('bad', 'La contraseña debe tener al menos 8 caracteres.');
            } elseif ($p1 !== $p2) {
                flash('bad', 'Las contraseñas no coinciden.');
            } else {
                set_setting($pdo, 'admin_password_hash', password_hash($p1, PASSWORD_DEFAULT));
                session_regenerate_id(true);
                $_SESSION['liveo_admin'] = true;
                flash('ok', 'Contraseña creada. Ya puedes administrar los enlaces.');
            }
        }
        go();
    }

    if (!$logged) {
        if ($a === 'login') {
            $fails = (int)($_SESSION['fails'] ?? 0);
            if ($fails > 0) {
                usleep(min($fails, 5) * 400000);
            }
            if (password_verify((string)($_POST['password'] ?? ''), (string)$hash)) {
                session_regenerate_id(true);
                $_SESSION['liveo_admin'] = true;
                $_SESSION['fails'] = 0;
            } else {
                $_SESSION['fails'] = $fails + 1;
                flash('bad', 'Contraseña incorrecta.');
            }
        }
        go();
    }

    // Sesión iniciada: toda acción exige el token CSRF.
    if (!hash_equals(csrf(), (string)($_POST['_t'] ?? ''))) {
        flash('bad', 'La sesión venció. Intenta de nuevo.');
        go();
    }

    if ($a === 'logout') {
        $_SESSION = [];
        session_destroy();
        session_start();
        flash('ok', 'Sesión cerrada.');
        go();
    }

    $eid = (string)($_POST['event_id'] ?? '');
    $back = valid_event_id($eid) ? 'v=event&id=' . rawurlencode($eid) . '&d=' . rawurlencode((string)($_POST['d'] ?? '')) : 'v=manual';

    try {
        switch ($a) {
            case 'add':
            case 'test_url':
                if (!valid_event_id($eid)) {
                    flash('bad', 'Evento inválido.');
                    go();
                }
                $f = read_source_fields();
                $_SESSION['prefill'] = $f;
                if ($f['err'] !== null) {
                    flash('bad', $f['err']);
                    go($back);
                }
                if ($a === 'test_url') {
                    $m = probe_message($f['kind'] === 'iframe'
                        ? probe_page($f['url'], $f['referer'], $f['user_agent'])
                        : probe_m3u8($f['url'], $f['referer'], $f['user_agent']));
                    flash($m[0], $m[1]);
                    go($back);
                }
                $st = $pdo->prepare('SELECT COUNT(*) FROM event_sources WHERE event_id = ?');
                $st->execute([$eid]);
                $n = (int)$st->fetchColumn();
                if ($n >= 10) {
                    flash('bad', 'Un evento admite hasta 10 enlaces.');
                    go($back);
                }
                $kick = (int)($_POST['kickoff'] ?? 0);
                $pdo->prepare(
                    'INSERT INTO event_sources (event_id, label, url, kind, embed, referer, user_agent, sort_order, active, home, away, league, kickoff)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
                )->execute([
                    $eid,
                    $f['label'] !== '' ? $f['label'] : 'Fuente ' . ($n + 1),
                    $f['url'],
                    $f['kind'],
                    $f['embed'] !== '' ? $f['embed'] : null,
                    $f['referer'] !== '' ? $f['referer'] : null,
                    $f['user_agent'] !== '' ? $f['user_agent'] : null,
                    $n,
                    substr(trim((string)($_POST['home'] ?? '')), 0, 120),
                    substr(trim((string)($_POST['away'] ?? '')), 0, 120),
                    substr(trim((string)($_POST['league'] ?? '')), 0, 160),
                    $kick > 0 ? $kick : null,
                ]);
                unset($_SESSION['prefill']);
                flash('ok', ($f['kind'] === 'iframe' ? 'Enlace iframe agregado.' : 'Enlace agregado.') . ($f['kind'] === 'hls' && stripos($f['url'], '.m3u8') === false ? ' Aviso: la URL no contiene ".m3u8".' : ''));
                go($back);
                break;

            case 'add_many':
                if (!valid_event_id($eid)) {
                    flash('bad', 'Evento inválido.');
                    go();
                }
                $referer = trim((string)($_POST['referer'] ?? ''));
                $ua = trim((string)($_POST['user_agent'] ?? ''));
                if (($referer !== '' && (strlen($referer) > 500 || !preg_match('#^https?://\S+$#i', $referer)))
                    || strlen($ua) > 300 || preg_match('/[\r\n]/', $ua)) {
                    flash('bad', 'El Referer o el User-Agent no son válidos.');
                    go($back);
                }
                $st = $pdo->prepare('SELECT COUNT(*) FROM event_sources WHERE event_id = ?');
                $st->execute([$eid]);
                $n = (int)$st->fetchColumn();
                $kick = (int)($_POST['kickoff'] ?? 0);
                $ins = $pdo->prepare(
                    'INSERT INTO event_sources (event_id, label, url, kind, embed, referer, user_agent, sort_order, active, home, away, league, kickoff)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
                );
                $added = 0;
                $skipped = 0;
                foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['bulk'] ?? '')) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $label = '';
                    $raw = $line;
                    // «Nombre | URL» (el nombre va antes de la primera barra; sin < ni http).
                    if (preg_match('/^([^|<]{1,60})\|(.+)$/s', $line, $mm) === 1 && stripos($mm[1], 'http') === false) {
                        $label = trim($mm[1]);
                        $raw = trim($mm[2]);
                    }
                    $p = parse_source_input($raw, 'auto');
                    if ($p['err'] !== null) {
                        $skipped++;
                        continue;
                    }
                    $url = $p['url'];
                    if ($n >= 10) {
                        $skipped++;
                        continue;
                    }
                    $ins->execute([
                        $eid, $label !== '' ? $label : 'Fuente ' . ($n + 1), $url, $p['kind'],
                        ($p['embed'] ?? '') !== '' ? $p['embed'] : null,
                        $referer !== '' ? $referer : null, $ua !== '' ? $ua : null, $n,
                        substr(trim((string)($_POST['home'] ?? '')), 0, 120),
                        substr(trim((string)($_POST['away'] ?? '')), 0, 120),
                        substr(trim((string)($_POST['league'] ?? '')), 0, 160),
                        $kick > 0 ? $kick : null,
                    ]);
                    $n++;
                    $added++;
                }
                flash($added > 0 ? 'ok' : 'bad', $added > 0
                    ? 'Enlaces agregados: ' . $added . ($skipped > 0 ? ' (' . $skipped . ' omitidos: inválidos o tope de 10 por evento)' : '') . '.'
                    : 'No se agregó ninguno: cada línea debe ser una URL http(s), un código <iframe …> o «Nombre | URL».');
                go($back);
                break;

            case 'save':
            case 'toggle':
            case 'delete':
            case 'move_up':
            case 'move_down':
            case 'test':
                $id = (int)($_POST['id'] ?? 0);
                $st = $pdo->prepare('SELECT * FROM event_sources WHERE id = ?');
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) {
                    flash('bad', 'El enlace ya no existe.');
                    go();
                }
                $eid = (string)$row['event_id'];
                $back = 'v=event&id=' . rawurlencode($eid) . '&d=' . rawurlencode((string)($_POST['d'] ?? ''));
                if ($a === 'move_up' || $a === 'move_down') {
                    // Reordena: renumera 0..n-1 según el orden actual e intercambia con el vecino.
                    $st = $pdo->prepare('SELECT id FROM event_sources WHERE event_id = ? ORDER BY sort_order ASC, id ASC');
                    $st->execute([$eid]);
                    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                    $pos = array_search($id, $ids, true);
                    if ($pos !== false) {
                        $to = $a === 'move_up' ? $pos - 1 : $pos + 1;
                        if ($to >= 0 && $to < count($ids)) {
                            $tmp = $ids[$pos];
                            $ids[$pos] = $ids[$to];
                            $ids[$to] = $tmp;
                        }
                        $up = $pdo->prepare('UPDATE event_sources SET sort_order = ? WHERE id = ?');
                        foreach ($ids as $i => $sid) {
                            $up->execute([min(99, $i), $sid]);
                        }
                    }
                    go($back . '#src-' . $id);
                } elseif ($a === 'delete') {
                    $pdo->prepare('DELETE FROM event_sources WHERE id = ?')->execute([$id]);
                    flash('ok', 'Enlace eliminado.');
                } elseif ($a === 'toggle') {
                    $pdo->prepare('UPDATE event_sources SET active = 1 - active WHERE id = ?')->execute([$id]);
                    flash('ok', ((int)$row['active'] === 1) ? 'Enlace desactivado (la app ya no lo muestra).' : 'Enlace activado.');
                } elseif ($a === 'test') {
                    $m = probe_message((string)$row['kind'] === 'iframe'
                        ? probe_page((string)$row['url'], $row['referer'], $row['user_agent'])
                        : probe_m3u8((string)$row['url'], $row['referer'], $row['user_agent']));
                    flash($m[0], '«' . $row['label'] . '»: ' . substr($m[1], 0, 400));
                } else {
                    $f = read_source_fields();
                    if ($f['err'] !== null) {
                        flash('bad', $f['err']);
                    } else {
                        $pdo->prepare(
                            'UPDATE event_sources SET label = ?, url = ?, kind = ?, embed = ?, referer = ?, user_agent = ?, sort_order = ? WHERE id = ?'
                        )->execute([
                            $f['label'] !== '' ? $f['label'] : (string)$row['label'],
                            $f['url'],
                            $f['kind'],
                            $f['embed'] !== '' ? $f['embed'] : null,
                            $f['referer'] !== '' ? $f['referer'] : null,
                            $f['user_agent'] !== '' ? $f['user_agent'] : null,
                            max(0, min(99, (int)($_POST['sort_order'] ?? 0))),
                            $id,
                        ]);
                        flash('ok', 'Cambios guardados.');
                    }
                }
                go($back);
                break;

            case 'manual_save':
                $mid = (int)($_POST['mid'] ?? 0);
                $f = read_manual_fields();
                $_SESSION['manual_prefill'] = $f;
                $errGo = $mid > 0 ? 'v=event&id=manual-' . $mid : 'v=manual_new';
                if ($f['err'] !== null) {
                    flash('bad', $f['err']);
                    go($errGo);
                }
                // Un solo nombre por equipo y competición (así no se repiten en Escudos / Competiciones).
                $f['league'] = canonical_name($pdo, 'league', $f['league']);
                if ($f['away'] !== '') {
                    $f['home'] = canonical_name($pdo, 'team', $f['home']);
                    $f['away'] = canonical_name($pdo, 'team', $f['away']);
                }
                // Los escudos de equipos quedan guardados para todos sus partidos (presentes y futuros).
                if ($f['away'] !== '') {
                    if ($f['home_logo'] !== '') {
                        remember_team_logo($pdo, $f['home'], $f['home_logo']);
                        $f['home_logo'] = '';
                    }
                    if ($f['away_logo'] !== '') {
                        remember_team_logo($pdo, $f['away'], $f['away_logo']);
                        $f['away_logo'] = '';
                    }
                }
                if ($mid > 0) {
                    $chk = $pdo->prepare('SELECT COUNT(*) FROM manual_events WHERE id = ?');
                    $chk->execute([$mid]);
                    if ((int)$chk->fetchColumn() === 0) {
                        flash('bad', 'El evento ya no existe.');
                        go('v=manual');
                    }
                    $pdo->prepare(
                        'UPDATE manual_events SET sport = ?, league = ?, home = ?, away = ?, title = ?, start_ts = ?, state_mode = ?,
                                minute = ?, home_logo = ?, away_logo = ?, time_tbc = ?, duration_min = ? WHERE id = ?'
                    )->execute([
                        $f['sport'], $f['league'], $f['home'], $f['away'], $f['title'], $f['start_ts'], $f['state_mode'],
                        $f['minute'] !== '' ? $f['minute'] : null,
                        $f['home_logo'] !== '' ? $f['home_logo'] : null,
                        $f['away_logo'] !== '' ? $f['away_logo'] : null,
                        $f['time_tbc'], $f['duration'] > 0 ? $f['duration'] : null, $mid,
                    ]);
                    unset($_SESSION['manual_prefill']);
                    flash('ok', 'Cambios guardados.');
                    go('v=event&id=manual-' . $mid);
                }
                $pdo->prepare(
                    'INSERT INTO manual_events (sport, league, home, away, title, start_ts, state_mode, active, minute, home_logo, away_logo, time_tbc, duration_min)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
                )->execute([
                    $f['sport'], $f['league'], $f['home'], $f['away'], $f['title'], $f['start_ts'], $f['state_mode'],
                    $f['minute'] !== '' ? $f['minute'] : null,
                    $f['home_logo'] !== '' ? $f['home_logo'] : null,
                    $f['away_logo'] !== '' ? $f['away_logo'] : null,
                    $f['time_tbc'], $f['duration'] > 0 ? $f['duration'] : null,
                ]);
                $newId = (int)$pdo->lastInsertId();
                unset($_SESSION['manual_prefill']);
                flash('ok', 'Evento creado. Agrega sus señales en la sección de abajo.');
                go('v=event&id=manual-' . $newId);
                break;

            case 'manual_quick':
                $mid = (int)($_POST['mid'] ?? 0);
                $mode = (string)($_POST['state_mode'] ?? 'auto');
                if (!array_key_exists($mode, manual_state_options())) {
                    $mode = 'auto';
                }
                $minute = trim((string)($_POST['minute'] ?? ''));
                if (strlen($minute) > 12) {
                    flash('bad', 'El minuto admite hasta 12 caracteres.');
                    go('v=event&id=manual-' . $mid);
                }
                $pdo->prepare('UPDATE manual_events SET state_mode = ?, minute = ? WHERE id = ?')
                    ->execute([$mode, $minute !== '' ? $minute : null, $mid]);
                flash('ok', 'Estado y minuto actualizados: ya se reflejan en la app.');
                go('v=event&id=manual-' . $mid);
                break;

            case 'csv_import':
                $up = $_FILES['csv'] ?? null;
                if (!is_array($up) || (int)($up['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name'])) {
                    flash('bad', 'No se recibió el archivo CSV (revisa que no supere el límite de subida de PHP).');
                    go('v=import');
                }
                if ((int)$up['size'] > 5 * 1024 * 1024) {
                    flash('bad', 'El CSV supera los 5 MB.');
                    go('v=import');
                }
                $readErr = null;
                $rows = csv_read_rows((string)$up['tmp_name'], $readErr);
                if (!$rows) {
                    flash('bad', $readErr ?? 'El CSV no tiene filas con datos.');
                    go('v=import');
                }
                $r = manual_import_rows($pdo, $rows, !empty($_POST['skip_info']));
                flash('ok', 'Importación lista: ' . $r['new'] . ' nuevos, ' . $r['updated'] . ' actualizados'
                    . ($r['skipped'] > 0 ? ', ' . $r['skipped'] . ' filas informativas omitidas' : '')
                    . (count($r['errors']) > 0 ? ', ' . count($r['errors']) . ' con error' : '') . '.');
                foreach (array_slice($r['errors'], 0, 8) as $line) {
                    flash('bad', $line);
                }
                if ($readErr !== null) {
                    flash('bad', $readErr);
                }
                go('v=manual');
                break;

            case 'logos_save':
                $names = (array)($_POST['name'] ?? []);
                $urls = (array)($_POST['url'] ?? []);
                $files = $_FILES['file'] ?? null;
                $saved = 0;
                $del = $pdo->prepare('DELETE FROM team_logos WHERE name_key = ?');
                foreach ($names as $i => $nm) {
                    $nm = trim((string)$nm);
                    $key = team_key($nm);
                    if ($key === '') {
                        continue;
                    }
                    // Imagen subida desde el teléfono / PC.
                    if (is_array($files) && (int)($files['error'][$key] ?? 4) === UPLOAD_ERR_OK) {
                        $r = save_team_logo_upload($pdo, $nm, [
                            'tmp_name' => $files['tmp_name'][$key], 'error' => $files['error'][$key], 'size' => $files['size'][$key],
                        ]);
                        if ($r['ok']) {
                            remember_team_logo($pdo, $nm, $r['url']);
                            $saved++;
                        } else {
                            flash('bad', '«' . $nm . '»: ' . $r['error']);
                        }
                        continue;
                    }
                    $raw = trim((string)($urls[$i] ?? ''));
                    if ($raw === '') {
                        $del->execute([$key]);
                        continue;
                    }
                    if (!logo_value_ok($raw)) {
                        flash('bad', 'Escudo de «' . $nm . '» ignorado: no es una URL http(s) válida.');
                        continue;
                    }
                    remember_team_logo($pdo, $nm, $raw);
                    $saved++;
                }
                flash('ok', 'Escudos guardados: ' . $saved . '. Quedan para todos los partidos de esos equipos.');
                go('v=logos&q=' . rawurlencode((string)($_POST['q'] ?? '')));
                break;

            case 'leagues_save':
                ensure_manual_schema($pdo);
                $names = (array)($_POST['name'] ?? []);
                $urls = (array)($_POST['url'] ?? []);
                $files = $_FILES['file'] ?? null;
                $saved = 0;
                $del = $pdo->prepare('DELETE FROM league_logos WHERE name_key = ?');
                foreach ($names as $i => $nm) {
                    $nm = trim((string)$nm);
                    $key = team_key($nm);
                    if ($key === '') {
                        continue;
                    }
                    if (is_array($files) && (int)($files['error'][$key] ?? 4) === UPLOAD_ERR_OK) {
                        $r = save_league_logo_upload($pdo, $nm, [
                            'tmp_name' => $files['tmp_name'][$key], 'error' => $files['error'][$key], 'size' => $files['size'][$key],
                        ]);
                        if ($r['ok']) {
                            remember_league_logo($pdo, $nm, $r['url']);
                            $saved++;
                        } else {
                            flash('bad', '«' . $nm . '»: ' . $r['error']);
                        }
                        continue;
                    }
                    $raw = trim((string)($urls[$i] ?? ''));
                    if ($raw === '') {
                        $del->execute([$key]);
                        continue;
                    }
                    if (!logo_value_ok($raw)) {
                        flash('bad', 'Logo de «' . $nm . '» ignorado: no es una URL http(s) válida.');
                        continue;
                    }
                    remember_league_logo($pdo, $nm, $raw);
                    $saved++;
                }
                flash('ok', 'Logos de competición guardados: ' . $saved . '. Se ven en todos sus partidos.');
                go('v=leagues');
                break;

            case 'logos_bulk':
                // Varias imágenes a la vez: el nombre del archivo es el del equipo (Chelsea.png).
                $b = $_FILES['bulk'] ?? null;
                $known = known_team_names($pdo);
                $ok = 0;
                $total = is_array($b['name'] ?? null) ? count($b['name']) : 0;
                for ($i = 0; $i < $total; $i++) {
                    if ((int)$b['error'][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $team = trim((string)preg_replace('/[_\-]+/', ' ', pathinfo((string)$b['name'][$i], PATHINFO_FILENAME)));
                    $team = $known[team_key($team)] ?? $team;
                    $r = save_team_logo_upload($pdo, $team, [
                        'tmp_name' => $b['tmp_name'][$i], 'error' => $b['error'][$i], 'size' => $b['size'][$i],
                    ]);
                    if ($r['ok']) {
                        remember_team_logo($pdo, $team, $r['url']);
                        $ok++;
                    } else {
                        flash('bad', (string)$b['name'][$i] . ': ' . $r['error']);
                    }
                }
                flash($ok > 0 ? 'ok' : 'bad', $ok > 0 ? 'Escudos subidos: ' . $ok . '. Ya quedan guardados para esos equipos.' : 'No se subió ninguna imagen.');
                go('v=logos');
                break;

            case 'logos_csv':
                $up = $_FILES['csv'] ?? null;
                if (!is_array($up) || (int)($up['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name'])) {
                    flash('bad', 'No se recibió el archivo CSV.');
                    go('v=logos');
                }
                $readErr = null;
                $rows = csv_read_rows((string)$up['tmp_name'], $readErr);
                $n = $rows ? logos_import_rows($pdo, $rows) : 0;
                flash($n > 0 ? 'ok' : 'bad', $n > 0 ? 'Escudos importados: ' . $n . '.' : 'No se encontró ningún equipo con escudo (columnas «equipo» y «escudo»).');
                go('v=logos');
                break;

            case 'manual_toggle':
                $mid = (int)($_POST['mid'] ?? 0);
                $pdo->prepare('UPDATE manual_events SET active = 1 - active WHERE id = ?')->execute([$mid]);
                flash('ok', 'Visibilidad del evento actualizada.');
                go('v=event&id=manual-' . $mid);
                break;

            case 'manual_delete':
                $mid = (int)($_POST['mid'] ?? 0);
                $pdo->prepare('DELETE FROM event_sources WHERE event_id = ?')->execute(['manual-' . $mid]);
                $pdo->prepare('DELETE FROM manual_events WHERE id = ?')->execute([$mid]);
                flash('ok', 'Evento eliminado junto con sus enlaces.');
                go('v=manual');
                break;

            case 'manual_purge':
                $limit = time() - 3 * 86400;
                $st = $pdo->prepare('SELECT id FROM manual_events WHERE start_ts < ?');
                $st->execute([$limit]);
                $old = $st->fetchAll(PDO::FETCH_COLUMN);
                foreach ($old as $oid) {
                    $pdo->prepare('DELETE FROM event_sources WHERE event_id = ?')->execute(['manual-' . (int)$oid]);
                }
                $pdo->prepare('DELETE FROM manual_events WHERE start_ts < ?')->execute([$limit]);
                flash('ok', 'Eventos propios de hace más de 3 días eliminados: ' . count($old) . '.');
                go('v=manual');
                break;

            case 'announcements_save':
                $items = announcements_parse((string)($_POST['text'] ?? ''));
                set_setting($pdo, 'announcements_text', implode("\n", $items));
                set_setting($pdo, 'announcements_on', !empty($_POST['enabled']) ? '1' : '0');
                flash('ok', 'Anuncios guardados: ' . count($items) . (empty($_POST['enabled']) ? ' (barra oculta en la app).' : '.'));
                go('v=announcements');
                break;

            case 'sport_add':
            case 'sport_edit':
            case 'sport_delete':
                $list = sports_catalog_load($pdo);
                $normName = static fn (string $n): string => trim((string)preg_replace('/\s+/u', ' ', $n));
                // Resuelve el logo del formulario: archivo subido > URL > quitar > dejar como estaba.
                $resolveLogo = static function (string $current, string $sportName) use (&$list): ?string {
                    $up = $_FILES['logo_file'] ?? null;
                    if (is_array($up) && (int)($up['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                        $r = save_sport_logo_upload($sportName, $up);
                        if (!$r['ok']) {
                            flash('bad', $r['error']);
                            return null;
                        }
                        return $r['logo'];
                    }
                    $url = trim((string)($_POST['logo_url'] ?? ''));
                    if ($url !== '') {
                        if (sport_valid_logo($url) === '' || strncmp($url, 'uploads/', 8) === 0) {
                            flash('bad', 'La URL del logo debe empezar con http:// o https://');
                            return null;
                        }
                        return $url;
                    }
                    if (!empty($_POST['logo_remove'])) {
                        return '';
                    }
                    return $current;
                };

                if ($a === 'sport_add') {
                    $name = $normName((string)($_POST['name'] ?? ''));
                    $icon = sport_valid_icon((string)($_POST['icon'] ?? ''));
                    if ($name === '' || mb_strlen($name, 'UTF-8') > 80) {
                        flash('bad', 'Escribe el nombre del deporte (hasta 80 caracteres).');
                        go('v=sports');
                    }
                    $idx = null;
                    foreach ($list as $i => $r) {
                        if (sport_key($r['name']) === sport_key($name)) {
                            $idx = $i;
                        }
                    }
                    $current = $idx !== null ? (string)$list[$idx]['logo'] : '';
                    $logo = $resolveLogo($current, $name);
                    if ($logo === null) {
                        go('v=sports');
                    }
                    if ($idx !== null) {
                        $list[$idx]['icon'] = $icon;
                        $list[$idx]['logo'] = $logo;
                        flash('ok', 'Ese deporte ya existía: se actualizó.');
                    } else {
                        $list[] = ['name' => $name, 'icon' => $icon, 'logo' => $logo];
                        flash('ok', 'Deporte agregado: ' . $name . '.');
                    }
                    if ($current !== '' && $current !== $logo) {
                        $tmp = $list;
                        sport_logo_delete_file($current, $tmp);
                    }
                } else {
                    $i = (int)($_POST['i'] ?? -1);
                    if (!isset($list[$i])) {
                        flash('bad', 'Ese deporte ya no existe.');
                        go('v=sports');
                    }
                    if ($a === 'sport_edit') {
                        $old = $list[$i];
                        $name = $normName((string)($_POST['name'] ?? ''));
                        if ($name === '' || mb_strlen($name, 'UTF-8') > 80) {
                            flash('bad', 'Escribe el nombre del deporte (hasta 80 caracteres).');
                            go('v=sports');
                        }
                        foreach ($list as $j => $r) {
                            if ($j !== $i && sport_key($r['name']) === sport_key($name)) {
                                flash('bad', 'Ya existe otro deporte con ese nombre: ' . $r['name'] . '.');
                                go('v=sports');
                            }
                        }
                        $logo = $resolveLogo((string)$old['logo'], $name);
                        if ($logo === null) {
                            go('v=sports');
                        }
                        $list[$i] = ['name' => $name, 'icon' => sport_valid_icon((string)($_POST['icon'] ?? $old['icon'])), 'logo' => $logo];
                        if ($old['logo'] !== '' && $old['logo'] !== $logo) {
                            sport_logo_delete_file((string)$old['logo'], $list);
                        }
                        // Renombrar también en los eventos, para que sigan apareciendo bajo este deporte.
                        $moved = 0;
                        if (sport_key($old['name']) !== sport_key($name) || $old['name'] !== $name) {
                            if (!empty($_POST['rename_events'])) {
                                try {
                                    $st = $pdo->prepare('UPDATE manual_events SET sport = ? WHERE sport = ?');
                                    foreach ($pdo->query("SELECT DISTINCT sport FROM manual_events WHERE sport <> ''")->fetchAll(PDO::FETCH_COLUMN) as $sp) {
                                        if (sport_key((string)$sp) === sport_key((string)$old['name'])) {
                                            $st->execute([$name, $sp]);
                                            $moved += $st->rowCount();
                                        }
                                    }
                                } catch (Throwable $e) {
                                }
                            }
                        }
                        flash('ok', 'Deporte actualizado: ' . $name . ($moved > 0 ? ' (' . $moved . ' eventos renombrados).' : '.'));
                    } else {
                        flash('ok', 'Deporte quitado: ' . $list[$i]['name'] . '.');
                        $gone = $list[$i];
                        array_splice($list, $i, 1);
                        sport_logo_delete_file((string)$gone['logo'], $list);
                    }
                }
                sports_catalog_save($pdo, array_values($list));
                go('v=sports');
                break;

            case 'aliases_save':
                $text = str_replace(["\r\n", "\r"], "\n", (string)($_POST['text'] ?? ''));
                $text = trim(substr($text, 0, 6000));
                set_setting($pdo, 'name_aliases', $text);
                $al = name_aliases($pdo, true);
                // Si el alias tenía escudo / logo y el nombre oficial no, se lo pasa al oficial.
                foreach (['team' => 'team_logos', 'league' => 'league_logos'] as $kind => $tbl) {
                    foreach (name_aliases_parse($text)[$kind] as $aKey => $canon) {
                        $st = $pdo->prepare('SELECT url FROM ' . $tbl . ' WHERE name_key = ?');
                        $st->execute([(string)$aKey]);
                        $url = $st->fetchColumn();
                        $st->execute([team_key($canon)]);
                        if ($url !== false && $st->fetchColumn() === false) {
                            $pdo->prepare('REPLACE INTO ' . $tbl . ' (name_key, name, url) VALUES (?, ?, ?)')
                                ->execute([team_key($canon), substr($canon, 0, 160), (string)$url]);
                        }
                    }
                }
                $r = manual_unify_existing($pdo);
                flash('ok', 'Nombres guardados. Renombrados: ' . $r['renamed'] . ' · partidos repetidos fundidos: ' . $r['merged'] . '.');
                go('v=aliases');
                break;

            case 'config_save':
                $minutes = (int)($_POST['duration'] ?? 0);
                $basket = (int)($_POST['duration_basket'] ?? 0);
                if ($minutes < 30 || $minutes > 720) {
                    flash('bad', 'La duración debe estar entre 30 y 720 minutos.');
                } elseif ($basket < 60 || $basket > 720) {
                    flash('bad', 'La duración del básquet debe estar entre 60 y 720 minutos.');
                } else {
                    set_setting($pdo, 'manual_event_duration', (string)$minutes);
                    set_setting($pdo, 'manual_event_duration_basket', (string)$basket);
                    flash('ok', 'Duraciones guardadas: general ' . $minutes . ' min, básquet ' . $basket . ' min.');
                }
                go('v=config');
                break;

            case 'delete_event':
                if (valid_event_id($eid)) {
                    $st = $pdo->prepare('DELETE FROM event_sources WHERE event_id = ?');
                    $st->execute([$eid]);
                    flash('ok', 'Se eliminaron ' . $st->rowCount() . ' enlace(s) del evento.');
                }
                go('v=linked');
                break;

            case 'purge':
                $st = $pdo->prepare('DELETE FROM event_sources WHERE kickoff IS NOT NULL AND kickoff < ?');
                $st->execute([time() - 3 * 86400]);
                flash('ok', 'Enlaces de eventos de hace más de 3 días eliminados: ' . $st->rowCount() . '.');
                go('v=linked');
                break;
        }
    } catch (Throwable $e) {
        flash('bad', 'Error: ' . $e->getMessage());
    }
    go($back);
}

// ---------------------------------------------------------------------------
// Vistas
// ---------------------------------------------------------------------------

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

ob_start();

if ($fatal) {
    echo '<div class="card"><p class="bad">' . h($fatal) . '</p>'
        . '<p><a class="btn" href="../setup.php">Instalar base de datos</a></p></div>';
} elseif ($needsSetup) {
    ?>
    <div class="card narrow">
      <h2>Crea la contraseña del panel</h2>
      <p class="mut">Se guarda cifrada en la base de datos. Solo se puede crear desde la red local.</p>
      <form method="post">
        <input type="hidden" name="a" value="setup">
        <label>Contraseña (mínimo 8 caracteres)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        <label>Repetir contraseña<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
        <button type="submit">Crear contraseña</button>
      </form>
    </div>
    <?php
} elseif (!$logged) {
    ?>
    <div class="card narrow">
      <h2>Iniciar sesión</h2>
      <form method="post">
        <input type="hidden" name="a" value="login">
        <label>Contraseña<input type="password" name="password" required autofocus autocomplete="current-password"></label>
        <button type="submit">Entrar</button>
      </form>
      <p class="mut">¿Olvidaste la contraseña? En phpMyAdmin ejecuta:<br>
      <code>DELETE FROM app_settings WHERE setting_key='admin_password_hash';</code></p>
    </div>
    <?php
} else {
    $view = admin_view();
    $date = valid_date((string)($_GET['d'] ?? '')) ? (string)$_GET['d'] : date('Y-m-d');

    // Plantilla CSV descargable.
    if ($view === 'import' && !empty($_GET['tpl'])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plantilla_liveo.csv"');
        echo csv_template();
        exit;
    }

    // ---- Detalle de un evento ---------------------------------------------
    if ($view === 'event' && valid_event_id((string)($_GET['id'] ?? ''))) {
        $eid = (string)$_GET['id'];
        $ev = null;
        $manualRow = null;
        if (preg_match('/^manual-(\d+)$/', $eid, $mm)) {
            $mst = $pdo->prepare('SELECT * FROM manual_events WHERE id = ?');
            $mst->execute([(int)$mm[1]]);
            $manualRow = $mst->fetch() ?: null;
            if ($manualRow === null) {
                flash('bad', 'El evento propio ya no existe.');
                go('v=manual');
            }
            $ev = manual_row_to_event($manualRow, manual_duration_minutes($pdo), time());
        }
        $st = $pdo->prepare('SELECT * FROM event_sources WHERE event_id = ? ORDER BY sort_order ASC, id ASC');
        $st->execute([$eid]);
        $rows = $st->fetchAll();
        $snap = $rows[0] ?? [];
        $home = (string)($ev['home'] ?? ($snap['home'] ?? ''));
        $away = (string)($ev['away'] ?? ($snap['away'] ?? ''));
        $league = (string)($ev['league'] ?? ($snap['league'] ?? ''));
        $kick = (int)($ev['kickoff'] ?? ($snap['kickoff'] ?? 0));
        $isLive = !empty($ev['live']);
        $pre = $_SESSION['prefill'] ?? [];
        unset($_SESSION['prefill']);
        $hidden = csrf_field()
            . '<input type="hidden" name="event_id" value="' . h($eid) . '">'
            . '<input type="hidden" name="d" value="' . h($date) . '">';
        ?>
        <p><?php if ($manualRow): ?><a class="link" href="?v=manual">← Volver a los eventos</a><?php else: ?><a class="link" href="?v=manual">← Volver a los eventos</a><?php endif; ?></p>
        <div class="card">
          <h2><?= h($home !== '' ? $home : 'Evento') ?><?php if ($away !== ''): ?> <span class="mut">vs</span> <?= h($away) ?><?php endif; ?></h2>
          <p class="mut"><?= h($league) ?> · <?= h(fmt_ts($kick)) ?>
            <?php if ($isLive): ?> · <span class="tag live">EN VIVO</span><?php endif; ?>
            · ID <code><?= h($eid) ?></code></p>
          <?php if ($manualRow): ?>
            <p class="mut">Estado actual: <b><?= h((string)($ev['status'] ?? '')) ?></b><?= isset($ev['minute']) ? ' · minuto <b>' . h((string)$ev['minute']) . '</b>' : '' ?><?= (int)$manualRow['active'] === 1 ? '' : ' · <span class="tag">Oculto en la app</span>' ?>.
              Las señales se agregan abajo y la app las usa igual que en cualquier otro evento.</p>
          <?php else: ?>
          <p class="mut">Este evento ya no existe en el calendario; se muestran los datos guardados de sus enlaces.</p>
          <?php endif; ?>
        </div>
        <?php
        if ($manualRow) {
            $mpre = $_SESSION['manual_prefill'] ?? null;
            unset($_SESSION['manual_prefill']);
            $mv = is_array($mpre) ? $mpre : [
                'sport'      => (string)$manualRow['sport'],
                'league'     => (string)$manualRow['league'],
                'home'       => (string)$manualRow['home'],
                'away'       => (string)$manualRow['away'],
                'title'      => (string)$manualRow['title'],
                'start'      => date('Y-m-d\TH:i', (int)$manualRow['start_ts']),
                'state_mode' => (string)$manualRow['state_mode'],
                'minute'     => (string)($manualRow['minute'] ?? ''),
                'home_logo'  => (string)($manualRow['home_logo'] ?? ''),
                'away_logo'  => (string)($manualRow['away_logo'] ?? ''),
                'time_tbc'   => (int)($manualRow['time_tbc'] ?? 0),
                'duration'   => (int)($manualRow['duration_min'] ?? 0),
            ];
            ?>
            <div class="card">
              <h2>Control en vivo</h2>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="a" value="manual_quick">
                <input type="hidden" name="mid" value="<?= (int)$manualRow['id'] ?>">
                <div class="grid">
                  <label>Estado
                    <select name="state_mode">
                      <?php foreach (manual_state_options() as $k => $lab): ?>
                        <option value="<?= h($k) ?>" <?= (string)$manualRow['state_mode'] === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Minuto
                    <input name="minute" value="<?= h((string)($manualRow['minute'] ?? '')) ?>" maxlength="12" placeholder="34, 45+2, Descanso">
                  </label>
                </div>
                <div class="actions"><button type="submit">Actualizar en la app</button></div>
              </form>
              <p class="mut">El minuto se muestra en la tarjeta (en rojo, debajo de la hora) únicamente mientras el evento está en vivo. Vacío = no se muestra minuto.</p>
            </div>
            <?php
            echo manual_form_html($pdo, $mv, (int)$manualRow['id'], manual_duration_minutes($pdo));
            ?>
            <div class="card">
              <h2>Visibilidad y eliminación</h2>
              <form method="post" class="actions">
                <?= csrf_field() ?>
                <input type="hidden" name="mid" value="<?= (int)$manualRow['id'] ?>">
                <button name="a" value="manual_toggle" class="alt"><?= (int)$manualRow['active'] === 1 ? 'Ocultar en la app' : 'Mostrar en la app' ?></button>
                <button name="a" value="manual_delete" class="danger" onclick="return confirm('¿Eliminar este evento y todos sus enlaces?')">Eliminar evento</button>
              </form>
            </div>
            <?php
        }
        ?>

        <div class="card">
          <h2>Enlaces del evento (<?= count($rows) ?>)</h2>
          <?php if (!$rows): ?><p class="mut">Todavía no hay enlaces. Agrega el primero abajo.</p><?php endif; ?>
          <?php foreach ($rows as $ri => $r): ?>
            <form method="post" class="src <?= (int)$r['active'] === 1 ? '' : 'off' ?>" id="src-<?= (int)$r['id'] ?>">
              <?= $hidden ?>
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button name="a" value="save" hidden aria-hidden="true" tabindex="-1"></button><?php /* Enter guarda (no mueve) */ ?>
              <div class="grid">
                <label>Nombre<input name="label" value="<?= h($r['label']) ?>" maxlength="60"></label>
                <div class="mover">
                  <span class="mut">Posición <b><?= $ri + 1 ?></b> de <?= count($rows) ?></span>
                  <div class="actions">
                    <button name="a" value="move_up" class="alt" title="Subir (va antes)" <?= $ri === 0 ? 'disabled' : '' ?>>▲ Subir</button>
                    <button name="a" value="move_down" class="alt" title="Bajar (va después)" <?= $ri === count($rows) - 1 ? 'disabled' : '' ?>>▼ Bajar</button>
                  </div>
                </div>
                <input type="hidden" name="sort_order" value="<?= (int)$r['sort_order'] ?>">
              </div>
              <div class="grid">
                <label>URL o código &lt;iframe&gt;<input name="url" value="<?= h(trim((string)($r['embed'] ?? '')) !== '' ? (string)$r['embed'] : (string)$r['url']) ?>" maxlength="2500" required></label>
                <label>Tipo
                  <select name="kind">
                    <option value="hls" <?= (string)$r['kind'] !== 'iframe' ? 'selected' : '' ?>>m3u8 (reproductor de Liveo)</option>
                    <option value="iframe" <?= (string)$r['kind'] === 'iframe' ? 'selected' : '' ?>>iframe (página web)</option>
                  </select>
                </label>
              </div>
              <div class="grid">
                <label>Referer (opcional)<input name="referer" value="<?= h((string)$r['referer']) ?>" maxlength="500"></label>
                <label>User-Agent (opcional)<input name="user_agent" value="<?= h((string)$r['user_agent']) ?>" maxlength="300"></label>
              </div>
              <div class="actions">
                <button name="a" value="save">Guardar</button>
                <button name="a" value="test" class="alt">Probar</button>
                <button name="a" value="toggle" class="alt"><?= (int)$r['active'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                <button name="a" value="delete" class="danger" onclick="return confirm('¿Eliminar este enlace?')">Eliminar</button>
                <?php if ((string)$r['kind'] === 'iframe'): ?><span class="tag">iframe<?= trim((string)($r['embed'] ?? '')) !== '' ? ' (código guardado)' : '' ?></span><?php endif; ?>
                <?php if ((int)$r['active'] !== 1): ?><span class="tag">Desactivado</span><?php endif; ?>
              </div>
            </form>
          <?php endforeach; ?>
        </div>

        <div class="card">
          <h2>Agregar enlace</h2>
          <form method="post">
            <?= $hidden ?>
            <input type="hidden" name="home" value="<?= h($home) ?>">
            <input type="hidden" name="away" value="<?= h($away) ?>">
            <input type="hidden" name="league" value="<?= h($league) ?>">
            <input type="hidden" name="kickoff" value="<?= (int)$kick ?>">
            <div class="grid">
              <label>Nombre (ej. Fuente HD)<input name="label" value="<?= h((string)($pre['label'] ?? '')) ?>" maxlength="60"></label>
            </div>
            <div class="grid">
              <label>URL m3u8, página web o código &lt;iframe&gt;<input name="url" value="<?= h(trim((string)($pre['embed'] ?? '')) !== '' ? (string)$pre['embed'] : (string)($pre['url'] ?? '')) ?>" placeholder="https://…/index.m3u8  ·  https://…/embed/123  ·  &lt;iframe src=…&gt;" maxlength="2500" required></label>
              <label>Tipo
                <select name="kind">
                  <option value="auto">Automático</option>
                  <option value="hls" <?= (string)($pre['kind'] ?? '') === 'hls' ? 'selected' : '' ?>>m3u8 (reproductor de Liveo)</option>
                  <option value="iframe" <?= (string)($pre['kind'] ?? '') === 'iframe' ? 'selected' : '' ?>>iframe (página web)</option>
                </select>
              </label>
            </div>
            <div class="grid">
              <label>Referer (opcional)<input name="referer" value="<?= h((string)($pre['referer'] ?? '')) ?>" maxlength="500"></label>
              <label>User-Agent (opcional)<input name="user_agent" value="<?= h((string)($pre['user_agent'] ?? '')) ?>" maxlength="300"></label>
            </div>
            <div class="actions">
              <button name="a" value="add">Agregar</button>
              <button name="a" value="test_url" class="alt">Probar antes de agregar</button>
            </div>
          </form>
          <p class="mut">Puedes pegar la URL de un .m3u8 o <b>el código <code>&lt;iframe …&gt;</code> completo</b> (se guarda el iframe y también su <code>src</code>): los iframe se abren dentro del reproductor de la app, cargados tal cual los pegaste. «Automático» detecta .m3u8; cualquier otra URL se trata como iframe. Referer y User-Agent solo hacen falta si el servidor los exige. El Chromecast no puede enviarlos ni transmitir iframes.</p>
        </div>

        <div class="card">
          <h2>Agregar varios enlaces a la vez</h2>
          <form method="post">
            <?= $hidden ?>
            <input type="hidden" name="home" value="<?= h($home) ?>">
            <input type="hidden" name="away" value="<?= h($away) ?>">
            <input type="hidden" name="league" value="<?= h($league) ?>">
            <input type="hidden" name="kickoff" value="<?= (int)$kick ?>">
            <label>Un enlace por línea: <code>Nombre | URL</code> o <code>Nombre | &lt;iframe …&gt;</code> (el nombre es opcional)
              <textarea name="bulk" rows="5" placeholder="Fuente HD | https://…/hd/index.m3u8&#10;Web | https://…/embed/123&#10;Otra | &lt;iframe src=&quot;https://…&quot;&gt;&lt;/iframe&gt;" style="width:100%"></textarea>
            </label>
            <div class="grid">
              <label>Referer común (opcional)<input name="referer" maxlength="500"></label>
              <label>User-Agent común (opcional)<input name="user_agent" maxlength="300"></label>
            </div>
            <div class="actions"><button name="a" value="add_many">Agregar todos</button></div>
          </form>
          <p class="mut">Hasta 10 enlaces por evento. En la app, al tocar el evento se abre una ventana con todas las opciones una debajo de la otra.</p>
        </div>
        <?php
    } elseif ($view === 'linked') {
        // ---- Eventos con enlaces ---------------------------------------------
        $list = $pdo->query(
            'SELECT event_id, MAX(home) AS home, MAX(away) AS away, MAX(league) AS league, MAX(kickoff) AS kickoff,
                    COUNT(*) AS n, SUM(active) AS act
             FROM event_sources GROUP BY event_id ORDER BY MAX(kickoff) DESC LIMIT 300'
        )->fetchAll();
        ?>
        <div class="card">
          <h2>Eventos con enlaces (<?= count($list) ?>)</h2>
          <?php if (!$list): ?><p class="mut">Aún no agregaste enlaces.</p><?php endif; ?>
          <?php foreach ($list as $r): ?>
            <a class="row" href="?v=event&id=<?= h(rawurlencode((string)$r['event_id'])) ?>&d=<?= h(rawurlencode($date)) ?>">
              <span class="t"><?= h(fmt_ts($r['kickoff'])) ?></span>
              <span class="m"><b><?= h($r['home']) ?></b><?php if ((string)$r['away'] !== ''): ?> vs <b><?= h($r['away']) ?></b><?php endif; ?><small><?= h($r['league']) ?></small></span>
              <span class="b"><span class="tag"><?= (int)$r['act'] ?>/<?= (int)$r['n'] ?> activos</span></span>
            </a>
          <?php endforeach; ?>
          <form method="post" style="margin-top:14px">
            <?= csrf_field() ?>
            <button name="a" value="purge" class="alt" onclick="return confirm('¿Borrar los enlaces de eventos de hace más de 3 días?')">Limpiar eventos de hace más de 3 días</button>
          </form>
        </div>
        <?php
    } elseif ($view === 'manual') {
        // ---- Eventos ---------------------------------------------------------------
        $f = (string)($_GET['f'] ?? 'up');
        if (!in_array($f, ['up', 'past', 'all'], true)) {
            $f = 'up';
        }
        $q = trim((string)($_GET['q'] ?? ''));
        $nowTs = time();
        $cut = $nowTs - 6 * 3600;
        $where = [];
        $par = [];
        if ($f === 'up') {
            $where[] = "(start_ts >= ? OR state_mode = 'live')";
            $par[] = $cut;
            $order = 'start_ts ASC, id ASC';
        } elseif ($f === 'past') {
            $where[] = 'start_ts < ?';
            $par[] = $cut;
            $order = 'start_ts DESC, id DESC';
        } else {
            $order = 'start_ts DESC, id DESC';
        }
        if ($q !== '') {
            $where[] = '(home LIKE ? OR away LIKE ? OR league LIKE ? OR sport LIKE ?)';
            for ($i = 0; $i < 4; $i++) {
                $par[] = '%' . $q . '%';
            }
        }
        $wsql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM manual_events' . $wsql);
        $cnt->execute($par);
        $total = (int)$cnt->fetchColumn();
        $st = $pdo->prepare('SELECT * FROM manual_events' . $wsql . ' ORDER BY ' . $order . ' LIMIT 300');
        $st->execute($par);
        $rows = $st->fetchAll();
        $linkCounts = [];
        foreach ($pdo->query("SELECT event_id, COUNT(*) AS n, SUM(active) AS act FROM event_sources WHERE event_id LIKE 'manual-%' GROUP BY event_id")->fetchAll() as $r) {
            $linkCounts[(string)$r['event_id']] = [(int)$r['n'], (int)$r['act']];
        }
        $durMin = manual_duration_minutes($pdo);
        // Los eventos en vivo van primero (conservando el orden original dentro de cada grupo).
        $liveRows = [];
        $otherRows = [];
        foreach ($rows as $r) {
            $ev = manual_row_to_event($r, $durMin, $nowTs);
            if (!empty($ev['live'])) {
                $liveRows[] = $r;
            } else {
                $otherRows[] = $r;
            }
        }
        if ($liveRows) {
            usort($liveRows, function ($a, $b) {
                return ((int)$a['start_ts'] <=> (int)$b['start_ts']) ?: ((int)$a['id'] <=> (int)$b['id']);
            });
            $rows = array_merge($liveRows, $otherRows);
        }
        ?>
        <div class="card">
          <form method="get" class="filters">
            <input type="hidden" name="v" value="manual">
            <label>Mostrar
              <select name="f">
                <option value="up" <?= $f === 'up' ? 'selected' : '' ?>>Próximos y en vivo</option>
                <option value="past" <?= $f === 'past' ? 'selected' : '' ?>>Pasados</option>
                <option value="all" <?= $f === 'all' ? 'selected' : '' ?>>Todos</option>
              </select>
            </label>
            <label>Buscar<input type="search" name="q" value="<?= h($q) ?>" placeholder="equipo, liga o deporte"></label>
            <button type="submit">Filtrar</button>
            <a class="btn" href="?v=manual_new">+ Nuevo</a>
          </form>
        </div>
        <div class="card">
          <h2>Eventos (<?= $total ?>)</h2>
          <p class="mut">Los eventos se cargan a mano o con <a class="link" href="?v=import">Importar CSV</a>. Toca uno para cambiar su estado y minuto, o agregarle enlaces.<?= $total > 300 ? ' Se muestran los primeros 300; usa el buscador para acotar.' : '' ?></p>
          <?php if (!$rows): ?><p class="mut">No hay eventos para este filtro.</p><?php endif; ?>
          <?php foreach ($rows as $r):
              $e = manual_row_to_event($r, $durMin, $nowTs);
              $lc = $linkCounts['manual-' . (int)$r['id']] ?? [0, 0];
              $tbc = !empty($r['time_tbc']); ?>
            <a class="row" href="?v=event&id=<?= h(rawurlencode('manual-' . (int)$r['id'])) ?>">
              <span class="t"><?php if (!empty($e['live'])): ?><span class="tag live">VIVO</span><?= isset($e['minute']) ? ' ' . h((string)$e['minute']) . '\'' : '' ?><?php elseif ($tbc): ?><?= h(date('d/m', (int)$r['start_ts'])) ?><br><small>sin hora</small><?php else: ?><?= h(fmt_ts($r['start_ts'])) ?><?php endif; ?></span>
              <span class="m"><b><?= h($r['home']) ?></b><?php if ((string)$r['away'] !== ''): ?> vs <b><?= h($r['away']) ?></b><?php endif; ?><small><?= h(trim($r['sport'] . ($r['sport'] !== '' && $r['league'] !== '' ? ' · ' : '') . $r['league'])) ?></small></span>
              <span class="b">
                <span class="tag"><?= h((string)$e['status']) ?></span>
                <?php if ($lc[0] > 0): ?><span class="tag ok"><?= $lc[1] ?>/<?= $lc[0] ?> enlaces</span><?php else: ?><span class="tag">Sin enlaces</span><?php endif; ?>
                <?php if ((int)$r['active'] !== 1): ?><span class="tag">Oculto</span><?php endif; ?>
              </span>
            </a>
          <?php endforeach; ?>
          <form method="post" style="margin-top:14px">
            <?= csrf_field() ?>
            <button name="a" value="manual_purge" class="alt" onclick="return confirm('¿Borrar los eventos de hace más de 3 días y sus enlaces?')">Limpiar eventos de hace más de 3 días</button>
          </form>
        </div>
        <?php
    } elseif ($view === 'manual_new') {
        // ---- Nuevo evento propio -------------------------------------------------
        $mpre = $_SESSION['manual_prefill'] ?? null;
        unset($_SESSION['manual_prefill']);
        $mv = is_array($mpre) ? $mpre : [
            'sport' => '', 'league' => '', 'home' => '', 'away' => '', 'title' => '',
            'start' => date('Y-m-d\TH:i', (int)(ceil(time() / 900) * 900)),
            'state_mode' => 'auto', 'minute' => '', 'home_logo' => '', 'away_logo' => '', 'time_tbc' => 0, 'duration' => 0,
        ];
        echo '<p><a class="link" href="?v=manual">← Volver a los eventos</a></p>';
        echo manual_form_html($pdo, $mv, 0, manual_duration_minutes($pdo));
    } elseif ($view === 'announcements') {
        // ---- Anuncios informativos -------------------------------------------------
        $ann = announcements_load($pdo);
        ?>
        <div class="card narrow" style="margin:0 0 17px;max-width:720px">
          <h2>Anuncios de la app</h2>
          <p class="mut">Aparecen en el inicio de la app como una barra con un megáfono y el texto deslizándose. Escribe <b>un anuncio por línea</b> (hasta 10, de 200 caracteres como máximo); pasan uno tras otro y se repiten. La app los actualiza al abrirla o al deslizar para recargar.</p>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="announcements_save">
            <label>Anuncios
              <textarea name="text" rows="7" maxlength="2500" placeholder="Nueva versión disponible: actualizá desde el panel de descargas&#10;Seguinos en Telegram para enterarte de las novedades"><?= h(implode("\n", $ann['items'])) ?></textarea>
            </label>
            <label style="color:var(--txt)"><input type="checkbox" name="enabled" value="1" <?= $ann['enabled'] ? 'checked' : '' ?>> Mostrar la barra de anuncios en la app</label>
            <div class="actions"><button type="submit">Guardar</button></div>
          </form>
        </div>
        <?php
    } elseif ($view === 'sports') {
        // ---- Deportes (nombre + ícono del filtro de la app) ----------------------
        $catalog = sports_catalog_load($pdo);
        $icons = sport_icons();
        $counts = [];
        $unlisted = [];
        try {
            foreach ($pdo->query("SELECT sport, COUNT(*) AS n FROM manual_events WHERE sport <> '' GROUP BY sport")->fetchAll() as $r) {
                $k = sport_key((string)$r['sport']);
                $counts[$k] = ($counts[$k] ?? 0) + (int)$r['n'];
                $unlisted[$k] = (string)$r['sport'];
            }
        } catch (Throwable $e) {
        }
        foreach ($catalog as $r) {
            unset($unlisted[sport_key($r['name'])]);
        }
        $svg = static function (string $key, int $size = 22) use ($icons): string {
            $inner = $icons[$key][1] ?? $icons['trophy'][1];
            return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
        };
        $picker = static function (string $sel, string $name) use ($icons, $svg): string {
            $o = '<div class="ico-grid">';
            foreach ($icons as $k => $def) {
                $o .= '<label title="' . h($def[0]) . '"><input type="radio" name="' . h($name) . '" value="' . h($k) . '"' . ($k === $sel ? ' checked' : '') . '><span>' . $svg($k) . '</span></label>';
            }
            return $o . '</div>';
        };
        $logoSrc = static fn (string $l): string => strncmp($l, 'uploads/', 8) === 0 ? '../' . $l : $l;
        $glyph = static function (array $r) use ($svg, $logoSrc): string {
            if ($r['logo'] !== '') {
                return '<img src="' . h($logoSrc($r['logo'])) . '" alt="" width="30" height="30" style="object-fit:contain" referrerpolicy="no-referrer">';
            }
            return $svg($r['icon']);
        };
        ?>
        <div class="card" style="margin:0 0 17px">
          <h2>Deportes (<?= count($catalog) ?>)</h2>
          <p class="mut">El nombre debe coincidir con el campo «Deporte» de los eventos (no importan mayúsculas ni tildes). En la app aparece con tu logo (si subiste uno) o con el ícono que elijas. Un deporte que no esté en esta lista se muestra igual, con el ícono de trofeo.</p>
          <?php foreach ($catalog as $i => $r): ?>
            <div class="sport-row">
              <div class="sport-top">
                <span class="sport-ico"><?= $glyph($r) ?></span>
                <div style="flex:1"><b><?= h($r['name']) ?></b><small class="mut" style="display:block;margin-top:3px"><?= (int)($counts[sport_key($r['name'])] ?? 0) ?> eventos<?= $r['logo'] !== '' ? ' · logo propio' : '' ?></small></div>
                <form method="post" style="display:inline" onsubmit="return confirm('¿Quitar este deporte de la lista?')">
                  <?= csrf_field() ?><input type="hidden" name="a" value="sport_delete"><input type="hidden" name="i" value="<?= (int)$i ?>">
                  <button type="submit" class="danger">Quitar</button>
                </form>
              </div>
              <details>
                <summary class="alt-btn">Editar nombre, logo o ícono</summary>
                <form method="post" enctype="multipart/form-data">
                  <?= csrf_field() ?><input type="hidden" name="a" value="sport_edit"><input type="hidden" name="i" value="<?= (int)$i ?>">
                  <label>Nombre<input name="name" maxlength="80" value="<?= h($r['name']) ?>" required></label>
                  <label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" name="rename_events" value="1" checked style="width:auto"> Renombrar también en los eventos que ya lo usan</label>
                  <p class="mut" style="margin:10px 0 6px">Logo propio <small>(opcional; reemplaza al ícono en la app)</small></p>
                  <?php if ($r['logo'] !== ''): ?>
                    <p style="margin:0 0 8px"><img src="<?= h($logoSrc($r['logo'])) ?>" alt="" width="48" height="48" style="object-fit:contain;vertical-align:middle;background:var(--panel2);border-radius:10px;padding:4px" referrerpolicy="no-referrer">
                    <label style="display:inline-flex;gap:6px;align-items:center;margin-left:10px"><input type="checkbox" name="logo_remove" value="1" style="width:auto"> Quitar logo</label></p>
                  <?php endif; ?>
                  <label>Subir imagen<input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
                  <label>…o pegar una URL<input name="logo_url" maxlength="490" placeholder="https://…/logo.png"></label>
                  <p class="mut" style="margin:10px 0 6px">Ícono <small>(se usa si no hay logo)</small></p>
                  <?= $picker($r['icon'], 'icon') ?>
                  <div class="actions"><button type="submit">Guardar cambios</button></div>
                </form>
              </details>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="card" style="margin:0 0 17px">
          <h2>Agregar deporte</h2>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="a" value="sport_add">
            <label>Nombre<input name="name" maxlength="80" placeholder="Hockey" required list="sports-unlisted"></label>
            <datalist id="sports-unlisted"><?php foreach ($unlisted as $u): ?><option value="<?= h($u) ?>"><?php endforeach; ?></datalist>
            <p class="mut" style="margin:10px 0 6px">Logo propio <small>(opcional)</small></p>
            <label>Subir imagen<input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
            <label>…o pegar una URL<input name="logo_url" maxlength="490" placeholder="https://…/logo.png"></label>
            <p class="mut" style="margin:10px 0 6px">Ícono <small>(se usa si no hay logo)</small></p>
            <?= $picker('trophy', 'icon') ?>
            <div class="actions"><button type="submit">Agregar deporte</button></div>
          </form>
          <?php if ($unlisted): ?><p class="mut" style="margin-top:12px">Deportes que ya usan tus eventos y no están en la lista: <?= h(implode(', ', array_values($unlisted))) ?>.</p><?php endif; ?>
        </div>
        <?php
    } elseif ($view === 'aliases') {
        // ---- Nombres unificados ----------------------------------------------------
        $mine = (string)(get_setting($pdo, 'name_aliases') ?? '');
        $def = [];
        foreach (name_alias_defaults() as $kind => $pairs) {
            foreach ($pairs as $a => $c) {
                $def[] = ($kind === 'league' ? 'liga: ' : '') . $a . ' = ' . $c;
            }
        }
        ?>
        <div class="card narrow" style="margin:0 0 17px;max-width:720px">
          <h2>Nombres unificados</h2>
          <p class="mut">Cada equipo y cada competición debe llamarse <b>siempre igual</b>, aunque cada CSV lo escriba distinto («Atlético-MG» / «Atlético Mineiro», «Brasileirão Série A» / «Brasileirão»). Al importar y al guardar un evento, Liveo ya une solo las variantes con otras tildes o mayúsculas y las que solo cambian «1.», «FC», «VfB», «VfL»… Para los demás casos escribe <b>una línea por alias</b>: <code>Como lo escribe el CSV = Nombre oficial</code>. Para competiciones antepón <code>liga:</code>. El nombre oficial es el que tiene escudo / logo. Al guardar se corrigen también los eventos que ya están cargados.</p>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="aliases_save">
            <label>Tus alias
              <textarea name="text" rows="8" maxlength="6000" placeholder="Man City = Manchester City&#10;liga: Serie A Brasil = Brasileirão"><?= h($mine) ?></textarea>
            </label>
            <div class="actions"><button type="submit">Guardar y unificar ahora</button></div>
          </form>
          <p class="mut" style="margin-top:14px">Ya incluidos de fábrica (no hace falta repetirlos):</p>
          <pre style="white-space:pre-wrap;margin:0"><?= h(implode("\n", $def)) ?></pre>
        </div>
        <?php
    } elseif ($view === 'config') {
        // ---- Configuración -------------------------------------------------------
        $durMin = manual_duration_minutes($pdo);
        $basketMin = manual_basket_duration_minutes($pdo);
        ?>
        <div class="card narrow" style="margin:0 0 17px;max-width:560px">
          <h2>Configuración</h2>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="config_save">
            <label>Duración de un evento propio (minutos)
              <input type="number" name="duration" min="30" max="720" value="<?= (int)$durMin ?>" required>
            </label>
            <p class="mut">Define cuándo termina un evento en estado «Automático por horario»: inicio + esta duración. Mientras tanto figura en vivo y después pasa a finalizado. Por defecto 135 minutos (entre 30 y 720).</p>
            <label>Duración de un partido de básquet / NBA (minutos)
              <input type="number" name="duration_basket" min="60" max="720" value="<?= (int)$basketMin ?>" required>
            </label>
            <p class="mut">Se aplica a los eventos cuyo deporte o liga sea básquet, baloncesto, basketball o NBA (los pausas, tiempos muertos y tiempos extra hacen que duren más que el fútbol). Por defecto 180 minutos (entre 60 y 720). Si un evento tiene su propia duración, esa manda.</p>
            <div class="actions"><button type="submit">Guardar</button></div>
          </form>
        </div>
        <?php
    } elseif ($view === 'import') {
        // ---- Importar CSV ---------------------------------------------------------
        ?>
        <div class="card" style="max-width:720px">
          <h2>Importar eventos desde CSV</h2>
          <p class="mut">Sube el calendario y los eventos aparecen en la app. Si lo vuelves a subir, los eventos se <b>actualizan</b> (no se duplican) y se conservan el estado, el minuto, la visibilidad y los enlaces que ya cargaste.</p>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="csv_import">
            <label>Archivo CSV<input type="file" name="csv" accept=".csv,.txt,text/csv" required></label>
            <label style="color:var(--txt)"><input type="checkbox" name="skip_info" value="1" checked> Omitir filas informativas (ventanas, períodos y jornadas sin partidos concretos)</label>
            <div class="actions">
              <button type="submit">Importar</button>
              <a class="btn alt" href="?v=import&tpl=1">Descargar plantilla</a>
            </div>
          </form>
        </div>
        <div class="card" style="max-width:720px">
          <h2>Columnas que entiende</h2>
          <p class="mut">
            <b>fecha</b> (AAAA-MM-DD o DD/MM/AAAA) · <b>hora</b> (cualquier columna que empiece con «hora», p. ej. <code>hora_Argentina_ART</code>; vacía = hora a confirmar) · <b>hasta</b> (hora de fin, HH:MM, o <code>duracion_min</code>; define hasta cuándo está «en vivo») ·
            <b>deporte</b> · <b>competicion</b> · <b>evento</b> («Local vs Visitante»; sin «vs» es un evento de un solo título).<br>
            Opcionales: <b>local</b>, <b>visitante</b>, <b>escudo_local</b>, <b>escudo_visitante</b> (URLs), <b>estado</b> (auto, programado, en vivo, finalizado, postergado, cancelado) y <b>minuto</b>.
            Las demás columnas (<code>dia</code>, <code>detalle</code>, <code>fuente</code>…) se ignoran. Las horas se interpretan en <b><?= h((string)liveo_config()['timezone']) ?></b>.
          </p>
        </div>
        <?php
    } elseif ($view === 'leagues') {
        // ---- Logos de competiciones ------------------------------------------------
        ensure_manual_schema($pdo);
        $leagueMap = [];
        foreach ($pdo->query('SELECT name_key, name, url FROM league_logos')->fetchAll() as $r) {
            $leagueMap[(string)$r['name_key']] = ['name' => (string)$r['name'], 'url' => (string)$r['url']];
        }
        $leagues = [];
        foreach ($pdo->query("SELECT DISTINCT league FROM manual_events WHERE league <> ''")->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $name = trim((string)$name);
            $key = team_key($name);
            if ($key !== '' && !isset($leagues[$key])) {
                $leagues[$key] = ['name' => $name, 'url' => $leagueMap[$key]['url'] ?? ''];
            }
        }
        foreach ($leagueMap as $key => $l) {
            if (!isset($leagues[$key])) {
                $leagues[$key] = ['name' => $l['name'], 'url' => $l['url']];
            }
        }
        $list = array_values($leagues);
        usort($list, function ($a, $b) {
            return (($a['url'] === '') ? 0 : 1) <=> (($b['url'] === '') ? 0 : 1) ?: strcasecmp($a['name'], $b['name']);
        });
        $missingL = 0;
        foreach ($list as $t) {
            if ($t['url'] === '') {
                $missingL++;
            }
        }
        ?>
        <div class="card">
          <h2>Competiciones (<?= count($list) ?> · <?= $missingL ?> sin logo)</h2>
          <p class="mut">Sube o pega el logo de cada competición (NBA, Brasileirão…) <b>una sola vez</b>: aparece a la izquierda del partido en la pantalla del reproductor de la app, en todos los eventos de esa competición. Vacía una URL para quitar el logo. Mejor PNG con fondo transparente, cuadrado.</p>
          <?php if (!$list): ?><p class="mut">Todavía no hay competiciones: importa un CSV o crea un evento.</p><?php else: ?>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="leagues_save">
            <?php foreach ($list as $t): ?>
              <div class="row" style="cursor:default">
                <span class="t" style="width:44px"><?php if ($t['url'] !== ''): ?><img src="<?= h(strncmp($t['url'], 'uploads/', 8) === 0 ? '../' . $t['url'] : $t['url']) ?>" alt="" width="32" height="32" style="object-fit:contain" referrerpolicy="no-referrer"><?php endif; ?></span>
                <span class="m" style="flex:0 0 170px"><b><?= h($t['name']) ?></b><input type="hidden" name="name[]" value="<?= h($t['name']) ?>"></span>
                <span class="m"><input name="url[]" value="<?= h($t['url']) ?>" maxlength="500" placeholder="URL del logo, o sube una imagen →" style="margin-top:0"></span>
                <span class="m" style="flex:0 0 190px"><input type="file" name="file[<?= h(team_key($t['name'])) ?>]" accept="image/png,image/jpeg,image/webp,image/gif" style="margin-top:0"></span>
              </div>
            <?php endforeach; ?>
            <div class="actions" style="margin-top:14px"><button type="submit">Guardar logos</button></div>
          </form>
          <?php endif; ?>
        </div>
        <?php
    } else {
        // ---- Escudos por equipo ---------------------------------------------------
        $q = trim((string)($_GET['q'] ?? ''));
        $logoMap = [];
        foreach ($pdo->query('SELECT name_key, name, url FROM team_logos')->fetchAll() as $r) {
            $logoMap[(string)$r['name_key']] = ['name' => (string)$r['name'], 'url' => (string)$r['url']];
        }
        $teams = [];
        $sqlTeams = "SELECT home AS t FROM manual_events WHERE away <> '' UNION SELECT away FROM manual_events WHERE away <> ''";
        foreach ($pdo->query($sqlTeams)->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $name = trim((string)$name);
            $key = team_key($name);
            if ($key !== '' && !isset($teams[$key])) {
                $teams[$key] = ['name' => $name, 'url' => $logoMap[$key]['url'] ?? ''];
            }
        }
        foreach ($logoMap as $key => $l) {
            if (!isset($teams[$key])) {
                $teams[$key] = ['name' => $l['name'], 'url' => $l['url']];
            }
        }
        $missing = 0;
        foreach ($teams as $t) {
            if ($t['url'] === '') {
                $missing++;
            }
        }
        $list = array_values(array_filter($teams, function ($t) use ($q) {
            return $q === '' || contains_ci($t['name'], $q);
        }));
        usort($list, function ($a, $b) {
            return (($a['url'] === '') ? 0 : 1) <=> (($b['url'] === '') ? 0 : 1) ?: strcasecmp($a['name'], $b['name']);
        });
        $shown = array_slice($list, 0, 150);
        ?>
        <div class="card">
          <form method="get" class="filters">
            <input type="hidden" name="v" value="logos">
            <label>Buscar equipo<input type="search" name="q" value="<?= h($q) ?>" placeholder="nombre del equipo"></label>
            <button type="submit">Filtrar</button>
          </form>
        </div>
        <div class="card">
          <h2>Escudos (<?= count($teams) ?> equipos · <?= $missing ?> sin escudo)</h2>
          <p class="mut">Sube o pega el escudo de cada equipo <b>una sola vez</b>: queda guardado y se usa en todos sus partidos, los de hoy y los futuros (también los que importes después). Lo que cargues en un evento también se guarda para ese equipo. Los equipos sin escudo se ven con sus iniciales en la app. Vacía una URL para quitar el escudo.<?= count($list) > 150 ? ' Se muestran 150; usa el buscador para ver otros.' : '' ?></p>
          <?php if (!$shown): ?><p class="mut">Todavía no hay equipos: importa un CSV o crea un evento.</p><?php else: ?>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="logos_save">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <?php foreach ($shown as $t): ?>
              <div class="row" style="cursor:default">
                <span class="t" style="width:44px"><?php if ($t['url'] !== ''): ?><img src="<?= h(strncmp($t['url'], 'uploads/', 8) === 0 ? '../' . $t['url'] : $t['url']) ?>" alt="" width="32" height="32" style="object-fit:contain" referrerpolicy="no-referrer"><?php endif; ?></span>
                <span class="m" style="flex:0 0 170px"><b><?= h($t['name']) ?></b><input type="hidden" name="name[]" value="<?= h($t['name']) ?>"></span>
                <span class="m"><input name="url[]" value="<?= h($t['url']) ?>" maxlength="500" placeholder="URL del escudo, o sube una imagen →" style="margin-top:0"></span>
                <span class="m" style="flex:0 0 190px"><input type="file" name="file[<?= h(team_key($t['name'])) ?>]" accept="image/png,image/jpeg,image/webp,image/gif" style="margin-top:0"></span>
              </div>
            <?php endforeach; ?>
            <div class="actions" style="margin-top:14px"><button type="submit">Guardar escudos</button></div>
          </form>
          <?php endif; ?>
        </div>
        <div class="card" style="max-width:720px">
          <h2>Subir varias imágenes a la vez</h2>
          <p class="mut">Elige muchas imágenes (PNG, JPG, WEBP o GIF, hasta 1,5 MB cada una) <b>con el nombre del equipo como nombre de archivo</b>: <code>Chelsea.png</code>, <code>Real Madrid.png</code>… Cada una queda guardada para ese equipo.</p>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="logos_bulk">
            <label>Imágenes<input type="file" name="bulk[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple required></label>
            <div class="actions"><button type="submit">Subir escudos</button></div>
          </form>
        </div>
        <div class="card" style="max-width:720px">
          <h2>Importar escudos desde CSV</h2>
          <p class="mut">Un CSV con dos columnas: <code>equipo</code> y <code>escudo</code> (URL de la imagen).</p>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="a" value="logos_csv">
            <label>Archivo CSV<input type="file" name="csv" accept=".csv,.txt,text/csv" required></label>
            <div class="actions"><button type="submit">Importar escudos</button></div>
          </form>
        </div>
        <?php
    }
}

$body = (string)ob_get_clean();
$nav = $logged && !$fatal && !$needsSetup;
$view = admin_view();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Liveo · Administración</title>
<link rel="icon" href="../assets/favicon.ico?v=1.0.22" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="../assets/liveo_icon.png?v=1.0.22">
<link rel="apple-touch-icon" href="../assets/liveo_icon.png?v=1.0.22">
<meta name="theme-color" content="#101722">
<style>
  :root { color-scheme: dark; --bg:#0a1019; --panel:#111b29; --panel2:#162235; --line:#26364b; --txt:#edf3fb; --mut:#91a2b9; --accent:#70dfb0; --accent2:#49b98e; --bad:#ff737d; --shadow:0 18px 48px rgba(0,0,0,.22); }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:radial-gradient(ellipse at 12% -10%,rgba(47,113,112,.18),transparent 38%),radial-gradient(ellipse at 100% 0%,rgba(54,83,130,.17),transparent 34%),var(--bg); color:var(--txt); }
  main { width:min(1160px,100%); margin:0 auto; padding:28px 22px 72px; }
  header { display:flex; align-items:center; justify-content:space-between; gap:18px; flex-wrap:wrap; padding:15px 18px; margin-bottom:24px; border:1px solid rgba(139,164,195,.16); border-radius:18px; background:rgba(17,27,41,.86); box-shadow:var(--shadow); backdrop-filter:blur(14px); }
  .brand { display:flex; align-items:center; gap:12px; }
  .brand img { display:block; flex:0 0 48px; width:48px; height:48px; object-fit:contain; object-position:center; border-radius:12px; }
  h1 { margin:0; font-size:19px; letter-spacing:3px; font-weight:900; line-height:1.2; }
  h1 + small,.brand small { display:block; color:var(--mut); font-size:11px; letter-spacing:.3px; margin-top:4px; }
  h2 { margin:0 0 12px; font-size:16px; letter-spacing:.1px; }
  nav { display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
  nav a,nav button { background:#172438; color:#dce7f5; border:1px solid #2a3c54; border-radius:10px; padding:9px 13px; font-size:12px; text-decoration:none; cursor:pointer; transition:.18s ease; }
  nav a:hover,nav button:hover { border-color:var(--accent); color:var(--accent); transform:translateY(-1px); }
  nav a.on { background:rgba(112,223,176,.1); border-color:rgba(112,223,176,.55); color:var(--accent); }
  .card { background:linear-gradient(145deg,rgba(22,34,53,.96),rgba(16,26,40,.96)); border:1px solid rgba(139,164,195,.17); border-radius:17px; padding:21px; margin-bottom:17px; box-shadow:var(--shadow); }
  .narrow { max-width:440px; margin:54px auto; }
  .mut { color:var(--mut); font-size:13px; line-height:1.6; }
  .ok { color:var(--accent); } .bad { color:var(--bad); }
  code { background:#0b1420; border:1px solid var(--line); border-radius:7px; padding:3px 7px; word-break:break-all; font-size:12px; }
  label { display:block; font-size:12px; color:#a9b8cc; margin:0 0 12px; }
  input, textarea { display:block; width:100%; margin-top:6px; background:#0a1421; color:var(--txt); border:1px solid #2b3c53; border-radius:10px; padding:11px 12px; font-size:14px; outline:none; font-family:inherit; transition:border-color .18s,box-shadow .18s; }
  input:focus, textarea:focus { border-color:var(--accent2); box-shadow:0 0 0 3px rgba(112,223,176,.12); }
  select { display:block; width:100%; margin-top:6px; background:#0a1421; color:var(--txt); border:1px solid #2b3c53; border-radius:10px; padding:11px 12px; font-size:14px; outline:none; }
  select:focus { border-color:var(--accent2); box-shadow:0 0 0 3px rgba(112,223,176,.12); }
  input.auto-end { border-style:dashed; opacity:.75; }
  label .mut { display:block; margin-top:6px; font-size:11px; }
  input[type=checkbox] { display:inline-block; width:auto; margin:0 7px 0 0; accent-color:var(--accent2); }
  .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .filters { display:grid; grid-template-columns:1fr 1.4fr auto auto; gap:12px; align-items:end; }
  .filters > a.btn, .filters > button { margin-bottom:12px; }
  .filters .chk { margin:0 0 12px; color:var(--txt); white-space:nowrap; }
  button { background:linear-gradient(135deg,var(--accent),#8be7c1); color:#092018; border:0; border-radius:10px; padding:10px 15px; font-weight:800; cursor:pointer; font-size:12px; transition:filter .18s,transform .18s; }
  button:hover { filter:brightness(1.06); transform:translateY(-1px); }
  button.alt { background:#263850; color:#e3edf9; border:1px solid #344a66; }
  button.danger { background:rgba(255,115,125,.12); color:#ff9aa2; border:1px solid rgba(255,115,125,.3); }
  .mover { display:flex; flex-direction:column; gap:6px; justify-content:flex-end; font-size:12px; }
  button:disabled { opacity:.35; cursor:default; }
  .sport-row { padding:12px 0; border-top:1px solid rgba(139,164,195,.12); }
  .sport-top { display:flex; align-items:center; gap:14px; }
  .sport-ico { width:40px; height:40px; border-radius:10px; border:1px solid var(--line); background:var(--panel2); color:var(--accent); display:grid; place-items:center; flex:none; }
  .sport-row details { margin-top:10px; }
  .sport-row summary { cursor:pointer; color:var(--accent); font-size:13px; font-weight:600; }
  .ico-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(46px,1fr)); gap:8px; margin:12px 0; }
  .ico-grid label { margin:0; cursor:pointer; position:relative; display:block; }
  .ico-grid input { position:absolute; opacity:0; width:0; height:0; }
  .ico-grid span { display:grid; place-items:center; height:46px; border-radius:10px; border:1px solid var(--line); background:var(--panel2); color:#c6d3e4; }
  .ico-grid input:checked + span { border:1.5px solid var(--accent); background:rgba(112,223,176,.12); color:var(--accent); }
  .ico-grid input:focus-visible + span { outline:2px solid var(--accent); outline-offset:2px; }
  .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  a.btn { display:inline-block; background:linear-gradient(135deg,var(--accent),#8be7c1); color:#092018; border-radius:10px; padding:10px 15px; font-weight:800; text-decoration:none; font-size:12px; }
  a.btn.alt { background:#263850; color:#e3edf9; border:1px solid #344a66; }
  a.link { color:var(--accent); text-decoration:none; }
  .src { border-top:1px solid var(--line); padding-top:16px; margin-top:16px; }
  .src:first-of-type { border-top:0; margin-top:0; padding-top:0; }
  .src.off { opacity:.58; }
  .row { display:flex; gap:14px; align-items:center; padding:14px 10px; border-top:1px solid rgba(139,164,195,.12); text-decoration:none; color:var(--txt); border-radius:9px; transition:background .16s; }
  .row:first-of-type { border-top:0; }
  .row:hover { background:rgba(111,144,180,.09); }
  .row .t { width:78px; color:var(--mut); font-size:12px; flex:none; }
  .row .t small { font-size:10px; }
  .row .m { flex:1; min-width:0; }
  .row .m b { font-weight:700; }
  .row .m small { display:block; color:var(--mut); margin-top:4px; font-size:11px; }
  .row .b { display:flex; gap:6px; flex:none; flex-wrap:wrap; justify-content:flex-end; }
  .tag { display:inline-block; font-size:10px; padding:4px 9px; border-radius:999px; border:1px solid #34465d; color:#aebdd0; background:rgba(7,14,23,.25); }
  .tag.ok { color:var(--accent); border-color:rgba(112,223,176,.45); background:rgba(112,223,176,.07); }
  .tag.live { color:#fff; background:#d94b59; border-color:#d94b59; font-weight:800; }
  .flash { border-radius:12px; padding:12px 15px; margin-bottom:13px; font-size:13px; border:1px solid var(--line); background:var(--panel); }
  .flash.ok { border-color:rgba(112,223,176,.45); } .flash.bad { border-color:rgba(255,115,125,.5); }
  @media(max-width:760px) { main{padding:16px 12px 48px} header{padding:13px} .filters{grid-template-columns:1fr 1fr} }
  @media(max-width:540px) { .grid,.filters{grid-template-columns:1fr} .row{flex-wrap:wrap;gap:7px} .row .t{width:auto;min-width:65px} .row .b{width:100%;justify-content:flex-start;padding-left:72px} }
</style>
</head>
<body>
<main>
  <header>
    <div class="brand"><img src="../assets/liveo_icon.png?v=1.0.22" alt="Liveo" width="48" height="48"><div><h1>LIVEO</h1><small>Centro de administración · v<?= h(LIVEO_VERSION) ?></small></div></div>
    <?php if ($nav): ?>
    <nav>
      <a href="?v=manual" class="<?= in_array($view, ['manual', 'manual_new', 'event'], true) ? 'on' : '' ?>">Eventos</a>
      <a href="?v=import" class="<?= $view === 'import' ? 'on' : '' ?>">Importar CSV</a>
      <a href="?v=logos" class="<?= $view === 'logos' ? 'on' : '' ?>">Escudos</a>
      <a href="?v=leagues" class="<?= $view === 'leagues' ? 'on' : '' ?>">Competiciones</a>
      <a href="?v=aliases" class="<?= $view === 'aliases' ? 'on' : '' ?>">Nombres</a>
      <a href="?v=sports" class="<?= $view === 'sports' ? 'on' : '' ?>">Deportes</a>
      <a href="?v=linked" class="<?= $view === 'linked' ? 'on' : '' ?>">Con enlaces</a>
      <a href="?v=announcements" class="<?= $view === 'announcements' ? 'on' : '' ?>">Anuncios</a>
      <a href="?v=config" class="<?= $view === 'config' ? 'on' : '' ?>">Configuración</a>
      <a href="../">Backend</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="a" value="logout"><button type="submit">Cerrar sesión</button></form>
    </nav>
    <?php endif; ?>
  </header>
  <?php foreach ($flashes as $f): ?>
    <div class="flash <?= h($f[0]) ?>"><?= h($f[1]) ?></div>
  <?php endforeach; ?>
  <?= $body ?>
</main>
</body>
</html>
