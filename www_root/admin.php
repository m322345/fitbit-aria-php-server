<?php
declare(strict_types=1);

/**
 * Administration des utilisateurs (réservée aux administrateurs) :
 * création, modification, réinitialisation du mot de passe, suppression.
 */

require_once __DIR__ . '/webcore.php';

$me = require_admin();

/* ---------- Actions ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    check_csrf();
    try {
        switch ((string)($_POST['action'] ?? '')) {
            case 'create':
                $fields = parse_user_input($_POST, null, load_users());
                $pwd = trim((string)($_POST['password'] ?? ''));
                if ($pwd === '') {
                    $pwd = random_password();
                } elseif (($err = password_error($pwd, $fields['name'])) !== null) {
                    throw new InvalidArgumentException($err);
                }
                users_update(function (array &$d) use ($fields, $pwd) {
                    foreach ($d['users'] as $u) {        // revalidation sous verrou
                        if (strcasecmp($u['name'], $fields['name']) === 0) {
                            throw new InvalidArgumentException('Ce nom est déjà utilisé.');
                        }
                    }
                    $d['users'][] = $fields + [
                        'id'            => $d['next_id']++,
                        'password_hash' => password_hash($pwd, PASSWORD_DEFAULT),
                        'must_change'   => true,
                    ];
                });
                flash('Utilisateur « ' . $fields['name'] . ' » créé. Mot de passe provisoire : ' . $pwd
                    . ' (affiché une seule fois ; il devra être changé à la première connexion).');
                break;

            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $name = users_update(function (array &$d) use ($id) {
                    foreach ($d['users'] as &$u) {
                        if ($u['id'] === $id) {
                            $fields = parse_user_input($_POST, $id, $d['users']);
                            if (!empty($u['admin']) && !$fields['admin'] && count_admins($d['users']) <= 1) {
                                throw new InvalidArgumentException('Il doit rester au moins un administrateur.');
                            }
                            $u = $fields + $u;
                            return $u['name'];
                        }
                    }
                    throw new InvalidArgumentException('Utilisateur introuvable.');
                });
                flash('Utilisateur « ' . $name . ' » mis à jour.');
                break;

            case 'save_push':
                $token = trim((string)($_POST['app_token'] ?? ''));
                if ($token !== '' && !pushover_key_valid($token)) {
                    throw new InvalidArgumentException('Jeton d\'application invalide : 30 lettres et chiffres.');
                }
                $bkey = trim((string)($_POST['battery_user_key'] ?? ''));
                if ($bkey !== '' && !pushover_key_valid($bkey)) {
                    throw new InvalidArgumentException('Clé du destinataire de l\'alerte batterie invalide : 30 lettres et chiffres.');
                }
                $uiUrl = trim((string)($_POST['ui_url'] ?? ''));
                if ($uiUrl !== '' && !ui_url_valid($uiUrl)) {
                    throw new InvalidArgumentException('URL de l\'interface invalide : adresse complète en http:// ou https:// (512 caractères max.).');
                }
                $thr = (int)($_POST['battery_threshold'] ?? 20);
                if ($thr < 1 || $thr > 99) {
                    throw new InvalidArgumentException('Seuil de batterie : entre 1 et 99 %.');
                }
                $clear  = !empty($_POST['clear_token']);
                $enable = !empty($_POST['pushover_enabled']);
                $batOn  = !empty($_POST['battery_alert_enabled']);
                settings_update(function (array &$st) use ($token, $clear, $enable, $batOn, $bkey, $thr, $uiUrl) {
                    if ($clear) {
                        $st['pushover_app_token'] = '';
                    } elseif ($token !== '') {
                        $st['pushover_app_token'] = $token;
                    }
                    if ($enable && $st['pushover_app_token'] === '') {
                        throw new InvalidArgumentException('Renseignez le jeton d\'application Pushover pour activer les notifications.');
                    }
                    if ($batOn && $bkey === '') {
                        throw new InvalidArgumentException('Renseignez la clé du destinataire de l\'alerte batterie.');
                    }
                    $st['pushover_enabled']      = $enable;
                    $st['battery_alert_enabled'] = $batOn;
                    $st['battery_threshold']     = $thr;
                    $st['battery_user_key']      = $bkey;
                    $st['ui_url']                = $uiUrl;
                });
                flash('Réglages des notifications enregistrés.');
                break;

            case 'test_push':
                $cfg = settings_read();
                $to = ($cfg['battery_user_key'] ?? '') !== '' ? (string)$cfg['battery_user_key'] : (string)($me['pushover_key'] ?? '');
                if (pushover_send($to, 'Test de notification', 'Les notifications Pushover fonctionnent (envoi depuis l\'administration).')) {
                    flash('Notification de test envoyée.');
                } else {
                    throw new RuntimeException('Échec de l\'envoi : ' . $GLOBALS['pushover_error']);
                }
                break;

            case 'reset':
                $id = (int)($_POST['id'] ?? 0);
                $target = find_user_by_id($id);
                if ($target === null) {
                    throw new InvalidArgumentException('Utilisateur introuvable.');
                }
                $pwd = random_password();
                user_patch($id, ['password_hash' => password_hash($pwd, PASSWORD_DEFAULT), 'must_change' => true]);
                flash('Mot de passe de « ' . $target['name'] . ' » réinitialisé. Mot de passe provisoire : ' . $pwd
                    . ' (affiché une seule fois ; ses sessions ouvertes sont fermées).');
                break;

            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if ($id === $me['id']) {
                    throw new InvalidArgumentException('Vous ne pouvez pas supprimer votre propre compte.');
                }
                $name = users_update(function (array &$d) use ($id) {
                    foreach ($d['users'] as $i => $u) {
                        if ($u['id'] === $id) {
                            if (!empty($u['admin']) && count_admins($d['users']) <= 1) {
                                throw new InvalidArgumentException('Il doit rester au moins un administrateur.');
                            }
                            array_splice($d['users'], $i, 1);
                            return $u['name'];
                        }
                    }
                    throw new InvalidArgumentException('Utilisateur introuvable.');
                });
                flash('Utilisateur « ' . $name . ' » supprimé. Son historique de pesées est conservé sur le serveur.');
                break;
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('admin.php');
}

/* ---------- Affichage ---------- */

