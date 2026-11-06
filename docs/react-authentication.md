# React authentication — Part 2, step 3

Implemented 2026-11-04 (Asia/Manila). Read `AGENTS.md`, migration analysis and
progress first, then the original login/index/logout/password pages, headers,
guards, profile onboarding code and shared scripts. Original PHP, configuration,
assets, uploads and database files remain unchanged. No API change was needed.

`frontend/src/api/client.js` includes session credentials, sends JSON, preserves
server error wording and HTTP status, and provides consistent network/invalid-JSON
errors. Development uses Vite's `/api` proxy; built code uses
`/barangay_survey_system/api`, outside the React `/app/` mount.

`AuthProvider`, `AuthContext` and `useAuth` read `/api/session` on initial load,
route navigation and focus/visibility return. Guards wait for fresh session state;
service failures show a retry control instead of silently treating an outage as
logout. Aborted/stale reads cannot overwrite mutation results. Successful login,
password change and logout update context from the server response. Passwords
stay in component state and are cleared on response; nothing is persisted in
browser storage.

Portal routes enforce resident/staff identity. As of Part 2, step 5, `/` renders
the public landing page directly and `/landing` remains its alias. Compatibility
admin/login entries finish at that public root. The `/login` page still directs
an existing identity to its portal dashboard and any owed onboarding. Staff must set a password, then complete their profile. As in
`require_staff_onboarding()`, password/profile pages remain exempt destinations.
Only Deleted Accounts and Residency Records require `AdminRoute`; staff denial
uses the exact `require_admin_login()` text. User Management and Deletion
Requests remain staff routes, matching their original page guards.

The PHP sidebar, account menu, resident plain header and logout controls were
extracted into small React components so the password pages have their source
layout. Password/profile page-specific navigation overrides are preserved.
Logout sends POST `/api/logout` and follows its landing destination.

Two explicit requirements differ from the checked-in PHP baseline:

- `login.php` contains one automatic-detection form, with no portal tabs or
  switch-tab text. React adds the requested **Resident Account** and **Admin &
  Staff** tabs, role-specific identifier labels/hints/placeholders and a switch
  suggestion, using a small additional stylesheet. The existing brand copy,
  recovery/first-login instructions, eye SVGs, Log In label and API error text
  are preserved. Tabs guide entry; the server still determines the account type,
  including its collision rule. No client-side role or identifier-format rule
  blocks a valid login. This follows the user's explicit two-portal requirement;
  an optional clarification was offered and no contrary preference was received.
- The original resident header hides first-login navigation without globally
  redirecting routes. React also forces direct resident URLs back to Change
  Password, as explicitly requested in this step.

The shared Change Password page preserves role-specific headings/instructions,
native required/minlength validation, server validation order/errors, five live
rule indicators, match hint, eye buttons and success modal. Rule labels and exact
success text come from the API. Stay Here, Close, backdrop and Escape dismiss the
modal without replaying the mutation. An incomplete staff profile is the direct
success destination, matching PHP; it does not receive a password modal.

Staff profile completion and dashboard contents remain the scaffolded routes for
their later migration steps. This task verifies the profile gate and destination,
not a React profile submission. The existing PHP profile remains available at
`/barangay_survey_system/staff/complete_profile.php` using the shared session.
Nothing was deployed into a root `app/` directory.

## Verification

- `npm run build --prefix frontend` and `npm run lint --prefix frontend` pass
  without warnings. New CLI fixture helper passes PHP syntax validation.
- `node --test docs/tests/react-auth.test.mjs`: three passing tests cover
  credential inclusion, development/production API paths, exact HTTP errors,
  network/HTML/abort handling, portal boundaries, both staff roles and onboarding
  precedence/exemptions. Rendered React checks cover empty/partial/complete/space
  checklist states, match hints, and success-modal message/actions/link.
- Live XAMPP API integration suite: **266 assertions passed**, including actual
  password updates, first-login/profile destinations, session regeneration,
  rejection states, audit/logout behavior and schema integrity. Its fixtures and
  history were removed by the runner.
- Browser through Vite and live XAMPP: returning resident, staff and admin logins;
  resident/staff first-login destinations and hidden navigation; direct-dashboard
  bypass attempts; profile gate after advancing only the disposable fixture's
  password flag; staff admin-route denial/admin acceptance; cross-portal redirect;
  all three roles' logout to landing; protected route after logout; empty-login
  error; both tab labels/hints and show/hide-password accessibility. No real
  account was used or modified. Browser credential changes were not submitted;
  password mutations are covered by the API suite and React presentation tests.
- Browser checks used six disposable rows from
  `docs/tests/react-auth-fixtures.php create`; `clean` deletes only those IDs and
  their history. The helper is CLI-only and introduces no API endpoint. It uses a
  public test-only password exclusively on disposable rows. Account identifiers
  and secrets from real accounts were never read or printed.
- SHA-256 source integrity check verifies all protected source files against the
  start-of-task snapshot. Original stylesheet/logo and frontend copies remain
  byte-identical. Existing unrelated `.DS_Store` changes are preserved.
