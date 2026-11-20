<?php
declare(strict_types=1);

/**
 * Catálogo de deportes (nombre + ícono) que ve la app en el filtro del inicio.
 * Se guarda en app_settings (clave sports_catalog) como JSON: [{"name","icon","logo"}].
 * «logo» es opcional: una URL http(s) o una imagen subida (uploads/logos/…); si existe,
 * la app la muestra en lugar del ícono.
 * Los íconos son Tabler Icons (MIT); la app trae los mismos archivos SVG.
 */

/** Íconos disponibles: clave => [etiqueta, contenido SVG 24x24 (trazo)]. */
function sport_icons(): array
{
    return [
        'ball-football' => ['Fútbol', '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /> <path d="M12 7l4.76 3.45l-1.76 5.55h-6l-1.76 -5.55l4.76 -3.45" /> <path d="M12 7v-4m3 13l2.5 3m-.74 -8.55l3.74 -1.45m-11.44 7.05l-2.56 2.95m.74 -8.55l-3.74 -1.45" />'],
        'ball-basketball' => ['Básquet', '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /> <path d="M5.65 5.65l12.7 12.7" /> <path d="M5.65 18.35l12.7 -12.7" /> <path d="M12 3a9 9 0 0 0 9 9" /> <path d="M3 12a9 9 0 0 1 9 9" />'],
        'ball-tennis' => ['Tenis', '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /> <path d="M6 5.3a9 9 0 0 1 0 13.4" /> <path d="M18 5.3a9 9 0 0 0 0 13.4" />'],
        'ball-volleyball' => ['Voleibol', '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /> <path d="M12 12a8 8 0 0 0 8 4" /> <path d="M7.5 13.5a12 12 0 0 0 8.5 6.5" /> <path d="M12 12a8 8 0 0 0 -7.464 4.928" /> <path d="M12.951 7.353a12 12 0 0 0 -9.88 4.111" /> <path d="M12 12a8 8 0 0 0 -.536 -8.928" /> <path d="M15.549 15.147a12 12 0 0 0 1.38 -10.611" />'],
        'ball-baseball' => ['Béisbol', '<path d="M5.636 18.364a9 9 0 1 0 12.728 -12.728a9 9 0 0 0 -12.728 12.728" /> <path d="M12.495 3.02a9 9 0 0 1 -9.475 9.475" /> <path d="M20.98 11.505a9 9 0 0 0 -9.475 9.475" /> <path d="M9 9l2 2" /> <path d="M13 13l2 2" /> <path d="M11 7l2 1" /> <path d="M7 11l1 2" /> <path d="M16 11l1 2" /> <path d="M11 16l2 1" />'],
        'ball-american-football' => ['Fútbol americano / Rugby', '<path d="M15 9l-6 6" /> <path d="M10 12l2 2" /> <path d="M12 10l2 2" /> <path d="M8 21a5 5 0 0 0 -5 -5" /> <path d="M16 3c-7.18 0 -13 5.82 -13 13a5 5 0 0 0 5 5c7.18 0 13 -5.82 13 -13a5 5 0 0 0 -5 -5" /> <path d="M16 3a5 5 0 0 0 5 5" />'],
        'ball-bowling' => ['Bowling', '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /> <path d="M11 9l0 .01" /> <path d="M15 8l0 .01" /> <path d="M14 12l0 .01" />'],
        'car' => ['Automovilismo', '<path d="M5 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M15 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M5 17h-2v-6l2 -5h9l4 5h1a2 2 0 0 1 2 2v4h-2m-4 0h-6m-6 -6h15m-6 0v-5" />'],
        'bike' => ['Ciclismo', '<path d="M2 18a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" /> <path d="M16 18a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" /> <path d="M12 19v-4l-3 -3l5 -4l2 3h3" /> <path d="M13.007 5a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />'],
        'motorbike' => ['Motociclismo', '<path d="M2 16a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M16 16a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M7.5 14h5l4 -4h-10.5m1.5 4l4 -4" /> <path d="M13 6h2l1.5 3l2 4" />'],
        'golf' => ['Golf', '<path d="M12 18v-15l7 4l-7 4" /> <path d="M9 17.67c-.62 .36 -1 .82 -1 1.33c0 1.1 1.8 2 4 2s4 -.9 4 -2c0 -.5 -.38 -.97 -1 -1.33" />'],
        'karate' => ['Artes marciales', '<path d="M3 9l4.5 1l3 2.5" /> <path d="M13 21v-8l3 -5.5" /> <path d="M8 4.5l4 2l4 1l4 3.5l-2 3.5" /> <path d="M15.007 5a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />'],
        'swimming' => ['Natación', '<path d="M15 9a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /> <path d="M6 11l4 -2l3.5 3l-1.5 2" /> <path d="M3 16.75a2.4 2.4 0 0 0 1 .25a2.4 2.4 0 0 0 2 -1a2.4 2.4 0 0 1 2 -1a2.4 2.4 0 0 1 2 1a2.4 2.4 0 0 0 2 1a2.4 2.4 0 0 0 2 -1a2.4 2.4 0 0 1 2 -1a2.4 2.4 0 0 1 2 1a2.4 2.4 0 0 0 2 1a2.4 2.4 0 0 0 1 -.25" />'],
        'run' => ['Atletismo', '<path d="M11.007 5a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M4 17l5 1l.75 -1.5" /> <path d="M15 21v-4l-4 -3l1 -6" /> <path d="M7 12v-3l5 -1l3 3l3 1" />'],
        'horse' => ['Hípica', '<path d="M7 10l-.85 8.507a1.357 1.357 0 0 0 1.35 1.493h.146a2 2 0 0 0 1.857 -1.257l.994 -2.486a2 2 0 0 1 1.857 -1.257h1.292a2 2 0 0 1 1.857 1.257l.994 2.486a2 2 0 0 0 1.857 1.257h.146a1.37 1.37 0 0 0 1.364 -1.494l-.864 -9.506h-8c0 -3 -3 -5 -6 -5l-3 6l2 2l3 -2" /> <path d="M22 14v-2a3 3 0 0 0 -3 -3" />'],
        'trophy' => ['Otro', '<path d="M8 21l8 0" /> <path d="M12 17l0 4" /> <path d="M7 4l10 0" /> <path d="M17 4v8a5 5 0 0 1 -10 0v-8" /> <path d="M3 9a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M17 9a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />'],
    ];
}

