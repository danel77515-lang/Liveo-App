<?php
// Opcional. Copiar como config.local.php solo si necesitas cambiar algo.
// Con XAMPP por defecto (root sin contraseña) NO hace falta crearlo.
// No subir config.local.php a GitHub.
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'liveo',
    'db_user' => 'root',
    'db_pass' => '',

    // IP de tu PC en la red local (se muestra en la página de inicio).
    'lan_ip' => '192.168.54.109',

    // Si PHP en Windows falla con "SSL certificate problem", descarga
    // https://curl.se/ca/cacert.pem y apunta aquí a su ruta, por ejemplo:
    // 'ca_bundle' => 'C:/xampp/php/extras/ssl/cacert.pem',
    // Último recurso (no recomendado): 'ssl_verify' => false,

    // Mostrar detalle técnico en los errores de la API.
    'debug' => true,
];
