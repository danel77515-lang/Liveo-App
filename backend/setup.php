<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$cfg = liveo_config();
$messages = [];
$ok = null;

if (!is_local_request()) {
    http_response_code(403);
    echo 'Instalación permitida solo desde la red local.';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $dbName = (string)$cfg['db_name'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        $ok = false;
        $messages[] = 'Nombre de base de datos inválido en la configuración.';
    } else {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], (int)$cfg['db_port']);
            $pdo = new PDO($dsn, (string)$cfg['db_user'], (string)$cfg['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . $dbName . '`');
            $pdo->exec('CREATE TABLE IF NOT EXISTS favorites (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_key VARCHAR(120) NOT NULL,
                event_id VARCHAR(80) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_device_event (device_key, event_id)
            ) ENGINE=InnoDB');
            $pdo->exec('CREATE TABLE IF NOT EXISTS api_cache (
                cache_key VARCHAR(190) PRIMARY KEY,
                payload MEDIUMTEXT NOT NULL,
                expires_at DATETIME NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB');
            $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB');
            ensure_sources_schema($pdo);
            $ok = true;
            $messages[] = 'Base de datos "' . $dbName . '" y tablas listas (incluye los enlaces del panel de administración).';
        } catch (Throwable $e) {
            $ok = false;
            $messages[] = 'Error: ' . $e->getMessage();
            $messages[] = '¿Está MySQL iniciado en el panel de XAMPP y son correctos los datos de src/config.local.php?';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Liveo · Instalación</title>
<style>
  body { margin:0; font-family: system-ui, "Segoe UI", sans-serif; background:#0b1422; color:#e8eef9; }
  main { max-width:560px; margin:0 auto; padding:40px 16px; }
  .card { background:#101a2a; border:1px solid #1f2d45; border-radius:14px; padding:20px; }
  button, a.btn { background:#86d957; color:#0b1422; border:0; border-radius:10px; padding:10px 16px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; font-size:14px; }
  .ok { color:#86d957; } .bad { color:#ff6b6b; }
  code { background:#0b1422; border:1px solid #1f2d45; border-radius:6px; padding:2px 6px; }
</style>
</head>
<body>
<main>
  <h1>Instalación de Liveo</h1>
  <div class="card">
    <p>Crea la base de datos <code><?= h($cfg['db_name']) ?></code> y sus tablas en <code><?= h($cfg['db_host']) ?></code> (usuario <code><?= h($cfg['db_user']) ?></code>). Es seguro repetirlo.</p>
    <?php foreach ($messages as $m): ?>
      <p class="<?= $ok ? 'ok' : 'bad' ?>"><?= h($m) ?></p>
    <?php endforeach; ?>
    <form method="post">
      <button type="submit">Instalar / reparar</button>
      <a class="btn" href="./" style="background:#2b3a57;color:#e8eef9">Volver</a>
    </form>
  </div>
</main>
</body>
</html>
