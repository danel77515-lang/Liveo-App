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

function valid_event_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $id);
}

/** Lee y valida los campos de un enlace del formulario. */
function read_source_fields(): array
{
    $f = [
        'label'      => trim((string)($_POST['label'] ?? '')),
        'url'        => trim((string)($_POST['url'] ?? '')),
        'referer'    => trim((string)($_POST['referer'] ?? '')),
        'user_agent' => trim((string)($_POST['user_agent'] ?? '')),
        'err'        => null,
    ];
    if (strlen($f['label']) > 60) {
        $f['err'] = 'El nombre del enlace admite hasta 60 caracteres.';
    } elseif ($f['url'] === '' || strlen($f['url']) > 1000 || !preg_match('#^https?://\S+$#i', $f['url'])) {
        $f['err'] = 'La URL debe empezar con http:// o https://, sin espacios (máximo 1000 caracteres).';
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
    $back = valid_event_id($eid) ? 'v=event&id=' . rawurlencode($eid) . '&d=' . rawurlencode((string)($_POST['d'] ?? '')) : 'v=home';

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
                    $m = probe_message(probe_m3u8($f['url'], $f['referer'], $f['user_agent']));
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
                    'INSERT INTO event_sources (event_id, label, url, referer, user_agent, sort_order, active, home, away, league, kickoff)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
                )->execute([
                    $eid,
                    $f['label'] !== '' ? $f['label'] : 'Fuente ' . ($n + 1),
                    $f['url'],
                    $f['referer'] !== '' ? $f['referer'] : null,
                    $f['user_agent'] !== '' ? $f['user_agent'] : null,
                    $n,
                    substr(trim((string)($_POST['home'] ?? '')), 0, 120),
                    substr(trim((string)($_POST['away'] ?? '')), 0, 120),
                    substr(trim((string)($_POST['league'] ?? '')), 0, 160),
                    $kick > 0 ? $kick : null,
                ]);
                unset($_SESSION['prefill']);
                flash('ok', 'Enlace agregado.' . (stripos($f['url'], '.m3u8') === false ? ' Aviso: la URL no contiene ".m3u8".' : ''));
                go($back);
                break;

            case 'save':
            case 'toggle':
            case 'delete':
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
                if ($a === 'delete') {
                    $pdo->prepare('DELETE FROM event_sources WHERE id = ?')->execute([$id]);
                    flash('ok', 'Enlace eliminado.');
                } elseif ($a === 'toggle') {
                    $pdo->prepare('UPDATE event_sources SET active = 1 - active WHERE id = ?')->execute([$id]);
                    flash('ok', ((int)$row['active'] === 1) ? 'Enlace desactivado (la app ya no lo muestra).' : 'Enlace activado.');
                } elseif ($a === 'test') {
                    $m = probe_message(probe_m3u8((string)$row['url'], $row['referer'], $row['user_agent']));
                    flash($m[0], '«' . $row['label'] . '»: ' . substr($m[1], 0, 400));
                } else {
                    $f = read_source_fields();
                    if ($f['err'] !== null) {
                        flash('bad', $f['err']);
                    } else {
                        $pdo->prepare(
                            'UPDATE event_sources SET label = ?, url = ?, referer = ?, user_agent = ?, sort_order = ? WHERE id = ?'
                        )->execute([
                            $f['label'] !== '' ? $f['label'] : (string)$row['label'],
                            $f['url'],
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
    $view = (string)($_GET['v'] ?? 'home');
    $date = valid_date((string)($_GET['d'] ?? '')) ? (string)$_GET['d'] : date('Y-m-d');

    // ---- Detalle de un evento ---------------------------------------------
    if ($view === 'event' && valid_event_id((string)($_GET['id'] ?? ''))) {
        $eid = (string)$_GET['id'];
        $ev = null;
        try {
            $data = provider('events/' . rawurlencode($eid), 'event');
            $raw = (isset($data['event']) && is_array($data['event'])) ? $data['event'] : $data;
            $ev = normalize_event($raw);
        } catch (Throwable $e) {
            $ev = null;
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
        $hasEmbed = !empty($ev['embed_url']);
        $pre = $_SESSION['prefill'] ?? [];
        unset($_SESSION['prefill']);
        $hidden = csrf_field()
            . '<input type="hidden" name="event_id" value="' . h($eid) . '">'
            . '<input type="hidden" name="d" value="' . h($date) . '">';
        ?>
        <p><a class="link" href="?v=home&d=<?= h(rawurlencode($date)) ?>">← Volver a los eventos</a></p>
        <div class="card">
          <h2><?= h($home !== '' ? $home : 'Evento') ?> <span class="mut">vs</span> <?= h($away) ?></h2>
          <p class="mut"><?= h($league) ?> · <?= h(fmt_ts($kick)) ?>
            <?php if ($isLive): ?> · <span class="tag live">EN VIVO</span><?php endif; ?>
            · ID <code><?= h($eid) ?></code></p>
          <p class="mut">Reproductor de Matchora: <?= $hasEmbed ? 'disponible (la app lo usa si el evento no tiene enlaces propios)' : 'sin señal en Matchora' ?>.
            <?php if ($ev === null): ?><br>No se pudo consultar a Matchora; se muestran los datos guardados.<?php endif; ?></p>
        </div>

        <div class="card">
          <h2>Enlaces del evento (<?= count($rows) ?>)</h2>
          <?php if (!$rows): ?><p class="mut">Todavía no hay enlaces. Agrega el primero abajo.</p><?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <form method="post" class="src <?= (int)$r['active'] === 1 ? '' : 'off' ?>">
              <?= $hidden ?>
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <div class="grid">
                <label>Nombre<input name="label" value="<?= h($r['label']) ?>" maxlength="60"></label>
                <label>Orden<input name="sort_order" type="number" min="0" max="99" value="<?= (int)$r['sort_order'] ?>"></label>
              </div>
              <label>URL m3u8<input name="url" value="<?= h($r['url']) ?>" maxlength="1000" required></label>
              <div class="grid">
                <label>Referer (opcional)<input name="referer" value="<?= h((string)$r['referer']) ?>" maxlength="500"></label>
                <label>User-Agent (opcional)<input name="user_agent" value="<?= h((string)$r['user_agent']) ?>" maxlength="300"></label>
              </div>
              <div class="actions">
                <button name="a" value="save">Guardar</button>
                <button name="a" value="test" class="alt">Probar</button>
                <button name="a" value="toggle" class="alt"><?= (int)$r['active'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                <button name="a" value="delete" class="danger" onclick="return confirm('¿Eliminar este enlace?')">Eliminar</button>
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
            <label>URL m3u8<input name="url" value="<?= h((string)($pre['url'] ?? '')) ?>" placeholder="https://…/index.m3u8" maxlength="1000" required></label>
            <div class="grid">
              <label>Referer (opcional)<input name="referer" value="<?= h((string)($pre['referer'] ?? '')) ?>" maxlength="500"></label>
              <label>User-Agent (opcional)<input name="user_agent" value="<?= h((string)($pre['user_agent'] ?? '')) ?>" maxlength="300"></label>
            </div>
            <div class="actions">
              <button name="a" value="add">Agregar</button>
              <button name="a" value="test_url" class="alt">Probar antes de agregar</button>
            </div>
          </form>
          <p class="mut">Referer y User-Agent solo hacen falta si el servidor del stream los exige. El Chromecast no puede enviarlos, así que esas fuentes se verán en el teléfono pero quizá no al transmitir.</p>
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
              <span class="m"><b><?= h($r['home']) ?></b> vs <b><?= h($r['away']) ?></b><small><?= h($r['league']) ?></small></span>
              <span class="b"><span class="tag"><?= (int)$r['act'] ?>/<?= (int)$r['n'] ?> activos</span></span>
            </a>
          <?php endforeach; ?>
          <form method="post" style="margin-top:14px">
            <?= csrf_field() ?>
            <button name="a" value="purge" class="alt" onclick="return confirm('¿Borrar los enlaces de eventos de hace más de 3 días?')">Limpiar eventos de hace más de 3 días</button>
          </form>
        </div>
        <?php
    } else {
        // ---- Eventos de Matchora -----------------------------------------------
        $onlyLive = !empty($_GET['live']);
        $q = trim((string)($_GET['q'] ?? ''));
        $events = [];
        $listErrors = [];
        $feeds = $onlyLive
            ? [['live', 'live']]
            : [
                ['schedule?' . http_build_query(['date' => $date]), 'schedule'],
                ['schedule?' . http_build_query(['date' => date('Y-m-d', strtotime($date . ' +1 day'))]), 'schedule'],
                ['live', 'live'],
            ];
        $uniqueEvents = [];
        foreach ($feeds as [$feedPath, $ttlKey]) {
            try {
                $feed = provider($feedPath, $ttlKey);
                foreach (normalize_events(extract_list($feed, 'events')) as $event) {
                    $eventId = (string)($event['id'] ?? '');
                    $eventKey = $eventId !== '' ? $eventId : implode('|', [
                        (string)($event['home'] ?? ''),
                        (string)($event['away'] ?? ''),
                        (string)($event['kickoff'] ?? ''),
                    ]);
                    if ($eventKey !== '||') {
                        $uniqueEvents[$eventKey] = $event;
                    }
                }
            } catch (Throwable $e) {
                $listErrors[] = $e->getMessage();
            }
        }
        $events = array_values($uniqueEvents);
        $listError = $events ? null : ($listErrors ? implode(' · ', array_unique($listErrors)) : null);
        if ($q !== '') {
            $events = array_values(array_filter($events, function ($e) use ($q) {
                return contains_ci((string)($e['home'] ?? '') . ' ' . (string)($e['away'] ?? '') . ' ' . (string)($e['league'] ?? ''), $q);
            }));
        }
        usort($events, function ($a, $b) {
            $la = !empty($a['live']) ? 0 : 1;
            $lb = !empty($b['live']) ? 0 : 1;
            return $la <=> $lb ?: ((int)($a['kickoff'] ?? 0) <=> (int)($b['kickoff'] ?? 0));
        });
        $events = array_slice($events, 0, 300);

        $counts = [];
        $ids = array_map(function ($e) { return (string)($e['id'] ?? ''); }, $events);
        $ids = array_values(array_filter($ids, function ($x) { return $x !== ''; }));
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare('SELECT event_id, COUNT(*) AS n, SUM(active) AS act FROM event_sources WHERE event_id IN (' . $marks . ') GROUP BY event_id');
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) {
                $counts[(string)$r['event_id']] = [(int)$r['n'], (int)$r['act']];
            }
        }
        ?>
        <div class="card">
          <form method="get" class="filters">
            <input type="hidden" name="v" value="home">
            <label>Fecha<input type="date" name="d" value="<?= h($date) ?>"></label>
            <label>Buscar<input type="search" name="q" value="<?= h($q) ?>" placeholder="equipo o liga"></label>
            <label class="chk"><input type="checkbox" name="live" value="1" <?= $onlyLive ? 'checked' : '' ?>> Solo en vivo</label>
            <button type="submit">Filtrar</button>
          </form>
        </div>
        <div class="card">
          <h2>Eventos disponibles (<?= count($events) ?>)</h2>
          <p class="mut">Se combinan eventos en vivo, la fecha elegida y el día siguiente, igual que en la aplicación. Cricket y las competiciones no seleccionadas están excluidos.</p>
          <?php if ($listError): ?><p class="bad">No se pudo consultar a Matchora: <?= h($listError) ?></p><?php endif; ?>
          <?php if (!$listError && !$events): ?><p class="mut">No hay eventos para este filtro.</p><?php endif; ?>
          <?php foreach ($events as $e):
              $id = (string)($e['id'] ?? '');
              if ($id === '' || !valid_event_id($id)) { continue; }
              $c = $counts[$id] ?? [0, 0]; ?>
            <a class="row" href="?v=event&id=<?= h(rawurlencode($id)) ?>&d=<?= h(rawurlencode($date)) ?>">
              <span class="t"><?= !empty($e['live']) ? '<span class="tag live">VIVO</span>' : h(fmt_ts($e['kickoff'] ?? 0)) ?></span>
              <span class="m"><b><?= h($e['home'] ?? '') ?></b> vs <b><?= h($e['away'] ?? '') ?></b><small><?= h($e['league'] ?? '') ?></small></span>
              <span class="b">
                <?php if ($c[0] > 0): ?><span class="tag ok"><?= $c[1] ?>/<?= $c[0] ?> enlaces</span><?php endif; ?>
                <?php if (!empty($e['embed_url'])): ?><span class="tag">Matchora</span><?php endif; ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
        <?php
    }
}

$body = (string)ob_get_clean();
$nav = $logged && !$fatal && !$needsSetup;
$view = (string)($_GET['v'] ?? 'home');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Liveo · Administración</title>
<link rel="icon" href="../assets/favicon.ico?v=1.0.14" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="../assets/liveo_icon.png?v=1.0.14">
<link rel="apple-touch-icon" href="../assets/liveo_icon.png?v=1.0.14">
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
  input { display:block; width:100%; margin-top:6px; background:#0a1421; color:var(--txt); border:1px solid #2b3c53; border-radius:10px; padding:11px 12px; font-size:14px; outline:none; transition:border-color .18s,box-shadow .18s; }
  input:focus { border-color:var(--accent2); box-shadow:0 0 0 3px rgba(112,223,176,.12); }
  input[type=checkbox] { display:inline-block; width:auto; margin:0 7px 0 0; accent-color:var(--accent2); }
  .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .filters { display:grid; grid-template-columns:1fr 1.4fr auto auto; gap:12px; align-items:end; }
  .filters .chk { margin:0 0 12px; color:var(--txt); white-space:nowrap; }
  button { background:linear-gradient(135deg,var(--accent),#8be7c1); color:#092018; border:0; border-radius:10px; padding:10px 15px; font-weight:800; cursor:pointer; font-size:12px; transition:filter .18s,transform .18s; }
  button:hover { filter:brightness(1.06); transform:translateY(-1px); }
  button.alt { background:#263850; color:#e3edf9; border:1px solid #344a66; }
  button.danger { background:rgba(255,115,125,.12); color:#ff9aa2; border:1px solid rgba(255,115,125,.3); }
  .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  a.btn { display:inline-block; background:linear-gradient(135deg,var(--accent),#8be7c1); color:#092018; border-radius:10px; padding:10px 15px; font-weight:800; text-decoration:none; font-size:12px; }
  a.link { color:var(--accent); text-decoration:none; }
  .src { border-top:1px solid var(--line); padding-top:16px; margin-top:16px; }
  .src:first-of-type { border-top:0; margin-top:0; padding-top:0; }
  .src.off { opacity:.58; }
  .row { display:flex; gap:14px; align-items:center; padding:14px 10px; border-top:1px solid rgba(139,164,195,.12); text-decoration:none; color:var(--txt); border-radius:9px; transition:background .16s; }
  .row:first-of-type { border-top:0; }
  .row:hover { background:rgba(111,144,180,.09); }
  .row .t { width:78px; color:var(--mut); font-size:12px; flex:none; }
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
    <div class="brand"><img src="../assets/liveo_icon.png?v=1.0.14" alt="Liveo" width="48" height="48"><div><h1>LIVEO</h1><small>Centro de administración · v<?= h(LIVEO_VERSION) ?></small></div></div>
    <?php if ($nav): ?>
    <nav>
      <a href="?v=home" class="<?= $view !== 'linked' ? 'on' : '' ?>">Eventos</a>
      <a href="?v=linked" class="<?= $view === 'linked' ? 'on' : '' ?>">Con enlaces</a>
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
