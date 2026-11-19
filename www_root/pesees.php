<?php
declare(strict_types=1);

/**
 * Page des pesées.
 *  - Sans connexion : pesées des invités (accès libre).
 *  - Connecté : ses propres pesées ; les « autres pesées » (invités, et pour un admin
 *    les autres utilisateurs) sont masquées derrière un bouton.
 *  - Utilisateur : peut basculer une de ses pesées vers les invités, ou s'attribuer une pesée d'invité.
 *  - Administrateur : peut changer le propriétaire de n'importe quelle pesée, ou la supprimer.
 *  - Graphique : poids, masse grasse ou les deux (choix mémorisé pour la session).
 * Connexion/déconnexion et mise en page : webcore.php
 */

require_once __DIR__ . '/webcore.php';

const ROWS_SHOWN   = 20;
const CHART_POINTS = 60;
const GRAPH_CHOICES = ['w' => 'Poids', 'f' => 'Masse grasse', 'b' => 'Les deux'];

/* ---------- Affichage ---------- */

/** Lignes d'un CSV, de la plus récente à la plus ancienne. */
function rows_desc(string $path): array
{
    $rows = read_rows($path);
    usort($rows, fn($a, $b) => (int)$b['timestamp'] <=> (int)$a['timestamp']);
    return $rows;
}

function kg(float $v): string
{
    return number_format($v, 1, ',', ' ');
}

/**
 * Courbes SVG. $series : liste (1 ou 2) de
 *   ['k' => 'w'|'f', 'unit' => 'kg'|'%', 'points' => [[timestamp, valeur], ...]]
 * Avec deux séries, chacune a son axe (poids à gauche, masse grasse à droite).
 */
function chart_svg(array $series): string
{
    $W = 480; $H = 230; $top = 22; $bottom = 36;
    $two = count($series) === 2;
    $padL = 66;
    $padR = $two ? 66 : 16;

    $ts = [];
    foreach ($series as $s) {
        foreach ($s['points'] as $p) {
            $ts[] = $p[0];
        }
    }
    $minT = min($ts);
    $maxT = max($ts);
    $spanT = max(1, $maxT - $minT);

    $out = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Évolution dans le temps">'
         . '<line class="grid" x1="' . $padL . '" y1="' . ($H - $bottom) . '" x2="' . ($W - $padR) . '" y2="' . ($H - $bottom) . '"/>'
         . '<line class="grid" x1="' . $padL . '" y1="' . $top . '" x2="' . ($W - $padR) . '" y2="' . $top . '"/>';

    foreach ($series as $i => $s) {
        $vals = array_column($s['points'], 1);
        $minV = min($vals);
        $maxV = max($vals);
        if ($maxV - $minV < 1.0) {
            $minV -= 0.5;
            $maxV += 0.5;
        }
        $coords = [];
        $dots = '';
        foreach ($s['points'] as [$t, $v]) {
            $x = round($padL + ($t - $minT) / $spanT * ($W - $padL - $padR), 1);
            $y = round($H - $bottom - ($v - $minV) / ($maxV - $minV) * ($H - $top - $bottom), 1);
            $coords[] = $x . ',' . $y;
            if (count($s['points']) <= 40) {
                $dots .= '<circle class="dt-' . $s['k'] . '" cx="' . $x . '" cy="' . $y . '" r="3"/>';
            }
        }
        $right = $two && $i === 1;
        $lx = $right ? $W - 4 : 4;
        $anchor = $right ? 'end' : 'start';
        $out .= '<polyline class="ln-' . $s['k'] . '" points="' . implode(' ', $coords) . '"/>' . $dots
              . '<text class="axis tx-' . $s['k'] . '" x="' . $lx . '" y="' . ($top + 5) . '" text-anchor="' . $anchor . '">'
              . h(kg($maxV) . ' ' . $s['unit']) . '</text>'
              . '<text class="axis tx-' . $s['k'] . '" x="' . $lx . '" y="' . ($H - $bottom + 5) . '" text-anchor="' . $anchor . '">'
              . h(kg($minV) . ' ' . $s['unit']) . '</text>';
    }
    $out .= '<text class="axis" x="' . $padL . '" y="' . ($H - 8) . '">' . h(date('d/m/Y', $minT)) . '</text>'
          . '<text class="axis" x="' . ($W - $padR) . '" y="' . ($H - 8) . '" text-anchor="end">' . h(date('d/m/Y', $maxT)) . '</text>'
          . '</svg>';
    return $out;
}