/** Deportes de fábrica (hasta que el panel guarde su propia lista). */
function sports_default_catalog(): array
{
    return array_map(static function (array $r): array {
        $r['logo'] = '';
        return $r;
    }, [
        ['name' => 'Fútbol', 'icon' => 'ball-football'],
        ['name' => 'Básquet', 'icon' => 'ball-basketball'],
        ['name' => 'Tenis', 'icon' => 'ball-tennis'],
        ['name' => 'Voleibol', 'icon' => 'ball-volleyball'],
        ['name' => 'Béisbol', 'icon' => 'ball-baseball'],
        ['name' => 'Rugby', 'icon' => 'ball-american-football'],
        ['name' => 'Fútbol americano', 'icon' => 'ball-american-football'],
        ['name' => 'Automovilismo', 'icon' => 'car'],
        ['name' => 'Motociclismo', 'icon' => 'motorbike'],
        ['name' => 'Ciclismo', 'icon' => 'bike'],
        ['name' => 'Golf', 'icon' => 'golf'],
        ['name' => 'Boxeo', 'icon' => 'karate'],
        ['name' => 'MMA', 'icon' => 'karate'],
    ]);
}

/** Clave de comparación: minúsculas y sin tildes. */
function sport_key(string $name): string
{
    $n = mb_strtolower(trim($name), 'UTF-8');
    return strtr($n, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n',
    ]);
}

function sport_valid_icon(string $icon): string
{
    return array_key_exists($icon, sport_icons()) ? $icon : 'trophy';
}