/** Champs communs aux formulaires de création et de modification. */
function user_fields(array $u): void
{
    $weight = $u['initial_weight_g'] > 0 ? number_format($u['initial_weight_g'] / 1000, 1, '.', '') : '';
    $year   = $u['birth_year'] > 0 ? (string)$u['birth_year'] : '';
    $height = $u['height_mm'] > 0 ? (string)intdiv($u['height_mm'], 10) : '';
    ?>
    <label>Nom (20 caractères max., sans accent)
      <input name="name" value="<?= h($u['name']) ?>" maxlength="20" required></label>
    <label class="inline"><input type="checkbox" name="admin" value="1"<?= !empty($u['admin']) ? ' checked' : '' ?>> Administrateur</label>
    <label class="inline"><input type="checkbox" name="on_scale" value="1"<?= !empty($u['on_scale']) ? ' checked' : '' ?>> Reconnu par la balance (profil ci-dessous)</label>
    <label>Poids de départ (kg)
      <input name="weight_kg" inputmode="decimal" value="<?= h($weight) ?>"></label>
    <label>Année de naissance
      <input name="birth_year" inputmode="numeric" value="<?= h($year) ?>"></label>
    <label>Sexe
      <select name="gender">
        <option value="m"<?= $u['gender'] === 0x02 ? ' selected' : '' ?>>Homme</option>
        <option value="f"<?= $u['gender'] === 0x00 ? ' selected' : '' ?>>Femme</option>
      </select></label>
    <label>Taille (cm)
      <input name="height_cm" inputmode="numeric" value="<?= h($height) ?>"></label>
    <label class="inline"><input type="checkbox" name="notify_weighing" value="1"<?= ($u['notify_weighing'] ?? false) ? ' checked' : '' ?>> Notifier à chaque nouvelle pesée (Pushover)</label>
    <label class="inline"><input type="checkbox" name="notify_hide_values" value="1"<?= !empty($u['notify_hide_values']) ? ' checked' : '' ?>> Masquer le poids et la masse grasse dans la notification</label>
    <label class="inline"><input type="checkbox" name="notify_link" value="1"<?= !empty($u['notify_link']) ? ' checked' : '' ?>> Joindre un lien vers l'interface</label>
    <label>Clé utilisateur Pushover
      <input name="pushover_key" value="<?= h($u['pushover_key'] ?? '') ?>" maxlength="30" autocomplete="off" spellcheck="false"></label>
    <label>Appareil Pushover (facultatif)
      <input name="pushover_device" value="<?= h($u['pushover_device'] ?? '') ?>" maxlength="25" autocomplete="off" spellcheck="false"></label>
    <?php
}

function password_status(array $u): string
{
    if ((string)$u['password_hash'] === '') {
        return 'Non défini';
    }
    return !empty($u['must_change']) ? 'Provisoire' : 'Défini';
}

page_head('Administration', $me);
?>
<style>
  .card form.stack { max-width:420px; margin-left:auto; margin-right:auto; }
  .card form.stack button[type=submit] { align-self:center; }
  .card h2 .card summary, .card > .muted { text-align:left; }
  .card .row { display:flex; flex-wrap:nowrap; gap:8px; max-width:420px; margin:8px auto 0; }
  .card .row > form { flex:1 1 0; min-width:0; margin:0; }
  .card .row > form { flex:1 1 0; }
  .card .row > form.del { flex:0 0 auto; }
  .card .row > form button { width:100%; height:44px; }
  .card .row > form.del button { width:44px; padding:0; display:inline-flex; align-items:center; justify-content:center; }
  .card form.stack button[type=submit] { width:100%; }
  .card details > summary { cursor:pointer; }
