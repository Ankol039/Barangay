# Request reviews, archived accounts and residency history

Part 2, step 14; Prompt 15; verified 2026-11-06 (Asia/Manila).

Read `AGENTS.md`, `docs/migration-analysis.md`, `docs/progress.md`, the four original pages, the legacy family page, existing resident submission APIs, and shared PHP helpers before implementation. The original PHP/config/includes/assets/uploads/database files are unchanged.

## Screens and shared components

Implemented `/staff/address-requests`, `/staff/deletion-requests`, `/staff/deleted-accounts`, and `/staff/residency-records` in the existing React router, including actual screens inside the existing admin route guard. All four are lazy loaded.

`RequestCard` renders the common queue card, heading, count and optional pending count for resident updates, staff updates, family requests and deletion requests. `ReviewModal` supplies the common review form in both its original inline mode (resident/family) and dialog mode (staff/deletion). `ReviewButtons`, `RequestStatus`, small typed tables/cards and `useRequestQueue` share decision actions, busy/error handling, retained input, refresh and session-count refresh. Existing CSS and icons are reused without modification.

Source presentation is retained: resident review remains inline with no required note; family review retains its optional inline note; staff/deletion reviews have optional approval notes and required rejection notes. Server validation now also enforces the existing staff rejection form's requirement, using the deletion handler's existing message, “Give a short reason when rejecting a request.” Family's original unconsumed success flash is preserved as no visible success message; resident/staff results use the existing Success dialog and deletion/archive results use the existing success alert.

The staff/family sections remain admin-only in Address Requests. The active family review endpoint remains staff/admin-accessible, matching its original handler. The separate `/staff/family-requests` placeholder and its broken legacy `resident_family_update_requests` implementation are outside these four requested conversions. No parallel family schema or payload translation was introduced.

## Server endpoints

All routes use the existing session/bootstrap, onboarding guards, MySQL schema and `config/functions.php` business helpers. JSON contains only presentation data and no account password/hash fields.

| Method | Path below `/api` | Access |
| --- | --- | --- |
| GET | `staff/address-requests` | Staff/admin; staff/family sections returned only to admin |
| POST | `staff/address-request/review` | Staff/admin |
| POST | `staff/staff-address-request/review` | Admin |
| POST | `staff/family-request/review` | Staff/admin, matching the active PHP handler |
| GET | `staff/deletion-requests` | Staff/admin |
| POST | `staff/deletion-request/review` | Admin |
| GET | `staff/deleted-accounts` | Admin |
| POST | `staff/deleted-account/restore` | Admin |
| POST | `staff/deleted-account/purge` | Admin |
| GET | `staff/residency-records?filter=permanent\|temporary` | Admin; read only |

Review POST bodies contain `request_id`, `action=approve|reject`, and `review_comment`. Archive bodies contain `kind=staff|residents` and `id`. State-changing GETs are refused.

Decision handlers lock the target record and then the pending proposal in a transaction, consistent with resident profile submission/edit lock ordering. A second reviewer gets the original already-processed error. Resident name, permanent address, temporary address and birthdate are applied only when carried by the proposal. Family approval atomically replaces the resident fields, children and references through the existing helper. Exceptions roll back both data and decision; rejection changes only the proposal's review metadata.

Deletion approval archives through `archive_account`, preserves responses, and resolves other pending deletion requests for the same resident. Restore uses `restore_account` and atomically adds the existing restoration marker. It leaves `is_active` unchanged, preserving even the original optimistic success wording. Deletion history shows current account state from the joined resident row and strips the marker from the displayed administrator note. Purge requires an archived target, protects the signed-in admin, and retains the original destructive confirmation. Residency history retains archived address text and deleted-resident fallback, global promotion/reversion totals, query-string filters, history navigation and name/number search. There is no mutation endpoint for the log.

## Verification

Run with XAMPP Apache/MySQL available:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/request-review-api.php --react
node --test docs/tests/shared-ui.test.mjs docs/tests/react-auth.test.mjs
npm --prefix frontend run build
npm --prefix frontend run lint
```

The CLI-only runner creates disposable staff/admin/resident accounts, proposals, household rows, one survey/response and residency history, and removes fixtures/audit rows in `finally`. Restore and purge are exercised only on these synthetic accounts. It executes the original PHP handlers as an oracle and compares complete stored records and exact outcomes, normalizing generated related-row IDs/timestamps. Distinct fixture submission times avoid imposing a tie-breaker on the original equal-second sort.

Results: **212 live API/PHP parity assertions, 10 live React integration tests, eight shared UI/auth regressions, build, lint and eight PHP syntax checks passed**. Coverage includes:

- Resident React request form submission followed by staff rejection; complete resident profile unchanged before approval and after rejection.
- Admin approval and staff approval paths; selective birthdate/name/permanent/temporary behavior; all stored request/household/target fields compared to PHP.
- Active family approvals/rejections, malformed payload remaining pending, and forced related-row failure rolling back the entire update.
- Two actual competing PHP reviewers: exactly one decision succeeds; the resident record agrees with the winner.
- Staff-review dialog note requirement, optional approval, close/reopen reset; deletion rejection validation and retry/cancel retaining input.
- Server role/onboarding boundaries, state-changing method restrictions, admin archive/restore/purge, preserved response counts and actual purge cascade.
- Exact original PHP queue/archive/log text and table content for both roles, restored-state labels, read-only log search/filter/global totals and Back navigation.

All 52 protected files hashed before implementation remained unchanged; shared CSS/logo copies remain byte-identical. No deployment performed. Full-browser desktop/mobile pixel geometry and native validation bubbles were not measured; interaction verification uses jsdom against live XAMPP plus the original PHP oracle.
