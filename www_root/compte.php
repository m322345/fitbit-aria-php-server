<?php
declare(strict_types=1);

/**
 * Mon compte : changement du mot de passe, et profil balance (poids, taille, année de
 * naissance, sexe) pour les utilisateurs reconnus par la balance.
 * Obligatoire tant que le mot de passe est provisoire (premier accès de l'admin
 * avec le mot de passe par défaut, création ou réinitialisation par un admin).
 */

require_once __DIR__ . '/webcore.php';

$me = require_login();
$forced = !empty($me['must_change']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    check_csrf();
    $old = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $conf = (string)($_POST['confirm'] ?? '');

    if (!password_verify($old, (string)$me['password_hash'])) {
        flash('Le mot de passe actuel est incorrect.', 'err');
    } elseif ($new !== $conf) {
        flash('La confirmation ne correspond pas au nouveau mot de passe.', 'err');
    } elseif ($new === $old) {
        flash('Le nouveau mot de passe doit être différent de l\'actuel.', 'err');
    } elseif (($err = password_error($new, $me['name'])) !== null) {
        flash($err, 'err');
    } else {
        user_patch($me['id'], [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
            'must_change'   => false,
        ]);
        $fresh = find_user_by_id($me['id']);
        session_regenerate_id(true);
        login_session($fresh);          // met à jour l'empreinte : cette session reste valide
        flash('Mot de passe modifié.');
        redirect('pesees.php');
    }
    redirect('compte.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    check_csrf();
    if ($forced) {
        flash('Changez d\'abord votre mot de passe.', 'err');
    } elseif (empty($me['on_scale'])) {
        http_response_code(403);
        exit('Aucun profil balance sur ce compte.');
    } else {
        try {
            // Même validation que l'administration ; seuls ces 4 champs sont modifiables ici
            $f = parse_user_input([
                'name'       => $me['name'],
                'on_scale'   => 1,
                'weight_kg'  => $_POST['weight_kg'] ?? '',
                'birth_year' => $_POST['birth_year'] ?? '',
                'gender'     => $_POST['gender'] ?? 'm',
                'height_cm'  => $_POST['height_cm'] ?? '',
            ], $me['id'], load_users());
            user_patch($me['id'], array_intersect_key(
                $f, array_flip(['initial_weight_g', 'birth_year', 'gender', 'height_mm'])));
            flash('Profil balance mis à jour. La balance le recevra à sa prochaine connexion (montez dessus une fois).');
        } catch (InvalidArgumentException | RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
    }
    redirect('compte.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($_POST['action'] ?? '', ['update_notifications', 'test_notification'], true)) {
    check_csrf();
    if ($forced) {
        flash('Changez d\'abord votre mot de passe.', 'err');
    } elseif (empty($me['on_scale'])) {
        http_response_code(403);
        exit('Aucun profil balance sur ce compte.');
    } elseif ($_POST['action'] === 'update_notifications') {
        try {
            user_patch($me['id'], parse_push_input($_POST));
            flash('Notifications mises à jour.');
        } catch (InvalidArgumentException | RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
    } else {   // test : envoi vers la clé ENREGISTRÉE
        if (pushover_send((string)($me['pushover_key'] ?? ''), 'Test de notification',
                          'Les notifications Pushover fonctionnent pour ' . $me['name'] . '.',
                          ($me['pushover_device'] ?? '') ?: null, null,
                          ($link = user_push_link($me)), $link ? 'Voir mes pesées' : null)) {
            flash('Notification de test envoyée.');
        } else {
            flash('Échec de l\'envoi : ' . $GLOBALS['pushover_error'], 'err');
        }
    }
    redirect('compte.php');
}

page_head('Mon compte', $me);
?>
<section class="card">
  <h2>Changer mon mot de passe</h2>
  <?php if ($forced): ?>
    <p class="msg err">Votre mot de passe est provisoire : choisissez-en un nouveau pour continuer.</p>
  <?php endif; ?>
  <p class="muted">Au moins <?= MIN_PASSWORD_LENGTH ?> caractères, différent du mot de passe actuel et ne contenant pas votre identifiant.</p>
  <center>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <label>Mot de passe actuel
      <input type="password" name="current" autocomplete="current-password" required></label>
    <label>Nouveau mot de passe
      <input type="password" name="new" autocomplete="new-password" minlength="<?= MIN_PASSWORD_LENGTH ?>" required></label>
    <label>Confirmer le nouveau mot de passe
      <input type="password" name="confirm" autocomplete="new-password" minlength="<?= MIN_PASSWORD_LENGTH ?>" required></label>
    <button type="submit">Enregistrer</button>
  </form>
  </center>
</section>
<?php if (!$forced && !empty($me['on_scale'])):
    $last = last_known($me);
    $weight = $me['initial_weight_g'] > 0 ? number_format($me['initial_weight_g'] / 1000, 1, '.', '') : '';
    $year   = $me['birth_year'] > 0 ? (string)$me['birth_year'] : '';
    $height = $me['height_mm'] > 0 ? (string)intdiv($me['height_mm'], 10) : '';
?>
<section class="card">
  <h2>Mon profil balance</h2>
  <p class="muted">Ces informations servent à la balance pour vous reconnaître et estimer la masse grasse.
    Elles lui sont transmises à sa prochaine connexion (montez dessus une fois).</p>
  <center><form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_profile">
    <label>Poids de départ (kg)
      <input name="weight_kg" inputmode="decimal" value="<?= h($weight) ?>" required></label>
    <p class="muted" style="margin:-4px 0 0">
      <?php if ($last): ?>
        Dès qu'une pesée est enregistrée, la balance se base sur votre dernière pesée
        (actuellement <?= h(number_format((int)$last['weight_g'] / 1000, 1, ',', ' ')) ?> kg) :
        ce poids ne sert qu'au démarrage.
      <?php else: ?>
        Utilisé pour vous reconnaître tant qu'aucune pesée n'est enregistrée ; ensuite la balance suit votre dernière pesée.
      <?php endif; ?>
    </p>
    <label>Taille (cm)
      <input name="height_cm" inputmode="numeric" value="<?= h($height) ?>" required></label>
    <label>Année de naissance
      <input name="birth_year" inputmode="numeric" value="<?= h($year) ?>" required></label>
    <label>Sexe
      <select name="gender">
        <option value="m"<?= $me['gender'] === 0x02 ? ' selected' : '' ?>>Homme</option>
        <option value="f"<?= $me['gender'] === 0x00 ? ' selected' : '' ?>>Femme</option>
      </select></label>
    <button type="submit">Enregistrer le profil</button>
  </form>
  </center>
</section>
<section class="card">
  <h2>Notifications Pushover</h2>
  <?php if (!pushover_ready()): ?>
    <p class="msg err">Les notifications Pushover ne sont pas activées par l'administrateur.</p>
  <?php endif; ?>
  <p class="muted">Recevez une notification sur votre téléphone à chaque nouvelle pesée. Votre clé utilisateur
    (« Your User Key ») se trouve sur la page d'accueil de pushover.net ou dans l'application.</p>
  <center><form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_notifications">
    <label class="inline"><input type="checkbox" name="notify_weighing" value="1"<?= ($me['notify_weighing'] ?? false) ? ' checked' : '' ?>>
      Me notifier à chaque nouvelle pesée</label>
    <label class="inline"><input type="checkbox" name="notify_hide_values" value="1"<?= !empty($me['notify_hide_values']) ? ' checked' : '' ?>>
      Masquer le poids et la masse grasse dans la notification</label>
    <label class="inline"><input type="checkbox" name="notify_link" value="1"<?= !empty($me['notify_link']) ? ' checked' : '' ?>>
      Joindre un lien vers l'interface</label>
    <?php if (trim((string)settings_read()['ui_url']) === ''): ?>
      <p class="muted" style="margin:-4px 0 0">L'administrateur n'a pas encore configuré l'adresse de l'interface : le lien n'est pas ajouté pour l'instant.</p>
    <?php endif; ?>
    <label>Clé utilisateur Pushover (30 caractères)
      <input name="pushover_key" value="<?= h($me['pushover_key'] ?? '') ?>" maxlength="30" autocomplete="off" spellcheck="false"></label>
    <label>Nom de l'appareil (facultatif : vide = tous mes appareils)
      <input name="pushover_device" value="<?= h($me['pushover_device'] ?? '') ?>" maxlength="25" autocomplete="off" spellcheck="false"></label>
    <button type="submit">Enregistrer les notifications</button>
  </form>
  <?php if (($me['pushover_key'] ?? '') !== ''): ?>
  <form method="post" class="stack" style="margin-top:8px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_notification">
    <button type="submit" class="secondary">Envoyer une notification de test</button>
  </form>
  <?php endif; ?>
  </center>
</section>
<?php endif;
page_foot();
