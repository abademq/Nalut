#!/usr/bin/env bash
#
# نسخة احتياطية مشفّرة برّا السيرفر (الاستضافة المشتركة أو أي FTP/SFTP) — تشتغل كل ليلة من cron.
#
#   bash deploy/offsite-backup.sh          نسخة توّا
#   bash deploy/offsite-backup.sh --test   يتأكد من الاتصال والرفع بملف صغير بس
#
# الإعداد مرة وحدة: bash deploy/setup-offsite-backup.sh
#
# شن يترفع:
#   db-<يوم الأسبوع>.sql.gz.enc     قاعدة البيانات — 7 نسخ تدور (الأحد، الاثنين...)
#   db-<السنة-الشهر>.sql.gz.enc      نسخة شهرية (تنكتب أول يوم في الشهر وتبقى)
#   files-latest.tar.gz.enc          الصور المرفوعة + ملف .env (كل أحد)
# كل ملف مشفّر AES-256 بكلمة سر في /etc/nalut-offsite.pass — بدونها النسخ ما تنفعش، فخزّنها برّا السيرفر.
#
set -uo pipefail

CONF=${OFFSITE_CONF:-/etc/nalut-offsite.conf}
PASSFILE=${OFFSITE_PASS:-/etc/nalut-offsite.pass}
APP_DIR=${OFFSITE_APP_DIR:-/var/www/nalut}
STATUS="$APP_DIR/storage/app/backup-status.json"
WORK=$(mktemp -d /tmp/nalut-offsite.XXXXXX)
trap 'rm -rf "$WORK"' EXIT

status() { # ok|fail  رسالة  الحجم
  printf '{"ok":%s,"at":"%s","message":"%s","size":"%s"}\n' "$1" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$2" "${3:-}" > "$STATUS"
  chown www-data:www-data "$STATUS" 2>/dev/null; chmod 644 "$STATUS" 2>/dev/null
}
fail() { echo "✗ $1" >&2; status false "$1"; exit 1; }

[[ -f $CONF && -f $PASSFILE ]] || fail "الإعداد ناقص — شغّل deploy/setup-offsite-backup.sh"
# shellcheck disable=SC1090
source "$CONF"
: "${METHOD:?}" "${HOST:?}" "${USER_NAME:?}" "${REMOTE_DIR:?}"

enc() { openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$PASSFILE" -in "$1" -out "$1.enc" && rm -f "$1"; }

upload() { # ملفات… → المجلد البعيد
  local cmds="set net:max-retries 2; set net:timeout 30; set ssl:verify-certificate ${VERIFY_CERT:-yes};"
  [[ $METHOD == ftp ]] && cmds+=" set ftp:ssl-force true; set ftp:ssl-protect-data true;"
  [[ $METHOD == sftp ]] && cmds+=" set sftp:auto-confirm yes;"
  cmds+=" mkdir -p -f $REMOTE_DIR; cd $REMOTE_DIR;"
  for f in "$@"; do cmds+=" put -O . $f;"; done
  cmds+=" bye"
  local args=(--env-password -u "$USER_NAME" -e "$cmds")
  [[ -n ${PORT:-} ]] && args=(-p "$PORT" "${args[@]}")
  LFTP_PASSWORD="$PASSWORD" lftp "${args[@]}" "$METHOD://$HOST" 2>"$WORK/lftp.err"
}

if [[ ${1:-} == --test ]]; then
  echo "test $(date)" > "$WORK/connection-test.txt"
  upload "$WORK/connection-test.txt" || fail "فشل الاتصال أو الرفع: $(tail -1 "$WORK/lftp.err")"
  echo "✓ الاتصال والرفع تمام ($METHOD://$HOST/$REMOTE_DIR)"
  exit 0
fi

DB_NAME=$(grep -E '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"')
DAY=$(date +%a)
FILES=()

mysqldump --single-transaction --routines "$DB_NAME" | gzip > "$WORK/db-$DAY.sql.gz" || fail "فشل mysqldump"
[[ $(stat -c %s "$WORK/db-$DAY.sql.gz") -gt 1000 ]] || fail "نسخة قاعدة البيانات صغيرة بشكل غريب"
enc "$WORK/db-$DAY.sql.gz" || fail "فشل التشفير"
FILES+=("$WORK/db-$DAY.sql.gz.enc")

if [[ $(date +%d) == 01 ]]; then
  cp "$WORK/db-$DAY.sql.gz.enc" "$WORK/db-$(date +%Y-%m).sql.gz.enc"
  FILES+=("$WORK/db-$(date +%Y-%m).sql.gz.enc")
fi

if [[ $(date +%u) == 7 || ${FORCE_FILES:-0} == 1 || ${1:-} == --files ]]; then
  tar -czf "$WORK/files-latest.tar.gz" -C "$APP_DIR" .env storage/app/public 2>/dev/null || fail "فشل تجميع الصور"
  enc "$WORK/files-latest.tar.gz" || fail "فشل تشفير الصور"
  FILES+=("$WORK/files-latest.tar.gz.enc")
fi

SIZE=$(du -ch "${FILES[@]}" | tail -1 | cut -f1)
upload "${FILES[@]}" || fail "فشل الرفع: $(tail -1 "$WORK/lftp.err")"
status true "تم رفع ${#FILES[@]} ملف" "$SIZE"
echo "✓ النسخة برّا السيرفر: ${#FILES[@]} ملف ($SIZE)"
