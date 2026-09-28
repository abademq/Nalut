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

echo "==> مزامنة النصوص"
php artisan texts:sync

# موقع الطلب: مكتبة الخريطة على السيرفر نفسه (أسرع من CDN) — ما يوقفش النشر لو فشل
if [[ ! -f public/weborder/leaflet/leaflet.js ]]; then
  echo "==> مكتبة الخريطة لموقع الطلب"
  mkdir -p public/weborder/leaflet
  curl -fsSL https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js -o public/weborder/leaflet/leaflet.js \
    && curl -fsSL https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css -o public/weborder/leaflet/leaflet.css \
    || { rm -f public/weborder/leaflet/leaflet.js; echo "   (تخطّي — الموقع يجيبها من CDN)"; }
fi

echo "==> بناء الكاش"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# بعد الكاش مش قبله: أوامر artisan كـ root تنشئ ملفات سجل وكاش باسم root،
# وخادم الويب (www-data) ما يقدرش يكتب فيها فيطيح بخطأ 500
echo "==> الصلاحيات"
mkdir -p storage/framework/{cache/data,sessions,views,testing} storage/logs storage/app/public bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
[[ -f storage/app/firebase.json ]] && chmod 640 storage/app/firebase.json

echo "==> إعادة تشغيل الخدمات"
systemctl restart php8.3-fpm
systemctl enable --now nalut-scheduler
systemctl enable --now nalut-queue
systemctl restart nalut-scheduler nalut-queue

php artisan up

# BlurHash للصور القديمة اللي ما عندهاش (يكمّل الناقص بس — كـ www-data باش ما يخلّيش ملفات باسم root)
echo "==> BlurHash للصور"
sudo -u www-data php artisan images:blurhash 2>&1 | tail -1 || true

echo
echo "تم النشر."
echo "ملاحظة: مواقع السائقين في الكاش وتنمسح مع optimize:clear —"
echo "        ترجع لوحدها خلال 8 ثواني من تطبيقات السائقين الشغّالة."
