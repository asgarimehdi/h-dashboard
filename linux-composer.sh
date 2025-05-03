#!/bin/bash

# بررسی سیستم‌عامل
if [[ "$(uname)" == "Linux" ]]; then
  echo "این اسکریپت فقط در لینوکس اجرا می‌شود..."

  # مراحل مربوط به لینوکس
  echo "🔃 [1/8] Pull کردن آخرین تغییرات از گیت..."
  git pull origin $(git rev-parse --abbrev-ref HEAD)

  # حذف جلوگیری از ردیابی تغییرات composer.json توسط Git
  echo "🛡️ [2/8] حذف جلوگیری از ردیابی تغییرات composer.json توسط Git..."
  git update-index --no-assume-unchanged composer.json

  echo "📁 [3/8] ساخت نسخه موقتی از composer.json..."
  cp composer.json composer.temp.json
  jq '.require["laravel/octane"] = "^2.8"' composer.temp.json > composer.linux.json

  echo "📦 [4/8] اجرای composer update روی نسخه موقتی..."
  COMPOSER=composer.linux.json composer update --no-dev -o -n --ignore-platform-reqs

  echo "🧹 [5/8] حذف فایل‌های موقتی composer..."
  rm composer.linux.json
  rm composer.temp.json

  echo "📦 [6/8] اجرای npm update برای بروزرسانی پکیج‌های JS..."
  npm update

  echo "🧱 [7/8] ساخت فایل‌های front-end با npm run build..."
  npm run build

  echo "⚙️ [8/8] اجرای artisan optimize..."
  php artisan optimize

  echo "✅ همه چیز با موفقیت بروزرسانی و بهینه شد!"
else
  echo "❌ این اسکریپت فقط در لینوکس اجرا می‌شود. در ویندوز اجرا نخواهد شد."
fi
