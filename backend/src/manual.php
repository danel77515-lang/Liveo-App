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
    $pdo->exec('CREATE TABLE IF NOT EXISTS league_logos (
        name_key VARCHAR(120) NOT NULL PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        url VARCHAR(500) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = true;

    // Una sola vez: unifica los nombres repetidos que ya haya (de CSV cargados antes).
    try {
        $st = $pdo->prepare('SELECT 1 FROM app_settings WHERE setting_key = ?');
        $st->execute(['names_unified_v1']);
        if ($st->fetchColumn() === false) {
            manual_unify_existing($pdo);
            $pdo->prepare('REPLACE INTO app_settings (setting_key, setting_value) VALUES (?, ?)')
                ->execute(['names_unified_v1', '1']);
        }
    } catch (Throwable $e) {
        // Sin tabla de ajustes todavía: se intenta en la próxima carga.
    }
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

// ---------------------------------------------------------------------------
// Nombres únicos: un equipo o una competición se llama siempre igual
// ---------------------------------------------------------------------------

/**
 * Alias que vienen de fábrica: «como lo escribió el CSV» => «como se llama en Liveo».
 * El nombre oficial es el que ya tiene escudo / logo. Se amplían desde el panel
 * (menú «Nombres»).
 */
function name_alias_defaults(): array
{
    return [
        'team' => [
            'Athletico-PR'         => 'Athletico Paranaense',
            'Atlético-MG'          => 'Atlético Mineiro',
            '1. FC Köln'           => 'Köln',
            '1. FC Kaiserslautern' => 'Kaiserslautern',
            'Darmstadt 98'         => 'Darmstadt',
            'FC Augsburg'          => 'Augsburg',
            'SC Freiburg'          => 'Freiburg',
            'VfB Stuttgart'        => 'Stuttgart',
            'VfL Bochum'           => 'Bochum',
        ],
        'league' => [
            'Brasileirão Série A' => 'Brasileirão',
        ],
    ];
}

/** Texto del panel -> ['team' => [clave => oficial], 'league' => [...]]. «Alias = Oficial»; «liga: Alias = Oficial». */
function name_aliases_parse(string $text): array
{
    $out = ['team' => [], 'league' => []];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $kind = 'team';
        if (preg_match('/^(liga|competicion|competición|league)\s*:\s*(.+)$/iu', $line, $m) === 1) {
            $kind = 'league';
            $line = trim($m[2]);
        }
        $p = strpos($line, '=');
        if ($p === false) {
            continue;
        }
        $alias = trim(substr($line, 0, $p));
        $canon = trim(substr($line, $p + 1));
        $k = team_key($alias);
        if ($k === '' || $canon === '' || $k === team_key($canon)) {
            continue;
        }
        $out[$kind][$k] = $canon;
    }
    return $out;
}

/** Alias en uso: los de fábrica + los que se escribieron en el panel (estos mandan). */
function name_aliases(PDO $pdo, bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $cache = ['team' => [], 'league' => []];
    foreach (name_alias_defaults() as $kind => $pairs) {
        foreach ($pairs as $alias => $canon) {
            $cache[$kind][team_key((string)$alias)] = $canon;
        }
    }
    try {
        $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $st->execute(['name_aliases']);
        $v = $st->fetchColumn();
        if ($v !== false) {
            foreach (name_aliases_parse((string)$v) as $kind => $pairs) {
                foreach ($pairs as $k => $canon) {
                    $cache[$kind][(string)$k] = $canon;
                }
            }
        }
    } catch (Throwable $e) {
        // Sin tabla de ajustes: solo los alias de fábrica.
    }
    return $cache;
}

/** Palabras que no distinguen a un equipo: «1. FC Köln» y «Köln» son el mismo. */
function team_loose_key(string $name): string
{
    $skip = ['fc', '1', '04', '05', '96', '98', '1899', 'vfb', 'vfl', 'tsg', 'fsv'];
    $w = [];
    foreach (explode(' ', team_key($name)) as $x) {
        if ($x !== '' && !in_array($x, $skip, true)) {
            $w[] = $x;
        }
    }
    return implode(' ', $w);
}

/**
 * Nombres ya conocidos de un tipo ('team' / 'league'): los que tienen escudo o logo
 * guardado (pesan mucho) y los que ya se usan en eventos. Con $add se registra un
 * nombre nuevo en el índice sin volver a consultar la base.
 * Devuelve ['key' => [clave => [nombre, peso total, peso del mejor]], 'loose' => [clave suelta => [clave...]]].
 */