/** Graphique d'un bloc selon le choix (w, f ou b), avec légende si deux courbes. */
function chart_block(array $rows, string $graph): string
{
    $w = [];
    $f = [];
    foreach (array_reverse(array_slice($rows, 0, CHART_POINTS)) as $r) {
        $t = (int)$r['timestamp'];
        $w[] = [$t, (int)$r['weight_g'] / 1000];
        if ((int)$r['fat1'] > 0) {
            $f[] = [$t, (int)$r['fat1'] / 1000];     // millièmes de pourcent -> %
        }
    }
    $note = fn(string $m): string => '<p class="muted chart-note">' . h($m) . '</p>';
    $noWeight = 'Pas assez de pesées pour tracer une courbe.';
    $noFat    = 'Pas assez de mesures de masse grasse pour tracer une courbe.';

    if ($graph === 'w') {
        return count($w) < 2 ? $note($noWeight) : chart_svg([['k' => 'w', 'unit' => 'kg', 'points' => $w]]);
    }
    if ($graph === 'f') {
        return count($f) < 2 ? $note($noFat) : chart_svg([['k' => 'f', 'unit' => '%', 'points' => $f]]);
    }

    // « Les deux » : deux courbes si possible, sinon celle qui peut l'être, avec la raison
    if (count($w) < 2) {
        return $note($noWeight);
    }
    if (count($f) < 2) {
        return chart_svg([['k' => 'w', 'unit' => 'kg', 'points' => $w]]) . $note($noFat);
    }
    return '<p class="legend"><span><i class="key kw"></i>Poids (axe gauche)</span>'
         . '<span><i class="key kf"></i>Masse grasse (axe droit)</span></p>'
         . chart_svg([
             ['k' => 'w', 'unit' => 'kg', 'points' => $w],
             ['k' => 'f', 'unit' => '%', 'points' => $f],
         ]);
}

/**
 * Formulaire(s) d'action sur une pesée.
 * $owner : propriétaire actuel (null = invités) ; $mode : 'admin' (déplacer / supprimer),
 * 'user' (basculer vers les invités) ou 'claim' (s'attribuer une pesée d'invité) ; $others : bloc affiché dans « Autres pesées ».
 */
function move_form(array $r, ?array $owner, string $mode, array $users, bool $others): string
{
    $srcId = (int)($owner['id'] ?? 0);
    $common = csrf_field()
            . '<input type="hidden" name="src" value="' . $srcId . '">'
            . '<input type="hidden" name="key" value="' . h(measurement_key($r)) . '">'
            . ($others ? '<input type="hidden" name="autres" value="1">' : '');

    $f = '<form method="post" class="inline-form">' . $common
       . '<input type="hidden" name="action" value="move_measurement">';
    if ($mode === 'admin') {
        $f .= '<select name="dst" aria-label="Nouveau propriétaire">';
        if ($srcId !== 0) {
            $f .= '<option value="0">Invité</option>';
        }
        foreach ($users as $u) {
            if ($u['id'] !== $srcId) {
                $f .= '<option value="' . (int)$u['id'] . '">' . h($u['name']) . '</option>';
            }
        }
        $f .= '</select><button type="submit" class="secondary">Déplacer</button></form>';
        return '<div class="actions">' . $f
             . '<form method="post" class="inline-form" '
             . 'onsubmit="return confirm(\'Supprimer définitivement cette pesée ?\');">' . $common
             . '<input type="hidden" name="action" value="delete_measurement">'
             . '<button type="submit" class="danger icon" aria-label="Supprimer la pesée" title="Supprimer">'
             . '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
             . '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>'
             . '</button></form></div>';
    }
    if ($mode === 'claim') {
        $me = current_user();
        return '<div class="actions">' . $f . '<input type="hidden" name="dst" value="' . (int)($me['id'] ?? 0) . '">'
             . '<button type="submit" class="secondary">M\'attribuer</button></form></div>';
    }
    return '<div class="actions">' . $f . '<input type="hidden" name="dst" value="0">'
         . '<button type="submit" class="secondary" '
         . 'onclick="return confirm(\'Basculer cette pesée vers les invités ?\');">Basculer vers invité</button></form></div>';
}

