# H-Dashboard

&#x20; &#x20;

## معرفی

داشبورد مدیریت سلامت بر پایه لاراول لایووایر و مری یو و لیفلت که اطلاعات مدیریتی روی نقشه و چارت ارائه میدهد
## ویژگی‌ها

- مدیریت کاربران با سطوح دسترسی مختلف
- مدیریت شهرستان‌ها و واحدها با قابلیت سیدینگ
- طراحی واکنشگرا برای نمایش در دستگاه‌های مختلف
- نقشه تعاملی و متصل به پایگاه داده

## نصب و راه‌اندازی

1. کلون کردن مخزن:

   ```bash
   git clone https://github.com/asgarimehdi/h-dashboard.git
   ```

2. نصب وابستگی‌ها:

   ```bash
   cd h-dashboard
   composer install
   npm install
   ```

3. تنظیم فایل محیطی:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. مایگریشن و سید:

   ```bash
   php artisan migrate --seed
   ```

5. راه‌اندازی سرور:

   ```bash
   php artisan serve
   ```


## مشارکت

1. Fork کردن مخزن
2. ساخت شاخه جدید:
   ```bash
   git checkout -b feature/YourFeature
   ```
3. کامیت تغییرات:
   ```bash
   git commit -m "Add some feature"
   ```
4. Push کردن به شاخه:
   ```bash
   git push origin feature/YourFeature
   ```
5. ایجاد Pull Request

## مجوز

این پروژه تحت مجوز [MIT](LICENSE) منتشر شده است.