function name_index(PDO $pdo, string $kind, bool $reload = false, ?string $add = null): array
{
    static $idx = [];
    if (!isset($idx[$kind]) || $reload) {
        $w = []; // nombre => peso
        try {
            if ($kind === 'team') {
                $logos = $pdo->query('SELECT name FROM team_logos')->fetchAll(PDO::FETCH_COLUMN);
                $used = $pdo->query("SELECT home FROM manual_events WHERE away <> '' UNION ALL SELECT away FROM manual_events WHERE away <> ''")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $logos = $pdo->query('SELECT name FROM league_logos')->fetchAll(PDO::FETCH_COLUMN);
                $used = $pdo->query("SELECT league FROM manual_events WHERE league <> ''")->fetchAll(PDO::FETCH_COLUMN);
            }
            foreach ($logos as $n) {
                $n = trim((string)$n);
                $w[$n] = ($w[$n] ?? 0) + 1000;
            }
            foreach ($used as $n) {
                $n = trim((string)$n);
                $w[$n] = ($w[$n] ?? 0) + 1;
            }
        } catch (Throwable $e) {
            // Sin tablas todavía: índice vacío.
        }
        $idx[$kind] = ['key' => [], 'loose' => [], 'best' => []];
        foreach ($w as $n => $wt) {
            name_index_put($idx[$kind], $kind, (string)$n, (int)$wt);
        }
    }
    if ($add !== null && $add !== '') {
        name_index_put($idx[$kind], $kind, $add, 1);
    }
    return $idx[$kind];
}

/** Suma un nombre (con su peso) al índice. */
function name_index_put(array &$ix, string $kind, string $name, int $wt): void
{
    $k = team_key($name);
    if ($k === '') {
        return;
    }
    if (!isset($ix['key'][$k])) {
        $ix['key'][$k] = [$name, $wt, $wt];
    } else {
        $ix['key'][$k][1] += $wt;
        // El nombre que se muestra es el de mayor peso (empate: el orden alfabético).
        if ($wt > $ix['key'][$k][2] || ($wt === $ix['key'][$k][2] && strcmp($name, $ix['key'][$k][0]) < 0)) {
            $ix['key'][$k][0] = $name;
            $ix['key'][$k][2] = $wt;
        }
    }
    if ($kind === 'team') {
        $lk = team_loose_key($name);
        if ($lk !== '' && !in_array($k, $ix['loose'][$lk] ?? [], true)) {
            $ix['loose'][$lk][] = $k;
        }
    }
}

/**
 * Nombre único de un equipo ($kind = 'team') o de una competición ($kind = 'league'):
 * 1) alias (de fábrica o del panel); 2) si ya existe con otras mayúsculas / tildes /
 * guiones, el que ya está; 3) equipos: si solo cambia «1.», «FC», «VfB»…, el que ya está
 * (se prefiere el que tiene escudo). Si es nuevo, queda como vino.
 */
function canonical_name(PDO $pdo, string $kind, string $name): string
{
    $name = trim((string)preg_replace('/\s+/u', ' ', $name));
    $k = team_key($name);
    if ($name === '' || $k === '') {
        return $name;
    }
    $al = name_aliases($pdo)[$kind] ?? [];
    for ($i = 0; $i < 4 && isset($al[$k]); $i++) {
        $name = trim((string)$al[$k]);
        $k = team_key($name);
        if ($k === '') {
            return $name;
        }
    }
    $ix = name_index($pdo, $kind);
    if (isset($ix['key'][$k])) {
        return $ix['key'][$k][0];
    }
    if ($kind === 'team') {
        $lk = team_loose_key($name);
        if ($lk !== '' && !empty($ix['loose'][$lk])) {
            $best = null;
            foreach ($ix['loose'][$lk] as $ck) {
                $c = $ix['key'][$ck];
                if ($best === null || $c[1] > $best[1]
                    || ($c[1] === $best[1] && strlen($c[0]) < strlen($best[0]))
                    || ($c[1] === $best[1] && strlen($c[0]) === strlen($best[0]) && strcmp($c[0], $best[0]) < 0)) {
                    $best = $c;
                }
            }
            if ($best !== null) {
                return $best[0];
            }
        }
    }
    name_index($pdo, $kind, false, $name); // nombre nuevo: sus variantes se unirán a este
    return $name;
}

