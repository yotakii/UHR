# UHR — Production HR System (handoff for Claude Code)

> Read this file fully before touching code. It replaces the chat history the project was started in.
> Then read `docs/SRS.md` (the full spec, Arabic — **v3**, §10 holds the company's real policies and the Dev Spec cards D-01…D-34) and `FEATURES.md` (task checklist).

## 1. What we are building
- **UHR (Uniqueness HR)**: production HR system for Atmata Group's client "Uniqueness / الفرادة". Owner: Atmata Group (Damascus).
- **Goal:** match the exhibition demo **v26** (`reference/demo/1_System/UHR_System.html`) **screen-for-screen in look, logic and features**, but as a real system with a database and a server-side API.
- **Decision (Oct 12, 2026) — production, NOT a demo.** Remove every exhibition-only tool:
  - relaxed mode, demo accounts list, visitors, sample-data buttons, reset, showcase/attract/expo mode, product tour, partners ticker, kiosk *simulation* (a real NFC terminal only on a registered device).
  - Everything that was hard-coded in the demo becomes manageable in **M16 settings** (company name, hero texts, prefixes, rules, SLAs, policies with effective dates, approval chains, holidays…).
- **v3 (Oct 15, 2026): the company's real HR rules override the demo's values.** Source files in `reference/company/` (text copies in `reference/company/text/`): staff regulations, violations table, attendance & leave policy HR.01-01, resignation procedure HR.01-03, official forms, conduct charter, and `UHR_Dev_Spec.xlsx` (34 feature cards, permission matrix, 4 phases). Everything is merged into SRS §10:
  - Multi-company (company_id + subdomain), editable permission matrix + `recruiter` role, unified request workflow (mandatory reason + second confirmation, delegates, 24h reminders).
  - Company policy defaults: 09:00–17:00 Sat–Thu, late-deduction table, overtime after 48h/week, leave notice periods + mandatory substitute, sick 70%/80%, notice 60 days.
  - New module **M17 discipline** (violations ladder, warnings F-01, complaints F-02); M11 follows HR.01-03 (forms F-02…F-07); staged referral points 10/20/30/40; match bands ≥50 / 41–49 review / ≤40 auto-reject; AI preliminary tests (Claude API, the only AI-labelled feature).
  - Values not yet decided by the client are in SRS §10.14 with the provisional default we use.
  - **Public jobs portal (SRS §10.15):** the main page is a multi-company careers portal — Atmata and every client company publish vacancies there (visibility internal/company/public, optional platform moderation); one applicant account applies to any company and each application is a `candidates` row inside that company; the current M01 page becomes the company page. Real path URLs + JobPosting JSON-LD for SEO. Built in task 05b right after the foundation.
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
  - Company system routes: `Route::middleware(['auth','fresh','portal:hr'])` + per-route `perm:module,action` (see Task 05 part 2).
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
- **Everything policy-driven is configurable per company (user decision, Oct 19):** no business threshold, weight, score, limit or period is hard-coded. Each one lives in company settings/policies (`Settings::DEFAULTS` / `POLICY_DEFAULTS` hold only the defaults, which equal the demo or the company documents), has an admin screen with validation, «reset to defaults», and (where it changes scores) a preview before saving; saving is audited and re-applies to open records when relevant (e.g. `MatchScore::recompute`). Check every new module against this rule.
- **No horizontal scrolling (user decision, Oct 18):** every screen shows all its boxes/columns at once — boards and card strips wrap with `grid [grid-template-columns:repeat(auto-fill,minmax(…,1fr))]`, wide tables merge or hide secondary columns per breakpoint (`hidden md:table-cell`) or become cards on mobile, tabs and lifecycles wrap. Check with `scratchpad/e2e/scan.mjs`-style scans at 1440 and 390.
- **Porting rule:** to build a screen, open the demo source for it (`reference/demo-src/screens/*.tsx`) and copy markup and classes 1:1. Replace `useStore`/`actions.ts` calls with API calls (TanStack Query), and drop demo-only bits. Where the v26 bundle differs from the source, the **v26 bundle wins** (`reference/demo-bundle/app.js`, beautified; app code starts at about line 15900).
- **Business logic lives on the server** (port `reference/demo-src/lib/rules.ts`: matchScore, F25, F19, calcDay, settlement, annualEntitlement… to PHP), and is unit-tested against the TS originals.
- **Routing:** path router (History API) `frontend/src/lib/router.ts` (`/page/id/…?query`); links are intercepted, old `#/` URLs redirect. Use `go()`, `href()`, `setQuery()` and `useRoute()`. SEO: `lib/seo.ts` (`setMeta`, `jobPostingLd`) + `/sitemap.xml`.
- **Multi-company:** `App\Support\Tenant` (`set/id/company/run/forget`) + trait `BelongsToCompany` (global scope + fills `company_id`; `Model::allCompanies()` to bypass). Middleware `ResolveTenant` runs every request inside the user's company. Settings/Counters/Audit are per company; `Settings::platform()` = company_id null. New company: `CompanyProvisioner::create()` (defaults via `CompanyDefaults`). Tests: `$this->makeCompany()` / `useCompany()`.
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

## 6. Status (Oct 15, 2026)
| Task | Status |
|---|---|
| 00 Docker infra, `/api/health`, `#/status` | ✅ user verified |
| 01 Design system & components (`#/kit`) | ✅ user verified |
| 02 Database, `uhr:install`, TestDataSeeder (demo-identical), audit log | ✅ user verified (after adding mockery) |
| 03 M01 public page and login | ✅ tests pass (backend + frontend) |
| 04 M02 apply wizard F-28 → M05 applicant portal (see below) | ✅ user verified |
| 04b v3 amendments to the apply flow (match bands, data consent, photo per job, referral rules) | ✅ user verified |
| 05 Foundation — multi-company, recruiter, DecisionDialog, Shell, permission matrix + panel, M15 sidebar/dashboard/bell/Ctrl K, forms registry + print template, file store. Unified request workflow moves to task 07 (with leaves) | ✅ user verified |
| 05b Public multi-company jobs portal, company/job pages, HR jobs screen, platform console (companies, reviews, portal texts), one applicant account, SEO | ✅ user verified |
| 06a Recruitment: M03 Kanban + M04 candidate file (F-25, F-29 issue, F-31 approvals per level) → employee file | ✅ user verified |
| 06b M06 onboarding (F-19, F-32, undertakings + conduct charter, custody, add-employee wizard, Excel import) + staged referral points + employee «documents to sign» | ✅ user said next |
| 06c CV database + applicant data erasure + manager alert + AI preliminary tests (Claude API) + configurable matching | ✅ user said next |
| 07a M07 directory, employee file, org chart, Excel export, additional documents | 📦 delivered, waiting for the user's test |
| 07b attendance + policies · 07c leave + unified request workflow + justifications · 07d monthly sheet + services/certificates · 08 Custody, M17 discipline, exit per HR.01-03 · 09 M12–M14 + development · 10 salary 2FA · 11 M16 + Reverb · 12 final verification | |

**Task 03 delivered:**
- Landing page: hero texts from settings, 3 cards, login card with show-password.
- «نسيت كلمة المرور؟» → `#/forgot` and `#/reset?token&email` (bilingual email via `App\Notifications\ResetPasswordNotice` + `resources/views/mail/reset.blade.php`).
- Jobs board from `/api/public/jobs`; job page `#/jobs/REQ-…` with a copy-link button. The apply wizard placeholder is replaced in task 04.
- Lockout after 5 failures (`unlock_minutes` policy). Blocked or terminated users are refused. Forced temp-password change screen.
- Per-portal placeholder home (`screens/Account.tsx`).
- The NFC terminal link shows only if `localStorage['uhr-device']` is set (device registration comes in M16).
- Tests: `backend/tests/Feature/AuthTest.php`, `frontend/src/lib/__tests__/router.test.ts`.
- User commands for 03: `docker compose up -d` (creates mailpit) → `docker compose restart web` (installs @tanstack/react-query) → `docker compose exec api php artisan test`.

**Task 04 delivered:**
- Backend layout (use it for every module):
  - `app/Services/<Domain>/` business logic: `Recruitment/MatchScore` (port of matchScore, unit-tested against values generated from rules.ts), `DuplicateCheck`, `ApplicationService` (submitApplication), `OfferResponse` (respondOffer + opens F-31 from the `appointment` approval chain).
  - `app/Http/Requests/` FormRequests with bilingual messages (`ApplicationRequest`); `app/Http/Resources/` API output (`MyApplicationResource`).
  - `App\Exceptions\ApiException` for business-rule refusals: `throw new ApiException($msg, 'code', 409)` or `ApiException::field('email', $msg, 'code')` (422 under the field).
  - `App\Support\Notify::hr()/user()` for bell notices.
- Endpoints: `POST /api/public/applications/check-duplicate`, `POST /api/public/applications` (multipart, `throttle:applications`), `GET /api/me/application`, `POST /api/me/application/offer-response`.
- Duplicate policy (production, stricter than the demo): merging into a previous file requires that account's password; an email that belongs to staff → «apply internally»; a file at offer/appointment/hired is not overwritten (409).
- Files: CV → `storage/app/private/cv/Y/m`, offer signature PNG → `signatures/Y/m` (both in `files`). Signatures are returned inline as data URLs, never public links.
- Confirmation mail `ApplicationReceived` is queued → new compose service **`worker`** (`queue:work`); generic bilingual mail view `mail/notice.blade.php`.
- Frontend layout: `lib/public.ts` (public queries), `lib/recruitment.ts` (stages/degrees + applicant hooks), `ui/password.tsx` (PasswordFields), `screens/apply/` (`form.ts` model+validation, `Apply.tsx`, `Applied.tsx`, `submission.ts`), `screens/candidate/CandidateHome.tsx`. `ui/docs.tsx` OfferDoc/DecisionDoc now take the API shape (snake_case).
- «الدخول كموظف» just refetches `/auth/me` (same user row gets `employee_id` at hire, M04).
- Tests: `tests/Unit/MatchScoreTest.php`, `tests/Feature/ApplicationTest.php`, `tests/Feature/CandidatePortalTest.php`, `frontend/src/screens/apply/__tests__/form.test.ts`.
- User commands for 04: `docker compose up -d` (creates the worker) → `docker compose exec api php artisan test`.

**Tasks 04b + 05 (part 1) + 05b delivered:**
- Accounts (password `123456`): `platform@atmata.sy` (platform_admin → `/platform`), `hr@atmata.sy` (Atmata HR), `jobs@atmata.sy` (recruiter), plus the alfarada accounts above. Applicant accounts have `company_id = null` and many `candidates` rows (one per company).
- Public routes: `/` portal, `/jobs?company&city&type&exp&q`, `/c/{slug}`, `/jobs/{slug}/{code}?ref=`, `/apply/{slug}/{code}`, `/my`, `/my/{id}`. API: `/api/public/{portal,jobs,companies/{slug},jobs/{slug}/{code}[/check|/apply]}`, `/api/me/applications…`, `/api/hr/jobs…` (`perm:jobs,…`), `/api/platform/…` (portal:platform).
- Job visibility internal/company/public; public jobs of a company with `portal_review` go `pending` → platform approves or rejects (reason ≥ 10 chars); editing content re-triggers review. `closes_at` + scheduled `jobs:close-expired` (compose service **`scheduler`**).
- Match bands: ≥50 screening, 41–49 review, ≤40 auto-reject + polite `ApplicationClosed` mail. Consent `agree_data` required, `keep_cv` optional, photo only when `requires_photo`.
- Tests: PortalTest, JobPublishingTest, PlatformTest + updated suites (68 backend, 27 frontend).

**Task 05 part 2 delivered:**
- **Permissions** (`App\Support\Perm`): matrix role × module × actions (`view create edit decide export print archive` = ع ض ت ق ص ط ح) × scope (`own/team/all`). Defaults from SRS §10.2 live in `Perm::DEFAULTS`; company overrides in `role_permissions` (missing cell = default). User = union of roles. Routes use `perm:module,action` (e.g. `perm:jobs,create`); the session payload carries `perms` and the UI hides with `can(user, module, action)` (`lib/session.ts`). Practical additions over the spec table: HR edits/decides on candidates and prints in its modules; executives view-only. Panel `/hr/settings/permissions` (admin) — saving is audited as `permissions.updated` (before/after letters) and refuses `self_lockout`.
- **M15**: `screens/console/Shell.tsx` has the bell (`/api/notices`, mark read after 1.5s; HR notices filtered by the module of their link) and the Ctrl K palette (`/api/hr/search`, by permission). `screens/hr/HrApp.tsx` = the demo sidebar filtered by `view` permission; unbuilt modules show a "being built in task N" page. `/` for an HR account = dashboard. Notice/task links → paths via `hrLink()` (`lib/hr.ts`).
- **Dashboard** `/api/hr/dashboard` (`Services/Dashboard/HrDashboard`): every section by permission; tasks newest first with `overdue` after 24h. `Services/Attendance/DayCalc` = `calcDay` port with policy params (unit-tested against rules.ts).
- **Print**: `form_templates` registry per company (`App\Support\Forms::DEFAULTS`: demo F-xx + company HR.01-0x-F-0y, printed `ref`, issue, date, `sensitive`) + `Settings 'print'` (logo, height, align, margin). `Paper` (`ui/docs.tsx`) reads `/api/print/{code}` (applicants pass `?company=slug`) — logo + centered title + fixed footer `ref · Issue · Date`; printing a sensitive form posts `/api/print/{code}` → audit `print`. Page `/hr/settings/forms`.
- **Files**: `/api/files/{id}` only (no public links) — owner or same-company user with `view` on the file's module (`Services/Files/FileAccess`); every download audited `file.download`. Use `fileUrl(id)` in the UI.
- Tests: `tests/Feature/FoundationTest.php`, `tests/Unit/DayCalcTest.php`, `frontend/src/lib/__tests__/hrlink.test.ts` (78 backend, 29 frontend).

**Task 06a delivered:**
- **Decision (Oct 16):** each F-31 level is approved from its owner's own account — the user named in the `appointment` approval chain, else any company user holding the level's `role_key` — strictly in order; admin cannot approve on anyone's behalf. Rejecting a level needs a reason (≥10) and closes the application.
- Services: `Recruitment/Pipeline` (move with rules: no move after the appointment opens except reject, `hired` only via F-31, `offer` needs an issued offer, reject needs a reason → `ApplicationClosed` mail; interview stage → `interview_invite` mail; notes; F-25 save + auto-move to technical when recommended; F-29 issue/revise → `offer_sent` mail), `Recruitment/F25` (port of F25/f25Score/f25Label, unit-tested), `Recruitment/AppointmentFlow` (decide + hire: employee code, onboarding row, applicant user → employee of the company, NFC card from stock `pending_ack`, referral status `hired`; points come in 06b). `Notifications/CandidateMessage` = template-driven candidate mail.
- API: `/api/hr/candidates[?job&q]`, `/{id}`, `/{id}/move|notes` (POST), `/{id}/interview|offer` (PUT) — `perm:candidates,view|edit`, reject also needs `decide`; `/api/hr/approvals` (my turn only) + `POST /api/hr/approvals/{approvalId}`; `/api/hr/recruitment-rules` (GET by candidates view, PUT by `settings,edit`, bands must ascend). Resource `Http/Resources/HrCandidate` (card/full/appointment with headcount check).
- Recruiter default now includes `decide` on candidates (rejecting applicants is their job).
- Frontend: `lib/candidates.ts`; `screens/hr/recruitment/` — `Board` (7 columns, drag & drop, SLA/match/badges, reject via DecisionDialog with presets), `CandidateFile` (tabs via `?tab=`), `InterviewForm`, `OfferTab`, `Approvals` (`MyApprovals` page `/hr/approvals` + `ApprovalActions`), `RulesModal`. `DecisionDialog` got `presets`. `OfferDoc` takes the company name.
- Tests: `tests/Feature/RecruitmentTest.php`, `tests/Unit/F25Test.php` (87 backend, 29 frontend).
- Layout fix (Oct 18): no screen needs horizontal scrolling (see §4).

**Task 06b delivered:**
- Migration `2026_10_18_000001_add_onboarding_and_points`: onboardings + `conduct_signature_file_id/conduct_signed_at`, `probation_started_at`, `confirmed_at/by`; new `point_logs` (company, employee, referral, candidate, stage 1..4, points ±, reason ar/en, by, at; unique candidate+stage). Fixes F-20/F-22/F-32 titles to SRS §1.5 and adds forms `COC` (conduct charter) and `CUS` (custody receipt).
- `App\Support\Signature` (store PNG data URL / dataUrl) — used by offers, undertakings and custody receipts.
- Services: `Onboarding/F19` (28 items, AUTO = 4 memo · 10 F-22 · 12 F-20 · 22 custody · 27 confirm, locked in UI and API `auto_item`; 14 done → probation), `Onboarding/OnboardingFlow` (toggle, memo → bell notice to every company employee, issue/return asset, employee `sign` f22|f20(mother name)|conduct|asset, `confirm` probation → active + referral stage 4, `probationEnd` from policy `probation_days`), `Onboarding/WelcomeMemo` (F-32 text, gendered), `Referrals/ReferralPoints` (rules `referral_stages` [10,20,30,40] × `hard_to_fill_x` 2; `sync` after every stage change: screening → 1, interview/technical/offer → 2, hired → 3; once per stage, nothing after rejection → referral `closed`; statuses shared → applied → processing → hired → rewarded), `People/NewEmployee` (wizard: onboarding + F-19 items 1 & 14 + temp-password account + `EmployeeWelcome` mail + assets pending_ack; shared row validation `check()`), `People/EmployeeImport` (openspout: xlsx template with codes sheet, preview with per-row errors, all-or-nothing commit, active without F-19, counter bumped past imported codes).
- New composer package **`openspout/openspout`** (xlsx/csv read & write) — the api entrypoint runs `composer install` because composer.json changed.
- API: `/api/hr/onboarding` (+ pending F-31 when the user sees candidates), `/{id}`, `/{id}/check|memo|assets|confirm` (POST), `DELETE /{id}/assets/{assetId}` — `perm:employees,view|edit`, list scoped by the employees scope (own/team/all); `/api/hr/employees/lookups`, `POST /api/hr/employees`, `/api/hr/employees/import/template|preview|import` — `perm:employees,create`; `/api/me/documents` + `POST /api/me/documents/sign` for any account linked to an employee. Rules: `referral_points` removed, `referral_stages` + `hard_to_fill_x` added (RulesController fills new keys from defaults).
- Frontend: `lib/onboarding.ts`; `screens/hr/onboarding/` — `OnboardingList` (pending F-31 + new joiners, buttons «إضافة موظف» / «استيراد من Excel»), `OnboardPage` (tabs `?tab=f19|f32|custody|pledges|log`, confirm via DecisionDialog), `AddEmployee` (4-step wizard), `ImportEmployees`; `screens/employee/` — `EmployeeApp` (employee portal shell: home + documents; rest in tasks 07/09) and `MyDocuments` (custody receipts, F-22, F-20 + mother name, conduct charter text from `reference/company`). HR staff with an employee file get «وثائقي للتوقيع» at `/hr/my-documents`. RulesModal edits the four stage values and the multiplier. Notice links: `onboard:{id}`, `docs`.
- Tests: `tests/Feature/OnboardingTest.php` (91 backend, 29 frontend).

**Task 06c delivered:**
- Migration `2026_10_19_000001_add_cv_database_and_tests`: candidates `cv_status` (active/archived/erased) + `cv_archived_at`, `erased_at`; job_openings `test_enabled`, `test_audience` (all|passed), `test_minutes`, `test_attempts`, `test_valid_days`; tables `job_tests` (draft|approved, questions + AI `generated` copy) and `candidate_tests` (token, attempt, snapshot questions, answers, suggested, scores, auto_pct, pct, sent/expires/started/deadline/submitted/reviewed). `Audit::SECRET` now masks `token` and `api_key`.
- **Claude API** (the only AI feature): composer package **`anthropic-ai/sdk`**. `Services/Ai/AiClient` (interface, bound in AppServiceProvider; tests swap a fake) + `ClaudeClient` — model `claude-opus-5-5`, structured outputs (json_schema), effort medium, server-side refusal fallback (`fallbacks: 'default'`, beta `server-side-fallback-2026-07-01`), typed SDK errors → bilingual ApiException codes `ai_not_configured|ai_auth|ai_busy|ai_unavailable|ai_incomplete`. Key = company setting `ai.api_key` (Crypt-encrypted, page «مفتاح Claude API» in the job test editor, needs `settings,edit`) else env `ANTHROPIC_API_KEY`. Only job data / question+rubric+answer are sent — never applicant personal data.
- `Services/Recruitment/PreliminaryTests`: generate (job title, dept, requirements, degree, years → 8 questions), save/approve (audit `test.generated|approved`, `edited` flag), `afterApplication` (auto-send if enabled+approved and audience matches band), send/resend within attempts (`CandidateMessage` template `test_invite`, link `/test/{token}`), start (server timer), submit (mcq auto-scored, open answers get AI suggested scores), review (HR final scores → pct, audit `test.reviewed`), expire (`tests:expire` every 10 min). No auto-reject.
- `Services/Cv/CvDatabase`: search (keep_cv only; q over name/major/experience/job, degree ≥, years ≥, city, status), export fields, archive/restore, `archiveExpired` (rule `cv_retention_months` 24, command `cv:archive-expired` daily), `erase` (applicant request: wipes files + personal fields, unlinks the account, redacts earlier candidate audit rows, keeps an anonymised trace; refused at appointment/hired → `erase_locked`). ApplicationService notifies department managers of each new CV (no personal data in the notice).
- API: `/api/hr/cv` (candidates,view; audited `cvdb.search` with filters/count/fields), `/api/hr/cv/export` (candidates,export; xlsx; audited `cvdb.export`), `POST /api/hr/cv/{id}/archive` (`restore` flag; candidates,edit); `/api/hr/jobs/{id}/test` GET (jobs,view) / PUT, `POST …/test/generate` (jobs,edit); `/api/hr/ai-settings` GET (jobs,view) / PUT (settings,edit); `POST /api/hr/candidates/{id}/test` and `/test/review` (candidates,edit); public `/api/public/tests/{token}` + `/start` + `/submit`; `POST /api/me/applications/{id}/erase` (password). HrCandidate card has `test`, full has `test_detail`, `keep_cv`, `cv_status`; MyApplicationResource has `test` (link while open), `keep_cv`, `can_erase`.
- Frontend: `lib/tests.ts`; `screens/hr/recruitment/JobTest.tsx` (`/hr/jobs/{id}/test`, settings + question editor + «اقتراح الأسئلة بالذكاء الاصطناعي» + key modal), `TestTab.tsx` (candidate file tab `?tab=test`, review), `CvDatabase.tsx` (`/hr/cv`, nav «قاعدة السير الذاتية»); test score on Kanban cards and the file header; Jobs rows get a «الاختبار» button; public page `screens/public/TakeTest.tsx` (`/test/{token}`, server-synced timer, answers kept in localStorage, auto-submit); applicant page shows the test card and «حذف بياناتي».
- Recruiter panel (D-21) = HrApp filtered by the recruiter's permissions (jobs, applicants, CV database, approvals, referrals view) — no employee data.
- Tests: `tests/Feature/PreliminaryTestTest.php`, `tests/Feature/CvDatabaseTest.php` (99 backend, 29 frontend; 101 after configurable matching).

**Configurable matching (Oct 19):**
- `Settings::DEFAULTS['match']` + `Settings::match()` (saved values over defaults, default key order — jsonb reorders keys): `weights` (exp/deg/sal/eng/file, sum 100, 0 = off), `exp` (meets, bonus_years, below_max), `deg` (above/equal/one_below/lower), `sal` (to_mid/to_max/over/over_penalty), `eng` (excellent/good/fair/weak), `file` (which items count). All percentages; defaults reproduce the demo exactly (`MatchScoreTest`).
- `MatchScore::config($job)` = company calibration + per-job `job_openings.match_weights` (migration `2026_10_19_000002`, JobForm «أوزان مطابقة خاصة بهذا الشاغر»); `compute($c, $job, $cfg)`; `recompute(?$job)` re-scores open applications (score + band, no stage moves) after saving rules or changing a job's weights/requirements.
- `RulesController`: GET returns rules, stage_sla, match, defaults; PUT validates (`validMatch`) and re-scores; `POST /api/hr/recruitment-rules/preview` (settings,edit) returns current vs new score for the latest open applications. Rules also hold `cv_retention_months` and `probation_at` (F-19 threshold, was a constant).
- Page `/hr/settings/recruitment` «قواعد التوظيف والمطابقة» (`screens/hr/RecruitmentSettings.tsx`) replaces RulesModal: weights, per-criterion scoring, preview, bands & automation, stage SLAs, referral points, retention, probation threshold. Editable with `settings,edit`; read-only for candidates viewers.

**Task 07a delivered (Oct 20)** — task 07 is split: 07a M07 · 07b M08 attendance + attendance policies page · 07c M09 leave + unified workflow + justifications · 07d monthly sheet + services:
- Migration `2026_10_20_000001_add_employee_documents`: `employee_documents` (type key from settings, title, file_id, expires_at, sensitive, status active/expired/archived, note, uploaded_by, alerted_at, archived_at/by). No hard delete.
- `Services/People/Directory`: `scoped($user, $module='employees')` (own/team/all — used by every M07 and M06 endpoint), `visible()`, `entitlement($e, $on)` (annualEntitlement port, values from policy `annual_leave`), `tenureDays()`. `Services/Attendance/RecentDays::rows($e, 14)` = AttendanceTable port (statuses before_start/leave/holiday/not_in/weekend/absent/justified/late/present, approved leave requests count as leave). `Services/People/EmployeeProfile` (`update`: personal + job fields, no salary, manager loop check, email also renames the login, dept/manager/title changes go to the timeline; `card`: NFC UID normalised `04:A1:…`, unique platform-wide, logged). `Services/People/EmployeeDocs` (upload with type/extension/size from settings, sensitive only by `docs.sensitive_roles`, archive/restore, `history()` from audit_logs = upload/view/download/archive/restore, `sweep()` → expired + one alert to HR and the employee `alert_days` before; command `docs:sweep` daily 07:30).
- `Settings::DEFAULTS['docs']` + `Settings::docs()`: types (8), extensions pdf/jpg/jpeg/png/docx, max_mb 10, alert_days 30, sensitive_roles [hr_director, hr_officer]. Page `/hr/settings/documents` (`screens/hr/DocumentSettings.tsx`, settings,edit; a type in use cannot be removed; dangerous extensions refused; audited `settings.docs`).
- `FileController` logs `file.view` (inline) or `file.download`. `FileAccess` knows employee documents: module employees + the viewer's employee scope + sensitivity.
- API (`DirectoryController`, `EmployeeDocController`): `GET /api/hr/employees?q&dept&status` (current|onboarding|probation|active|leave|resigning|terminated|all), `GET /{id}` (HrEmployee::full: tabs data, leave balance, attendance, leaves, services, assets, documents, logs, `can`), `PUT /{id}`, `PUT /{id}/card`, `POST /{id}/documents` (multipart), `POST /{id}/documents/{doc}/archive` (`restore`), `GET /{id}/documents/{doc}/log`, `GET /api/hr/employees/export` (employees,export, xlsx, audited `employees.export`, no salary), `GET /api/hr/org` (org,view; `open` = inside the viewer's employee scope), `GET|PUT /api/hr/employee-docs/settings`. `/api/hr/employees/lookups` moved to employees,view (assets only for creators).
- Frontend: `lib/employees.ts`; `screens/hr/employees/` — `Employees` (filters in the URL, add/import/export), `EmployeeFile` (`?tab=overview|attendance|leaves|assets|docs|log`, lifecycle, salary shown as «محمي» until task 10), `EditProfile` + `CardModal` (USB reader types the UID), `DocumentsTab` (upload, view/download, history, archive), `OrgChart` (demo markup; fits the page width by scaling — no horizontal scroll; nested list under md). `EmpBadge` = the six status badges.
- Tests: `tests/Feature/EmployeeDirectoryTest.php` (10 tests).

**Open questions in SRS** (ask the user only when you reach them):
- SMS provider for OTP.
- ZERP data scope.

## 7. Testing
```
docker compose exec api php artisan test        # backend (DB uhr_test; RefreshDatabase)
docker compose exec web npm run typecheck
docker compose exec web npm test
```
- The api image has GD (tests use `UploadedFile::fake()->image()` and signature PNGs). `tests/TestCase` clears Eloquent's cached column lists after each test (a unit test filling a model can otherwise cache a stale `uhr_test` schema).
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
- `company/` (v3): the client's real documents — **authoritative for rules, values, forms and approval paths** (SRS §1.1 priority 0). Use `company/text/*.txt` to read them; forms are in `company/forms/` (docx/pdf/xlsx) and their printed layout (logo top, centered title, footer `Uniqueness-HR.01-0x-F-0y · Issue · Date`) is the target for `Paper`.
