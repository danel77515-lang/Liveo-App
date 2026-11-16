<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$cfg = liveo_config();
$lan = (string)$cfg['lan_ip'];
$appUrl = 'http://' . $lan . '/Liveo/api';

$checks = [];
$add = function (string $label, ?bool $ok, string $detail = '') use (&$checks) {
    $checks[] = [$label, $ok, $detail];
};

$add('PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='), 'Se requiere 7.4 o superior');
foreach (['curl', 'openssl', 'json', 'pdo_mysql'] as $ext) {
    $add('Extensión ' . $ext, extension_loaded($ext), extension_loaded($ext) ? '' : 'Actívala en php.ini');
}
if (function_exists('apache_get_modules')) {
    $add('Apache mod_rewrite', in_array('mod_rewrite', apache_get_modules(), true), 'Necesario para /Liveo/api/…');
}

$pdo = db();
if (!$pdo) {
    $add('Conexión MySQL', false, (string)($GLOBALS['liveo_db_error'] ?? 'Sin conexión') . ' — ¿está MySQL iniciado en XAMPP?');
} else {
    $add('Conexión MySQL', true, 'Base ' . $cfg['db_name']);
    try {
        $pdo->query('SELECT 1 FROM favorites LIMIT 1');
        $pdo->query('SELECT 1 FROM api_cache LIMIT 1');
        $pdo->query('SELECT 1 FROM app_settings LIMIT 1');
        $pdo->query('SELECT 1 FROM event_sources LIMIT 1');
        $add('Tablas instaladas', true);
    } catch (Throwable $e) {
        $add('Tablas instaladas', false, 'Ejecuta la instalación (botón de abajo)');
    }
}

$providerResult = null;
if (($_GET['check'] ?? '') === 'provider') {
    try {
        $data = provider('live', 'live');
        $n = count(extract_list($data, 'events'));
        $providerResult = [true, 'Proveedor accesible. Eventos en vivo recibidos: ' . $n];
    } catch (Throwable $e) {
        $providerResult = [false, $e->getMessage()];
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Liveo · Backend</title>
<style>
  :root { color-scheme: dark; --bg:#0b1422; --card:#101a2a; --line:#1f2d45; --txt:#e8eef9; --mut:#92a2bd; --ok:#86d957; --bad:#ff6b6b; --warn:#f4c542; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--txt); }
  main { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
  h1 { margin:0 0 4px; letter-spacing:3px; font-weight:900; }
  p.sub { margin:0 0 24px; color:var(--mut); }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px; margin-bottom:16px; }
  .card h2 { margin:0 0 12px; font-size:16px; }
  code { background:#0b1422; border:1px solid var(--line); border-radius:6px; padding:2px 6px; word-break:break-all; }
  table { width:100%; border-collapse:collapse; }
  td { padding:8px 4px; border-top:1px solid var(--line); vertical-align:top; font-size:14px; }
  tr:first-child td { border-top:0; }
  .ok { color:var(--ok); } .bad { color:var(--bad); } .unk { color:var(--warn); }
  .mut { color:var(--mut); font-size:13px; }
  a.btn, button { display:inline-block; background:var(--ok); color:#0b1422; border:0; border-radius:10px; padding:10px 16px; font-weight:700; text-decoration:none; cursor:pointer; font-size:14px; }
  a.link { color:var(--ok); }
  ul { margin:0; padding-left:18px; line-height:1.9; }
</style>
</head>
<body>
<main>
  <h1>LIVEO</h1>
  <p class="sub">Backend v<?= h(LIVEO_VERSION) ?> · XAMPP local · Proveedor: Matchora</p>

  <div class="card">
    <h2>URL para la app Android</h2>
    <p><code><?= h($appUrl) ?></code></p>
    <p class="mut">Es la URL con la que se compila la APK (variable <code>API_BASE_URL</code> en GitHub Actions). El teléfono debe estar en la misma red Wi-Fi que esta PC.</p>
  </div>

  <div class="card">
    <h2>Panel de administración</h2>
    <p class="mut">Agrega enlaces m3u8 a los eventos que genera Matchora. La app los reproduce con su reproductor integrado (con PiP y Chromecast).</p>
    <p><a class="btn" href="admin/">Abrir panel</a></p>
  </div>

  <div class="card">
    <h2>Diagnóstico</h2>
    <table>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td style="width:28px" class="<?= $c[1] === null ? 'unk' : ($c[1] ? 'ok' : 'bad') ?>"><?= $c[1] === null ? '?' : ($c[1] ? '✔' : '✖') ?></td>
          <td><?= h($c[0]) ?><?php if ($c[2] !== ''): ?><div class="mut"><?= h($c[2]) ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($providerResult !== null): ?>
        <tr>
          <td class="<?= $providerResult[0] ? 'ok' : 'bad' ?>"><?= $providerResult[0] ? '✔' : '✖' ?></td>
          <td>Proveedor Matchora<div class="mut"><?= h($providerResult[1]) ?></div></td>
        </tr>
      <?php endif; ?>
    </table>
    <p style="margin-top:14px">
      <a class="btn" href="?check=provider">Probar proveedor</a>
      <a class="btn" href="setup.php" style="background:#2b3a57;color:#e8eef9">Instalar base de datos</a>
    </p>
  </div>

  <div class="card">
    <h2>Endpoints</h2>
    <ul>
      <li><a class="link" href="api/health">api/health</a> · <a class="link" href="api/health?deep=1">api/health?deep=1</a></li>
      <li><a class="link" href="api/schedule">api/schedule</a> (<code>?date=AAAA-MM-DD&amp;watchable=1</code>)</li>
      <li><a class="link" href="api/live">api/live</a></li>
      <li><a class="link" href="api/channels">api/channels</a></li>
      <li><code>api/events/{id}</code> (cada evento incluye <code>links</code>: los m3u8 agregados desde el panel)</li>
      <li><code>api/favorites</code> (GET/POST/DELETE, con <code>device</code> y <code>event_id</code>)</li>
    </ul>
  </div>
</main>
</body>
</html>
