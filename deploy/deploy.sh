#!/usr/bin/env bash
# نشر / تحديث UHR على السيرفر — أمر واحد:  ./deploy/deploy.sh  [الفرع]
# 1) يسحب آخر نسخة من GitHub (السيرفر لا يُعدَّل عليه كود يدوياً)  2) نسخة احتياطية من القاعدة
# 3) يبني الصور ويشغّل (الترحيلات تلقائياً داخل حاوية api)  4) ينتظر الجاهزية ويسجّل النسخة المنشورة
set -euo pipefail

main() {
  local ROOT; ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  cd "$ROOT"
  local ENV=deploy/.env
  local DC="docker compose -f deploy/docker-compose.yml --env-file $ENV"
  local BACKUPS=${UHR_BACKUPS:-/root/uhr-backups}

  if [ ! -f "$ENV" ]; then
    cp deploy/.env.example "$ENV"
    sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" "$ENV"
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 16)|" "$ENV"
    chmod 600 "$ENV"
    echo "[uhr] أُنشئ deploy/.env — راجع APP_URL و FRONTEND_URL ثم أعد تشغيل الأمر."
    exit 0
  fi

  # البث اللحظي (Reverb): يُضاف للملف الموجود إن لم يكن — بمفاتيح عشوائية
  if ! grep -q '^REVERB_APP_KEY=' "$ENV"; then
    {
      echo ""
      echo "# التحديث اللحظي (Reverb) — أُضيف تلقائياً"
      echo "BROADCAST_CONNECTION=reverb"
      echo "REVERB_APP_ID=uhr"
      echo "REVERB_APP_KEY=$(openssl rand -hex 16)"
      echo "REVERB_APP_SECRET=$(openssl rand -hex 24)"
      echo "REVERB_HOST=reverb"
      echo "REVERB_PORT=8080"
      echo "REVERB_SCHEME=http"
    } >> "$ENV"
    echo "[uhr] أُضيفت إعدادات Reverb إلى deploy/.env"
  fi

  local BRANCH="${1:-$(grep -E '^DEPLOY_BRANCH=' "$ENV" | cut -d= -f2)}"
  BRANCH="${BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"
  echo "[uhr] الفرع: $BRANCH"
  git fetch --prune origin "$BRANCH"
  git checkout -q -B "$BRANCH" "origin/$BRANCH"
  git reset -q --hard "origin/$BRANCH"

  mkdir -p "$BACKUPS"
  if [ -n "$($DC ps -q db 2>/dev/null)" ]; then
    local F="$BACKUPS/uhr-$(date +%F-%H%M).dump"
    $DC exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > "$F" && echo "[uhr] نسخة احتياطية: $F"
    ls -1t "$BACKUPS"/uhr-*.dump 2>/dev/null | tail -n +21 | xargs -r rm -f     # آخر 20 نسخة
  fi

  $DC build --pull
  $DC up -d --remove-orphans

  echo -n "[uhr] انتظار جاهزية الـ API"
  for _ in $(seq 1 90); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' uhr-api 2>/dev/null)" = "healthy" ] && break
    echo -n "."; sleep 2
  done
  echo
  if [ "$(docker inspect -f '{{.State.Health.Status}}' uhr-api)" != "healthy" ]; then
    echo "[uhr] ✗ الـ API لم يجهز — آخر السجلات:"; $DC logs --tail=80 api; exit 1
  fi
  local PORT; PORT="$(grep -E '^UHR_HTTP_PORT=' "$ENV" | cut -d= -f2)"
  curl -fsS "http://127.0.0.1:${PORT:-8190}/api/health" >/dev/null && echo "[uhr] ✓ الواجهة والـ API تعمل على :${PORT:-8190}"
  echo "$(date '+%F %T') $(git rev-parse --short HEAD) $BRANCH" >> deploy/deployed.log
  docker image prune -f >/dev/null
  $DC ps
}

main "$@"
