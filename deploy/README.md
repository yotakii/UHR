# نشر UHR على السيرفر

السيرفر: `37.60.249.71` (Contabo، Docker، Nginx Proxy Manager على 80/443).
UHR يعمل كمجموعة حاويات باسم `uhr`:

| الحاوية | الوظيفة |
|---|---|
| `uhr-web` | الواجهة (Nginx) على البورت **8190**، وتمرّر `/api` للـ API |
| `uhr-api` | Laravel (PHP-FPM)، وتشغّل الترحيلات تلقائياً |
| `uhr-worker` | طابور المهام (البريد) |
| `uhr-scheduler` | المهام المجدولة |
| `uhr-db` | PostgreSQL 18 |
| `uhr-redis` | Redis |
| `uhr-mail` | صندوق بريد تجريبي (محلي فقط) |

الكود يأتي دائماً من GitHub، ولا يُعدَّل شيء يدوياً على السيرفر. كل تحديث = `git pull` + بناء + تشغيل، بأمر واحد: `./deploy/deploy.sh`.

---

## أولاً: التجهيز (مرة واحدة)

### 1) مفتاح قراءة من GitHub (Deploy key)

على السيرفر:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/uhr_github -N "" -C "uhr-server"
cat ~/.ssh/uhr_github.pub
```

انسخ السطر الناتج، وأضفه في GitHub:
**yotakii/UHR ← Settings ← Deploy keys ← Add deploy key**. اتركه بدون صلاحية كتابة.

```bash
cat >> ~/.ssh/config <<'EOF'
Host github-uhr
  HostName github.com
  User git
  IdentityFile ~/.ssh/uhr_github
  IdentitiesOnly yes
EOF
ssh -o StrictHostKeyChecking=accept-new -T github-uhr     # يجب أن يقول: successfully authenticated
git clone git@github-uhr:yotakii/UHR.git /root/uhr
```

### 2) فرع النشر `release`

من جهازك (Windows):

```powershell
cd "D:\projects\UHR\local project\uhr"
git push origin claude/friendly-dirac-v5ljwj:release
```

### 3) أول تشغيل

```bash
cd /root/uhr
./deploy/deploy.sh          # أول مرة: يُنشئ deploy/.env (مفتاح التطبيق + كلمة سر القاعدة) ويتوقف
nano deploy/.env            # راجع APP_URL و FRONTEND_URL (الدومين)
./deploy/deploy.sh          # يبني ويشغّل (5–10 دقائق أول مرة)
```

### 4) البيانات

اختر واحداً من الخيارين:

- **سيرفر التجربة** (`APP_ENV=staging`) مع بيانات الديمو وحسابات التجربة:

  ```bash
  docker exec -u www-data uhr-api php artisan db:seed --class=TestDataSeeder
  ```

- **تشغيل حقيقي** (`APP_ENV=production`) بنظام نظيف وأول مدير:

  ```bash
  docker exec -u www-data uhr-api php artisan uhr:install --email=admin@company.com
  ```

### 5) الدومين و HTTPS من Nginx Proxy Manager

افتح `http://37.60.249.71:81` ← **Hosts ← Proxy Hosts ← Add Proxy Host**:

- **Details:**

  | الحقل | القيمة |
  |---|---|
  | Domain Names | `uhr.37.60.249.71.nip.io` (أو `uhr.atmata-group.com` بعد إضافة سجل DNS) |
  | Scheme | `http` |
  | Forward Hostname / IP | `37.60.249.71` |
  | Forward Port | `8190` |

  فعّل **Block Common Exploits** و **Websockets Support**.

- **SSL:** Request a new SSL Certificate (Let's Encrypt)، ثم فعّل **Force SSL** و **HTTP/2**.
- **Advanced:**

  ```
  client_max_body_size 25m;
  ```

الدومين لازم يطابق `APP_URL` في `deploy/.env`. إذا غيّرته، شغّل `./deploy/deploy.sh` من جديد.

---

## ثانياً: النشر التلقائي من GitHub (Actions)

### على السيرفر: مفتاح دخول خاص بـ GitHub Actions

```bash
ssh-keygen -t ed25519 -f ~/.ssh/uhr_actions -N "" -C "github-actions"
cat ~/.ssh/uhr_actions.pub >> ~/.ssh/authorized_keys
cat ~/.ssh/uhr_actions          # المفتاح الخاص — انسخه كاملاً (من BEGIN إلى END)
```

### في GitHub: الأسرار

افتح **yotakii/UHR ← Settings ← Secrets and variables ← Actions ← New repository secret** وأضف:

| السر | القيمة |
|---|---|
| `SSH_HOST` | `37.60.249.71` |
| `SSH_USER` | `root` |
| `SSH_KEY` | المفتاح الخاص الذي نسخته |

اختياري: `SSH_PORT` إذا لم يكن 22، و `UHR_PATH` إذا لم يكن المجلد `/root/uhr`.

### كيف تنشر بعدها

بعد ما تجرّب التعديل على جهازك وتتأكد منه، أمر واحد من Windows:

```powershell
git pull
git push origin HEAD:release
```

GitHub يشغّل فحص الواجهة ثم يدخل السيرفر ويشغّل `deploy.sh`. ترفع التعديلات فقط، لا المجلد كاملاً.
تتابع التقدم من تبويب **Actions** في المستودع.

بدون push: **Actions ← Deploy UHR ← Run workflow**.

---

## أوامر مفيدة على السيرفر

```bash
cd /root/uhr
./deploy/deploy.sh                                   # تحديث يدوي من فرع release
cat deploy/deployed.log                              # تاريخ كل نشر ورقم النسخة
docker compose -f deploy/docker-compose.yml ps       # حالة الحاويات
docker compose -f deploy/docker-compose.yml logs -f api worker
docker exec -u www-data uhr-api php artisan tinker   # طرفية Laravel
ls /root/uhr-backups                                 # نسخة احتياطية قبل كل نشر (آخر 20)
```

**صندوق البريد التجريبي:** من جهازك افتح نفقاً، ثم افتح `http://localhost:8126`:

```powershell
ssh -L 8126:127.0.0.1:8126 root@37.60.249.71
```

**استرجاع قاعدة البيانات من نسخة احتياطية:**

```bash
docker exec -i uhr-db pg_restore -U uhr -d uhr --clean --if-exists < /root/uhr-backups/uhr-YYYY-MM-DD-HHMM.dump
```

**الرجوع لنسخة سابقة:** من جهازك:

```powershell
git push --force origin <commit>:release
```
