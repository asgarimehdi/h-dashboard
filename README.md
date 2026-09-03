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
| Tests | Pest |
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
