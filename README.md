# Fitbit Aria – self-hosted PHP server

A PHP server that replaces the Fitbit cloud for a **Fitbit Aria** connected scale (protocol **V3**, firmware **V35**). It receives the weigh-ins sent by the scale, stores them in per-user CSV files, sends the user list back to the scale (so it can recognise who steps on it) and provides a mobile-friendly web interface (in French).

The protocol is described by the [micolous/helvetic](https://github.com/micolous/helvetic) project (`protocol.md`), which this server is based on.

French version: [LISEZMOI.md](LISEZMOI.md)

---

## Table of contents

1. [Features](#features)
2. [Architecture and files](#architecture-and-files)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Connecting the scale](#connecting-the-scale)
7. [Using the web interface](#using-the-web-interface)
8. [Data storage](#data-storage)
9. [How it works](#how-it-works)
10. [Security](#security)
11. [Backup and maintenance](#backup-and-maintenance)
12. [Troubleshooting](#troubleshooting)
13. [Known limitations](#known-limitations)

---

## Features

### Scale side (`aria.php`)

- **Aria V3 protocol**: `GET /scale/register` (empty response, 200) and `POST /scale/upload`.
- **CRC-16 XMODEM** check on every request; invalid request → HTTP 400.
- Optional filter on the scale's **MAC address** (`ALLOWED_MACS`).
- **Weigh-in storage**: weight, impedance, body fat (`fat1`, `fat2`), covariance, timestamp.
- **Clock correction**: if the scale's clock drifts by more than 2 minutes, timestamps are realigned to the server time.
- **User recognition**:
  1. by the user ID the scale sends back;
  2. otherwise by inference: the user whose reference weight is closest (within the allowed delta);
  3. otherwise the weigh-in goes to the **guests** (`guests.csv`).
- **User update on the scale** at every exchange: name, birth year converted to an age, gender, height, and a **min/max weight range computed automatically** from the last weigh-in (rounded down to the kg, ± 3 kg by default).
- **Global de-duplication**: the scale sometimes re-sends its cached weigh-ins. A weigh-in that is already known, moved or deleted is never recreated.
- **Full exchange log** scale ⇄ server (`exchanges.log`, one JSON line per exchange: headers, raw bytes in hexadecimal, decoded version, status).

### Web side

- **Weigh-in page** (`pesees.php`)
  - Logged out: free access to the **guests'** weigh-ins, with a login form.
  - Logged in: your latest weigh-in in large type with the difference from the previous one, a chart and a table of the last 20 weigh-ins.
  - **SVG charts** (no JavaScript, no library): *Weight*, *Body fat* or *Both* (dual axis: weight on the left, body fat on the right). **"Both" is the default**; the choice is remembered for the session. A message is shown when there are not enough points (fewer than 2) to draw a curve.
  - **"Other weigh-ins"** button: weigh-ins that do not belong to the user are hidden by default.
  - Body fat shown as a percentage with two decimals (e.g. `27.92 %`).
- **Accounts and authentication**
  - One password per user, stored with `password_hash`.
  - A default administrator account is created on first launch: **`admin` / `changeme`**, with a **mandatory** password change on first login.
  - Passwords: at least 10 characters, different from the previous one, different from the default password, not containing the username.
  - CSRF protection, `HttpOnly` / `SameSite=Lax` session cookies (and `Secure` over HTTPS), session invalidated if the password is changed elsewhere.
  - **Per-IP lockout**: 5 failed logins within 15 minutes → temporary block.
- **Weigh-in management**
  - *User*: **release** one of their weigh-ins to the guests; **claim** a guest weigh-in (the "M'attribuer" button).
  - *Administrator*: **move** any weigh-in to any owner (guests included) and **delete** a weigh-in (trash icon, with confirmation).
  - Every action is checked server-side: a user cannot touch other people's weigh-ins, even with a forged request (HTTP 403).
- **My account** (`compte.php`): password change; for users recognised by the scale, an editable **scale profile** (starting weight, height, birth year, gender), sent to the scale at its next connection.
- **Administration** (`admin.php`, administrators only): user list, creation (typed or generated temporary password), editing (name, administrator role, recognised by the scale or not, profile), password reset (temporary password shown **only once**, open sessions closed), deletion. Safeguards: you cannot delete your own account, and you cannot remove or delete the **last administrator**. Deleting an account keeps its weigh-in history on the server.
- **Responsive interface**: tables become cards under 640 px, 44 px touch targets, no horizontal scrolling, automatic light/dark theme. A "Fitbit by Cédric" banner on every page.

---

## Architecture and files

| File | Role |
|---|---|
| `lib.php` | Configuration, CRC, CSV reading/writing, de-duplication, moving and deleting weigh-ins, `users.json` management, building the users sent to the scale |
| `aria.php` | Endpoints **reserved for the scale** (`/scale/register`, `/scale/upload`) and the exchange log |
| `webcore.php` | Web foundation: session, CSRF, login/logout, brute-force lockout, layout and CSS |
| `pesees.php` | Weigh-in page, charts, moving/deleting weigh-ins |
| `compte.php` | Password and scale profile |
| `admin.php` | User administration |
| `data/` | Data (created automatically, see [Data storage](#data-storage)) |

The scale and the browsers share only `lib.php`. `aria.php`, `lib.php` and `webcore.php` must **never** be served directly to browsers (see the Apache configuration).

---

## Requirements

- **PHP 8.0 or later** (8.3/8.4 recommended). No special extension beyond a standard installation (sessions, `password_hash`).
- A web server: **Apache 2.4** (examples below) or Nginx + PHP-FPM.
- The web server must be able to **write** to the `data/` folder.
- A **Fitbit Aria** scale using protocol V3, and a way to **redirect its traffic** to your server (internal DNS, see below).
- The scale speaks **plain HTTP on port 80** only; browsers should preferably use HTTPS (443).

---

## Installation

### 1. Copy the files

```bash
sudo mkdir -p /var/www/aria
sudo cp aria.php lib.php webcore.php pesees.php compte.php admin.php /var/www/aria/
sudo chown -R www-data:www-data /var/www/aria
```

### 2. Place the data (recommended: outside the web root)

By default the data lives in `data/` next to the scripts (protected by a `.htaccess` containing `Require all denied`, created automatically, which works with Apache). It is better to move it out of the web root:

```bash
sudo mkdir -p /var/lib/aria
sudo chown www-data:www-data /var/lib/aria
sudo chmod 750 /var/lib/aria
```

then, in `lib.php`, change:

```php
const DATA_DIR = '/var/lib/aria';
```

### 3. Configure Apache

The examples below are indicative (adapt them to your setup). Two DNS names are the simplest option: one for the scale (HTTP), one for people (HTTPS).

```apache
<VirtualHost *:80>
    ServerName www.fitbit.com
    DocumentRoot /var/www/aria
    RewriteEngine On
    # Balance : uniquement ces deux chemins
    RewriteRule ^/scale/(register|upload)$ /aria.php [L]
    # Navigateur arrivé par erreur en HTTP : renvoi vers la page en HTTPS
    RewriteRule ^ https://fitbit.yourdomain.com%{REQUEST_URI} [R=302,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName fitbit.yourdomain.com
    DocumentRoot /var/www/aria
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

Enable the modules and reload:

```bash
sudo a2enmod rewrite ssl
sudo systemctl reload apache2
```

#### A single DNS name for both uses

This is possible: the scale only uses port 80, browsers use port 443. With a single `ServerName`, the `*:80` vhost serves the scale (as above) and the `*:443` vhost serves the web interface. The TLS certificate must then be valid for that name (internal CA trusted by your browsers). Do **not** redirect port 80 to HTTPS: the scale does not follow redirects.

### 4. First launch

Open the web interface, log in with **`admin` / `changeme`** and choose a new password right away. On first access the server creates `data/` (or `DATA_DIR`), `users.json` and the administrator account, and imports the starting users (see `$SEED_USERS` below).

### 5. Create the users

In **Administration → New user**: name (20 characters max., no accents), starting weight, birth year, gender, height, "Recognised by the scale" ticked. A temporary password is generated (or typed in); the user will have to change it at first login.

---

## Configuration

Everything is set at the top of `lib.php`:

| Constant | Default | Purpose |
|---|---|---|
| `DATA_DIR` | `__DIR__ . '/data'` | Data folder (ideally outside the web root) |
| `TIMEZONE` | `Europe/Paris` | Time zone of the displayed dates |
| `ALLOWED_MACS` | `[]` | Allowed MAC addresses (lowercase hex, no separator, e.g. `['20f85e123456']`); empty = all scales |
| `MAX_LOGIN_FAILURES` | `5` | Failed logins before an IP is blocked |
| `LOCKOUT_SECONDS` | `900` | Block duration (seconds) |
| `WEIGHT_DELTA_G` | `3000` | Half-width of the recognition range (g) around the last weigh-in rounded down to the kg |
| `SEND_PREVIOUS_VALUES` | `false` | Send the last known values to the scale (like the reference servers, 0 is sent) |
| `$SEED_USERS` | Cedric, Marie | Users imported **only once**, when `users.json` is created; can be emptied afterwards |
| `DEFAULT_ADMIN_NAME` / `DEFAULT_ADMIN_PASSWORD` | `admin` / `changeme` | Initial administrator account (change forced) |
| `MIN_PASSWORD_LENGTH` | `10` | Minimum password length |

Other settings in `pesees.php`: `ROWS_SHOWN` (20 rows shown per table) and `CHART_POINTS` (60 points maximum per chart).

If the site is behind a reverse proxy, adapt `client_ip()` in `webcore.php` (it uses `REMOTE_ADDR` by default) so the lockout targets the real client address.

---

## Connecting the scale

The scale calls `http://www.fitbit.com/scale/register` then `http://www.fitbit.com/scale/upload`, over **HTTP on port 80**.

1. **Set up the scale's Wi-Fi** (through the Fitbit app or the usual Aria pairing procedure).
2. **Redirect `www.fitbit.com` to your server** for the scale's network: a local DNS entry (router, Pi-hole, dnsmasq…) pointing that name to your server's address.
3. Step on the scale: it sends its weigh-ins to `/scale/upload` and receives the user list in return.
4. Check in `data/exchanges.log` that exchanges arrive and are answered (status 200).

Note: if you use a dedicated vhost (`balance.example.lan` above), it must be the one that `www.fitbit.com` resolves to on the scale's side (or add it as a `ServerAlias`).

---

## Using the web interface

| Page | Who | Content |
|---|---|---|
| `pesees.php` | Everyone | Weigh-ins; chart choice; "Other weigh-ins"; actions on weigh-ins |
| `compte.php` | Logged in | Password; scale profile |
| `admin.php` | Administrators | User management |

### Rights on weigh-ins

| Action | User | Administrator |
|---|---|---|
| See their own weigh-ins | ✔ | ✔ |
| See the guests' weigh-ins | ✔ (free access, even logged out) | ✔ |
| See other users' weigh-ins | ✘ | ✔ |
| Release one of their weigh-ins to the guests | ✔ | ✔ |
| Claim a guest weigh-in | ✔ | ✔ |
| Move a weigh-in to another owner | ✘ | ✔ |
| Delete a weigh-in | ✘ | ✔ |

### Updating the profile on the scale

A change to starting weight, height, birth year or gender (in "My account" or in the administration) is **sent to the scale at its next exchange**: just step on the scale once.

---

## Data storage

Everything is in `DATA_DIR`:

| File | Content |
|---|---|
| `users.json` | Users (ID, name, password hash, role, scale profile) and the ID counter |
| `user_<id>.csv` | A user's weigh-ins |
| `guests.csv` | Unassigned weigh-ins (guests) |
| `deleted_keys.txt` | Keys of deleted weigh-ins (so they are not recreated) |
| `exchanges.log` | Log of exchanges with the scale (JSON, one line per exchange) |
| `login_attempts.json` | Failed logins per IP |
| `.lock`, `.users.lock` | Write locks |
| `.htaccess` | `Require all denied` (if the folder is under the web root) |

### CSV format

Columns: `timestamp, datetime, aria_timestamp, weight_g, weight_kg, impedance, fat1, covariance, fat2`

- `timestamp`: real timestamp (corrected for clock drift); `datetime`: the same date in readable form.
- `aria_timestamp`: timestamp as the scale sent it.
- `weight_g` / `weight_kg`: weight in grams / in kg.
- `fat1`: body fat in **thousandths of a percent** (`27921` = 27.92 %).
- A weigh-in is identified by `aria_timestamp + '_' + weight_g`.

Writes are protected by a lock, and rewrites go through a temporary file followed by a rename (never a half-written file).

---

## How it works

### `POST /scale/upload` request

Little-endian body, ending with a 2-byte CRC-16 XMODEM:

- header: protocol (3), battery, MAC, authentication code (16 bytes), firmware, scale timestamp, number of measurements;
- then 32 bytes per measurement: `id2`, impedance, weight (g), timestamp, user ID, `fat1`, covariance, `fat2`.

### Server response

Current timestamp, unit (kg), status, number of users, then **77 bytes per user**: ID, 16 padding bytes, name (20 **uppercase** characters padded with spaces), minimum and maximum tolerance (**absolute bounds** in grams), age, gender (`0x02` male, `0x00` female), height (mm), and four weight/body fat/covariance/timestamp values (0 while `SEND_PREVIOUS_VALUES` is disabled). These are followed by the `0,3,0` trailer, the CRC-16, then the message size (`0x19 + 0x4d × number of users`). The `Content-Length` header is mandatory.

### Min/max range

For each user: `ref = last weigh-in rounded down to the kg` (or the starting weight if there is no weigh-in yet), `min = ref − 3 kg`, `max = ref + 3 kg`. If two users have weights too close to be told apart, a message is written to the PHP error log: the scale may then confuse them, and the server falls back to inference by weight proximity.

### Age

The scale receives an age; you only enter the **birth year**, and the age is recomputed automatically from the current year.

---

## Security

- **Change the `admin` password immediately** (the server enforces this at first login).
- **Serve the interface over HTTPS**: passwords travel in forms. Port 80 is only useful for the scale.
- Do not serve `aria.php`, `lib.php` or `webcore.php` on the name meant for people (`FilesMatch` rule), and do not expose the web scripts on the scale's name (`Require all denied` rule).
- Put `DATA_DIR` **outside the web root**: `exchanges.log` contains the scale's authentication code and the raw bytes of the exchanges.
- To restrict requests to your own scale, set `ALLOWED_MACS`.
- Temporary passwords are shown only once; pass them on through a safe channel.
- This application is meant for a **local network / home use**. Do not expose it as is on the Internet without an extra layer (VPN, reverse-proxy authentication, etc.).

---

## Backup and maintenance

- **Backup**: simply copy `DATA_DIR` (CSV files, `users.json`, `deleted_keys.txt`).
- **Restore**: put the folder back, with the web server's permissions.
- **Large log**: `exchanges.log` grows with every exchange; rotate it with `logrotate` or empty it from time to time.
- **Export**: the CSV files open directly in a spreadsheet or a script.
- **Deleting a user**: their `user_<id>.csv` file stays on the server but is no longer shown in the interface.

---

## Troubleshooting

| Symptom | What to check |
|---|---|
| Nothing arrives in `exchanges.log` | The scale cannot reach your server: check the scale's Wi-Fi, the DNS resolution of `www.fitbit.com`, that port 80 is open, and the scale's vhost |
| Status 400 in the log | Invalid CRC, protocol other than 3, or truncated body |
| Status 403 | `ALLOWED_MACS` does not contain your scale's address |
| All weigh-ins go to the guests | The user's starting weight or profile is too far from their real weight (± 3 kg range); update their starting weight, then step on the scale again |
| The new profile is not taken into account | It is only sent at the next exchange: step on the scale once |
| "Too many attempts" at login | The IP is blocked for 15 minutes; delete its entry in `login_attempts.json` to unblock sooner |
| Write error / blank page | The web server user lacks write permission on `DATA_DIR` |
| A deleted or moved weigh-in "comes back" | Should not happen (key remembered); check that `deleted_keys.txt` and the CSV files are writable |
| Body fat missing (shown as "–") | The scale did not provide a value for that weigh-in (`fat1` at 0); the "Body fat" chart needs at least 2 measurements |

---

## Known limitations

- Designed for **protocol V3 / firmware V35** only; other versions are rejected (400).
- `SEND_PREVIOUS_VALUES = true` has not been verified on a real scale: the reference servers send 0 and the scale still recognises the users.
- CSV storage suits family use (a few users, a few thousand weigh-ins); it is not meant for a large number of users.
- No "forgotten password" e-mail: an administrator resets the password from the administration page.

---

## Credits

- Protocol specification: [micolous/helvetic](https://github.com/micolous/helvetic).
