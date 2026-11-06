# Authentication JSON API

Implemented in Part 2, step 2 (2026-11-04). XAMPP base URL:
`http://localhost/barangay_survey_system/api`.
Vite callers use `/api`, through the existing proxy. No CORS headers are added.

Apache's existing `mod_rewrite` and `AllowOverride All` serve the extensionless
routes through `api/index.php`. No existing PHP page or configuration was edited.
`api/lib/bootstrap.php` loads the unchanged `config/db.php` and
`config/functions.php`; the latter starts the same PHP session used by the pages.
The API introduces no schema, migrations or database reset. The existing database
bootstrap retains its own initialization behavior. Internal library files are
denied direct web access; the integration runner is CLI-only.

## Requests and responses

POST requests accept a JSON object (`Content-Type: application/json`) or the
original form encoding. Passwords remain untrimmed; only the login identifier is
trimmed. Own-account identity always comes from the session cookie.

| Method | Path | Request fields | Result |
| --- | --- | --- | --- |
| POST | `/api/login` | `identifier`, `password` | Session regeneration, original session keys, `log_login()`, sanitized session state and React destination |
| POST | `/api/logout` | None | `log_logout()` before session teardown, cookie expiry, anonymous state, `/landing` destination; anonymous calls are harmless |
| GET | `/api/session` | None | Fresh account/header state, password-rule labels, next destination; anonymous requests return 200 with `authenticated:false` and `user:null` |
| POST | `/api/change-password` | `current_password`, `new_password`, `confirm_password` | Original validation order/messages, hash update, first-login cleared, fresh state and original success/modal/profile outcome |

Success envelope:

```json
{
  "ok": true,
  "data": {
    "authenticated": true,
    "user": {},
    "password_rules": [{ "key": "length", "label": "At least 8 characters long" }]
  },
  "redirect": "/resident/dashboard"
}
```

`user` is null for anonymous sessions and logout. Authenticated user fields are:

- `id`, `account_type` (`resident` or `staff`), `role` (`resident`, `staff`, `admin`),
  `name`, `display_name`, `first_name`, `courtesy_title`, `portal_label`.
- `photo` (stored project-relative path, or null when the file is missing),
  `photo_path` (stored path), `has_photo`, `initial`. Resolve project-relative
  photos against `/barangay_survey_system/`, independently of the React mount.
- `is_first_login`, `profile_completed`, `is_active`, `deleted_at`, `show_sidebar`.
  Residents have no profile-completion column or gate, so `profile_completed` is
  true for residents. Sidebar visibility describes the normal next destination;
  password/profile success views may override it as the PHP pages do.
- `residency_status`, `residence_type`, `residence_type_label`; the resident
  number for residents, or `staff_number` and `username` for staff/admin.
- Admin-only `pending_deletions` and `archived_accounts`, using the header queries.

Password-rule keys/labels come directly from `password_rules()`; validation calls
`password_policy_errors()`. Callbacks and password hashes are never serialized.
All API responses have `Content-Type: application/json` and `Cache-Control: no-store`.
Unexpected failures, including the existing database bootstrap's HTML outage
exit, become JSON errors without SQL details or stack traces.

Errors have exactly `{ "ok": false, "message": "..." }`. HTTP statuses are 400
for malformed JSON/field types, 401 for invalid credentials or a required missing
session, 403 for a correctly authenticated but deactivated login, 404 for unknown
endpoints, 405 for wrong methods (with `Allow`), 415 for unsupported content types,
422 for original form validation failures, and 500/503 for service failures.
Wrong passwords and archived accounts both return the exact original generic
message: `Invalid username/Resident Number or password.`

## Source behavior and destinations

The implementation follows `login.php`, `index.php`, both logout/password pages,
both headers, and the shared helpers. Staff lookup accepts **Staff Number or
username**, as requested. The original pages look up username only and assign
Staff Number as username for newly created staff. The API additionally supports
legacy accounts whose username differs from their Staff Number. If one staff
account's username equals another's Staff Number, username takes precedence;
otherwise the lowest staff ID resolves duplicate legacy numbers deterministically.
The original resident-versus-staff collision rule remains: `R-YYYY-NNNN` shape
chooses resident, other shapes choose staff, before verifying one password.

New logins go to change-password when first-login is set, then staff complete-profile
when required, otherwise the appropriate dashboard. As on the original login
page, an already authenticated login request returns that portal's dashboard
without checking submitted credentials or writing another login event.
`/api/session` provides the outstanding onboarding destination; it does not
globally add a resident route gate absent from the source.

Archived/missing signed-in accounts are logged out. Deactivated residents are
also logged out when fresh session state is read. The staff header only checks
archive mid-session, so that distinction is preserved; new staff logins still
reject deactivation. This API does not invent a separate archived-account message.

Password success returns the exact source `message`, `redirect`, and `ui`:
`{modal:"password-success-modal",outcome:"first"|"normal"}` for the self-page
confirmation, or `{flashKind:"success",outcome:...}` for staff profile onboarding.
The original flash keys are also retained for PHP compatibility. Session GETs do
not replay the password outcome; use the mutation response for React feedback.
Redirects are React route paths relative to the configured router basename;
the JSON API never sends HTML redirects.

## Verification

Run the integration check using XAMPP's PHP while Apache and MySQL are running:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/authentication-api.php
```

An optional first argument selects another API base URL, including the Vite proxy.
The script generates random credentials internally, creates only throwaway
resident/staff rows, uses curl with a private cookie jar, and removes those rows
and their login-history entries in `finally`. It never authenticates a real user,
resets the database, or prints passwords/cookies. Auto-increment sequences are
allowed to advance; they are not reset during cleanup.

Verified against XAMPP: **266 assertions passed**, covering cookies, regenerated
session ID, login/session/logout and audit rows, wrong/missing credentials,
both collision formats, deactivated/archived messages, fresh header data, admin
counts, shared password policy/validation precedence, password changes and
first-login/profile destinations, independent Staff Number and username login,
JSON headers, method/body errors and absence of hashes/CORS headers.
Table definitions before/after matched (ignoring auto-increment counters).
The database-unavailable JSON boundary was also checked in an isolated PHP process.
The existing Vite proxy returned the JSON session response and forwarded the
PHP session cookie. PHP syntax checks, frontend build/lint and source-integrity
checks passed. Original pages, configuration, includes, assets, uploads and database
files were unchanged. React authentication screens and guards were subsequently
implemented in Part 2, step 3; see `docs/react-authentication.md`.
