#!/usr/bin/env bash
#
# نشر أو تحديث — يُشغّل على السيرفر
#
#   bash deploy/deploy.sh           تحديث من GitHub
#   bash deploy/deploy.sh --fresh   أول نشر
#
set -euo pipefail

APP_DIR="/var/www/nalut"
cd "$APP_DIR"

FRESH=false
[[ "${1:-}" == "--fresh" ]] && FRESH=true

if ! $FRESH; then
  echo "==> سحب آخر نسخة من GitHub"
  git fetch origin
  git reset --hard origin/main
fi

[[ -f .env ]] || { echo "ملف .env مفقود — ارفعه أولاً"; exit 1; }

echo "==> وضع الصيانة"
php artisan down || true

echo "==> حزم Composer"
composer install --no-dev --optimize-autoloader --no-interaction

if $FRESH; then
  grep -q "APP_KEY=base64" .env || php artisan key:generate --force
  php artisan storage:link || true
fi

echo "==> الهجرات"
php artisan migrate --force

echo "==> الصلاحيات"
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "==> بناء الكاش"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> إعادة تشغيل الخدمات"
systemctl restart php8.3-fpm
systemctl enable --now nalut-scheduler
systemctl enable --now nalut-queue
systemctl restart nalut-scheduler nalut-queue

php artisan up

echo
echo "تم النشر."
echo "ملاحظة: مواقع السائقين في الكاش وتنمسح مع optimize:clear —"
echo "        ترجع لوحدها خلال 8 ثواني من تطبيقات السائقين الشغّالة."
