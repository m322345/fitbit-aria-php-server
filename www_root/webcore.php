<?php
declare(strict_types=1);

/**
 * Socle commun des pages web (pesees.php, compte.php, admin.php) :
 * session, CSRF, connexion/déconnexion, verrouillage anti-force-brute, mise en page.
 */

require_once __DIR__ . '/lib.php';

const DUMMY_HASH = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

session_name('aria_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ---------- Utilitaires ---------- */

function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function client_ip(): string
{
    // Derrière un reverse proxy, REMOTE_ADDR est l'IP du proxy : adaptez si besoin.
    return $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function check_csrf(): void
{
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Requête invalide.');
    }
}

/** Mot de passe provisoire lisible (sans caractères ambigus). */
function random_password(int $len = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function password_error(string $pwd, ?string $name = null): ?string
{
    if (strlen($pwd) < MIN_PASSWORD_LENGTH) {
        return 'Le mot de passe doit faire au moins ' . MIN_PASSWORD_LENGTH . ' caractères.';
    }
    if ($pwd === DEFAULT_ADMIN_PASSWORD) {
        return 'Ce mot de passe par défaut n\'est pas autorisé.';
    }
    if ($name !== null && $name !== '' && stripos($pwd, $name) !== false) {
        return 'Le mot de passe ne doit pas contenir l\'identifiant.';
    }
    return null;
}

/* ---------- Verrouillage après échecs de connexion ---------- */

function load_attempts(): array
{
    $f = DATA_DIR . '/login_attempts.json';
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    return is_array($d) ? $d : [];
}

function save_attempts(array $a): void
{
    @file_put_contents(DATA_DIR . '/login_attempts.json', json_encode($a), LOCK_EX);
}

function is_locked(string $ip): bool
{
    $a = load_attempts()[$ip] ?? null;
    return $a && $a['count'] >= MAX_LOGIN_FAILURES && time() - $a['last'] < LOCKOUT_SECONDS;
}

function register_failure(string $ip): void
{
    $all = load_attempts();
    $a = $all[$ip] ?? ['count' => 0, 'last' => 0];
    if (time() - $a['last'] >= LOCKOUT_SECONDS) {
        $a['count'] = 0;
    }
    $a['count']++;
    $a['last'] = time();
    $all[$ip] = $a;
    foreach ($all as $k => $v) {          // purge des entrées expirées
        if (time() - $v['last'] > LOCKOUT_SECONDS) {
            unset($all[$k]);
        }
    }
    save_attempts($all);
}

function clear_failures(string $ip): void
{
    $all = load_attempts();
    unset($all[$ip]);
    save_attempts($all);
}

/* ---------- Session et droits ---------- */

/** Empreinte du hash : un changement de mot de passe invalide les sessions ouvertes. */
function pwd_fingerprint(string $hash): string
{
    return substr(hash('sha256', $hash), 0, 16);
}

function login_session(array $u): void
{
    $_SESSION['uid'] = $u['id'];
    $_SESSION['ph']  = pwd_fingerprint((string)$u['password_hash']);
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = isset($_SESSION['uid']) ? find_user_by_id((int)$_SESSION['uid']) : null;
        if ($user !== null && !hash_equals((string)($_SESSION['ph'] ?? ''), pwd_fingerprint((string)$user['password_hash']))) {
            $user = null;   // mot de passe changé ailleurs : session invalide
            flash('Votre session a expiré, reconnectez-vous.', 'err');
        }
        if ($user === null) {
            unset($_SESSION['uid'], $_SESSION['ph']);
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        flash('Veuillez vous connecter.', 'err');
        redirect('pesees.php');
    }
    return $u;
}

/** Tant que le mot de passe provisoire n'est pas changé, seule compte.php est accessible. */
function enforce_password_change(?array $u): void
{
    if ($u && !empty($u['must_change'])) {
        redirect('compte.php');
    }
}

function require_admin(): array
{
    $u = require_login();
    enforce_password_change($u);
    if (empty($u['admin'])) {
        http_response_code(403);
        exit('Accès réservé aux administrateurs.');
    }
    return $u;
}

/** Connexion / déconnexion : traitées pour toutes les pages. */
function handle_auth_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'login' && $action !== 'logout') {
        return;
    }
    check_csrf();

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        flash('Vous êtes déconnecté.');
        redirect('pesees.php');
    }

    $ip = client_ip();
    if (is_locked($ip)) {
        flash('Trop de tentatives. Réessayez dans quelques minutes.', 'err');
        redirect('pesees.php');
    }
    $u = find_user_by_name(trim((string)($_POST['username'] ?? '')));
    $stored = (string)($u['password_hash'] ?? '');
    // password_verify est toujours exécuté, pour ne pas révéler si l'identifiant existe
    $ok = password_verify((string)($_POST['password'] ?? ''), $stored !== '' ? $stored : DUMMY_HASH)
          && $u !== null && $stored !== '';
    if (!$ok) {
        register_failure($ip);
        flash('Identifiant ou mot de passe incorrect.', 'err');
        redirect('pesees.php');
    }
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    login_session($u);
    clear_failures($ip);
    redirect('pesees.php');
}

