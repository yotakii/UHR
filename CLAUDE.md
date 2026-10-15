# UHR — Production HR System (handoff for Claude Code)

> Read this file fully before touching code. It replaces the chat history the project was started in.
> Then read `docs/SRS.md` (the full spec, Arabic) and `FEATURES.md` (task checklist).

## 1. What we are building
- **UHR (Uniqueness HR)**: production HR system for Atmata Group's client "Uniqueness / الفرادة". Owner: Atmata Group (Damascus).
- **Goal:** match the exhibition demo **v26** (`reference/demo/1_System/UHR_System.html`) **screen-for-screen in look, logic and features**, but as a real system with a database and a server-side API.
- **Decision (Oct 12, 2026) — production, NOT a demo.** Remove every exhibition-only tool:
  - relaxed mode, demo accounts list, visitors, sample-data buttons, reset, showcase/attract/expo mode, product tour, partners ticker, kiosk *simulation* (a real NFC terminal only on a registered device).
  - Everything that was hard-coded in the demo becomes manageable in **M16 settings** (company name, hero texts, prefixes, rules, SLAs, policies with effective dates, approval chains, holidays…).
- **Build UHR standalone first.** Integration with **ZERP** (Atmata's ERP: accounting, official payroll) comes **later** — don't start it.

## 2. How we work with the user
- The user speaks **Syrian Arabic**; reply in Syrian Arabic, **masculine** forms, short and direct, no long explanations, minimal questions.
- **Step by step:** build one task → user extracts/pulls, runs it locally in Docker, runs the tests → says "done / next" → next task. Never mark a task done before its tests pass.
- Each delivery ends with: what changed (short bullets), the exact commands to run, and what to try in the browser.
- The user's machine is **Windows**, project at `D:\projects\UHR\local project\uhr`, commands run in **cmd/PowerShell**:
  - ⚠️ In cmd, `^` is an escape char → always quote composer constraints: `composer require "vendor/pkg:^1.6"`.
- Port constraints (user has other containers): **web 5180 · api 8180 · postgres 54330 · redis 6390 · mailpit 8125**. Windows reserved 63790 → never use it. Ports are overridable in root `.env` (copy of `.env.example`).

## 3. Stack & layout
```
docker-compose.yml   project "uhr": uhr-db (postgres:18) · uhr-redis · uhr-api (php 8.3, artisan serve :8000) · uhr-mail (mailpit) · uhr-web (node 22, vite :5173)
docker/api/          Dockerfile, entrypoint.sh (composer install if needed, key:generate, wait db, migrate), php.ini
docker/db/init.sql   creates uhr_test DB (phpunit)
backend/             Laravel 12, API only, all routes under /api
frontend/            React 19 + TypeScript 7 + Vite 8 + Tailwind v4 + TanStack Query 5 + lucide-react; vitest 5 + jsdom
docs/SRS.md          full SRS v2 (authoritative spec)
reference/           demo v26 + its source + extracted bundle (see §8) — read-only, not shipped
```
- Backend skeleton is hand-written (minimal): `config/app.php` holds only overrides (merged with framework defaults). No Sanctum.
- **Auth = session cookie (httpOnly) + CSRF**, same origin via the Vite proxy (`/api` → `http://api:8000`). Middleware prepended to the `api` group in `bootstrap/app.php`: SetLocale, EncryptCookies, AddQueuedCookies, StartSession, ValidateCsrfToken. Frontend sends `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie (`frontend/src/lib/api.ts`).
- Route middleware aliases: `fresh` (blocks users who must change a temp password → 403 `password_change_required`), `portal:hr|employee|candidate`.
  - Future system routes: `Route::middleware(['auth','fresh','portal:hr'])`.
- **API messages are bilingual:** `App\Support\L::t($ar, $en)` picks the language from the `Accept-Language` header, which the frontend sends from the user's preference. Errors carry a stable `code`.
- Mail: dev mail goes to Mailpit (http://localhost:8125). The compose `environment:` sets MAIL_* and FRONTEND_URL, which override `backend/.env` (the user's `.env` already exists, so new keys must go in compose or be documented).

## 4. Conventions (follow them)
- **Bilingual everywhere:**
  - UI: `const L = useL(); L('عربي','English')`.
  - Data: `Bi = {ar, en}` and `bi(x, lang)`.
  - Arabic is the default with RTL; numerals are Latin (`ar-SY-u-nu-latn`).
- **Design system** is copied verbatim from the demo:
  - `frontend/src/index.css`: 3 themes — `slate` (default, classes `dark slate`), `dark`, `light`.
  - Tokens: canvas/surface/ink/brand/brass/info/line/sub/show-*.
  - Fonts: "UHR Arabic" (IBM Plex), Figtree, Outfit.
  - Kit: `ui/kit.tsx` (Btn, Badge, Panel, PageHead, Field, Seg, Avatar, Modal, Drawer, Tabs, Empty, Progress, toast, SignaturePad, KV), `ui/docs.tsx` (Paper, SigBox, Timeline, Lifecycle, OfferDoc, DecisionDoc), `ui/viz.tsx` (Sparkline, Bars, Radar, Ring, Donut, Heatmap), `ui/brand.tsx` (Logo, LangToggle, ThemeToggle).
  - Gallery of all of the above: `#/kit` (dev only).
- **Porting rule:** to build a screen, open the demo source for it (`reference/demo-src/screens/*.tsx`) and copy markup and classes 1:1. Replace `useStore`/`actions.ts` calls with API calls (TanStack Query), and drop demo-only bits. Where the v26 bundle differs from the source, the **v26 bundle wins** (`reference/demo-bundle/app.js`, beautified; app code starts at about line 15900).
- **Business logic lives on the server** (port `reference/demo-src/lib/rules.ts`: matchScore, F25, F19, calcDay, settlement, annualEntitlement… to PHP), and is unit-tested against the TS originals.
- **Routing:** hash router `frontend/src/lib/router.ts` (`#/page/id?query`). Use `go()`, `href()` and `useRoute()`.
- **Models:**
  - Base `App\Models\Model`: `$guarded=['id']`, and `Auditable` writes `audit_logs` automatically on every create/update/delete (changed fields only; secrets masked).
  - Opt out per model with `protected bool $audit = false` (EventLog, AuditLog, Notice, Scan, AttendanceDay, StoredFile).
  - Seeders wrap their work in `Audit::mute()`. Manual events: `Audit::record('auth.login', $user)`.
  - `Model::shouldBeStrict()` is on outside production: lazy loading, unknown attributes and missing attributes all throw. Eager-load your relations.
- **Timeline / event log:** the `HasEventLog` trait on Employee and Candidate: `$model->log($ar, $en, $kind, $form, $at)`.
- **Numbering:** `App\Support\Counters` (row-locked): `employeeCode()` gives UHR-1033, `candidateRef()`, `decisionNo()` gives QR-2026-xxx, `assetTag()` gives AST-0075, `jobCode()`. Prefixes come from settings.
- **Settings & policies:** `App\Support\Settings::get/put/company/prefix/rule/policy($key, $onDate)`. Policies resolve by `effective_from`; defaults are in `Settings::DEFAULTS` and `POLICY_DEFAULTS`.
- Table for vacancies is **`job_openings`** (because `jobs` = Laravel queue). Postgres **jsonb does not preserve key order** → compare by key in tests.

## 5. Data
- Migrations: `backend/database/migrations/2026_10_12_00000{1..5}_*` cover 45 tables across core, organization, recruitment, people-ops and engagement.
- `php artisan db:seed` runs `DefaultsSeeder` only: roles (admin, hr_director, hr_officer, finance, executive, ceo, manager, employee, candidate), settings, policies, 6 cities, asset categories, competencies, approval chains, channels and templates, the current performance cycle. It is idempotent and production-safe.
- `php artisan uhr:install --email=…` gives a clean install plus the first admin. Without `--password`, the admin gets a temporary password and must change it.
- `php artisan db:seed --class=TestDataSeeder` (dev only, refused in production) loads the **exact demo data**:
  - It uses the demo's mulberry32 `rng(20261006)` ported to PHP, in the same draw order. Values are verified identical to `seed.ts`, and the tests freeze the clock at 2026-10-12 10:30 Damascus.
  - Content: 8 departments, 32 employees (UHR-1001..1032), 6 jobs, 10 candidates, 74 assets, 351 attendance-day rows, leaves, a resignation, referrals, rewards, notices.
  - Test accounts (password `123456`): `admin@alfarada.sy`, `hr@alfarada.sy` (Rasha, UHR-1003, hr_director), `samer.halabi@alfarada.sy` (employee), `leen.ali@mail.sy` (applicant with an offer).
  - Employee emails follow `first.last@alfarada.sy` (with the `al-` prefix dropped).

## 6. Status (Oct 13, 2026)
| Task | Status |
|---|---|
| 00 Docker infra, `/api/health`, `#/status` | ✅ user verified |
| 01 Design system & components (`#/kit`) | ✅ user verified |
| 02 Database, `uhr:install`, TestDataSeeder (demo-identical), audit log | ✅ user verified (after adding mockery) |
| 03 M01 public page and login (see below) | 📦 delivered, **waiting for the user's test run** |
| 04 M02 apply wizard F-28 → M05 applicant portal | ⏭ next |
| 05 M15 shell → M03 Kanban → M04 candidate file → M06 | |
| 06 M07–M11 · 07 M12–M14 · 08 salary 2FA · 09 M16 admin + Reverb + PDF/Excel · 10 final verification | |

**Task 03 delivered:**
- Landing page: hero texts from settings, 3 cards, login card with show-password.
- «نسيت كلمة المرور؟» → `#/forgot` and `#/reset?token&email` (bilingual email via `App\Notifications\ResetPasswordNotice` + `resources/views/mail/reset.blade.php`).
- Jobs board from `/api/public/jobs`; job page `#/jobs/REQ-…` with a copy-link button. The apply wizard placeholder is replaced in task 04.
- Lockout after 5 failures (`unlock_minutes` policy). Blocked or terminated users are refused. Forced temp-password change screen.
- Per-portal placeholder home (`screens/Account.tsx`).
- The NFC terminal link shows only if `localStorage['uhr-device']` is set (device registration comes in M16).
- Tests: `backend/tests/Feature/AuthTest.php`, `frontend/src/lib/__tests__/router.test.ts`.
- User commands for 03: `docker compose up -d` (creates mailpit) → `docker compose restart web` (installs @tanstack/react-query) → `docker compose exec api php artisan test`.

**Open questions in SRS** (ask the user only when you reach them):
- SMS provider for OTP.
- Whether F-31 approval levels each sign from their own account.
- ZERP data scope.

## 7. Testing
```
docker compose exec api php artisan test        # backend (DB uhr_test; RefreshDatabase)
docker compose exec web npm run typecheck
docker compose exec web npm test
```
- `phpunit.xml` forces `DB_DATABASE=uhr_test`, `MAIL_MAILER=array`, and `FRONTEND_URL`.
- `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` are not forced, so they don't override compose env. Add `force="true"` when a compose env var must be overridden.
- Verify UI visually: 3 themes × AR/EN at 1440px and 390px, no console errors, no horizontal scroll (SRS §9.4).
- Previous sessions could not reach Packagist from the sandbox. You (Claude Code on the user's machine) can run composer, docker and tests directly, so **always run the tests yourself before handing over**.

## 8. Reference material (`reference/`, read-only)
- `demo/1_System/UHR_System.html`: **the demo v26 itself** (single file; open in a browser to compare).
- `demo/2_NFC_Reader/`: USB reader bridge `UHR-NFC-Bridge.bat`. `demo/3_Documents/`: architecture and user guide PDFs. `demo/erp+uhr/`: ERP+UHR showcase.
- `demo-src/`: React source of the demo (slightly older than v26). This is the logic authority:
  - `lib/actions.ts` maps to API endpoints, `lib/rules.ts` holds the business rules, `data/seed.ts` the seed data, `lib/types.ts` the domain types.
  - `screens/*.tsx` hold the markup to port.
- `demo-bundle/`: v26 bundle unpacked:
  - `app.js` (beautified): 2FA around lines 17760–18300 and 22735–23810; partners around 38200 (dropped).
  - `style.css`, `assets/`.
  - `inventory.json` / `delta.txt` list the strings present in v26 but not in the source (the v26-only features).
  - `UHR_Architecture_v1.0.txt` and `UHR_User_Guide_v1.0.txt` are text versions of the PDFs.
- `screens/`: screenshots of the demo (`refshots/`) and of our task 03 build (`task03/`).