/**
 * Bloc d'un propriétaire. Options : note, owner (null = invités), mode ('none'|'user'|'admin'),
 * users (cibles possibles pour l'admin), others (bloc des « autres pesées »), graph (w|f|b).
 */
function render_block(string $title, array $rows, array $o = []): void
{
    $owner  = $o['owner'] ?? null;
    $mode   = $o['mode'] ?? 'none';
    $users  = $o['users'] ?? [];
    $others = !empty($o['others']);
    $graph  = $o['graph'] ?? 'b';

    echo '<section class="card"><h2>' . h($title) . '</h2>';
    if (!empty($o['note'])) {
        echo '<p class="muted">' . h($o['note']) . '</p>';
    }
    if (!$rows) {
        echo '<p class="muted">Aucune pesée enregistrée.</p></section>';
        return;
    }

    $last = $rows[0];
    $prev = $rows[1] ?? null;
    $lastKg = (int)$last['weight_g'] / 1000;
    echo '<p class="big">' . h(kg($lastKg)) . ' kg';
    if ($prev) {
        $d = $lastKg - (int)$prev['weight_g'] / 1000;
        echo ' <span class="delta">(' . ($d >= 0 ? '+' : '−') . h(kg(abs($d))) . ' kg)</span>';
    }
    echo '</p><p class="muted">Dernière pesée : ' . h(date('d/m/Y H:i', (int)$last['timestamp'])) . '</p>';

    echo chart_block($rows, $graph);

    $shown = array_slice($rows, 0, ROWS_SHOWN);
    $hasFat = false;
    foreach ($shown as $r) {
        if ((int)$r['fat1'] > 0) {
            $hasFat = true;
        }
    }
    echo '<div class="table-wrap"><table class="wtable"><thead><tr><th>Date</th><th>Poids</th>'
       . ($hasFat ? '<th>Masse grasse</th>' : '')
       . ($mode !== 'none' ? '<th class="act">Actions</th>' : '') . '</tr></thead><tbody>';
    foreach ($shown as $r) {
        echo '<tr><td class="date">' . h(date('d/m/Y H:i', (int)$r['timestamp'])) . '</td>'
           . '<td class="w">' . h(kg((int)$r['weight_g'] / 1000)) . ' kg</td>';
        if ($hasFat) {
            $fat = (int)$r['fat1'];
            // fat1 est en millièmes de pourcent : 27921 -> 27,92 %
            echo '<td class="fat">' . ($fat > 0 ? h(number_format($fat / 1000, 2, ',', ' ')) . ' %' : '–') . '</td>';
        }
        if ($mode !== 'none') {
            echo '<td class="act">' . move_form($r, $owner, $mode, $users, $others) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';
}

/** Sélecteur du contenu des graphiques (liens : fonctionne sans JavaScript). */
function graph_selector(string $graph, bool $others): string
{
    $out = '<div class="seg" role="group" aria-label="Contenu des graphiques">';
    foreach (GRAPH_CHOICES as $k => $label) {
        $q = 'g=' . $k . ($others ? '&autres=1' : '');
        $out .= '<a href="pesees.php?' . h($q) . '"' . ($k === $graph ? ' class="on" aria-current="true"' : '') . '>'
              . h($label) . '</a>';
    }
    return $out . '</div>';
}

/** Après une action, on revient sur la page (avec les « autres pesées » ouvertes si besoin). */
function back_url(): string
{
    return 'pesees.php' . (!empty($_POST['autres']) ? '?autres=1' : '');
}

/* ---------- Déplacement / suppression d'une pesée ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'move_measurement') {
    check_csrf();
    $me = require_login();
    enforce_password_change($me);
    $srcId = (int)($_POST['src'] ?? 0);   // 0 = invités
    $dstId = (int)($_POST['dst'] ?? 0);
    // Un utilisateur ne peut que : basculer l'une de SES pesées vers les invités,
    // ou s'attribuer à LUI une pesée d'invité. Rien d'autre.
    $release = $srcId === $me['id'] && $dstId === 0;
    $claim   = $srcId === 0 && $dstId === $me['id'];
    if (empty($me['admin']) && !$release && !$claim) {
        http_response_code(403);
        exit('Action non autorisée.');
    }
    try {
        $from = $srcId === 0 ? null : find_user_by_id($srcId);
        $to   = $dstId === 0 ? null : find_user_by_id($dstId);
        if (($srcId !== 0 && $from === null) || ($dstId !== 0 && $to === null)) {
            throw new InvalidArgumentException('Propriétaire inconnu.');
        }
        move_measurement((string)($_POST['key'] ?? ''), $from, $to);
        flash('Pesée déplacée vers ' . ($to['name'] ?? 'les invités') . '.');
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect(back_url());
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_measurement') {
    check_csrf();
    $me = require_login();
    enforce_password_change($me);
    if (empty($me['admin'])) {
        http_response_code(403);
        exit('Action réservée aux administrateurs.');
    }
    try {
        $srcId = (int)($_POST['src'] ?? 0);   // 0 = invités
        $from = $srcId === 0 ? null : find_user_by_id($srcId);
        if ($srcId !== 0 && $from === null) {
            throw new InvalidArgumentException('Propriétaire inconnu.');
        }
        delete_measurement((string)($_POST['key'] ?? ''), $from);
        flash('Pesée supprimée.');
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect(back_url());
}

/* ---------- Page ---------- */

$current = current_user();
enforce_password_change($current);

// Choix du graphique : mémorisé pour la session
$graph = $_SESSION['graph'] ?? 'b';
if (isset($_GET['g']) && isset(GRAPH_CHOICES[(string)$_GET['g']])) {
    $graph = (string)$_GET['g'];
    $_SESSION['graph'] = $graph;
}
if (!isset(GRAPH_CHOICES[$graph])) {
    $graph = 'b';
}
$showOthers = !empty($_GET['autres']);

page_head('Pesées', $current);

if (!$current) {
    ?>
<section class="card">
  <h2>Connexion</h2>
  <p class="muted">Connectez-vous pour voir vos pesées. Les pesées des invités sont visibles ci-dessous sans connexion.</p>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="login">
    <input name="username" placeholder="Identifiant" autocomplete="username" required>
    <input name="password" type="password" placeholder="Mot de passe" autocomplete="current-password" required>
    <button type="submit">Se connecter</button>
  </form>
</section>
<?php
    render_block('Invités', rows_desc(csv_path(null)), [
        'note' => 'Accès libre : pesées non attribuées à un utilisateur.',
    ]);
    page_foot();
    exit;
}

$isAdmin  = !empty($current['admin']);
$allUsers = $isAdmin ? load_users() : [];
$mode     = $isAdmin ? 'admin' : 'user';

echo graph_selector($graph, $showOthers);

// Mes pesées. Un admin sans pesée à son nom n'a pas de bloc vide.
if (!$isAdmin || !empty($current['on_scale']) || is_file(csv_path($current))) {
    render_block($current['name'], rows_desc(csv_path($current)), [
        'owner' => $current, 'mode' => $mode, 'users' => $allUsers, 'graph' => $graph,
    ]);
} else {
    echo '<p class="muted">Aucune pesée à votre nom.</p>';
}

// Autres pesées : masquées par défaut
if (!$showOthers) {
    echo '<p><a class="btn" href="pesees.php?autres=1">Autres pesées</a></p>';
} else {
    echo '<p><a class="btn" href="pesees.php">Masquer les autres pesées</a></p>';
    echo '<h2 class="section">Autres pesées</h2>';
    if ($isAdmin) {
        foreach ($allUsers as $u) {
            if ($u['id'] !== $current['id'] && (!empty($u['on_scale']) || is_file(csv_path($u)))) {
                render_block($u['name'], rows_desc(csv_path($u)), [
                    'owner' => $u, 'mode' => 'admin', 'users' => $allUsers, 'others' => true, 'graph' => $graph,
                ]);
            }
        }
    }
    render_block('Invités', rows_desc(csv_path(null)), [
        'note'   => 'Pesées non attribuées à un utilisateur.',
        'mode'   => $isAdmin ? 'admin' : 'claim',
        'users'  => $allUsers,
        'others' => true,
        'graph'  => $graph,
    ]);
}

page_foot();
