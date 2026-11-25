# Installation Guide — Health Dashboard (Nginx + PHP-FPM)

Full setup guide for deploying **h-dashboard** (Laravel 13 + Livewire 4) on a bare
Ubuntu server with native **nginx** and **PHP-FPM**. PostgreSQL (PostGIS) and Redis
run in Docker via the bundled compose file — only web/PHP run on the host.

Tested on: Ubuntu with PHP 8.5.x, nginx 1.2x, Docker Compose v2.

---

## 1. Requirements

| Component | Version |
|---|---|
| OS | Ubuntu 22.04 / 24.04 / newer |
| PHP | ^8.4.1 (tested on 8.5.x) |
| Composer | 2.x |
| Node + npm | Node 24+ (builds Vite assets only) |
| PostgreSQL | 16 **with PostGIS 3.4** (via Docker) |
| Redis | recent, password-protected (via Docker) |

> **Node 20 is not the reference version.** CI never runs it — every pinned `node-version` in this repo is `'24'` — and no `engines` field, `.nvmrc`, or other pin in the repo supports Node 20. The reference version is Node 24, the one CI pins (`node-version: '24'`).

---

## 2. Install system packages

On Ubuntu releases where PHP 8.5 is not in the default repos, add the Ondrej PPA:

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update
```

> On very new Ubuntu releases `php8.5` may already be in `universe` — skip the PPA.

Install nginx + FPM + all extensions the app needs:

```bash
sudo apt-get install -y \
    nginx \
    php8.5-fpm php8.5-cli \
    php8.5-common php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip \
    php8.5-gd php8.5-intl php8.5-bcmath php8.5-opcache \
    php8.5-pgsql php8.5-redis \
    unzip git curl
```

> **Do not forget `php8.5-gd` and `php8.5-redis`.**
> - `gd` — hard requirement of `phpoffice/phpspreadsheet` (Excel import/export);
>   composer will refuse to resolve dependencies without it.
> - `redis` — the app uses the `phpredis` client by default
>   (`config/database.php` → `REDIS_CLIENT=phpredis`). Without it Laravel
>   cannot reach Redis at all (cache/session/queue all fail).

Verify:

```bash
php -v                                # expect 8.5.x
php -m | grep -E '^(gd|redis|pdo_pgsql|mbstring|zip)$'
```

---

## 3. Get the code

Use a dedicated service account (substitute your own username throughout):

```bash
sudo adduser --disabled-password --gecos "" dashboard
sudo usermod -aG docker dashboard     # only if it should manage compose
sudo su - dashboard
git clone https://github.com/asgarimehdi/h-dashboard.git h-dashboard
cd h-dashboard
git checkout beta
```

---

## 4. Install dependencies

### PHP (Composer)

```bash
# if composer is missing:
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

composer install --no-dev --optimize-autoloader
```

### Frontend assets

```bash
npm ci          # or: npm install
npm run build   # produces public/build/manifest.json — REQUIRED
```

> If `public/build/manifest.json` is missing, every page returns
> **500 — Vite manifest not found**. Always build after deploys.

---

## 5. Database + Redis (Docker)

The repo ships `docker-compose-pgsql-.yml` (PostGIS 16-3.4, Redis, pgAdmin,
phpRedisAdmin):

```bash
docker compose -f docker-compose-pgsql-.yml up -d
docker ps     # wait until h-dashboard-postgis shows (healthy)
```

> **Postgres password gotcha:** `POSTGRES_PASSWORD` only applies on FIRST init
> of the `postgis_data` volume. To change it on an existing volume:
>
> ```bash
> docker exec -it h-dashboard-postgis psql -U <user> -d <db> \
>   -c "ALTER USER <user> WITH PASSWORD '<new>';"
> ```

---

## 6. Configure the environment

```bash
cp .env.example.pgsql .env
nano .env
```

Key values:

```ini
APP_NAME=h-dashboard
APP_ENV=production
APP_DEBUG=false
APP_URL=http://your-host.example.com        # canonical public URL
CSP_ENFORCING=false                          # flip to true at go-live — see §13

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=h_dashboard
DB_USERNAME=...
DB_PASSWORD=...

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=<password from compose env>

