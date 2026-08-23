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
| PHP | ^8.3 (tested on 8.5.x) |
| Composer | 2.x |
| Node + npm | Node 20+ (builds Vite assets only) |
| PostgreSQL | 16 **with PostGIS 3.4** (via Docker) |
| Redis | recent, password-protected (via Docker) |

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
git clone https://github.com/Shabakebehdasht/h-dashboard.git h-dashboard
cd h-dashboard
git checkout dev                      # or main / beta
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
TILE_SERVER_IP=tile.openstreetmap.org
```

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

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options SAMEORIGIN;
}
```

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

## 11. Queue worker + scheduler

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
ExecStart=/usr/bin/php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now h-dashboard-queue
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
systemctl status nginx php8.5-fpm h-dashboard-queue
docker ps                            # postgis healthy, redis up
```

Then log in through a browser with the seeded admin (`DB_DEFAULT_EMAIL` /
`DB_DEFAULT_PASSWORD`) and open a few pages — especially `/hr-dashboard`, which
exercises Livewire view compilation.

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

---

## Appendix: running the test suite (optional)

Tests need a separate Postgres DB + password-less-ish Redis per `phpunit.xml`.
See `AGENTS.md` → "Running Tests (Pest)" for the full procedure
(`php artisan config:clear`, then `REDIS_PASSWORD=<real> ./vendor/bin/pest --no-coverage`).

Note: without `xdebug` installed, run Pest with `--no-coverage`; exporting
`XDEBUG_MODE=coverage` without xdebug present makes the runner exit silently.

