<?php
declare(strict_types=1);

/**
 * Point d'accès RÉSERVÉ À LA BALANCE Fitbit Aria (protocole 3, firmware V35).
 * Endpoints : GET /scale/register  et  POST /scale/upload
 * La consultation des pesées par les humains est dans pesees.php.
 * Configuration et fonctions communes : lib.php
 */

require_once __DIR__ . '/lib.php';


/* ---------- Journal des échanges (balance <-> serveur) ---------- */

const EXCHANGE_LOG = DATA_DIR . '/exchanges.log';

$rawRequest = file_get_contents('php://input');   // corps lu une seule fois
$GLOBALS['aria_response'] = '';                   // corps de la réponse envoyée
$GLOBALS['aria_decoded']  = [];                   // version lisible (en-tête, pesées)

// S'exécute en fin de script, même après un exit (400, 500, etc.)
// On ne journalise que le trafic de la balance (pas les robots qui frappent l'URL)
if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/scale/')) register_shutdown_function(function () use ($rawRequest) {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0750, true);
    }
    $entry = [
        'time'            => date('c'),
        'ip'              => $_SERVER['REMOTE_ADDR'] ?? '',
        'method'          => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri'             => $_SERVER['REQUEST_URI'] ?? '',
        'request_headers' => function_exists('getallheaders') ? getallheaders() : [],
        'request_len'     => strlen($rawRequest),
        'request_hex'     => bin2hex($rawRequest),
        'status'          => http_response_code() ?: 200,
        'response_len'    => strlen($GLOBALS['aria_response']),
        'response_hex'    => bin2hex($GLOBALS['aria_response']),
        'decoded'         => $GLOBALS['aria_decoded'],
    ];
    $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                                 | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    @file_put_contents(EXCHANGE_LOG, $json . "\n", FILE_APPEND | LOCK_EX);
});

/* ---------- Routage ---------- */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (str_ends_with($path, '/scale/register')) {
    $serial = strtolower((string)($_GET['serialNumber'] ?? ''));
    if (ALLOWED_MACS && !in_array($serial, ALLOWED_MACS, true)) {
        http_response_code(403);
        exit;
    }
    http_response_code(200);          // réponse vide attendue
    exit;
}

if (!str_ends_with($path, '/scale/upload') || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit;
}

/* ---------- Réception des pesées ---------- */

$raw = $rawRequest;
if (strlen($raw) < 48) {
    http_response_code(400);
    exit;
}
$body = substr($raw, 0, -2);
if (unpack('v', substr($raw, -2))[1] !== crc16_xmodem($body)) {
    http_response_code(400);
    exit;
}

$h = unpack('Vproto/Vbattery/H12mac/a16auth/Vfw/Vunk/Vts/Vcount', $body);
$GLOBALS['aria_decoded']['header'] = ['auth' => bin2hex($h['auth'])] + $h;
$GLOBALS['aria_decoded']['measurements'] = [];
if ($h['proto'] !== 3) {
    error_log("aria: protocole {$h['proto']} non géré");
    http_response_code(400);
    exit;
}
if (ALLOWED_MACS && !in_array(strtolower($h['mac']), ALLOWED_MACS, true)) {
    error_log("aria: balance non autorisée {$h['mac']}");
    http_response_code(403);
    exit;
}

// Correction d'horloge : on ne corrige que si l'écart est significatif
$offset = time() - $h['ts'];
if (abs($offset) < 120) {
    $offset = 0;
}

$usersById = array_column($USERS, null, 'id');

// Poids de référence AVANT ces nouvelles pesées (sert à déduire l'utilisateur)
$refs = [];
foreach ($USERS as $u) {
    $refs[$u['id']] = reference_weight($u);
}
$perFile = [];   // chemin CSV => lignes
$known = all_known_keys();   // pesées déjà enregistrées (chez n'importe quel propriétaire)