/**
 * Clave para no duplicar al volver a importar. Con rivales: fecha + local + visitante
 * (no depende de cómo se escriba la competición); evento de un solo título: fecha + competición + título.
 */
function manual_import_key(string $date, string $league, string $home, string $away): string
{
    if ($away !== '') {
        return sha1($date . '|' . team_key($home) . '|' . team_key($away));
    }
    return sha1($date . '|' . team_key($league) . '|' . team_key($home));
}

/**
 * Limpia lo que ya está guardado: pasa competiciones y equipos a su nombre único,
 * recalcula la clave de importación y funde los partidos que quedaron repetidos
 * (conserva el más antiguo y le pasa las señales y favoritos del otro).
 * Devuelve ['renamed' => n, 'rekeyed' => n, 'merged' => n].
 */
function manual_unify_existing(PDO $pdo): array
{
    $res = ['renamed' => 0, 'rekeyed' => 0, 'merged' => 0];
    $tz = new DateTimeZone((string)liveo_config()['timezone']);
    name_aliases($pdo, true);
    name_index($pdo, 'team', true);
    name_index($pdo, 'league', true);

    $rows = $pdo->query('SELECT id, league, home, away, start_ts, import_key FROM manual_events ORDER BY id')->fetchAll();
    $upd = $pdo->prepare('UPDATE manual_events SET league = ?, home = ?, away = ? WHERE id = ?');
    $setKey = $pdo->prepare('UPDATE manual_events SET import_key = ? WHERE id = ?');
    $keep = []; // clave nueva => id que se queda
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $league = canonical_name($pdo, 'league', (string)$r['league']);
        $home = (string)$r['home'];
        $away = (string)$r['away'];
        if ($away !== '') {
            $home = canonical_name($pdo, 'team', $home);
            $away = canonical_name($pdo, 'team', $away);
        }
        if ($league !== (string)$r['league'] || $home !== (string)$r['home'] || $away !== (string)$r['away']) {
            $upd->execute([$league, $home, $away, $id]);
            $res['renamed']++;
        }
        if ($r['import_key'] === null) {
            continue; // creado a mano: la próxima importación lo reconoce por equipos y fecha
        }
        $date = (new DateTimeImmutable('@' . (int)$r['start_ts']))->setTimezone($tz)->format('Y-m-d');
        $newKey = manual_import_key($date, $league, $home, $away);
        if (isset($keep[$newKey])) {
            // Mismo partido cargado dos veces: se funde en el primero.
            try {
                $to = 'manual-' . $keep[$newKey];
                $from = 'manual-' . $id;
                $pdo->prepare('UPDATE event_sources SET event_id = ? WHERE event_id = ?')->execute([$to, $from]);
                try {
                    $pdo->prepare('UPDATE IGNORE favorites SET event_id = ? WHERE event_id = ?')->execute([$to, $from]);
                    $pdo->prepare('DELETE FROM favorites WHERE event_id = ?')->execute([$from]);
                } catch (Throwable $e) {
                    // Sin favoritos: nada que mover.
                }
                $pdo->prepare('DELETE FROM manual_events WHERE id = ?')->execute([$id]);
                $res['merged']++;
            } catch (Throwable $e) {
                // Si no se pudo fundir, queda como estaba.
            }
            continue;
        }
        $keep[$newKey] = $id;
        if ($newKey !== (string)$r['import_key']) {
            try {
                $setKey->execute([$newKey, $id]);
                $res['rekeyed']++;
            } catch (Throwable $e) {
                // Clave ya usada por otro partido: se deja la anterior.
            }
        }
    }
    return $res;
}

/** URL base pública de Liveo (http://host/Liveo), sacada de la petición actual. */
function liveo_public_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
        $host = 'localhost';
    }
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = preg_replace('#/(api|admin)$#', '', $dir) ?? $dir;
    $dir = preg_replace('#/public$#', '', $dir) ?? $dir;
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($dir === '.' ? '' : $dir, '/');
}

/** Escudo guardado -> URL que entiende la app (los subidos son relativos: uploads/logos/…). */
function logo_public_url(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') {
        return '';
    }
    if (strncmp($stored, 'uploads/', 8) === 0) {
        return liveo_public_base() . '/' . $stored;
    }
    return $stored;
}

/** Carpeta donde se guardan los escudos subidos. */
function logo_dir(): string
{
    return dirname(__DIR__) . '/uploads/logos';
}

