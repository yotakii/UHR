#!/bin/sh
# تشغيل حاوية الـ API (و worker و scheduler من نفس الصورة):
# مجلدات التخزين ← انتظار القاعدة ← الترحيلات (حاوية app فقط) ← كاش الإعدادات ← التشغيل كمستخدم www-data
set -e
cd /var/www
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
as_www() { su-exec www-data "$@"; }

until pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -q; do echo "[uhr] waiting for database..."; sleep 2; done
if [ "${UHR_ROLE:-app}" = "app" ]; then
  rm -f /tmp/uhr-ready
  as_www php artisan migrate --force
fi
as_www php artisan config:cache >/dev/null
as_www php artisan route:cache >/dev/null
as_www php artisan view:cache >/dev/null
[ "${UHR_ROLE:-app}" = "app" ] && touch /tmp/uhr-ready
echo "[uhr] ${UHR_ROLE:-app} ready"

# php-fpm يبدأ كـ root ويشغّل عمّاله كـ www-data؛ بقية الأوامر (الطابور، المجدول) كـ www-data مباشرة
if [ "$1" = "php-fpm" ]; then exec "$@"; fi
exec su-exec www-data "$@"
