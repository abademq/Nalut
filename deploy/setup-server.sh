#!/usr/bin/env bash
#
# تهيئة سيرفر Ubuntu 24.04 لمنصة توصيل نالوت — نسخة GitHub
#
#   bash setup-server.sh
#
# ما يحتاجش دومين من البداية — يشتغل على IP وتضيف الدومين بعدين.
#
set -euo pipefail

APP_DIR="/var/www/nalut"
REPO="https://github.com/abademq/Nalut.git"
DB_NAME="nalut"
DB_USER="nalut"

[[ $EUID -eq 0 ]] || { echo "لازم تشغّله كـ root"; exit 1; }

echo "==> تحديث النظام"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y && apt-get upgrade -y

echo "==> الحزم الأساسية"
apt-get install -y software-properties-common curl git unzip ufw fail2ban

echo "==> PHP 8.3"
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl \
  php8.3-redis php8.3-opcache

echo "==> Nginx و MySQL و Redis"
apt-get install -y nginx mysql-server redis-server
systemctl enable --now redis-server

echo "==> Composer"
curl -sS https://getcomposer.org/installer | php -- \
  --install-dir=/usr/local/bin --filename=composer

echo "==> قاعدة البيانات"
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

echo "==> جلب المشروع من GitHub"
if [[ -d "$APP_DIR/.git" ]]; then
  git -C "$APP_DIR" pull
else
  rm -rf "$APP_DIR"
  git clone "$REPO" "$APP_DIR"
fi

echo "==> حدود رفع الصور في PHP (الافتراضي 2MB للملف و 8MB للطلب)"
cat > /etc/php/8.3/fpm/conf.d/99-nalut.ini <<PHPINI
upload_max_filesize = 8M
post_max_size = 48M
max_file_uploads = 20
PHPINI

echo "==> Nginx"
SERVER_IP="$(curl -s ifconfig.me || echo '_')"

cat > /etc/nginx/sites-available/nalut <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root ${APP_DIR}/public;

    index index.php;
    charset utf-8;
    client_max_body_size 50M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php\$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known).* { deny all; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;
}
NGINX

ln -sf /etc/nginx/sites-available/nalut /etc/nginx/sites-enabled/nalut
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> جدار الحماية"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

echo "==> خدمات الجدولة والطوابير"
for svc in scheduler queue; do
  if [[ "$svc" == "scheduler" ]]; then
    CMD="schedule:work"
    DESC="Nalut scheduler"
  else
    CMD="queue:work --sleep=3 --tries=3 --max-time=3600"
    DESC="Nalut queue worker"
  fi

  cat > /etc/systemd/system/nalut-${svc}.service <<UNIT
[Unit]
Description=${DESC}
After=network.target mysql.service redis-server.service

[Service]
Type=simple
User=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php ${APP_DIR}/artisan ${CMD}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
done

systemctl daemon-reload

echo "==> نسخة احتياطية يومية"
# النسخ فيها كل بيانات الزبائن — root بس يقراها
install -d -m 700 /var/backups/nalut
cat > /usr/local/bin/nalut-backup <<BACKUP
#!/usr/bin/env bash
set -e
umask 077
STAMP=\$(date +%F-%H%M)
mysqldump --single-transaction ${DB_NAME} | gzip > /var/backups/nalut/${DB_NAME}-\${STAMP}.sql.gz
ls -1t /var/backups/nalut/*.sql.gz | tail -n +15 | xargs -r rm --
BACKUP
chmod +x /usr/local/bin/nalut-backup
echo "0 3 * * * root /usr/local/bin/nalut-backup" > /etc/cron.d/nalut-backup

echo
echo "================================================"
echo "  تمت التهيئة"
echo "================================================"
echo
echo "عنوان السيرفر:  http://${SERVER_IP}"
echo
echo "بيانات قاعدة البيانات — احفظها فوراً، ما تتعرضش مرة ثانية:"
echo "  DB_DATABASE=${DB_NAME}"
echo "  DB_USERNAME=${DB_USER}"
echo "  DB_PASSWORD=${DB_PASS}"
echo
echo "الخطوة الجاية:"
echo "  1) ارفع ملف .env و storage/app/firebase.json من جهازك"
echo "  2) cd ${APP_DIR} && bash deploy/deploy.sh --fresh"
echo
