# UHR — نظام الموارد البشرية (بيئة التطوير)

## التشغيل لأول مرة
```powershell
cd uhr
docker compose up -d --build
docker compose logs -f api      # أول مرة: composer install يأخذ 2–4 دقائق، انتظر "[uhr] API ready"
```

| الخدمة | الرابط على جهازك |
|---|---|
| الواجهة | http://localhost:5180 |
| الـ API (فحص) | http://localhost:8180/api/health |
| PostgreSQL | localhost:54330 · uhr / uhr_secret · قاعدة `uhr` |
| Redis | localhost:6390 |
| صندوق البريد التجريبي (كل رسائل النظام) | http://localhost:8125 |

لتغيير أي بورت: انسخ `.env.example` إلى `.env` بجانب `docker-compose.yml` وعدّل.

## اختبار المهمة
```powershell
docker compose exec api php artisan test     # اختبارات الـ backend (على قاعدة uhr_test)
docker compose exec web npm run typecheck    # فحص أنواع الواجهة
docker compose exec web npm test             # اختبارات الواجهة (vitest)
```
- http://localhost:5180 — الواجهة العامة والدخول (المهمة 03)
- http://localhost:5180/#/kit — مكتبة المكونات (بيئة التطوير فقط)
- http://localhost:5180/#/status — فحص الجاهزية: «كل الخدمات تعمل ومتصلة»

## البيانات
```powershell
# تثبيت نظيف (الإنتاج): القيم الأساسية + حساب المدير الأول (كلمة مرور مؤقتة تُغيَّر عند أول دخول)
docker compose exec api php artisan uhr:install --email=admin@company.sy

# بيانات الاختبار = بيانات ديمو المعرض حرفياً (32 موظفاً، 10 متقدمين…) — للتطوير فقط، مرفوضة بالإنتاج
docker compose exec api php artisan migrate:fresh --seed
docker compose exec api php artisan db:seed --class=TestDataSeeder
```
حسابات بيانات الاختبار (كلمة المرور 123456): `admin@alfarada.sy` مدير النظام · `hr@alfarada.sy` الموارد البشرية ·
`samer.halabi@alfarada.sy` موظف · `leen.ali@mail.sy` متقدمة (لديها عرض عمل). بريد كل موظف: `الاسم.الكنية@alfarada.sy`.

## أوامر مفيدة
```powershell
docker compose exec api php artisan migrate:fresh --seed   # إعادة بناء القاعدة (القيم الأساسية فقط)
docker compose exec api bash                               # طرفية داخل الـ API
docker compose restart web                                 # بعد إضافة حزمة npm
docker compose down                                        # إيقاف (البيانات محفوظة)
docker compose down -v                                     # إيقاف + حذف كل البيانات والحزم
```

## الهيكل
```
backend/    Laravel 12 (API فقط، كل المسارات تحت /api)
frontend/   React 19 + TypeScript + Vite + Tailwind v4 (الشعارات والخطوط مضمّنة)
docker/     Dockerfile الـ API + سكربت التشغيل + تهيئة القاعدة
```
التعديل على الكود يظهر مباشرة (الواجهة hot reload، والـ API بدون إعادة تشغيل).

## خطة البناء
نظام تشغيلي كامل بشكل ومنطق ديمو المعرض v26، بدون أدوات العرض، مع إدارة كاملة للإعدادات وقاعدة بيانات حقيقية.
قائمة المهام والمطابقة الكاملة في [`FEATURES.md`](FEATURES.md)، وكل مهمة تُسلَّم مع اختبارها.
