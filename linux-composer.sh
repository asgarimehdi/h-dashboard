#!/bin/bash

echo "🛡️ جلوگیری از ردیابی composer.json توسط Git..."
git update-index --assume-unchanged composer.json

echo "📁 ساخت فایل موقتی composer برای سرور لینوکس..."
cp composer.json composer.temp.json

echo "➕ افزودن laravel/octane به صورت دستی..."
jq '.require["laravel/octane"] = "^2.8"' composer.temp.json > composer.linux.json

echo "📦 نصب پکیج‌ها با composer.linux.json (موقتی)..."
COMPOSER=composer.linux.json composer install --no-dev -o -n --ignore-platform-reqs

echo "🧹 تمیزکاری فایل‌های موقتی..."
rm composer.linux.json
rm composer.temp.json

echo "✅ نصب کامل شد بدون تغییر composer.json اصلی"
