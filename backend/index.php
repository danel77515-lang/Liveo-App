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

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Liveo · Backend</title>
<link rel="icon" href="assets/favicon.ico?v=1.0.22" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/liveo_icon.png?v=1.0.22">
<link rel="apple-touch-icon" href="assets/liveo_icon.png?v=1.0.22">
<meta name="theme-color" content="#101722">
<style>
  :root { color-scheme:dark; --bg:#0a1019; --card:#111b29; --line:#26364b; --txt:#edf3fb; --mut:#91a2b9; --ok:#70dfb0; --bad:#ff737d; }
  *{box-sizing:border-box} body{margin:0;min-height:100vh;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:radial-gradient(ellipse at 12% -10%,rgba(47,113,112,.18),transparent 38%),radial-gradient(ellipse at 100% 0%,rgba(54,83,130,.17),transparent 34%),var(--bg);color:var(--txt)}
  main{max-width:900px;margin:0 auto;padding:38px 18px 70px}.brand{display:flex;align-items:center;gap:14px;margin-bottom:24px}.brand img{display:block;flex:0 0 54px;width:54px;height:54px;border-radius:13px;object-fit:contain;object-position:center}h1{margin:0;letter-spacing:3px;font-weight:900}.sub{margin:5px 0 0;color:var(--mut);font-size:13px}
  .card{background:linear-gradient(145deg,rgba(22,34,53,.96),rgba(16,26,40,.96));border:1px solid rgba(139,164,195,.17);border-radius:17px;padding:21px;margin-bottom:16px;box-shadow:0 18px 48px rgba(0,0,0,.2)}.card h2{margin:0 0 13px;font-size:16px}
  code{background:#0b1420;border:1px solid var(--line);border-radius:7px;padding:3px 7px;word-break:break-all}table{width:100%;border-collapse:collapse}td{padding:11px 5px;border-top:1px solid rgba(139,164,195,.12);vertical-align:top;font-size:13px}tr:first-child td{border-top:0}.ok{color:var(--ok)}.bad{color:var(--bad)}.unk{color:#f4c86a}.mut{color:var(--mut);font-size:12px;line-height:1.6}
  a.btn,button{display:inline-block;background:linear-gradient(135deg,#70dfb0,#8be7c1);color:#092018;border:0;border-radius:10px;padding:10px 16px;font-weight:800;text-decoration:none;cursor:pointer;font-size:12px;margin-right:5px}a.link{color:var(--ok)}ul{margin:0;padding-left:18px;line-height:2}
</style>
</head>
<body>
<main>
  <div class="brand"><img src="assets/liveo_icon.png?v=1.0.22" alt="Liveo" width="54" height="54"><div><h1>LIVEO</h1><p class="sub">Backend v<?= h(LIVEO_VERSION) ?> · XAMPP local · Eventos propios (manual / CSV)</p></div></div>

  <div class="card">
    <h2>URL para la app Android</h2>
    <p><code><?= h($appUrl) ?></code></p>
    <p class="mut">Es la URL con la que se compila la APK (variable <code>API_BASE_URL</code> en GitHub Actions). El teléfono debe estar en la misma red Wi-Fi que esta PC.</p>
  </div>

  <div class="card">
    <h2>Panel de administración</h2>
    <p class="mut">Carga los eventos a mano o subiendo un CSV, cambia su estado y minuto, y agrega enlaces m3u8. La app los reproduce con su reproductor integrado (con PiP y Chromecast).</p>
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
    </table>
    <p style="margin-top:14px">
      <a class="btn" href="setup.php" style="background:#2b3a57;color:#e8eef9">Instalar base de datos</a>
    </p>
  </div>

  <div class="card">
    <h2>Endpoints</h2>
    <ul>
      <li><a class="link" href="api/health">api/health</a></li>
      <li><a class="link" href="api/schedule">api/schedule</a> (<code>?date=AAAA-MM-DD</code>)</li>
      <li><a class="link" href="api/upcoming">api/upcoming</a> (<code>?days=14</code>, desde hoy)</li>
      <li><a class="link" href="api/live">api/live</a></li>
      <li><code>api/events/{id}</code> (cada evento incluye <code>links</code>: los m3u8 agregados desde el panel)</li>
      <li><code>api/favorites</code> (GET/POST/DELETE, con <code>device</code> y <code>event_id</code>)</li>
    </ul>
  </div>
</main>
</body>
</html>