</style>
<?php
$users = load_users();
?>
<section class="card">
  <h2>Utilisateurs</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Nom</th><th>Rôle</th><th>Balance</th><th>Mot de passe</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['name']) ?></td>
        <td><?= !empty($u['admin']) ? 'Admin' : 'Utilisateur' ?></td>
        <td><?= !empty($u['on_scale']) ? 'Oui' : 'Non' ?></td>
        <td><?= h(password_status($u)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <center>Les modifications du profil balance (poids de départ, taille…)<br>sont transmises à la balance à sa prochaine connexion.</center>
</section>

<?php $cfg = settings_read(); ?>
<section class="card">
  <h2>Notifications Pushover</h2>
  <details<?= !pushover_ready($cfg) ? ' open' : '' ?>>
    <summary>Réglages</summary>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_push">
      <label class="inline"><input type="checkbox" name="pushover_enabled" value="1"<?= !empty($cfg['pushover_enabled']) ? ' checked' : '' ?>> Activer les notifications Pushover</label>
      <label>Jeton d'application Pushover (API token)
        <input type="password" name="app_token" autocomplete="off" spellcheck="false" maxlength="30"
               placeholder="<?= $cfg['pushover_app_token'] !== '' ? '•••••• enregistré — laisser vide pour le conserver' : '30 caractères' ?>"></label>
      <?php if ($cfg['pushover_app_token'] !== ''): ?>
        <label class="inline"><input type="checkbox" name="clear_token" value="1"> Effacer le jeton enregistré</label>
      <?php endif; ?>
      <label class="inline"><input type="checkbox" name="battery_alert_enabled" value="1"<?= !empty($cfg['battery_alert_enabled']) ? ' checked' : '' ?>> Alerte de batterie faible</label>
      <label>Seuil d'alerte (%)
        <input name="battery_threshold" inputmode="numeric" value="<?= (int)$cfg['battery_threshold'] ?>" maxlength="2"></label>
      <label>Clé Pushover du destinataire de l'alerte (utilisateur ou groupe)
        <input name="battery_user_key" value="<?= h($cfg['battery_user_key']) ?>" maxlength="30" autocomplete="off" spellcheck="false"></label>
      <label>URL de l'interface utilisateur (lien joint aux notifications de pesée, facultatif)
        <input type="url" name="ui_url" value="<?= h($cfg['ui_url']) ?>" maxlength="512" placeholder="https://fitbit.exemple.fr/" autocomplete="off" spellcheck="false"></label>
      <button type="submit">Enregistrer</button>
    </form>
    <div class="row">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_push">
        <button type="submit" class="secondary">Envoyer une notification de test</button>
      </form>
    </div>
    <p class="muted">Le test part vers le destinataire de l'alerte batterie (ou, à défaut, votre propre clé). Les notifications de pesée
      sont réglées pour chaque utilisateur (« Modifier » ci-dessous, ou « Mon compte »). Le serveur doit pouvoir joindre api.pushover.net en HTTPS.</p>
  </details>
  <?php foreach ($cfg['scales'] as $mac => $st): ?>
    <p class="muted">Balance <?= h($mac) ?> : batterie <?= (int)($st['battery'] ?? 0) ?> %, dernier échange le
      <?= h(date('d/m/Y H:i', (int)($st['seen'] ?? 0))) ?><?= !empty($st['alerted']) ? ' · alerte envoyée' : '' ?>.</p>
  <?php endforeach; ?>
</section>

<?php foreach ($users as $u): ?>
<section class="card">
  <h2><?= h($u['name']) ?>
    <?php if (!empty($u['admin'])): ?><span class="badge">admin</span><?php endif; ?>
    <?php if ($u['id'] === $me['id']): ?><span class="badge">vous</span><?php endif; ?></h2>
  <details>
    <summary>Modifier</summary>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <?php user_fields($u); ?>
      <button type="submit">Enregistrer</button>
    </form>
    <div class="row">
      <form method="post" onsubmit="return confirm('Réinitialiser le mot de passe de <?= h(addslashes($u['name'])) ?> ?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <button type="submit" class="secondary">Réinitialiser le mot de passe</button>
      </form>
      <?php if ($u['id'] !== $me['id']): ?>
      <form method="post" class="del" onsubmit="return confirm('Supprimer le compte de <?= h(addslashes($u['name'])) ?> ?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <button type="submit" class="danger icon" aria-label="Supprimer le compte" title="Supprimer">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </details>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>Nouvel utilisateur</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <?php user_fields(['name' => '', 'admin' => false, 'on_scale' => true,
                       'initial_weight_g' => 70000, 'birth_year' => 1990, 'gender' => 0x02, 'height_mm' => 1750,
                       'notify_weighing' => false, 'notify_hide_values' => false, 'notify_link' => false,
                       'pushover_key' => '', 'pushover_device' => '']); ?>
    <label>Mot de passe provisoire (laisser vide pour en générer un)
      <input type="text" name="password" autocomplete="off"></label>
    <button type="submit">Créer l'utilisateur</button>
  </form>
</section>
<?php
page_foot();
