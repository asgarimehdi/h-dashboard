# Plan 027: Rewrite README (Bilingual EN/FA)

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

The current README is Persian-only, references a nonexistent `linux-composer.sh` script (steps 6-7), and doesn't link to the comprehensive setup docs (`install-guid.md`, `AGENTS.md`). It fails to serve the project's English-speaking contributors.

### Current Code (Issues)

**File:** `README.md:1-78`

```markdown
# H-Dashboard

&#x20; &#x20;

## معرفی
داشبورد مدیریت سلامت بر پایه لاراول لایووایر و مری یو و لیفلت ...
...
6. در لینوکس
   ```bash
   chmod +x linux-composer.sh
   ``` 
7. پیشنیاز لینوکس فرانکین و ...
   ```bash
   chmod +x linux-composer.sh
   ``` 
```

**Issues identified:**
1. Steps 6-7 reference `linux-composer.sh` which doesn't exist (`ls scripts/linux-composer.sh` would fail).
2. No English translation.
3. No link to `AGENTS.md` or `install-guid.md`.
4. Missing: Docker setup, test commands, contributing guidelines.
5. The `&#x20; &#x20;` at line 3 is an HTML-encoded whitespace artifact.

---

## Solution

Rewrite README as a bilingual document with English first, Persian below. Link to detailed docs.

### Changes

**File:** `README.md` (complete rewrite)

```markdown
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
- Todo management with recurring tasks
- HR dashboard with org charts and analytics
- Bilingual UI (RTL Persian/English)

### Tech Stack

| Component | Technology |
|-----------|-----------|
| Backend | Laravel 13, PHP 8.5 |
| Frontend | Livewire 4, MaryUI (DaisyUI 5), Tailwind CSS 4, Alpine.js |
| Database | PostgreSQL 16 (PostGIS) |
| Cache/Queue | Redis |
| Auth | Laravel Sanctum |
| Tests | Pest (928 passing) |
| Mobile | Flutter (Sanctum API tokens) |

### Quick Start

**Prerequisites:** Docker, PHP 8.5, Composer, Node.js 24+

```bash
# 1. Clone
git clone https://github.com/asgarimehdi/h-dashboard.git
cd h-dashboard

# 2. Start database
docker compose -f docker-compose-pgsql-.yml up -d

# 3. Install dependencies
composer install
npm install

# 4. Configure environment
cp .env.example .env
php artisan key:generate

# 5. Run migrations
php artisan migrate

# 6. Build frontend
npm run build

# 7. Start development server
php artisan serve
```

### Testing

```bash
# Run all tests (928 passing)
composer test

# Run specific test suite
composer test -- --filter=Hardware
```

### Contributing

See [AGENTS.md](AGENTS.md) for development guidelines and conventions.
See [docs/install-guid.md](docs/install-guid.md) for detailed installation instructions.

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

پیش‌نیاز: Docker، PHP 8.5، Composer، Node.js 24+

```bash
# ۱. کلون کردن مخزن
git clone https://github.com/asgarimehdi/h-dashboard.git
cd h-dashboard

# ۲. راه‌اندازی پایگاه داده
docker compose -f docker-compose-pgsql-.yml up -d

# ۳. نصب وابستگی‌ها
composer install
npm install

# ۴. تنظیم فایل محیطی
cp .env.example .env
php artisan key:generate

# ۵. اجرای مایگریشن‌ها
php artisan migrate

# ۶. بیلد فرانت‌اند
npm run build

# ۷. اجرای سرور توسعه
php artisan serve
```

### تست‌ها

```bash
composer test
```

### مشارکت

راهنمای توسعه: [AGENTS.md](AGENTS.md)
راهنمای نصب: [docs/install-guid.md](docs/install-guid.md)

---

## License

[MIT](LICENSE)
```

---

## Verification

1. **Render check:**
   - View `README.md` on GitHub (or locally with a Markdown previewer)
   - Verify both English and Persian sections render correctly
   - Verify all links work: `AGENTS.md`, `docs/install-guid.md`, `LICENSE`

2. **No broken references:**
   ```bash
   grep -n 'linux-composer' README.md
   ```
   Expected: 0 matches.

3. **Link validation:**
   ```bash
   test -f AGENTS.md && echo "EXISTS" || echo "MISSING"
   test -f docs/install-guid.md && echo "EXISTS" || echo "MISSING"
   test -f LICENSE && echo "EXISTS" || echo "MISSING"
   ```

---

## STOP Conditions

- If `docs/install-guid.md` doesn't exist, check `install-guid.md` in project root or adjust the link.
- If `LICENSE` file doesn't exist, remove the license link.

---

## Out of Scope

- Translating `AGENTS.md` to Persian.
- Adding screenshots/GIFs to README.
- Creating a CONTRIBUTING.md (use AGENTS.md instead).
- Adding badges for PHPStan/Pint/coverage.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `grep -n 'linux-composer' README.md` | 0 matches |
| 2 | `test -f AGENTS.md` | EXISTS |
| 3 | `test -f docs/install-guid.md` | EXISTS (or adjust link) |
| 4 | GitHub render preview | Both sections display correctly |
| 5 | All markdown links clickable | No 404s |

---

## Maintenance Notes

- **Language order:** English first for international accessibility; Persian second for the primary team.
- **Badge:** The tests badge URL should be updated if the repo URL changes.
- **Docker compose file:** The README references `docker-compose-pgsql-.yml` — verify this filename is correct in the repo.
- **Keep it short:** Detailed setup goes in `docs/install-guid.md`. README is the entry point.
