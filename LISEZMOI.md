# Fitbit Aria – serveur PHP auto-hébergé

Serveur PHP qui remplace le cloud Fitbit pour une balance connectée **Fitbit Aria** (protocole **V3**, firmware **V35**). Il reçoit les pesées envoyées par la balance, les range dans des fichiers CSV par utilisateur, renvoie à la balance la liste des utilisateurs (pour qu'elle reconnaisse qui monte dessus) et propose une interface web en français, adaptée au téléphone.

Le protocole est décrit par le projet [micolous/helvetic](https://github.com/micolous/helvetic) (`protocol.md`), dont ce serveur s'inspire.

---

## Sommaire

1. [Fonctionnalités](#fonctionnalités)
2. [Architecture et fichiers](#architecture-et-fichiers)
3. [Prérequis](#prérequis)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Brancher la balance](#brancher-la-balance)
7. [Utilisation de l'interface web](#utilisation-de-linterface-web)
8. [Stockage des données](#stockage-des-données)
9. [Fonctionnement détaillé](#fonctionnement-détaillé)
10. [Sécurité](#sécurité)
11. [Sauvegarde et maintenance](#sauvegarde-et-maintenance)
12. [Dépannage](#dépannage)
13. [Limites connues](#limites-connues)

---

## Fonctionnalités

### Côté balance (`aria.php`)

- Protocole **Aria V3** : `GET /scale/register` (réponse vide, 200) et `POST /scale/upload`.
- Vérification du **CRC-16 XMODEM** de chaque requête ; requête invalide → HTTP 400.
- Filtre optionnel par **adresse MAC** de la balance (`ALLOWED_MACS`).
- **Enregistrement des pesées** : poids, impédance, masse grasse (`fat1`, `fat2`), covariance, horodatage.
- **Correction d'horloge** : si l'horloge de la balance dérive de plus de 2 minutes, les horodatages sont recalés sur l'heure du serveur.
- **Reconnaissance de l'utilisateur** :
  1. par l'identifiant d'utilisateur que la balance renvoie ;
  2. sinon, par déduction : l'utilisateur dont le poids de référence est le plus proche (dans le delta autorisé) ;
  3. sinon la pesée va chez les **invités** (`guests.csv`).
- **Mise à jour des utilisateurs sur la balance** à chaque échange : nom, année de naissance converties en âge, sexe, taille, et fourchette de poids **min/max calculée automatiquement** à partir de la dernière pesée (arrondie au kg inférieur, ± 3 kg par défaut).
- **Anti-doublons global** : la balance renvoie parfois ses pesées en cache. Une pesée déjà connue, déplacée ou supprimée n'est jamais recréée.
- **Journal complet des échanges** balance ⇄ serveur (`exchanges.log`, une ligne JSON par échange : en-têtes, octets bruts en hexadécimal, version décodée, statut).

### Côté web

- **Page des pesées** (`pesees.php`)
  - Sans connexion : accès libre aux pesées des **invités**, avec formulaire de connexion.
  - Connecté : sa dernière pesée en grand avec l'écart par rapport à la précédente, un graphique et un tableau des 20 dernières pesées.
  - **Graphiques SVG** (sans JavaScript, sans bibliothèque) : *Poids*, *Masse grasse* ou *Les deux* (double axe : poids à gauche, masse grasse à droite). **« Les deux » est le choix par défaut** ; le choix est mémorisé pour la session. Un message s'affiche quand il n'y a pas assez de points (moins de 2) pour tracer une courbe.
  - Bouton **« Autres pesées »** : les pesées qui n'appartiennent pas à l'utilisateur sont masquées par défaut.
  - Masse grasse affichée en pourcentage avec deux décimales (ex. `27,92 %`).
- **Comptes et authentification**
  - Un mot de passe par utilisateur, stocké avec `password_hash`.
  - Compte administrateur par défaut créé au premier lancement : **`admin` / `changeme`**, changement **obligatoire** à la première connexion.
  - Mot de passe : 10 caractères minimum, différent de l'ancien, différent du mot de passe par défaut, ne contenant pas l'identifiant.
  - Protection CSRF, cookies de session `HttpOnly` / `SameSite=Lax` (et `Secure` en HTTPS), session invalidée si le mot de passe est changé ailleurs.
  - **Verrouillage par IP** : 5 échecs de connexion en 15 minutes → blocage temporaire.
- **Gestion des pesées**
  - *Utilisateur* : **basculer** une de ses pesées vers les invités ; **s'attribuer** une pesée d'invité (bouton « M'attribuer »).
  - *Administrateur* : **déplacer** n'importe quelle pesée vers n'importe quel propriétaire (invités compris) et **supprimer** une pesée (icône poubelle, avec confirmation).
  - Chaque action est contrôlée côté serveur : un utilisateur ne peut pas toucher aux pesées des autres, même en forgeant une requête (HTTP 403).
- **Mon compte** (`compte.php`) : changement du mot de passe ; pour les utilisateurs reconnus par la balance, **profil balance** modifiable (poids de départ, taille, année de naissance, sexe), transmis à la balance à sa prochaine connexion.
- **Administration** (`admin.php`, réservée aux administrateurs) : liste des utilisateurs, création (mot de passe provisoire saisi ou généré), modification (nom, rôle administrateur, reconnu ou non par la balance, profil), réinitialisation du mot de passe (mot de passe provisoire affiché **une seule fois**, sessions ouvertes fermées), suppression. Garde-fous : impossible de supprimer son propre compte, impossible de retirer ou supprimer le **dernier administrateur**. La suppression d'un compte conserve son historique de pesées sur le serveur.
- **Interface responsive** : tableaux transformés en cartes sous 640 px, zones tactiles de 44 px, aucun défilement horizontal, thème clair/sombre automatique. Bandeau « Fitbit by Cédric » sur toutes les pages.

---

## Architecture et fichiers

| Fichier | Rôle |
|---|---|
| `lib.php` | Configuration, CRC, lecture/écriture des CSV, déduplication, déplacement et suppression de pesées, gestion de `users.json`, construction des utilisateurs envoyés à la balance |
| `aria.php` | Points d'accès **réservés à la balance** (`/scale/register`, `/scale/upload`) et journal des échanges |
| `webcore.php` | Socle web : session, CSRF, connexion/déconnexion, verrouillage anti-force-brute, mise en page et CSS |
| `pesees.php` | Page des pesées, graphiques, déplacement/suppression de pesées |
| `compte.php` | Mot de passe et profil balance |
| `admin.php` | Administration des utilisateurs |
| `data/` | Données (créé automatiquement, voir [Stockage](#stockage-des-données)) |

La balance et les navigateurs ne partagent que `lib.php`. Les fichiers `aria.php`, `lib.php` et `webcore.php` ne doivent **jamais** être servis directement aux navigateurs (voir la configuration Apache).

---

## Prérequis

- **PHP 8.0 ou plus** (8.3 ou 8.4 recommandés). Aucune extension particulière au-delà d'une installation standard (sessions, `password_hash`).
- Un serveur web : **Apache 2.4** (exemples ci-dessous) ou Nginx + PHP-FPM.
- Le serveur web doit pouvoir **écrire** dans le dossier `data/`.
- Une balance **Fitbit Aria** en protocole V3, et la possibilité de **rediriger son trafic** vers votre serveur (DNS interne, voir plus bas).
- La balance parle en **HTTP sur le port 80** uniquement ; les navigateurs utiliseront de préférence HTTPS (443).

---

## Installation

### 1. Copier les fichiers

```bash
sudo mkdir -p /var/www/aria
sudo cp aria.php lib.php webcore.php pesees.php compte.php admin.php /var/www/aria/
sudo chown -R www-data:www-data /var/www/aria
```

### 2. Placer les données (recommandé : hors de la racine web)

Par défaut les données sont dans `data/` à côté des scripts (protégé par un `.htaccess` `Require all denied` créé automatiquement, valable sous Apache). Il est préférable de les sortir de la racine web :

```bash
sudo mkdir -p /var/lib/aria
sudo chown www-data:www-data /var/lib/aria
sudo chmod 750 /var/lib/aria
```

puis, dans `lib.php`, modifier :

```php
const DATA_DIR = '/var/lib/aria';
```

### 3. Configurer Apache

Les exemples ci-dessous sont indicatifs (à adapter à votre installation). Deux noms DNS sont le plus simple : l'un pour la balance (HTTP), l'autre pour les humains (HTTPS).

```apache
<VirtualHost *:80>
    ServerName www.fitbit.com
    DocumentRoot /var/www/www_root
    RewriteEngine On
    # Balance : uniquement ces deux chemins
    RewriteRule ^/scale/(register|upload)$ /aria.php [L]
    # Navigateur arrivé par erreur en HTTP : renvoi vers la page en HTTPS
    RewriteRule ^ https://fitbit.yourdomain.com%{REQUEST_URI} [R=302,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName fitbit.yourdomain.com
    DocumentRoot /var/www/www_root
    DirectoryIndex pesees.php
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/cert_fullchain.crt
    SSLCertificateKeyFile /etc/ssl/certs/cert.key
    # La balance n'a rien à faire ici, et lib.php n'a pas à être servi
    <FilesMatch "^(aria|lib)\.php$">
        Require all denied
    </FilesMatch>
</VirtualHost>
```

Activer les modules et recharger :

```bash
sudo a2enmod rewrite ssl
sudo systemctl reload apache2
```

#### Un seul nom DNS pour les deux usages

C'est possible : la balance n'utilise que le port 80, les navigateurs le port 443. Avec un seul `ServerName`, le vhost `*:80` sert la balance (comme ci-dessus) et le vhost `*:443` sert l'interface web. Le certificat TLS doit alors être valable pour ce nom (autorité interne approuvée par vos navigateurs). Ne redirigez **pas** le port 80 vers HTTPS : la balance ne suit pas les redirections.

### 4. Premier lancement

Ouvrir l'interface web, se connecter avec **`admin` / `changeme`** et choisir immédiatement un nouveau mot de passe. Au premier accès, le serveur crée `data/` (ou `DATA_DIR`), `users.json` et le compte administrateur, et importe les utilisateurs de départ (voir `$SEED_USERS` plus bas).

### 5. Créer les utilisateurs

Dans **Administration → Nouvel utilisateur** : nom (20 caractères max., sans accent), poids de départ, année de naissance, sexe, taille, case « Reconnu par la balance » cochée. Un mot de passe provisoire est généré (ou saisi) ; l'utilisateur devra le changer à sa première connexion.

---

## Configuration

Tout se règle en tête de `lib.php` :

| Constante | Défaut | Rôle |
|---|---|---|
| `DATA_DIR` | `__DIR__ . '/data'` | Dossier des données (idéalement hors racine web) |
| `TIMEZONE` | `Europe/Paris` | Fuseau horaire des dates affichées |
| `ALLOWED_MACS` | `[]` | Adresses MAC autorisées (hexa minuscule sans séparateur, ex. `['20f85e123456']`) ; vide = toutes |
| `MAX_LOGIN_FAILURES` | `5` | Échecs de connexion avant blocage de l'IP |
| `LOCKOUT_SECONDS` | `900` | Durée du blocage (secondes) |
| `WEIGHT_DELTA_G` | `3000` | Demi-largeur de la fourchette de reconnaissance (g) autour de la dernière pesée arrondie au kg inférieur |
| `SEND_PREVIOUS_VALUES` | `false` | Envoyer à la balance les dernières valeurs connues (comme les serveurs de référence, on envoie 0) |
| `$SEED_USERS` | Cedric, Marie | Utilisateurs importés **une seule fois** à la création de `users.json` ; peut être vidée ensuite |
| `DEFAULT_ADMIN_NAME` / `DEFAULT_ADMIN_PASSWORD` | `admin` / `changeme` | Compte administrateur initial (changement forcé) |
| `MIN_PASSWORD_LENGTH` | `10` | Longueur minimale des mots de passe |

Autres réglages dans `pesees.php` : `ROWS_SHOWN` (20 lignes affichées par tableau) et `CHART_POINTS` (60 points maximum par graphique).

Si le site est derrière un reverse proxy, adaptez `client_ip()` dans `webcore.php` (par défaut `REMOTE_ADDR`) pour que le verrouillage cible la vraie adresse du client.

---

## Brancher la balance

La balance appelle `http://www.fitbit.com/scale/register` puis `http://www.fitbit.com/scale/upload`, en **HTTP, port 80**.

1. **Configurer le Wi-Fi de la balance** (via l'application Fitbit ou la procédure d'appairage habituelle de l'Aria).
2. **Détourner `www.fitbit.com` vers votre serveur** pour le réseau de la balance : entrée DNS locale (routeur, Pi-hole, dnsmasq…) faisant pointer ce nom vers l'adresse de votre serveur.
3. Monter sur la balance : elle envoie ses pesées à `/scale/upload` et reçoit en retour la liste des utilisateurs.
4. Vérifier dans `data/exchanges.log` que les échanges arrivent et sont répondus (statut 200).

Remarque : si vous utilisez un vhost dédié (`balance.exemple.lan` ci-dessus), il doit être celui que résout `www.fitbit.com` côté balance (ou ajoutez-le en `ServerAlias`).

---

## Utilisation de l'interface web

| Page | Qui | Contenu |
|---|---|---|
| `pesees.php` | Tous | Pesées ; choix du graphique ; « Autres pesées » ; actions sur les pesées |
| `compte.php` | Connecté | Mot de passe ; profil balance |
| `admin.php` | Administrateurs | Gestion des utilisateurs |

### Droits sur les pesées

| Action | Utilisateur | Administrateur |
|---|---|---|
| Voir ses pesées | ✔ | ✔ |
| Voir les pesées des invités | ✔ (accès libre, même sans connexion) | ✔ |
| Voir les pesées des autres utilisateurs | ✘ | ✔ |
| Basculer une de ses pesées vers les invités | ✔ | ✔ |
| S'attribuer une pesée d'invité | ✔ | ✔ |
| Déplacer une pesée vers un autre propriétaire | ✘ | ✔ |
| Supprimer une pesée | ✘ | ✔ |

### Mettre à jour le profil sur la balance

Une modification de poids de départ, taille, année de naissance ou sexe (dans « Mon compte » ou l'administration) est **envoyée à la balance à son prochain échange** : il suffit de monter dessus une fois.

---

## Stockage des données

Tout est dans `DATA_DIR` :

| Fichier | Contenu |
|---|---|
| `users.json` | Utilisateurs (identifiant, nom, hash du mot de passe, rôle, profil balance) et compteur d'identifiants |
| `user_<id>.csv` | Pesées d'un utilisateur |
| `guests.csv` | Pesées non attribuées (invités) |
| `deleted_keys.txt` | Clés des pesées supprimées (pour ne pas les recréer) |
| `exchanges.log` | Journal des échanges avec la balance (JSON, une ligne par échange) |
| `login_attempts.json` | Échecs de connexion par IP |
| `.lock`, `.users.lock` | Verrous d'écriture |
| `.htaccess` | `Require all denied` (si le dossier est sous la racine web) |

### Format des CSV

Colonnes : `timestamp, datetime, aria_timestamp, weight_g, weight_kg, impedance, fat1, covariance, fat2`

- `timestamp` : horodatage réel (corrigé de la dérive d'horloge) ; `datetime` : même date lisible.
- `aria_timestamp` : horodatage tel que la balance l'a envoyé.
- `weight_g` / `weight_kg` : poids en grammes / en kg.
- `fat1` : masse grasse en **millièmes de pourcent** (`27921` = 27,92 %).
- Une pesée est identifiée par `aria_timestamp + '_' + weight_g`.

Les écritures sont protégées par verrou et les réécritures passent par un fichier temporaire suivi d'un renommage (jamais de fichier à moitié écrit).

---

## Fonctionnement détaillé

### Requête `POST /scale/upload`

Corps en little-endian, terminé par un CRC-16 XMODEM sur 2 octets :

- en-tête : protocole (3), batterie, MAC, code d'authentification (16 octets), firmware, horodatage de la balance, nombre de mesures ;
- puis 32 octets par mesure : `id2`, impédance, poids (g), horodatage, identifiant utilisateur, `fat1`, covariance, `fat2`.

### Réponse du serveur

Horodatage courant, unité (kg), statut, nombre d'utilisateurs, puis **77 octets par utilisateur** : identifiant, 16 octets de remplissage, nom (20 caractères en **majuscules** complétés par des espaces), tolérance minimale et maximale (**bornes absolues** en grammes), âge, sexe (`0x02` homme, `0x00` femme), taille (mm), et quatre valeurs de poids/masse grasse/covariance/horodatage (à 0 tant que `SEND_PREVIOUS_VALUES` est désactivé). Suivent le trailer `0,3,0`, le CRC-16, puis la taille du message (`0x19 + 0x4d × nombre d'utilisateurs`). L'en-tête `Content-Length` est obligatoire.

### Fourchette min/max

Pour chaque utilisateur : `réf = dernière pesée arrondie au kg inférieur` (ou le poids de départ s'il n'y a encore aucune pesée), `min = réf − 3 kg`, `max = réf + 3 kg`. Si deux utilisateurs ont des poids trop proches pour être distingués, un message est écrit dans le journal d'erreurs PHP : la balance risque alors de les confondre, et le serveur retombe sur la déduction par proximité de poids.

### Âge

La balance reçoit un âge ; vous ne saisissez que l'**année de naissance**, l'âge est recalculé automatiquement avec l'année courante.

---

## Sécurité

- **Changez le mot de passe `admin` immédiatement** (le serveur l'impose à la première connexion).
- **Servez l'interface en HTTPS** : les mots de passe transitent dans les formulaires. Le port 80 n'est utile qu'à la balance.
- Ne servez pas `aria.php`, `lib.php` ni `webcore.php` sur le nom destiné aux humains (règle `FilesMatch`), et n'exposez pas les scripts web sur le nom de la balance (règle `Require all denied`).
- Placez `DATA_DIR` **hors de la racine web** : `exchanges.log` contient le code d'authentification de la balance et les octets bruts des échanges.
- Pour limiter les requêtes à votre balance, renseignez `ALLOWED_MACS`.
- Les mots de passe provisoires s'affichent une seule fois ; transmettez-les par un canal sûr.
- Cette application est prévue pour un **réseau local / usage domestique**. Ne l'exposez pas telle quelle sur Internet sans une couche supplémentaire (VPN, authentification du reverse proxy, etc.).

---

## Sauvegarde et maintenance

- **Sauvegarde** : copiez simplement `DATA_DIR` (CSV, `users.json`, `deleted_keys.txt`).
- **Restauration** : remettez le dossier en place, avec les droits du serveur web.
- **Journal volumineux** : `exchanges.log` grossit à chaque échange ; faites-le tourner avec `logrotate` ou videz-le de temps en temps.
- **Export** : les CSV s'ouvrent directement dans un tableur ou un script.
- **Suppression d'un utilisateur** : son fichier `user_<id>.csv` reste sur le serveur mais n'est plus affiché dans l'interface.

---

## Dépannage

| Symptôme | Piste |
|---|---|
| Rien n'arrive dans `exchanges.log` | La balance ne joint pas votre serveur : vérifier le Wi-Fi de la balance, la résolution DNS de `www.fitbit.com`, le port 80 ouvert, le vhost de la balance |
| Statut 400 dans le journal | CRC invalide, protocole différent de 3, ou corps tronqué |
| Statut 403 | `ALLOWED_MACS` ne contient pas l'adresse de votre balance |
| Les pesées vont toutes chez les invités | Poids de départ ou profil de l'utilisateur trop éloigné de son poids réel (fourchette ± 3 kg) ; mettez à jour son poids de départ puis remontez sur la balance |
| Le nouveau profil n'est pas pris en compte | Il n'est envoyé qu'à l'échange suivant : montez sur la balance une fois |
| « Trop de tentatives » à la connexion | Blocage de l'IP 15 minutes ; supprimer l'entrée dans `login_attempts.json` pour débloquer plus tôt |
| Erreur d'écriture / page blanche | Droits d'écriture manquants sur `DATA_DIR` pour l'utilisateur du serveur web |
| Une pesée supprimée ou déplacée « revient » | Ne doit pas arriver (clé mémorisée) ; vérifier que `deleted_keys.txt` et les CSV sont bien accessibles en écriture |
| Masse grasse absente (affichée « – ») | La balance n'a pas fourni de valeur pour cette pesée (`fat1` à 0) ; le graphique « Masse grasse » a besoin d'au moins 2 mesures |

---

## Limites connues

- Conçu pour le protocole **V3 / firmware V35** uniquement ; les autres versions sont refusées (400).
- Le mode `SEND_PREVIOUS_VALUES = true` n'a pas été vérifié sur une vraie balance : les serveurs de référence envoient 0 et la balance reconnaît quand même les utilisateurs.
- Le stockage en CSV convient à un usage familial (quelques utilisateurs, quelques milliers de pesées) ; ce n'est pas prévu pour un grand nombre d'utilisateurs.
- Pas de mot de passe oublié par e-mail : un administrateur réinitialise le mot de passe depuis la page d'administration.

---

## Crédits

- Spécification du protocole : [micolous/helvetic](https://github.com/micolous/helvetic).