/** Logo válido: URL http(s) o imagen ya subida (uploads/logos/…). Si no, ''. */
function sport_valid_logo(string $v): string
{
    $v = trim($v);
    if (preg_match('#^https?://\S{4,490}$#i', $v) === 1) {
        return $v;
    }
    return preg_match('#^uploads/logos/[a-z0-9-]+\.(png|jpg|webp|gif)$#', $v) === 1 ? $v : '';
}

/**
 * Guarda una imagen subida como logo de un deporte (uploads/logos/sport-…).
 * Devuelve ['ok' => bool, 'logo' => 'uploads/logos/…', 'error' => string].
 */
function save_sport_logo_upload(string $sportName, array $file): array
{
    if ((int)($file['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        return ['ok' => false, 'logo' => '', 'error' => 'No se pudo recibir el archivo (revisa el límite de subida de PHP).'];
    }
    if ((int)$file['size'] > 1536 * 1024) {
        return ['ok' => false, 'logo' => '', 'error' => 'La imagen supera 1,5 MB.'];
    }
    $info = @getimagesize((string)$file['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
    if ($ext === null) {
        return ['ok' => false, 'logo' => '', 'error' => 'Solo se aceptan imágenes PNG, JPG, WEBP o GIF.'];
    }
    $dir = logo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'logo' => '', 'error' => 'No se pudo crear la carpeta uploads/logos.'];
    }
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', sport_key($sportName)), '-');
    $name = 'sport-' . ($slug !== '' ? $slug : 'x') . '-' . substr((string)sha1_file((string)$file['tmp_name']), 0, 8) . '.' . $ext;
    if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'logo' => '', 'error' => 'No se pudo guardar la imagen en uploads/logos.'];
    }
    return ['ok' => true, 'logo' => 'uploads/logos/' . $name, 'error' => ''];
}

/** Borra el archivo de un logo subido (las URL externas no se tocan). */
function sport_logo_delete_file(string $logo, array $keepInList = []): void
{
    if (strncmp($logo, 'uploads/logos/', 14) !== 0) {
        return;
    }
    foreach ($keepInList as $r) {
        if (($r['logo'] ?? '') === $logo) {
            return; // otro deporte lo sigue usando
        }
    }
    @unlink(logo_dir() . '/' . basename($logo));
}

/** Lista guardada en el panel (o la de fábrica si todavía no se guardó ninguna). */
function sports_catalog_load(?PDO $pdo = null): array
{
    if ($pdo === null) {
        $pdo = db();
    }
    if (!$pdo) {
        return sports_default_catalog();
    }
    try {
        $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $st->execute(['sports_catalog']);
        $raw = $st->fetchColumn();
        if ($raw === false) {
            return sports_default_catalog();
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return sports_default_catalog();
        }
        $out = [];
        foreach ($data as $r) {
            if (is_array($r) && trim((string)($r['name'] ?? '')) !== '') {
                $out[] = [
                    'name' => substr(trim((string)$r['name']), 0, 80),
                    'icon' => sport_valid_icon((string)($r['icon'] ?? '')),
                    'logo' => sport_valid_logo((string)($r['logo'] ?? '')),
                ];
            }
        }
        return $out;
    } catch (Throwable $e) {
        return sports_default_catalog();
    }
}

function sports_catalog_save(PDO $pdo, array $list): void
{
    $clean = [];
    foreach ($list as $r) {
        $clean[] = [
            'name' => (string)$r['name'],
            'icon' => sport_valid_icon((string)$r['icon']),
            'logo' => sport_valid_logo((string)($r['logo'] ?? '')),
        ];
    }
    $pdo->prepare('REPLACE INTO app_settings (setting_key, setting_value) VALUES (?, ?)')
        ->execute(['sports_catalog', json_encode($clean, JSON_UNESCAPED_UNICODE)]);
}

/** Catálogo listo para la API: el logo subido pasa a URL absoluta. */
function sports_catalog_public(?PDO $pdo = null): array
{
    $out = [];
    foreach (sports_catalog_load($pdo) as $r) {
        $r['logo'] = $r['logo'] !== '' ? logo_public_url($r['logo']) : '';
        $out[] = $r;
    }
    return $out;
}
