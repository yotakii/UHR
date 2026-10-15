#!/usr/bin/env bash
set -e
cd /var/www

# 1) ملف البيئة
[ -f .env ] || { cp .env.example .env; echo "[uhr] created .env from .env.example"; }

# 2) مجلدات التخزين (قد لا تُنسخ فارغة على ويندوز)
mkdir -p storage/app/public storage/framework/{cache/data,sessions,views,testing} storage/logs bootstrap/cache

# 3) الحزم — أول تشغيل أو عند تغيّر composer.json
if [ ! -f vendor/autoload.php ] || [ composer.json -nt vendor/autoload.php ]; then
  echo "[uhr] composer install ..."
  composer install --no-interaction --prefer-dist
fi

# 4) مفتاح التطبيق
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

# 5) انتظار قاعدة البيانات ثم الترحيلات
until pg_isready -h "${DB_HOST:-db}" -p 5432 -q; do echo "[uhr] waiting for database..."; sleep 2; done
php artisan migrate --force
php artisan optimize:clear >/dev/null

echo "[uhr] API ready on :8000"
exec "$@"
