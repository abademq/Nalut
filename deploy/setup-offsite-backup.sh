#!/usr/bin/env bash
#
# إعداد النسخ الاحتياطي برّا السيرفر — مرة وحدة، كـ root:
#   bash deploy/setup-offsite-backup.sh
#
# يسألك على بيانات FTP/SFTP متاع الاستضافة (كلمة السر ما تبانش وانت تكتبها)،
# ويولّد كلمة تشفير، ويجرّب الاتصال، ويضيف المهمة الليلية (03:30).
#
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "شغّله كـ root"; exit 1; }

command -v lftp >/dev/null || apt-get install -y lftp
command -v openssl >/dev/null || apt-get install -y openssl

echo "== بيانات الاستضافة (من لوحة الاستضافة ← FTP Accounts أو SSH) =="
read -rp "الطريقة (sftp لو الاستضافة فيها SSH، وإلا ftp): " METHOD
[[ $METHOD == sftp || $METHOD == ftp ]] || { echo "اكتب sftp أو ftp"; exit 1; }
read -rp "السيرفر (مثلاً ftp.azanx.ly أو IP): " HOST
read -rp "المنفذ (Enter = الافتراضي): " PORT
read -rp "اسم المستخدم: " USER_NAME
read -rsp "كلمة السر (ما تبانش): " PASSWORD; echo
read -rp "المجلد البعيد — برّا public_html (مثلاً backups/nalut): " REMOTE_DIR
[[ $REMOTE_DIR == public_html* || $REMOTE_DIR == www* ]] && { echo "⚠ ما تحطش النسخ داخل public_html — أي حد يقدر يحمّلها من المتصفح"; exit 1; }

umask 077
{
  printf 'METHOD=%q\n' "$METHOD"
  printf 'HOST=%q\n' "$HOST"
  printf 'PORT=%q\n' "$PORT"
  printf 'USER_NAME=%q\n' "$USER_NAME"
  printf 'PASSWORD=%q\n' "$PASSWORD"
  printf 'REMOTE_DIR=%q\n' "$REMOTE_DIR"
  echo 'VERIFY_CERT=yes'
} > /etc/nalut-offsite.conf
chmod 600 /etc/nalut-offsite.conf

if [[ ! -f /etc/nalut-offsite.pass ]]; then
  openssl rand -base64 36 > /etc/nalut-offsite.pass
  chmod 600 /etc/nalut-offsite.pass
  echo
  echo "================ مهم جداً ================"
  echo "كلمة تشفير النسخ (احفظها في مدير كلمات السر، برّا السيرفر):"
  cat /etc/nalut-offsite.pass
  echo "بدونها، النسخ اللي في الاستضافة ما تنفعش لو السيرفر ضاع."
  echo "=========================================="
  read -rp "حفظتها؟ اضغط Enter للمتابعة "
fi

echo "== تجربة الاتصال =="
if ! bash "$(dirname "$0")/offsite-backup.sh" --test; then
  echo "لو الخطأ في الشهادة (certificate) والاستضافة مشتركة، جرّب: sed -i 's/VERIFY_CERT=yes/VERIFY_CERT=no/' /etc/nalut-offsite.conf"
  exit 1
fi

echo "30 3 * * * root bash /var/www/nalut/deploy/offsite-backup.sh >> /var/log/nalut-offsite.log 2>&1" > /etc/cron.d/nalut-offsite
chmod 644 /etc/cron.d/nalut-offsite
echo "== أول نسخة كاملة (قاعدة البيانات + الصور) =="
FORCE_FILES=1 bash "$(dirname "$0")/offsite-backup.sh"
echo "تم ✓ — النسخة تتعاود كل ليلة 03:30، ولوحة التحكم تنبّهك لو وقفت."