for ($i = 0; $i < $h['count']; $i++) {
    $m = unpack(
        'Vid2/Vimpedance/Vweight_g/Vtimestamp/Vuser_id/Vfat1/Vcovariance/Vfat2',
        substr($body, 46 + 32 * $i, 32)
    );
    $realTs = $m['timestamp'] + $offset;

    // Une pesée renvoyée par la balance mais déjà enregistrée (par exemple déplacée vers
    // un autre propriétaire) n'est pas recréée chez le propriétaire d'origine.
    $key = $m['timestamp'] . '_' . $m['weight_g'];
    if (isset($known[$key])) {
        $GLOBALS['aria_decoded']['measurements'][] = $m + ['assigned_to' => null, 'how' => 'doublon'];
        continue;
    }
    $known[$key] = true;
    $user = $usersById[$m['user_id']] ?? null;
    $how = $user ? 'balance' : 'invité';
    if ($user === null) {
        // La balance n'a pas reconnu la personne : on déduit d'après le poids
        $user = guess_user($m['weight_g'], $USERS, $refs);
        if ($user !== null) {
            $how = 'déduit';
        }
    }
    error_log(sprintf('aria: pesée user_id=%d poids=%d g -> %s (%s)',
        $m['user_id'], $m['weight_g'], $user['name'] ?? 'guests', $how));
    $GLOBALS['aria_decoded']['measurements'][] = $m + [
        'assigned_to' => $user['name'] ?? 'guests',
        'how'         => $how,
        'real_ts'     => $realTs,
        'fat1_pct'    => $m['fat1'] / 1000, // fat1 est en millièmes de pourcent (27921 = 27,921 %)
    ];

    $perFile[csv_path($user)][] = [
        'timestamp'      => $realTs,
        'datetime'       => date('Y-m-d H:i:s', $realTs),
        'aria_timestamp' => $m['timestamp'],
        'weight_g'       => $m['weight_g'],
        'weight_kg'      => number_format($m['weight_g'] / 1000, 2, '.', ''),
        'impedance'      => $m['impedance'],
        'fat1'           => $m['fat1'],
        'covariance'     => $m['covariance'],
        'fat2'           => $m['fat2'],
    ];
}

if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) {
    error_log('aria: impossible de créer ' . DATA_DIR);
    http_response_code(500);
    exit;
}

// Verrou global pour éviter les écritures concurrentes
$lock = fopen(DATA_DIR . '/.lock', 'c');
flock($lock, LOCK_EX);

try {
    foreach ($perFile as $file => $rows) {
        usort($rows, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        append_rows($file, $rows);
    }
} catch (Throwable $e) {
    // 500 => la balance conservera ses mesures en cache et réessaiera
    error_log('aria: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

/* ---------- Réponse : utilisateurs + dernières valeurs connues ---------- */

$resp  = pack('V', time());
$resp .= pack('CCC', 2, 0x32, 0x01);       // kg, configurée, unknown1
$resp .= pack('V', count($USERS));

$sentRefs = [];

foreach ($USERS as $u) {
    $last = last_known($u);   // relu après l'enregistrement des nouvelles pesées
    $ref  = $last ? intdiv((int)$last['weight_g'], 1000) * 1000   // kg inférieur, comme helvetic
                  : (int)$u['initial_weight_g'];

    // Bornes absolues de reconnaissance (grammes)
    $u['min'] = max(0, $ref - WEIGHT_DELTA_G);
    $u['max'] = $ref + WEIGHT_DELTA_G;

    if (SEND_PREVIOUS_VALUES && $last) {
        $u['weight1'] = $u['weight2'] = (int)$last['weight_g'];
        $u['body_fat']   = (int)$last['fat1'];
        $u['covariance'] = (int)$last['covariance'];
        $u['last_ts']    = (int)$last['timestamp'];
    }
    $sentRefs[$u['name']] = $ref;
    $resp .= build_user($u);
}

// Avertir si deux utilisateurs sont trop proches pour être distingués
$names = array_keys($sentRefs);
for ($i = 0; $i < count($names); $i++) {
    for ($j = $i + 1; $j < count($names); $j++) {
        if (abs($sentRefs[$names[$i]] - $sentRefs[$names[$j]]) <= 2 * WEIGHT_DELTA_G) {
            error_log("aria: poids trop proches entre {$names[$i]} et {$names[$j]}");
        }
    }
}

$resp .= pack('VVV', 0, 3, 0);             // toujours 0 / pas de mise à jour (3) / inconnu
// CRC sur le corps, puis taille du message (corps + CRC) = 0x19 + 0x4d × nb d'utilisateurs
$resp .= pack('vv', crc16_xmodem($resp), strlen($resp) + 2);

flock($lock, LOCK_UN);
fclose($lock);

$GLOBALS['aria_response'] = $resp;
$GLOBALS['aria_decoded']['users_sent'] = $sentRefs;

header('Content-Type: application/octet-stream');
header('Content-Length: ' . strlen($resp));
echo $resp;
