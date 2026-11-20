<?php
declare(strict_types=1);

/**
 * Configuration et fonctions communes à aria.php (accès de la balance)
 * et pesees.php (page web des utilisateurs).
 */

// Idéalement HORS de la racine web (ex. /var/lib/aria) : le dossier contient les CSV,
// le journal des échanges (token et code d'authentification de la balance) et les
// tentatives de connexion.
const DATA_DIR = __DIR__ . '/data';

const TIMEZONE = 'Europe/Paris';
date_default_timezone_set(TIMEZONE);

// Adresses MAC autorisées pour la balance (hexadécimal minuscule, sans séparateur,
// ex. ['20f85e123456']). Liste vide = toutes les balances sont acceptées.
const ALLOWED_MACS = [];

// Page web : verrouillage d'une IP après trop d'échecs de connexion
const MAX_LOGIN_FAILURES = 5;
const LOCKOUT_SECONDS = 900;

// Les tolérances min/max envoyées à la balance sont des BORNES ABSOLUES en grammes :
// dernière pesée (arrondie au kg inférieur) ± WEIGHT_DELTA_G, plancher à 0.
// (Le serveur de référence helvetic utilise 4000 g ; ici 3000 g à votre demande.)
const WEIGHT_DELTA_G = 3000;

// Les serveurs de référence (helvetic, serveur de test) envoient 0 pour
// weight1 / body_fat / covariance / weight2 / timestamp, et la balance reconnaît
// quand même les utilisateurs. true = envoyer les dernières valeurs connues
// (non vérifié sur une vraie balance).
const SEND_PREVIOUS_VALUES = false;

// Les utilisateurs sont gérés par les administrateurs depuis admin.php et stockés dans
// data/users.json. Cette liste n'est importée qu'UNE FOIS, à la création de ce fichier
// (sans mot de passe : un admin doit leur en définir un). Elle peut ensuite être vidée.
// initial_weight_g : poids de référence tant qu'aucune pesée n'est connue.
$SEED_USERS = [
    ['id' => 1, 'name' => 'Cedric', 'initial_weight_g' => 80000,
     'birth_year' => 1991, 'gender' => 0x02, 'height_mm' => 1800],
    ['id' => 2, 'name' => 'Marie',  'initial_weight_g' => 57000,
     'birth_year' => 1993, 'gender' => 0x00, 'height_mm' => 1650],
];

// Compte administrateur créé automatiquement au premier lancement.
// Son mot de passe par défaut DOIT être changé à la première connexion.
const DEFAULT_ADMIN_NAME = 'admin';
const DEFAULT_ADMIN_PASSWORD = 'changeme';
const MIN_PASSWORD_LENGTH = 10;
const USERS_FILE = DATA_DIR . '/users.json';
// Clés des pesées supprimées : la balance les renvoie parfois, elles ne doivent pas revenir.
const DELETED_FILE = DATA_DIR . '/deleted_keys.txt';

// Réglages globaux (Pushover, alerte batterie) et état des balances, modifiables depuis admin.php.
const SETTINGS_FILE = DATA_DIR . '/settings.json';
// API Pushover (modifiable pour un serveur de test).
const PUSHOVER_URL = 'https://api.pushover.net/1/messages.json';
const SETTINGS_DEFAULTS = [
    'pushover_enabled'      => false,
    'pushover_app_token'    => '',
    'battery_alert_enabled' => false,
    'battery_threshold'     => 20,    // % : alerte quand le niveau est inférieur ou égal
    'battery_user_key'      => '',    // clé Pushover (utilisateur ou groupe) destinataire de l'alerte
    'ui_url'                => '',    // URL de l'interface web, jointe aux notifications de pesée (facultatif)
    'scales'                => [],    // état par balance : battery, seen, alerted
];

const CSV_HEADER = ['timestamp', 'datetime', 'aria_timestamp', 'weight_g',
                    'weight_kg', 'impedance', 'fat1', 'covariance', 'fat2'];

/* ---------- Utilitaires ---------- */

function crc16_xmodem(string $data): int
{
    $crc = 0x0000;
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return $crc;
}

function csv_path(?array $user): string
{
    if ($user === null) {
        return DATA_DIR . '/guests.csv';
    }
    return sprintf('%s/user_%d.csv', DATA_DIR, $user['id']);
}