/* ---------- Mise en page ---------- */

function page_head(string $title, ?array $user = null): void
{
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $nav = ['pesees.php' => 'Pesées'];
    if ($user && empty($user['must_change'])) {
        $nav['compte.php'] = 'Mon compte';
        if (!empty($user['admin'])) {
            $nav['admin.php'] = 'Administration';
        }
    }
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E %3Cstyle%3E :root %7B --accent:%232563eb%3B --card:%23fff%3B --accent2:%23c2410c%3B %7D %40media (prefers-color-scheme: dark) %7B :root %7B --accent:%237aa7ff%3B --card:%2312161c%3B --accent2:%23fbbf24%3B %7D %7D %3C/style%3E %3Crect width=%2264%22 height=%2264%22 rx=%2214%22 fill=%22var(--accent)%22/%3E %3Crect x=%229%22 y=%2212%22 width=%2246%22 height=%2240%22 rx=%229%22 fill=%22var(--card)%22/%3E %3Cpath d=%22M20 31a12 12 0 0 1 24 0%22 fill=%22none%22 stroke=%22var(--accent)%22 stroke-width=%224%22 stroke-linecap=%22round%22/%3E %3Cpath d=%22M32 31l5-8%22 stroke=%22var(--accent2)%22 stroke-width=%223.5%22 stroke-linecap=%22round%22/%3E %3Ccircle cx=%2232%22 cy=%2231%22 r=%223%22 fill=%22var(--accent2)%22/%3E %3Crect x=%2220%22 y=%2240%22 width=%229%22 height=%226%22 rx=%223%22 fill=%22var(--accent)%22 opacity=%22.35%22/%3E %3Crect x=%2235%22 y=%2240%22 width=%229%22 height=%226%22 rx=%223%22 fill=%22var(--accent)%22 opacity=%22.35%22/%3E %3C/svg%3E">
<style>
:root { --bg:#f6f7f9; --card:#fff; --text:#1c2430; --muted:#667085; --line:#e4e7ec; --accent:#2563eb; --err:#b42318; --ok:#067647; --accent2:#c2410c; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#12161c; --card:#1b212a; --text:#e6e9ee; --muted:#9aa4b2; --line:#2b3340; --accent:#7aa7ff; --err:#ff8a80; --ok:#6ce9a6; --accent2:#fbbf24; }
}
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 system-ui, sans-serif; }
main { max-width:760px; margin:0 auto; padding:16px; }
header { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:8px 16px; margin-bottom:8px; }
nav { display:flex; gap:14px; align-items:center; flex-wrap:wrap; }
nav a { color:var(--muted); text-decoration:none; padding:2px 0; }
nav a.current { color:var(--text); border-bottom:2px solid var(--accent); }
nav form { display:inline; }
h1 { font-size:1.4rem; margin:0; }
h2 { font-size:1.1rem; margin:0 0 4px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; margin:16px 0; }
.muted { color:var(--muted); margin:2px 0 8px; font-size:.9rem; }
.big { font-size:2rem; font-weight:600; margin:4px 0 0; }
.delta { font-size:1rem; font-weight:400; color:var(--muted); }
html { -webkit-text-size-adjust:100%; }
.chart { display:block; width:100%; max-width:560px; height:auto; margin:8px auto; }
.chart .grid { stroke:var(--line); stroke-width:1; }
.chart .axis { fill:var(--muted); font-size:14px; }
.chart .ln-w, .chart .ln-f { fill:none; stroke-width:2.5; stroke-linejoin:round; stroke-linecap:round; }
.chart .ln-w { stroke:var(--accent); }
.chart .ln-f { stroke:var(--accent2); stroke-dasharray:7 5; }
.chart .dt-w { fill:var(--accent); }
.chart .dt-f { fill:var(--accent2); }
.chart .axis.tx-w { fill:var(--accent); }
.chart .axis.tx-f { fill:var(--accent2); }
.legend { display:flex; flex-wrap:wrap; justify-content:center; gap:4px 16px; margin:8px 0 0; font-size:.85rem; color:var(--muted); }
.key { display:inline-block; width:22px; height:0; border-top:3px solid; margin-right:6px; vertical-align:middle; }
.key.kw { border-color:var(--accent); }
.key.kf { border-color:var(--accent2); border-top-style:dashed; }
.seg { display:flex; border:1px solid var(--line); border-radius:10px; overflow:hidden; margin:12px 0; background:var(--card); }
.seg a { flex:1; text-align:center; padding:8px 6px; color:var(--muted); text-decoration:none; font-size:.95rem; }
.seg a + a { border-left:1px solid var(--line); }
.seg a.on { background:var(--accent); color:#fff; }
.btn { display:inline-block; padding:9px 14px; border-radius:8px; background:var(--line); color:var(--text); text-decoration:none; }
h2.section { margin:20px 0 0; }
.table-wrap { overflow-x:auto; }
td.w { white-space:nowrap; }
th.act, td.act { text-align:right; }
table { width:100%; border-collapse:collapse; margin-top:8px; }
th, td { text-align:left; padding:6px 4px; border-bottom:1px solid var(--line); }
th { color:var(--muted); font-weight:500; font-size:.85rem; }
form.stack { display:grid; gap:10px; max-width:420px; }
label { display:grid; gap:4px; font-size:.9rem; color:var(--muted); }
label.inline { display:flex; gap:8px; align-items:center; color:var(--text); }
input, select { padding:9px 10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--text); font:inherit; }
input[type=checkbox] { padding:0; width:auto; }
button { padding:9px 14px; border:0; border-radius:8px; background:var(--accent); color:#fff; font:inherit; cursor:pointer; }
button.link { background:none; color:var(--accent); padding:2px 4px; }
button.danger { background:var(--err); color:#fff; }
button.secondary { background:var(--line); color:var(--text); }
.row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:8px; }
.msg { padding:10px 12px; border-radius:8px; border:1px solid var(--line); background:var(--card); margin:12px 0; overflow-wrap:anywhere; }
.msg.ok { border-color:var(--ok); }
.msg.err { border-color:var(--err); color:var(--err); }
.badge { display:inline-block; font-size:.75rem; padding:1px 8px; border-radius:999px; border:1px solid var(--line); color:var(--muted); margin-left:4px; }
.actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }
.inline-form { display:flex; gap:6px; align-items:center; margin:0; }
td select, td button { padding:3px 8px; font-size:.85rem; }
button.icon { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; padding:0; }
details { margin-top:8px; }
summary { cursor:pointer; color:var(--accent); }