/** Guarda el escudo de un equipo para siempre (todos sus partidos, presentes y futuros). */
function remember_team_logo(PDO $pdo, string $name, string $url): void
{
    $key = team_key($name);
    $url = trim($url);
    if ($key === '' || $url === '') {
        return;
    }
    $pdo->prepare('REPLACE INTO team_logos (name_key, name, url) VALUES (?, ?, ?)')
        ->execute([$key, substr(trim($name), 0, 120), substr($url, 0, 500)]);
}

/**
 * Guarda una imagen subida como escudo del equipo.
 * Devuelve ['ok' => bool, 'url' => 'uploads/logos/…', 'error' => string].
 */
function save_team_logo_upload(PDO $pdo, string $teamName, array $file): array
{
    $key = team_key($teamName);
    if ($key === '') {
        return ['ok' => false, 'url' => '', 'error' => 'Nombre de equipo vacío.'];
    }
    if ((int)($file['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        return ['ok' => false, 'url' => '', 'error' => 'No se pudo recibir el archivo.'];
    }
    if ((int)$file['size'] > 1536 * 1024) {
        return ['ok' => false, 'url' => '', 'error' => 'La imagen supera 1,5 MB.'];
    }
    $info = @getimagesize((string)$file['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
    if ($ext === null) {
        return ['ok' => false, 'url' => '', 'error' => 'Solo se aceptan imágenes PNG, JPG, WEBP o GIF.'];
    }
    $dir = logo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'url' => '', 'error' => 'No se pudo crear la carpeta uploads/logos.'];
    }
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $key), '-');
    $name = $slug . '-' . substr((string)sha1_file((string)$file['tmp_name']), 0, 8) . '.' . $ext;
    if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'url' => '', 'error' => 'No se pudo guardar la imagen en uploads/logos.'];
    }
    // Borra el escudo subido anterior del mismo equipo.
    $st = $pdo->prepare('SELECT url FROM team_logos WHERE name_key = ?');
    $st->execute([$key]);
    $old = (string)($st->fetchColumn() ?: '');
    if (strncmp($old, 'uploads/logos/', 14) === 0 && basename($old) !== $name) {
        @unlink($dir . '/' . basename($old));
    }
    return ['ok' => true, 'url' => 'uploads/logos/' . $name, 'error' => ''];
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

/** Guarda el logo de una competición (todos sus partidos, presentes y futuros). */
function remember_league_logo(PDO $pdo, string $name, string $url): void
{
    $key = team_key($name);
    $url = trim($url);
    if ($key === '' || $url === '') {
        return;
    }
    $pdo->prepare('REPLACE INTO league_logos (name_key, name, url) VALUES (?, ?, ?)')
        ->execute([$key, substr(trim($name), 0, 160), substr($url, 0, 500)]);
}

/**
 * Guarda una imagen subida como logo de la competición.
 * Devuelve ['ok' => bool, 'url' => 'uploads/logos/…', 'error' => string].
 */
function save_league_logo_upload(PDO $pdo, string $leagueName, array $file): array
{
    $key = team_key($leagueName);
    if ($key === '') {
        return ['ok' => false, 'url' => '', 'error' => 'Nombre de competición vacío.'];
    }
    if ((int)($file['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        return ['ok' => false, 'url' => '', 'error' => 'No se pudo recibir el archivo.'];
    }
    if ((int)$file['size'] > 1536 * 1024) {
        return ['ok' => false, 'url' => '', 'error' => 'La imagen supera 1,5 MB.'];
    }
    $info = @getimagesize((string)$file['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
    if ($ext === null) {
        return ['ok' => false, 'url' => '', 'error' => 'Solo se aceptan imágenes PNG, JPG, WEBP o GIF.'];
    }
    $dir = logo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'url' => '', 'error' => 'No se pudo crear la carpeta uploads/logos.'];
    }
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $key), '-');
    $name = 'liga-' . $slug . '-' . substr((string)sha1_file((string)$file['tmp_name']), 0, 8) . '.' . $ext;
    if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'url' => '', 'error' => 'No se pudo guardar la imagen en uploads/logos.'];
    }
    // Borra el logo subido anterior de la misma competición.
    $st = $pdo->prepare('SELECT url FROM league_logos WHERE name_key = ?');
    $st->execute([$key]);
    $old = (string)($st->fetchColumn() ?: '');
    if (strncmp($old, 'uploads/logos/', 14) === 0 && basename($old) !== $name) {
        @unlink($dir . '/' . basename($old));
    }
    return ['ok' => true, 'url' => 'uploads/logos/' . $name, 'error' => ''];
}

