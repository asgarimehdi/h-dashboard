# Health Dashboard (داشبورد سلامت)

> 🏥 Hospital/healthcare center hardware inventory, organizational units, tickets, and analytics dashboard.

[![Tests](https://github.com/asgarimehdi/h-dashboard/actions/workflows/test.yml/badge.svg)](https://github.com/asgarimehdi/h-dashboard/actions/workflows/test.yml)

## English

### Overview

Health Dashboard is a Laravel application for managing hospital/healthcare center hardware inventory, organizational units, tickets, and todos. Built with Livewire, MaryUI, and Tailwind CSS.

**Key features:**
- User management with role-based access (Spatie)
- Hardware inventory tracking with audit history
- Interactive GIS map with PostGIS spatial queries
- Ticket system with assignment and workflow
- Todo management
- HR dashboard with org charts and analytics
- Bilingual UI (RTL Persian/English)

### Tech Stack

| Component | Technology |
|-----------|-----------|
| Backend | Laravel 13, PHP 8.4 |
| Frontend | Livewire 4, MaryUI (DaisyUI 5), Tailwind CSS 4, Alpine.js |
| Database | PostgreSQL 16 (PostGIS) |
| Cache/Queue | Redis |
| Auth | Laravel Sanctum |
| Tests | Pest |
| Mobile | Flutter (Sanctum API tokens) |

### Quick Start

**Prerequisites:** Docker, Composer, Node.js 24+, and PHP 8.4+ (CI and
`install-guid.md` §2 use 8.5) with `mbstring`, `xml` (dom), `curl`, `zip` and
`gd` — `composer install` refuses to resolve dependencies without them (`gd` is
phpspreadsheet's hard requirement) — plus `pdo_pgsql` and `redis` (phpredis) at
runtime: without `redis` the app cannot reach Redis at all (cache, session,
queue). The full system-package list, including nginx/FPM/OPcache for a
production host, is in [`install-guid.md` §2](install-guid.md).

```bash
# 1. Clone
git clone https://github.com/asgarimehdi/h-dashboard.git
cd h-dashboard

# 2. Install dependencies
composer install
npm install

# 3. Configure environment — MUST come before `docker compose up`
#    docker-compose-pgsql-.yml interpolates ${DB_DATABASE}, ${DB_USERNAME},
#    ${DB_PASSWORD} and ${REDIS_PASSWORD} from this file. With no `.env` they
#    all resolve to "" and the PostGIS container refuses to initialise:
#    "You must specify POSTGRES_PASSWORD to a non-empty value for the superuser"
cp .env.example .env

#    Then edit .env: the template ships DB_PASSWORD blank, so set it to any
#    non-empty value and keep REDIS_PASSWORD in sync with it.
php artisan key:generate

# 4. Start database + Redis
docker compose -f docker-compose-pgsql-.yml up -d

# 5. Migrate and seed — `--seed` is NOT optional. Roles and the login users
#    come from the seeders; `migrate` alone leaves 0 users and 0 roles, so
#    login is impossible.
php artisan migrate --seed

# 6. Build frontend
npm run build

# 7. Start the app: server + Vite + ONE queue worker on the default `redis`
#    queue. `php artisan serve` alone starts no worker at all — queued jobs
#    then never run.
composer dev
```

```bash
# 8. Start the rest of the background work — each command in its own terminal.
#    Nothing below fails loudly if skipped: the work is queued and never run.
#    - no scheduler      → Zabbix sync, `reports:generate-daily` and the
#                          nightly archives never run
#    - no redis-reports  → confirmed hardware/person imports
#                          (`ImportHardwareJob`, `ImportPersonsJob`) stay in
#                          `queues:reports`
#    - no redis-maintenance → the Tools page retention jobs
#                          (`ArchiveActivityLogsJob`, `CleanNotificationsJob`)
#                          stay in `queues:maintenance`
php artisan schedule:work
php artisan queue:work redis-reports
php artisan queue:work redis-maintenance

#    Production uses the systemd units and a `schedule:run` cron instead of
#    `schedule:work` — see install-guid.md §11.
```

### Testing

```bash
# Run all tests
composer test

# Run specific test suite
composer test -- --filter=Hardware
```

### Contributing

See [AGENTS.md](AGENTS.md) for development guidelines and conventions.

```bash
# Format code before committing
vendor/bin/pint --dirty --format agent

# Run tests
composer test
```

---

## فارسی (Persian)

### معرفی

داشبورد مدیریت سلامت بر پایه لاراول لایووایر و مری یو و لیفلت که اطلاعات مدیریتی روی نقشه و چارت ارائه میدهد.

**ویژگی‌ها:**
- مدیریت کاربران با سطوح دسترسی مختلف
- مدیریت شهرستان‌ها و واحدها با قابلیت سیدینگ
- طراحی واکنشگرا برای نمایش در دستگاه‌های مختلف
- نقشه تعاملی و متصل به پایگاه داده

### نصب و راه‌اندازی

پیش‌نیاز: Docker، Composer، Node.js 24+ و PHP 8.4+ (CI و `install-guid.md` §2 از
8.5 استفاده می‌کنند) با اکستنشن‌های `mbstring`، `xml` (dom)، `curl`، `zip` و
`gd` — بدون این‌ها `composer install` وابستگی‌ها را resolve نمی‌کند (`gd` نیازِ
سختِ phpspreadsheet است) — به‌علاوهٔ `pdo_pgsql` و `redis` (phpredis) در زمان
اجرا: بدون `redis` اپ اصلاً به Redis دسترسی ندارد (cache، session و queue).
فهرست کامل بسته‌های سیستمی، شامل nginx/FPM/OPcache برای سرور production:
[`install-guid.md` §2](install-guid.md).

```bash
# ۱. کلون کردن مخزن
git clone https://github.com/asgarimehdi/h-dashboard.git
cd h-dashboard

# ۲. نصب وابستگی‌ها
composer install
npm install

# ۳. تنظیم فایل محیطی — حتما پیش از `docker compose up`
#    فایل compose مقدارهای ${DB_DATABASE} و ${DB_USERNAME} و ${DB_PASSWORD} و
#    ${REDIS_PASSWORD} را از همین فایل می‌خواند؛ بدون `.env` همه خالی می‌شوند و
#    کانتینر PostGIS راه‌اندازی نمی‌شود
#    («You must specify POSTGRES_PASSWORD to a non-empty value for the superuser»)
cp .env.example .env

#    سپس در `.env` مقدار `DB_PASSWORD` را (برخلاف قالب، که خالی است) غیرخالی
#    کنید و `REDIS_PASSWORD` را با همان مقدار هماهنگ نگه دارید.
php artisan key:generate

# ۴. راه‌اندازی پایگاه داده و Redis
docker compose -f docker-compose-pgsql-.yml up -d

# ۵. اجرای مایگریشن‌ها همراه با seed — پرچم `--seed` اختیاری نیست. نقش‌ها و
#    کاربران ورود از سیدرها می‌آیند؛ `migrate` تنها صفر کاربر و صفر نقش
#    می‌سازد و ورود غیرممکن می‌شود.
php artisan migrate --seed

# ۶. بیلد فرانت‌اند
npm run build

# ۷. اجرای اپ: سرور + Vite + یک worker روی صف پیش‌فرض `redis`.
#    `php artisan serve` تنها هیچ worker‌ای بالا نمی‌آورد و کارهای صف‌شده
#    هرگز اجرا نمی‌شوند.
composer dev
```

```bash
# ۸. بقیهٔ کارهای پس‌زمینه — هر دستور در یک ترمینال جدا. اگر اجرا نشوند هیچ
#    خطایی دیده نمی‌شود؛ کار در صف می‌ماند:
#    - بدون scheduler: سینک Zabbix، `reports:generate-daily` و آرشیوهای شبانه
#    - بدون worker `redis-reports`: ایمپورت‌های تأییدشدهٔ سخت‌افزار/پرسنل
#      (`ImportHardwareJob`، `ImportPersonsJob`) در `queues:reports`
#    - بدون worker `redis-maintenance`: کارهای نگهداری صفحهٔ ابزارها در
#      `queues:maintenance`
php artisan schedule:work
php artisan queue:work redis-reports
php artisan queue:work redis-maintenance

#    در production به‌جای `schedule:work` از unit‌های systemd و cron
#    `schedule:run` استفاده می‌شود — install-guid.md §11.
```

### تست‌ها

```bash
composer test
```

### مشارکت

راهنمای توسعه: [AGENTS.md](AGENTS.md)
