# Barangay Health Center Survey System — React application

React 18 + Vite + React Router, Chart.js **4.4.4** / react-chartjs-2, plain CSS and the existing PHP/MySQL backend. The migrated screens retain the original PHP appearance, labels, validation and role rules. Function components and hooks compose shared layouts, forms, tables, charts and dialogs. Development prop validation uses [prop-types](https://github.com/meta/prop-types); optional behavior uses parameter defaults.

## Setup

1. Install Node.js **22.12 or newer** and npm (verified with Node 26.13.0 / npm 11.20.0). The installed Vite version also supports Node 20.19+, but use 22.12+ for this project.
2. Keep this whole project in XAMPP's document root as `barangay_survey_system`. On this Mac the root is `/Applications/XAMPP/xamppfiles/htdocs/barangay_survey_system`.
3. Start **Apache and MySQL** in XAMPP Manager. Apache must serve `http://localhost` on port 80. PHP 8.x with mysqli and image-upload support is required; the existing XAMPP PHP 8.2 runtime is used for verification.
4. Use the **existing `barangay_survey_system` MySQL database** and the existing connection configuration in `config/db.php`. Do not recreate, reset or re-import an existing database. For a genuinely empty installation, an administrator can first provision it from `database/barangay_survey.sql`; the existing PHP bootstrap supplies its established incremental initialization. Keep connection credentials private. This React migration does not change the schema, database configuration or `config/functions.php` business rules.
5. Apache needs `mod_rewrite` and `.htaccess` overrides enabled for both `app/` and `api/`. PHP must be able to write profile photos to the existing upload directories. Confirm [the session API](http://localhost/barangay_survey_system/api/session) returns JSON before starting the frontend.
6. Install the locked frontend dependencies:

```sh
cd /Applications/XAMPP/xamppfiles/htdocs/barangay_survey_system/frontend
npm ci
```

Use accounts already issued by the health center. Newly issued Resident/Staff Numbers are temporary passwords until the first password change; there is no self-registration or email recovery. Staff onboarding proceeds through password setup and Complete Profile. No real account credentials are included here.

## Run during development

```sh
# From frontend/
npm run dev
```

Open [the development app](http://localhost:5173/barangay_survey_system/app/) (or Vite's printed URL if that port is occupied). Vite proxies `/api/...` to `http://localhost/barangay_survey_system/api/...`, and forwards existing uploads/assets to Apache. MySQL and Apache must stay running. The original DOM-manipulating `assets/js/script.js` is not loaded into React.

## Build and serve through XAMPP

```sh
# From frontend/
npm run lint
npm run build

# Copy ALL build files, including the Apache fallback dotfile.
mkdir -p ../app
cp -R dist/. ../app/
```

The build is generated in `frontend/dist/`; its contents are deployed in root `app/`. Open **[the built React app](http://localhost/barangay_survey_system/app/)**. A direct visit or refresh of [the sign-in route](http://localhost/barangay_survey_system/app/login) must also work. `app/.htaccess` preserves real files and directories and falls back to `index.html` for React routes. Do not copy the build over the original PHP pages. Copying a subsequent build replaces its generated entry/assets; older hashed assets may remain harmlessly until an explicit deployment cleanup.

Vite's `base`, React Router's basename and the Apache `RewriteBase` all use `/barangay_survey_system/app/`. Production requests use the same-origin `/barangay_survey_system/api/` prefix; there is no dependency on Vite's development proxy. Changing the folder or mount path requires updating those three settings and the corresponding API/upload paths together.

`npm run preview` previews `dist/` through Vite; it does not replace the Apache/PHP verification above.

## Folder structure

```text
barangay_survey_system/
├── frontend/
│   ├── src/
│   │   ├── components/     shared UI, layouts and feature components
│   │   ├── pages/          public, resident, staff and development views
│   │   ├── hooks/          data, auth, forms, dirty warning and photo crop
│   │   ├── context/        session and shared form-warning state
│   │   ├── routes/         route inventory and role/onboarding guards
│   │   ├── api/            fetch client and development/production API base
│   │   ├── config/         person/address field transforms
│   │   ├── utils/          modal reset helper
│   │   ├── assets/         unchanged shared CSS/logo copies
│   │   └── propTypes.js    shared component contracts
│   ├── public/.htaccess    production SPA route fallback
│   ├── dist/               npm run build output
│   └── vite.config.js      mount base and local proxy
├── app/                    generated build served by Apache
├── api/                    JSON/CSV router, PHP adapters and .htaccess
├── docs/                   migration notes, progress, tests and verification
├── config/, includes/      original server helpers, database/session bootstrap
├── resident/, staff/       original PHP screens and handlers
├── admin/                  original compatibility entry
├── assets/, uploads/       original styling/icons and stored photos
└── database/               original SQL setup/migration records
```

## Route map

Paths below are relative to `/barangay_survey_system/app/`. Source PHP paths are relative to the project root. The root `/` renders the landing screen and `/landing` remains an alias. Portal routes require a valid session; staff/admin onboarding and admin-only checks remain enforced by the API as well as the router.

| React route | Original PHP source | Access / query |
| --- | --- | --- |
| `/` | `landing.php` | Public |
| `/landing` | `landing.php` | Public |
| `/login` | `login.php` | Public |
| `/resident/dashboard` | `resident/dashboard.php` | Resident |
| `/resident/stat-detail` | `resident/stat_detail.php` | Resident; `view=available/completed/assigned` |
| `/resident/surveys` | `resident/surveys.php` | Resident; optional `submitted=<surveyId>` |
| `/resident/survey-form` | `resident/survey_form.php` | Resident; `id=<surveyId>`, optional `error` |
| `/resident/confirmation` | `resident/confirmation.php` | Resident; optional `survey_id` |
| `/resident/history` | `resident/history.php` | Resident |
| `/resident/view-response` | `resident/view_response.php` | Resident; `id=<responseId>`, optional `print` |
| `/resident/profile` | `resident/profile.php` | Resident |
| `/resident/change-password` | `resident/change_password.php` | Resident |
| `/staff/dashboard` | `staff/dashboard.php` | Staff/admin |
| `/staff/stat-detail` | `staff/stat_detail.php` | Staff/admin; `view` whitelist; account/activity views admin only |
| `/staff/survey-management` | `staff/survey_management.php` | Staff/admin |
| `/staff/add-survey` | `staff/add_survey.php` | Staff/admin |
| `/staff/edit-survey` | `staff/edit_survey.php` | Staff/admin; `id=<surveyId>` |
| `/staff/question-management` | `staff/question_management.php` | Staff/admin; `survey_id=<id>` |
| `/staff/results-dashboard` | `staff/results_dashboard.php` | Staff/admin; optional `survey_id` |
| `/staff/reports` | `staff/reports.php` | Staff/admin; optional `survey_id` |
| `/staff/resident-management` | `staff/resident_management.php` | Staff/admin; optional `edit=<residentId>` |
| `/staff/user-management` | `staff/user_management.php` | Staff/admin; staff roster/actions admin only |
| `/staff/complete-profile` | `staff/complete_profile.php` | Staff/admin; own profile; admin record/photo only |
| `/staff/change-password` | `staff/change_password.php` | Staff/admin |
| `/staff/address-requests` | `staff/address_requests.php` | Staff/admin; resident review; staff/family panels admin only |
| `/staff/family-requests` | `staff/family_requests.php` | Staff/admin; guarded redirect to `/staff/address-requests` |
| `/staff/deletion-requests` | `staff/deletion_requests.php` | Staff/admin; read for staff/admin; decisions admin only |
| `/staff/deleted-accounts` | `staff/deleted_accounts.php` | Admin |
| `/staff/residency-records` | `staff/residency_records.php` | Admin; optional `filter=permanent/temporary` |

Compatibility routes (session guards apply to their destinations):

| Entry | Destination |
| --- | --- |
| `/admin` | `/` |
| `/admin/index.php` | `/` |
| `/resident/login` | `/` |
| `/resident/login.php` | `/` |
| `/staff/login` | `/` |
| `/staff/login.php` | `/` |
| `/staff/statistics` | `/staff/dashboard` |
| `/staff/statistics.php` | `/staff/dashboard` |

`/dev/components` is a development-only shared-component gallery, excluded from the production route tree. Unknown URLs retain the existing Page Not Found screen. Logout, submissions, statistics and CSV downloads are API actions rather than extra screens. The legacy family-request PHP screen has missing alternative schema/helper dependencies; the authorized React redirect uses the working integrated review queue.

## Implemented API list

The authoritative dispatcher is `api/index.php` (not the proposed endpoint names in the initial analysis). Prefix every path below with **`/barangay_survey_system/api/`** in production; Vite uses `/api/` in development. Requests use the existing PHP session cookie with `credentials: include`. Business rules, authorization and transactions stay on PHP/MySQL.

Successful JSON uses `{ok:true,data:...,message?,redirect?,ui?,outcome?}`; errors use `{ok:false,message}` with an HTTP error status. Some valid guard/action outcomes carry a React redirect instead of page data. Unknown paths return 404, unsupported methods 405, and CSV endpoints return attachments. POST accepts the endpoint's existing fields via JSON/form bodies; photo endpoints require multipart. IDs and action fields are submitted as strings where required by the adapters. Never pass a caller-supplied identity to an own-account endpoint.

| Method | Path | Access | Input / purpose |
| --- | --- | --- | --- |
| POST | `change-password` | Current session | `current_password`, `new_password`, `confirm_password`; current session role |
| POST | `login` | Public | `identifier`, `password`; account resolution and session creation |
| POST | `logout` | Current session | Record logout and destroy current session |
| POST | `resident/birthdate-requests` | Resident (own account) | `birthdate`; propose a correction |
| GET | `resident/confirmation` | Resident (own account) | Optional `survey_id`; title/fallback only |
| POST | `resident/contact` | Resident (own account) | `email`, `contact_number`; immediate save |
| GET | `resident/dashboard` | Resident (own account) | Own dashboard metrics/address/household data |
| POST | `resident/family-requests` | Resident (own account) | Family/person fields + `family_reason`; propose household corrections |
| GET | `resident/history` | Resident (own account) | Own response history |
| POST | `resident/name-address-requests` | Resident (own account) | Name + permanent/temporary address fields; propose corrections |
| POST | `resident/photo` | Resident (own account) | Multipart `profile_photo` (+ `upload_photo`); cropped image upload |
| POST | `resident/photo/remove` | Resident (own account) | Remove own profile photo |
| GET | `resident/profile` | Resident (own account) | Own record, household, pending requests and form metadata |
| GET | `resident/response` | Resident (own account) | `id`; own response and typed answers |
| GET | `resident/response-export` | Resident (own account) | `id`; own response CSV attachment |
| GET | `resident/stat-detail` | Resident (own account) | `view=available/completed/assigned` |
| GET | `resident/stats` | Resident (own account) | Own completion series |
| GET | `resident/survey-form` | Resident (own account) | `id`; open/unanswered survey and questions |
| POST | `resident/survey-submissions` | Resident (own account) | `survey_id`, `q<questionId>` answers; atomic one-response submission |
| GET | `resident/surveys` | Resident (own account) | Open surveys, own answer state; optional `submitted` title |
| GET | `session` | Public | Sanitized current identity, password rules and next route; anonymous allowed |
| POST | `staff/address-request/review` | Staff/admin | `request_id`, `action=approve/reject`, optional `review_comment` |
| GET | `staff/address-requests` | Staff/admin | Resident queue; staff/family panel data exposed for admin |
| GET | `staff/dashboard` | Staff/admin | Dashboard metrics; administrative data only for admins |
| POST | `staff/deleted-account/purge` | Admin | `kind=staff/residents`, `id`; permanently delete archived target |
| POST | `staff/deleted-account/restore` | Admin | `kind=staff/residents`, `id`; preserve active/first-login flags |
| GET | `staff/deleted-accounts` | Admin | Archived residents/staff and held-response counts |
| POST | `staff/deletion-request/review` | Admin | `request_id`, `action`, `review_comment` (required on reject) |
| GET | `staff/deletion-requests` | Staff/admin | All requests and archive/restoration state |
| POST | `staff/family-request/review` | Staff/admin | `request_id`, `action`, optional `review_comment`; active household queue |
| POST | `staff/photo` | Staff/admin | Multipart `profile_photo` (+ `upload_photo`); own photo, 5 MB cap |
| POST | `staff/photo/remove` | Staff/admin | Remove own photo |
| GET, POST | `staff/profile` | Staff/admin | Read own employment/profile; POST non-admin personal/address/emergency fields |
| POST | `staff/question/delete` | Staff/admin | `survey_id`, `question_id`; delete/resequence |
| POST | `staff/question/move` | Staff/admin | `survey_id`, `qid`, `move=up/down` |
| POST | `staff/question/update` | Staff/admin | `survey_id`, `question_id`, text/type, `existing_choices`, `new_choices` |
| GET, POST | `staff/questions` | Staff/admin | GET `survey_id`; POST `survey_id`, `question_text`, `question_type`, `choices` |
| GET | `staff/report` | Staff/admin | Optional `survey_id`; complete printable report data |
| GET | `staff/report-export` | Staff/admin | `survey_id`; report CSV attachment |
| GET | `staff/residency-records` | Admin | Optional `filter=permanent/temporary`; read-only history |
| GET | `staff/resident` | Staff/admin | `id`; sanitized editable resident/household and restoration preview |
| POST | `staff/resident/delete` | Admin | `resident_id`; move to archive |
| POST | `staff/resident/password-reset` | Staff/admin | `resident_id`; reset to issued Resident Number |
| POST | `staff/resident/promote` | Admin | `resident_id`, optional `reason`; minimum-stay/address transition |
| POST | `staff/resident/request-delete` | Staff/admin | `resident_id`, `reason`; raise a deletion request (UI staff-only) |
| POST | `staff/resident/revert` | Admin | `resident_id`, required `reason`; restore archived address if present |
| POST | `staff/resident/toggle` | Staff/admin | `resident_id`; activate/deactivate, keep survey history |
| POST | `staff/resident/update` | Staff/admin | `resident_id` + resident/name/address/family fields |
| GET, POST | `staff/residents` | Staff/admin | List + form metadata, or POST resident/name/address/family fields |
| GET | `staff/results` | Staff/admin | Optional `survey_id`; selector and result breakdowns |
| POST | `staff/staff-address-request/review` | Admin | `request_id`, `action`, `review_comment` (required rejection note) |
| GET | `staff/stat-detail` | Staff/admin | Whitelisted `view`; account/activity views admin only |
| GET | `staff/stats` | Staff/admin | Optional `from`, `to`; chart series |
| GET | `staff/survey` | Staff/admin | `id`; one survey metadata record |
| POST | `staff/survey/delete` | Staff/admin | `id`; delete survey and cascading content |
| POST | `staff/survey/toggle` | Staff/admin | `id`; activate/deactivate |
| POST | `staff/survey/update` | Staff/admin | `id` + metadata/status |
| GET, POST | `staff/surveys` | Staff/admin | List or create survey metadata + `questions` |
| POST | `staff/user/delete` | Admin | `staff_id`; archive with self/last-admin protection |
| POST | `staff/user/password-reset` | Admin | `staff_id`; reset to issued Staff Number; self-action protection |
| POST | `staff/user/resident-toggle` | Staff/admin | `resident_id`; User Management wording |
| POST | `staff/user/toggle` | Admin | `staff_id`; self-action protection |
| GET, POST | `staff/users` | Staff/admin | GET resident accounts + admin-only staff roster; POST name/date_hired creates staff (admin only) |

Name fields are courtesy title/custom title, first/middle/last name and extension. Address fields are `household_number`, `purok`, `street_highway`, `barangay`, `municipality`, `postal_code`, `province`, with `temp_` counterparts. Family fields carry occupation/hotline, marital/children flags, fixed spouse/parent fields and indexed children/references. Read responses provide source-derived field metadata; there is no separate form-metadata endpoint. Details, exact validation and source comparisons are in the feature notes under `docs/`.

## Verification and known limits

From the project root:

```sh
npm --prefix frontend run lint
npm --prefix frontend run build
node docs/tests/rubric-audit.mjs
node docs/tests/apache-smoke.mjs
node docs/tests/parity-regression.mjs
PARITY_REACT_URL=http://localhost/barangay_survey_system/app/ node docs/tests/parity-browser.mjs
```

The API/React and browser runners require XAMPP and permission to launch the local Chrome executable used in the scripts. They create registered synthetic fixtures and clean them in `finally`; run fixture suites sequentially. The browser runner also expects Playwright/pngjs installed in `/tmp/barangay-visual-tools` (its `createRequire` path). These are audit tools, not application runtime dependencies. The structural audit uses the parser bundled with the locked Rolldown/Vite dependency.

See [rubric audit](../docs/rubric-check.md), [functional checklist](../docs/parity-checklist.md), [visual audit](../docs/visual-parity-audit.md) and [progress](../docs/progress.md) for evidence. Physical/native print-dialog output and mail/telephone delivery are outside the automated checks. Intentional earlier additions (login portal tabs, survey filters, onboarding and printable staff slip) and the legacy family redirect remain as documented. Source-specific counting, validation, archive wording and unconsumed-flash quirks are preserved.