@media (max-width: 640px) {
  main { padding:10px; }
  .card { padding:12px; margin:12px 0; border-radius:10px; }
  .big { font-size:1.75rem; }
  button, select, .btn { min-height:44px; }
  .btn { display:flex; align-items:center; justify-content:center; }
  .seg a { padding:12px 4px; }
  form.stack { max-width:none; }
  /* Tableau des pesées : une carte par ligne, sans défilement horizontal */
  table.wtable thead { display:none; }
  table.wtable, table.wtable tbody { display:block; }
  table.wtable tr { display:grid; grid-template-columns:1fr auto; grid-template-areas:"date w" "fat fat" "act act";
                    gap:2px 12px; padding:10px 0; border-bottom:1px solid var(--line); }
  table.wtable td { display:block; border:0; padding:0; }
  table.wtable td.date { grid-area:date; color:var(--muted); font-size:.9rem; align-self:center; }
  table.wtable td.w { grid-area:w; font-weight:600; font-size:1.05rem; text-align:right; }
  table.wtable td.fat { grid-area:fat; font-size:.9rem; }
  table.wtable td.fat::before { content:'Masse grasse : '; color:var(--muted); }
  table.wtable td.act { grid-area:act; margin-top:8px; }
  td select, td button { min-height:44px; font-size:1rem; padding:8px 12px; }
  /* Déplacer + corbeille sur une seule ligne */
  .actions { flex-wrap:nowrap; align-items:center; width:100%; }
  .actions > .inline-form { width:auto; }
  .actions > .inline-form:first-child { flex:1; min-width:0; }
  .inline-form select { flex:1; min-width:0; }
  .inline-form button { flex:0 0 auto; }
  button.icon, td button.icon { width:44px; height:44px; padding:0; }
  /* Utilisateur : un seul bouton pleine largeur */
  .actions > .inline-form:only-child { width:100%; justify-content:flex-end; }
  .actions > .inline-form:only-child button { flex:0 0 auto; }
}
.banner { background:var(--accent); color:#fff; text-align:center; padding:14px 16px; }
.banner-title { font-size:1.9rem; font-weight:800; letter-spacing:.01em; }
@media (prefers-color-scheme: dark) { .banner { color:#12161c; } }
.chart-note { text-align:center; }
.inline-form select, .inline-form button, td button.icon, .actions button.icon { height:34px; min-height:0; padding-top:0; padding-bottom:0; line-height:1; }
.actions button.icon, td button.icon { width:34px; padding:0; }
@media (max-width:640px) {
  .inline-form select, .inline-form button, td button.icon, .actions button.icon { height:44px; }
  .actions button.icon, td button.icon { width:44px; }
}
</style>
</head>
<body>
<div class="banner"><span class="banner-title">Fitbit by Cédric</span></div>
<main>
<header>
  <h1><?= h($title) ?></h1>
  <nav>
    <?php foreach ($nav as $href => $label): ?>
      <a href="<?= h($href) ?>"<?= $href === $self ? ' class="current"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
    <?php if ($user): ?>
      <form method="post" action="pesees.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="logout">
        <?= h($user['name']) ?> · <button class="link" type="submit">Se déconnecter</button>
      </form>
    <?php endif; ?>
  </nav>
</header>
<?php
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<p class="msg ' . h($type) . '" role="status">' . h($msg) . '</p>';
    }
    unset($_SESSION['flash']);
}

function page_foot(): void
{
    echo "</main>\n</body>\n</html>\n";
}

handle_auth_post();