/** Lit toutes les lignes d'un CSV sous forme de tableaux associatifs. */
function read_rows(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $fh = fopen($path, 'rb');
    $rows = [];
    $header = fgetcsv($fh, null, ',', '"', '');
    while ($header && ($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if (count($r) === count($header)) {
            $rows[] = array_combine($header, $r);
        }
    }
    fclose($fh);
    return $rows;
}

/** Ajoute les nouvelles pesées (dédoublonnées sur aria_timestamp + weight_g). */
function append_rows(string $path, array $rows): int
{
    if (!$rows) {
        return 0;
    }
    $known = [];
    foreach (read_rows($path) as $r) {
        $known[$r['aria_timestamp'] . '_' . $r['weight_g']] = true;
    }

    $isNew = !is_file($path);
    $fh = fopen($path, 'ab');
    if ($fh === false) {
        throw new RuntimeException("Impossible d'ouvrir $path");
    }
    if ($isNew) {
        fputcsv($fh, CSV_HEADER, ',', '"', '');
    }
    $added = 0;
    foreach ($rows as $r) {
        $key = $r['aria_timestamp'] . '_' . $r['weight_g'];
        if (isset($known[$key])) {
            continue;
        }
        $known[$key] = true;
        fputcsv($fh, array_map(fn($c) => $r[$c], CSV_HEADER), ',', '"', '');
        $added++;
    }
    fclose($fh);
    return $added;
}

/** Dernière pesée connue (timestamp le plus récent) d'un utilisateur. */
function last_known(array $user): ?array
{
    $last = null;
    foreach (read_rows(csv_path($user)) as $r) {
        if ($last === null || (int)$r['timestamp'] > (int)$last['timestamp']) {
            $last = $r;
        }
    }
    return $last;
}

/** Identifiant d'une pesée (même clé que la déduplication). */
function measurement_key(array $r): string
{
    return $r['aria_timestamp'] . '_' . $r['weight_g'];
}

/** Réécrit un CSV complet (fichier temporaire puis renommage : jamais à moitié écrit). */
function write_rows(string $path, array $rows): void
{
    $tmp = $path . '.tmp';
    $fh = fopen($tmp, 'wb');
    if ($fh === false) {
        throw new RuntimeException("Impossible d'écrire $path");
    }
    fputcsv($fh, CSV_HEADER, ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($fh, array_map(fn($c) => $r[$c], CSV_HEADER), ',', '"', '');
    }
    fclose($fh);
    if (!rename($tmp, $path)) {
        throw new RuntimeException("Impossible de remplacer $path");
    }
}

/** Clés de toutes les pesées déjà enregistrées, tous propriétaires confondus. */
function all_known_keys(): array
{
    $files = glob(DATA_DIR . '/user_*.csv') ?: [];
    $files[] = csv_path(null);
    $keys = [];
    foreach ($files as $f) {
        foreach (read_rows($f) as $r) {
            $keys[measurement_key($r)] = true;
        }
    }
    if (is_file(DELETED_FILE)) {
        foreach (file(DELETED_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $k) {
            $keys[$k] = true;
        }
    }
    return $keys;
}

/** Supprime définitivement une pesée (null = invités) et mémorise sa clé. */
function delete_measurement(string $key, ?array $owner): void
{
    $path = csv_path($owner);
    $lock = fopen(DATA_DIR . '/.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Impossible de verrouiller ' . DATA_DIR);
    }
    flock($lock, LOCK_EX);
    try {
        $kept = [];
        $found = false;
        foreach (read_rows($path) as $r) {
            if (!$found && measurement_key($r) === $key) {
                $found = true;
            } else {
                $kept[] = $r;
            }
        }
        if (!$found) {
            throw new InvalidArgumentException('Pesée introuvable (déjà supprimée ou déplacée ?).');
        }
        // La clé est mémorisée d'abord : en cas de coupure, la pesée reste au pire visible, jamais recréée.
        file_put_contents(DELETED_FILE, $key . "\n", FILE_APPEND | LOCK_EX);
        write_rows($path, $kept);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Déplace une pesée d'un propriétaire à un autre (null = invités).
 * Sous le même verrou que l'enregistrement des pesées de la balance.
 */
function move_measurement(string $key, ?array $from, ?array $to): void
{
    $src = csv_path($from);
    $dst = csv_path($to);
    if ($src === $dst) {
        throw new InvalidArgumentException('La pesée appartient déjà à ce propriétaire.');
    }
    $lock = fopen(DATA_DIR . '/.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Impossible de verrouiller ' . DATA_DIR);
    }
    flock($lock, LOCK_EX);
    try {
        $moved = null;
        $kept = [];
        foreach (read_rows($src) as $r) {
            if ($moved === null && measurement_key($r) === $key) {
                $moved = $r;
            } else {
                $kept[] = $r;
            }
        }
        if ($moved === null) {
            throw new InvalidArgumentException('Pesée introuvable (déjà déplacée ?).');
        }
        append_rows($dst, [$moved]);   // d'abord la destination : au pire un doublon, jamais une perte
        write_rows($src, $kept);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Poids de référence : dernière pesée connue, sinon poids initial configuré. */
function reference_weight(array $user): int
{
    $last = last_known($user);
    return $last ? (int)$last['weight_g'] : (int)$user['initial_weight_g'];
}

/** Déduit l'utilisateur le plus proche (dans le delta) pour une pesée non attribuée. */
function guess_user(int $weight_g, array $users, array $refs): ?array
{
    $best = null;
    $bestDiff = WEIGHT_DELTA_G + 1;
    foreach ($users as $u) {
        $diff = abs($weight_g - $refs[$u['id']]);
        if ($diff <= WEIGHT_DELTA_G && $diff < $bestDiff) {
            $best = $u;
            $bestDiff = $diff;
        }
    }
    return $best;
}

function build_user(array $u): string
{
    return pack('V', $u['id'])
         . str_repeat("\0", 16)
         . str_pad(strtoupper(substr($u['name'], 0, 20)), 20, ' ')
         . pack('VVV', $u['min'], $u['max'], max(0, (int)date('Y') - $u['birth_year']))
         . pack('C', $u['gender'])
         . pack('V', $u['height_mm'])
         . pack('VVVV', $u['weight1'] ?? 0, $u['body_fat'] ?? 0,
                        $u['covariance'] ?? 0, $u['weight2'] ?? 0)
         . pack('V', $u['last_ts'] ?? 0);   // total : 77 octets (0x4d)
}

/* ---------- Stockage des utilisateurs (data/users.json) ---------- */

function users_read(): array
{
    $d = is_file(USERS_FILE) ? json_decode((string)file_get_contents(USERS_FILE), true) : null;
    if (!is_array($d) || !isset($d['users']) || !is_array($d['users'])) {
        return ['next_id' => 1, 'users' => []];
    }
    $d['next_id'] = (int)($d['next_id'] ?? 1);
    return $d;
}

function users_write(array $d): void
{
    $tmp = USERS_FILE . '.tmp';
    $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, USERS_FILE)) {
        throw new RuntimeException("Écriture de users.json impossible (droits sur " . DATA_DIR . " ?)");
    }
    @chmod(USERS_FILE, 0640);
}

/**
 * Lecture + modification + écriture sous verrou exclusif.
 * $fn reçoit les données par référence ; une exception annule l'écriture.
 */
function users_update(callable $fn): mixed
{
    $lock = fopen(DATA_DIR . '/.users.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Impossible de verrouiller ' . DATA_DIR);
    }
    flock($lock, LOCK_EX);
    try {
        $d = users_read();
        $result = $fn($d);
        users_write($d);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function load_users(): array
{
    return users_read()['users'];
}

function find_user_by_id(int $id): ?array
{
    foreach (load_users() as $u) {
        if ($u['id'] === $id) {
            return $u;
        }
    }
    return null;
}

function find_user_by_name(string $name): ?array
{
    foreach (load_users() as $u) {
        if (strcasecmp($u['name'], $name) === 0) {
            return $u;
        }
    }
    return null;
}

/** Modifie quelques champs d'un utilisateur existant. */
function user_patch(int $id, array $changes): void
{
    users_update(function (array &$d) use ($id, $changes) {
        foreach ($d['users'] as &$u) {
            if ($u['id'] === $id) {
                $u = $changes + $u;
                return;
            }
        }
        throw new InvalidArgumentException('Utilisateur introuvable.');
    });
}

function count_admins(array $users): int
{
    return count(array_filter($users, fn($u) => !empty($u['admin'])));
}

/**
 * Valide les champs du formulaire utilisateur et renvoie les champs normalisés.
 * $editingId : id de l'utilisateur modifié (pour l'unicité du nom), null à la création.
 */
function parse_user_input(array $in, ?int $editingId, array $allUsers): array
{
    $name = trim((string)($in['name'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{0,19}$/', $name)) {
        throw new InvalidArgumentException(
            'Nom invalide : 1 à 20 caractères (lettres sans accent, chiffres, espace, - ou _).');
    }
    foreach ($allUsers as $u) {
        if ($u['id'] !== $editingId && strcasecmp($u['name'], $name) === 0) {
            throw new InvalidArgumentException('Ce nom est déjà utilisé.');
        }
    }

    $onScale = !empty($in['on_scale']);
    $weight  = (float)str_replace(',', '.', (string)($in['weight_kg'] ?? '0'));
    $year    = (int)($in['birth_year'] ?? 0);
    $height  = (int)($in['height_cm'] ?? 0);

    if ($onScale) {
        if ($weight < 30 || $weight > 250) {
            throw new InvalidArgumentException('Poids de départ : entre 30 et 250 kg.');
        }
        if ($year < 1900 || $year > (int)date('Y')) {
            throw new InvalidArgumentException('Année de naissance invalide.');
        }
        if ($height < 100 || $height > 250) {
            throw new InvalidArgumentException('Taille : entre 100 et 250 cm.');
        }
    }

    return parse_push_input($in) + [
        'name'             => $name,
        'admin'            => !empty($in['admin']),
        'on_scale'         => $onScale,
        'initial_weight_g' => (int)round($weight * 1000),
        'birth_year'       => $year,
        'gender'           => (($in['gender'] ?? 'm') === 'f') ? 0x00 : 0x02,
        'height_mm'        => $height * 10,
    ];
}

/* ---------- Réglages globaux et Pushover ---------- */

function settings_read(): array
{
    $d = is_file(SETTINGS_FILE) ? json_decode((string)file_get_contents(SETTINGS_FILE), true) : null;
    return array_replace(SETTINGS_DEFAULTS, is_array($d) ? $d : []);
}

/** Lecture + modification + écriture sous verrou exclusif ($fn reçoit les réglages par référence). */
function settings_update(callable $fn): mixed
{
    $lock = fopen(DATA_DIR . '/.settings.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Impossible de verrouiller ' . DATA_DIR);
    }
    flock($lock, LOCK_EX);
    try {
        $s = settings_read();
        $result = $fn($s);
        $tmp = SETTINGS_FILE . '.tmp';
        $json = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, SETTINGS_FILE)) {
            throw new RuntimeException('Écriture de settings.json impossible (droits sur ' . DATA_DIR . ' ?)');
        }
        @chmod(SETTINGS_FILE, 0640);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Clé utilisateur / groupe / jeton d'application Pushover : 30 lettres et chiffres. */
function pushover_key_valid(string $k): bool
{
    return (bool)preg_match('/^[A-Za-z0-9]{30}$/', $k);
}

/**
 * Champs Pushover d'un utilisateur, validés : clé (id Pushover), appareil (optionnel),
 * et envoi d'une notification à chaque nouvelle pesée.
 */
function parse_push_input(array $in): array
{
    $key = trim((string)($in['pushover_key'] ?? ''));
    if ($key !== '' && !pushover_key_valid($key)) {
        throw new InvalidArgumentException('Clé Pushover invalide : 30 lettres et chiffres.');
    }
    $dev = trim((string)($in['pushover_device'] ?? ''));
    if ($dev !== '' && !preg_match('/^[A-Za-z0-9_-]{1,25}$/', $dev)) {
        throw new InvalidArgumentException('Nom d\'appareil Pushover invalide (25 caractères max. : lettres, chiffres, - ou _).');
    }
    return [
        'pushover_key'    => $key,
        'pushover_device' => $dev,
        'notify_weighing'    => !empty($in['notify_weighing']),
        'notify_hide_values' => !empty($in['notify_hide_values']),   // pas de poids ni de masse grasse dans la notification
        'notify_link'        => !empty($in['notify_link']),          // joindre le lien vers l'interface
    ];
}

/** Pushover est-il utilisable (activé et jeton d'application renseigné) ? */
function pushover_ready(?array $s = null): bool
{
    $s ??= settings_read();
    return !empty($s['pushover_enabled']) && pushover_key_valid((string)$s['pushover_app_token']);
}

/** URL de l'interface valide (http/https, 512 caractères max.) ? */
function ui_url_valid(string $u): bool
{
    return strlen($u) <= 512 && (bool)preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL) !== false;
}

/** Lien à joindre à la notification d'un utilisateur : URL configurée par l'admin, si l'utilisateur l'a demandée. */
function user_push_link(array $u, ?array $s = null): ?string
{
    $s ??= settings_read();
    $url = trim((string)$s['ui_url']);
    return !empty($u['notify_link']) && $url !== '' && ui_url_valid($url) ? $url : null;
}

/** Cet utilisateur reçoit-il une notification à chaque pesée ? */
function user_wants_weighing_push(array $u): bool
{
    return ($u['pushover_key'] ?? '') !== '' && ($u['notify_weighing'] ?? false);
}

/**
 * Envoie une notification Pushover. Renvoie true si Pushover l'a acceptée ;
 * sinon false, avec la raison dans $GLOBALS['pushover_error'].
 */
function pushover_send(string $userKey, string $title, string $message, ?string $device = null, ?int $timestamp = null,
                       ?string $url = null, ?string $urlTitle = null): bool
{
    $GLOBALS['pushover_error'] = '';
    $s = settings_read();
    if (!pushover_ready($s)) {
        $GLOBALS['pushover_error'] = 'Pushover est désactivé ou le jeton d\'application est manquant.';
        return false;
    }
    if (!pushover_key_valid($userKey)) {
        $GLOBALS['pushover_error'] = 'Clé Pushover du destinataire absente ou invalide.';
        return false;
    }
    $fields = [
        'token'   => $s['pushover_app_token'],
        'user'    => $userKey,
        'title'   => mb_substr($title, 0, 250),
        'message' => mb_substr($message, 0, 1024),
    ];
    if ($device !== null && $device !== '') {
        $fields['device'] = $device;
    }
    if ($timestamp !== null && $timestamp > 0) {
        $fields['timestamp'] = (string)$timestamp;
    }
    if ($url !== null && $url !== '') {
        $fields['url'] = $url;
        $fields['url_title'] = mb_substr($urlTitle ?: 'Ouvrir', 0, 100);
    }
    $body = http_build_query($fields);
    $code = 0;
    $resp = '';
    if (function_exists('curl_init')) {
        $ch = curl_init(PUSHOVER_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code === 0) {
            $GLOBALS['pushover_error'] = 'Connexion à Pushover impossible' . ($err !== '' ? ' (' . $err . ')' : '') . '.';
            error_log('aria: pushover : ' . $GLOBALS['pushover_error']);
            return false;
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body, 'timeout' => 8, 'ignore_errors' => true,
        ]]);
        $resp = (string)@file_get_contents(PUSHOVER_URL, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/ (\d{3})/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        if ($code === 0) {
            $GLOBALS['pushover_error'] = 'Connexion à Pushover impossible.';
            error_log('aria: pushover : ' . $GLOBALS['pushover_error']);
            return false;
        }
    }
    $json = json_decode($resp, true);
    if ($code === 200 && ($json['status'] ?? 0) === 1) {
        return true;
    }
    $reasons = is_array($json['errors'] ?? null) ? implode(' ; ', $json['errors']) : '';
    $GLOBALS['pushover_error'] = 'Refusé par Pushover (HTTP ' . $code . ')' . ($reasons !== '' ? ' : ' . $reasons : '') . '.';
    error_log('aria: pushover : ' . $GLOBALS['pushover_error']);
    return false;
}

/** Première utilisation : importe $SEED_USERS et crée le compte admin par défaut. */
function ensure_users_file(): void
{
    global $SEED_USERS;
    if (is_file(USERS_FILE) || !is_dir(DATA_DIR)) {
        return;
    }
    $seed = $SEED_USERS;
    users_update(function (array &$d) use ($seed) {
        if ($d['users']) {
            return;
        }
        $maxId = 0;
        foreach ($seed as $s) {
            $d['users'][] = [
                'id' => $s['id'], 'name' => $s['name'], 'password_hash' => '',
                'admin' => false, 'must_change' => false, 'on_scale' => true,
                'initial_weight_g' => $s['initial_weight_g'], 'birth_year' => $s['birth_year'],
                'gender' => $s['gender'], 'height_mm' => $s['height_mm'],
            ];
            $maxId = max($maxId, $s['id']);
            // Ancien nommage user_<id>_<nom>.csv -> user_<id>.csv
            $new = sprintf('%s/user_%d.csv', DATA_DIR, $s['id']);
            if (!is_file($new)) {
                foreach (glob(sprintf('%s/user_%d_*.csv', DATA_DIR, $s['id'])) ?: [] as $old) {
                    rename($old, $new);
                    break;
                }
            }
        }
        $d['users'][] = [
            'id' => $maxId + 1, 'name' => DEFAULT_ADMIN_NAME,
            'password_hash' => password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
            'admin' => true, 'must_change' => true, 'on_scale' => false,
            'initial_weight_g' => 0, 'birth_year' => 0, 'gender' => 0x02, 'height_mm' => 0,
        ];
        $d['next_id'] = $maxId + 2;
    });
}

/** Crée le dossier de données et le protège (Apache 2.4) s'il est sous la racine web. */
function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0750, true);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (is_dir(DATA_DIR) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
}
ensure_data_dir();
ensure_users_file();

// Utilisateurs transmis à la balance (aria.php) : ceux marqués « on_scale »
$USERS = array_values(array_filter(load_users(), fn($u) => !empty($u['on_scale'])));