DB_DEFAULT_EMAIL=...      # seeded admin login
DB_DEFAULT_PASSWORD=...

ZABBIX_URL=http://127.0.0.1:8443/api_jsonrpc.php
ZABBIX_TOKEN=...
ZABBIX_OUT_ITEM_ID=...    # Zabbix itemid — outbound interface
ZABBIX_IN_ITEM_ID=...     # Zabbix itemid — inbound interface
# Map tiles: only TILE_URL_TEMPLATE has any effect (TILE_SERVER_* were retired in #1508).
TILE_URL_TEMPLATE=https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png
```

`ZABBIX_OUT_ITEM_ID` / `ZABBIX_IN_ITEM_ID` feed the 5-minute traffic sync; with
either unset the sync records a failed run and the dashboard sync banner flags
it. The `/it/networks` and `/it/wireless` widgets read the item IDs registered
in `zabbix_devices` instead (#1621).

`APP_URL` is the canonical public URL — it no longer has to equal the host the
browser uses. The GIS map and the Zabbix charts call `/api/*` with the session
cookie, and Sanctum always trusts the incoming request host plus the `APP_URL`
host (`config/sanctum.php`, #1532); `SANCTUM_STATEFUL_DOMAINS` only adds extra
origins. A deployment served from `http://sad1:8000` with a different `APP_URL`
needs no cookie-domain change — after deploying, run `php artisan config:clear`.

`SESSION_SECURE_COOKIE` is deliberately absent from this block — leave it
**unset**: `Secure` is then derived from the request scheme, which is correct on
the plain-`http` nginx block in §8 and on HTTPS alike. Force
`SESSION_SECURE_COOKIE=true` **only** when TLS terminates at a proxy that is not
pinned in `TRUSTED_PROXIES` (#855); forced on a plain-`http` deployment it
reproduces the #1003 419 "Page Expired" login wall. The full rationale sits
beside the key in `.env.example.pgsql` — read it there instead of duplicating it
here. It is checked again at go-live in §13.4.

Initialize the app:

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force
```

---

## 7. Storage permissions

`storage/` and `bootstrap/cache/` must be writable by whoever runs BOTH artisan
(CLI) and PHP-FPM.

**Recommended (single-app server): run PHP-FPM as the code owner** (see §9).
Then plain ownership is enough:

```bash
chown -R dashboard:dashboard storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod g+s {} +   # setgid: future files inherit group
```

**Alternative (keep `www-data`):** give both users permanent access with POSIX
ACLs — default ACLs apply to every future file regardless of umask:

```bash
sudo apt-get install -y acl
sudo setfacl -R   -m u:dashboard:rwx,u:www-data:rwX storage bootstrap/cache
sudo setfacl -R -d -m u:dashboard:rwx,u:www-data:rwX storage bootstrap/cache
```

> **Warning:** mixing chown/chmod rounds WITHOUT ACLs keeps breaking — whoever
> writes last owns the compiled-view cache and locks the other side out.
> Classic symptom: `tempnam(): file created in the system's temporary directory`
> → HTTP 500 on the first authenticated page (Livewire compiles views on demand).

---

## 8. Nginx site

Create `/etc/nginx/sites-available/h-dashboard.conf`:

```nginx
server {
    listen 80;
    server_name your-host.example.com 192.168.x.x;

    # Excel/CSV imports can be large — otherwise uploads fail with 413
    client_max_body_size 64M;

    root /home/dashboard/h-dashboard/public;   # adjust to your path
    index index.php;

    charset utf-8;

    # Compression (#1181). font/woff2 is deliberately absent from gzip_types:
    # woff2 is already compressed, so gzipping it only burns CPU.
    gzip on;
    gzip_vary on;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types
        text/plain text/css text/xml text/javascript
        application/javascript application/json application/xml
        image/svg+xml;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # never serve dotfiles
    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Static assets (#1181).
    #
    # `add_header` does NOT inherit: a location that declares its own
    # `add_header` discards every `add_header` of the enclosing server block.
    # Each location below therefore repeats `X-Content-Type-Options: nosniff` —
    # without that repetition the static assets would ship without it, and the
    # PHP SecurityHeaders middleware cannot cover them because they never pass
    # through PHP.
    #
    # `X-Frame-Options` is deliberately NOT added here or at server level
    # (#1571). It is owned by the SecurityHeaders middleware on web responses,
    # and nginx `add_header` APPENDS rather than replaces: adding it here would
    # ship a duplicate (`DENY` from the app, `SAMEORIGIN` from nginx) on every
    # page the middleware covers.
    #
    # Vite emits CONTENT-HASHED filenames under /build/assets, so the content
    # never changes under the same URL: cache it for a year and never
    # revalidate. `expires` is deliberately absent here — combined with an
    # `add_header Cache-Control` it would emit TWO Cache-Control headers.
    location ^~ /build/assets/ {
        add_header Cache-Control "public, max-age=31536000, immutable" always;
        add_header X-Content-Type-Options nosniff;
    }

    # These paths are NOT hashed: a deploy can replace their content under the
    # same URL, so they get a short TTL and NO `immutable` — a stale copy must
    # not outlive the next deploy. Kept after the two regex locations above
    # because nginx tries regex locations in the order they appear.
    location ~* ^/(?:js|css|icons|fonts)/ {
        expires 1d;
        add_header X-Content-Type-Options nosniff;
    }

    add_header X-Content-Type-Options nosniff;
}
```

> **The compression and cache headers above only take effect once the operator
> reloads nginx** (`nginx -s reload`, or `sudo systemctl reload nginx` as in
> the next step). Editing the file changes nothing on a running server — until
> the reload, nginx keeps serving the previous configuration.

Enable and reload:

```bash
sudo ln -sfn /etc/nginx/sites-available/h-dashboard.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default      # drop the welcome page
sudo nginx -t && sudo systemctl reload nginx
```

If the docroot lives under `/home/<user>`, nginx must be able to traverse it:

```bash
chmod o+x /home/dashboard
```

---

## 9. PHP-FPM pool

Edit `/etc/php/8.5/fpm/pool.d/www.conf`.

**Option A — single-purpose server (recommended): workers run as the app user**

```ini
user = dashboard
group = dashboard
listen = /run/php/php8.5-fpm.sock
listen.owner = www-data          ; socket must stay readable by nginx
listen.group = www-data
```

With A there is exactly one owner of `storage/` — CLI and web can never lock
each other out.

**Option B — shared server: keep `www-data`**

Leave `user/group = www-data`, but then you MUST use the ACL setup from §7 and
run artisan cache commands consistently (ideally `sudo -u www-data php artisan ...`).

Apply and verify:

```bash
sudo systemctl restart php8.5-fpm nginx
ps -o user= -C php-fpm8.5 | sort | uniq -c   # worker user should be "dashboard"
```

---

## 10. Production caches

```bash
php artisan optimize     # config + routes + views + events
```

Re-run after every deploy. After changing `.env`:
`php artisan config:clear && php artisan optimize`.

---

## 11. Queue workers + scheduler

The app pins its long-running jobs to their own queue connections
(`#[Connection('redis-maintenance')]` and `#[Connection('redis-reports')]` in
`app/Jobs/`), and each of those connections pushes onto its own Redis list
(`queues:maintenance`, `queues:reports`) — the default worker never reads them.
**All three connections need a worker**: without the second and third, retention
and the daily reports are pushed and then never executed.

`--timeout` is only the fallback for a job that does not declare its own
`$timeout` (Laravel always prefers the job's property). Keep it **below** the
connection's `retry_after`, because Redis reserves a job for `retry_after`
seconds: if that window closes while the first attempt is still running, a
second worker picks the same job up and the work runs twice.

| Service | Connection | Serves | `retry_after` | `--timeout` |
|---|---|---|---|---|
| `h-dashboard-queue` | `redis` | Zabbix sync, notifications | 90s | 60s |
| `h-dashboard-queue-maintenance` | `redis-maintenance` | activity-log + notification retention | 360s | 300s |
| `h-dashboard-queue-reports` | `redis-reports` | daily reports | 660s | 600s |

Create `/etc/systemd/system/h-dashboard-queue.service`:

```ini
[Unit]
Description=h-dashboard queue worker
After=network.target docker.service

[Service]
User=dashboard
Restart=always
RestartSec=3
WorkingDirectory=/home/dashboard/h-dashboard
ExecStart=/usr/bin/php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --timeout=60

[Install]
WantedBy=multi-user.target
```

Create `/etc/systemd/system/h-dashboard-queue-maintenance.service`:

```ini
[Unit]
Description=h-dashboard retention queue worker
After=network.target docker.service

[Service]
User=dashboard
Restart=always
RestartSec=3
WorkingDirectory=/home/dashboard/h-dashboard
ExecStart=/usr/bin/php artisan queue:work redis-maintenance --sleep=3 --tries=3 --max-time=3600 --timeout=300

[Install]
WantedBy=multi-user.target
```

Create `/etc/systemd/system/h-dashboard-queue-reports.service`:

```ini
[Unit]
Description=h-dashboard reports queue worker
After=network.target docker.service

[Service]
User=dashboard
Restart=always
RestartSec=3
WorkingDirectory=/home/dashboard/h-dashboard
ExecStart=/usr/bin/php artisan queue:work redis-reports --sleep=3 --tries=3 --max-time=3600 --timeout=600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now h-dashboard-queue h-dashboard-queue-maintenance h-dashboard-queue-reports
```

Scheduler cron (`crontab -e` as the app user) — runs recurring todos, due
maintenance, archives, daily reports, Zabbix sync:

```cron
* * * * * cd /home/dashboard/h-dashboard && php artisan schedule:run >> /dev/null 2>&1
```

---

## 12. Verify

```bash
curl -I http://localhost/            # expect 302 -> /login (auth guard)
curl -I http://localhost/login       # expect 200
curl -I http://localhost/build/manifest.json   # expect 200
tail -f storage/logs/laravel.log     # watch for errors while you log in via browser
systemctl status nginx php8.5-fpm h-dashboard-queue h-dashboard-queue-maintenance h-dashboard-queue-reports
docker ps                            # postgis healthy, redis up
```

Then log in through a browser with the seeded admin (`DB_DEFAULT_EMAIL` /
`DB_DEFAULT_PASSWORD`) and open a few pages — especially `/hr-dashboard`, which
exercises Livewire view compilation.

---

## 13. Go-live checklist (production)

An install that follows this guide arrives in production with
`CSP_ENFORCING=false` — the template default — so the app ships
`Content-Security-Policy-Report-Only`, which browsers **report but never block**.
Everything in #1281 and #1352 (the switch, the nonce, the CSP-safe build, the CI
gate) only becomes real protection after this section. Finish it in exactly one
of two ways: enforce at handoff (13.1 → 13.3), or take the dated deferral in
"Choosing your exit". A deployment that leaves Report-Only on with neither is the
failure this section exists to prevent.

### 13.1 Report-only soak — before handoff (minutes, not weeks)

Log in and walk the pages the CI gate covers — `/login`, `/dashboard`, `/map`,
`/maps/route2`, `/hr-dashboard`, `/tickets/inbox`, plus `/todo`,
`/reports/tickets`, `/units` and `/settings` — then read the report backlog:

```bash
grep 'csp-report' storage/logs/laravel.log | tail -n 50
```

**Blocking** is a `script-src` / `connect-src` / `default-src` violation on a
**first-party** asset. Ignore `chrome-extension://`, `moz-extension://`, and
third-party analytics you do not control. Treat the log as an early signal, not
proof: the policy advertises `report-to` without `report-uri` (#742), which
Firefox and Safari do not implement — on those browsers the console walk in
13.3 is the only real proof.

Over HTTPS, also confirm the session cookie carries `Secure`:

```bash
curl -sI https://<host>/login | grep -i '^set-cookie:'
```

### 13.2 Flip

```bash
# in .env
CSP_ENFORCING=true

# then rebuild the cached config (§10)
php artisan config:clear && php artisan optimize
```

Without the cache rebuild the new value never reaches the running app and the
flip silently does nothing.

### 13.3 Verify

```bash
curl -sI http://<host>/login | grep -i '^content-security-policy:'
```

It must print `content-security-policy:` — **never**
`content-security-policy-report-only:`. `Reporting-Endpoints` must still be
present (the middleware emits it in both modes). Then repeat the 13.1 walk with
the browser devtools console open: zero `Content-Security-Policy` errors.

> The check has to hit a **200 web route**. `curl -I http://<host>/` proves
> nothing: `/` answers with the auth 302, which is produced *before*
> `SecurityHeaders` runs and carries none of the six headers (see the table in
> `docs/agents/api-auth.md:76`).

**Rollback:** set `CSP_ENFORCING=false` and re-run
`php artisan config:clear && php artisan optimize` — no web-server restart is
involved.

### 13.4 HTTPS / session cookie

`SESSION_SECURE_COOKIE` stays **unset**, so `Secure` is derived from the request
scheme — safe on every server, including the plain-`http` nginx block in §8.
Force `SESSION_SECURE_COOKIE=true` **only** when TLS terminates at a proxy that
is not pinned in `TRUSTED_PROXIES` (#855). Forced on a plain-`http` deployment it
reproduces the #1003 419 "Page Expired" login wall. The reasoning lives beside
the key in `.env.example.pgsql`; do not copy it into a deployment unread.

### 13.5 Debug

Confirm `APP_DEBUG=false` survived any local debugging. It is the template
default (§6), and a deployment left on `true` leaks stack traces on every error.

### Choosing your exit

- **Enforce at handoff:** 13.1 clean → 13.2 → 13.3 clean. Done.
- **Dated deferral:** if you cannot enforce at handoff, record in YOUR
  deployment's ops log: the reason, the owner, and the date you will re-run
  13.1–13.3 — then re-run them at each release. Leaving it undated is the
  failure this section exists to prevent.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Every page: `Vite manifest not found` | Frontend not built | `npm ci && npm run build` |
| 500 after login; log shows `tempnam(): file created in the system's temporary directory` | Compiled-view cache not writable by FPM user | §7 permissions (owner match or ACLs), then `php artisan view:clear` |
| Composer refuses: `ext-gd ... missing from your system` | GD extension absent for CLI | `apt-get install php8.5-gd` |
| Redis errors / `NOAUTH Authentication required` | Missing ext or wrong password | Install `php8.5-redis`; align `.env REDIS_PASSWORD` with compose |
| Upload of Excel fails with 413 | Body size limit | `client_max_body_size 64M;` in vhost |
| Pages show stale config after `.env` change | Config cached by `optimize` | `php artisan config:clear && php artisan optimize` |
| Livewire Entangle error in console: property cannot be found | Blade wires `wireModel="showHelpModal"` but component lacks it | Add `public bool $showHelpModal = false;` to the page's class/SFC |
| Dashboard banner: `sync زیبکس ناموفق — داده ترافیک در دسترس نیست` | `zabbix_sync_logs.error` = `Zabbix item IDs not configured` | Set `ZABBIX_OUT_ITEM_ID` / `ZABBIX_IN_ITEM_ID` in `.env`, then `php artisan config:clear` |

---

## Appendix: running the test suite (optional)

Tests need a separate Postgres DB + password-less-ish Redis per `phpunit.xml`.
See `AGENTS.md` → "Running Tests (Pest)" for the full procedure
(`php artisan config:clear`, then `REDIS_PASSWORD=<real> ./vendor/bin/pest --no-coverage`).

Note: without `xdebug` installed, run Pest with `--no-coverage`; exporting
`XDEBUG_MODE=coverage` without xdebug present makes the runner exit silently.