/** [clave => url] de los logos guardados por competición (una consulta por petición). */
function league_logo_map(?PDO $pdo = null): array
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
        foreach ($pdo->query('SELECT name_key, url FROM league_logos')->fetchAll() as $r) {
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

/** Minutos que dura un partido de básquet (NBA, etc.). Por defecto 180. */
function manual_basket_duration_minutes(?PDO $pdo = null): int
{
    static $cache = null;
    if ($pdo === null) {
        $pdo = db();
    }
    if ($cache !== null) {
        return $cache;
    }
    $min = 180;
    if ($pdo) {
        try {
            $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
            $st->execute(['manual_event_duration_basket']);
            $v = $st->fetchColumn();
            if ($v !== false && is_numeric($v)) {
                $min = (int)$v;
            }
        } catch (Throwable $e) {
            // Sin tabla de ajustes: se usa el valor por defecto.
        }
    }
    $cache = max(60, min(720, $min));
    return $cache;
}

/** true si el deporte/liga es básquet (NBA, baloncesto, basketball…). */
function manual_is_basketball(string $sport, string $league = ''): bool
{
    $t = mb_strtolower($sport . ' ' . $league, 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    return preg_match('/basq|basket|baloncesto|\bnba\b|wnba|euroliga|euroleague|\bfiba\b|liga nacional de basq/', $t) === 1;
}

/** Duración automática según el deporte (si no hay una propia del evento). */
function manual_sport_duration_minutes(string $sport, string $league, int $globalMin, ?PDO $pdo = null): int
{
    if (manual_is_basketball($sport, $league)) {
        return manual_basket_duration_minutes($pdo);
    }
    return $globalMin;
}

/** Convierte una fila de manual_events al formato de evento de la API. */
function manual_row_to_event(array $r, int $durationMin, int $now): array
{
    $start = (int)$r['start_ts'];
    // Duración propia del evento (columna «hasta» del CSV); si no, la global.
    // Sin duración propia: la del deporte (el básquet dura más que el fútbol) o la global.
    $own = (int)($r['duration_min'] ?? 0);
    $durationMin = $own > 0
        ? $own
        : manual_sport_duration_minutes((string)($r['sport'] ?? ''), (string)($r['league'] ?? ''), $durationMin);
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
    // Logo de la competición (se carga una vez en el panel: Competiciones).
    if ($league !== '') {
        $ll = league_logo_map()[team_key($league)] ?? '';
        if ($ll !== '') {
            $ev['league_logo'] = logo_public_url($ll);
        }
    }
    if ($hl !== '') {
        $ev['home_logo'] = logo_public_url($hl);
    }
    if ($al !== '') {
        $ev['away_logo'] = logo_public_url($al);
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
    // El SQL solo preselecciona (con la duración más larga posible); el corte exacto
    // por deporte lo hace manual_row_to_event() y el filtro de abajo.
    $dur = max(manual_duration_minutes($pdo), manual_basket_duration_minutes($pdo));
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

// ---------------------------------------------------------------------------
// Anuncios informativos (barra deslizante de la app)
// ---------------------------------------------------------------------------

/** Anuncios guardados: ['enabled' => bool, 'items' => string[]]. */
function announcements_load(?PDO $pdo = null): array
{
    if ($pdo === null) {
        $pdo = db();
    }
    $out = ['enabled' => true, 'items' => []];
    if (!$pdo) {
        return $out;
    }
    try {
        $st = $pdo->prepare('SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN (?, ?)');
        $st->execute(['announcements_on', 'announcements_text']);
        foreach ($st->fetchAll() as $r) {
            if ($r['setting_key'] === 'announcements_on') {
                $out['enabled'] = (string)$r['setting_value'] !== '0';
            } else {
                $out['items'] = announcements_parse((string)$r['setting_value']);
            }
        }
    } catch (Throwable $e) {
        // Sin tabla de ajustes: sin anuncios.
    }
    return $out;
}

/** Una línea = un anuncio. Máximo 10 anuncios de 200 caracteres. */
function announcements_parse(string $text): array
{
    $items = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
        $line = trim((string)preg_replace('/\s+/u', ' ', $line));
        if ($line === '') {
            continue;
        }
        if (function_exists('mb_substr')) {
            $line = mb_substr($line, 0, 200);
        } else {
            $line = substr($line, 0, 200);
        }
        $items[] = $line;
        if (count($items) >= 10) {
            break;
        }
    }
    return $items;
}
