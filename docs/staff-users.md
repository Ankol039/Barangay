# Staff accounts and profile migration

Part 2, step 15, completed 2026-11-06 (Asia/Manila). Sources: the complete unchanged `staff/user_management.php`, `staff/complete_profile.php`, staff header, shared cropper/modal/search script, and existing PHP account/address helpers.

React routes: `/staff/user-management` and `/staff/complete-profile`. Both are lazy loaded. The User Management page remains staff-accessible; only its staff roster and Add Staff control are admin-only. The staff view includes Resident Accounts and its reversible activation control.

## API

All paths below are relative to `/api`. Sessions, database, schema and business helpers remain the existing application's. Read payloads allowlist fields and exclude password hashes, archive metadata and unrelated personal details.

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/staff/users` | Resident accounts in source order; admin-only staff roster, onboarding flags and pending deletion count; titles and server date. |
| POST | `/staff/users` | Validates employment fields, generates the existing global Staff Number, sets username/temporary password to that number and fixes role to staff. Returns the hand-over slip once. |
| POST | `/staff/user/toggle` | Admin-only staff activation toggle; disallows self. |
| POST | `/staff/user/password-reset` | Admin-only reset to Staff Number and first-login flag; disallows self. |
| POST | `/staff/user/delete` | Admin-only archive using `archive_account`; self and last-other-active-admin checks/messages match PHP. |
| POST | `/staff/user/resident-toggle` | Staff/admin resident activation toggle, retaining User Management's exact success wording. |
| GET, POST | `/staff/profile` | Own employment/profile data; non-admin personal details, initial address or later address proposal; admin record is read-only. |
| POST | `/staff/photo` | Own multipart photo; source upload/image/extension checks, 5 MB cap and old-file cleanup. |
| POST | `/staff/photo/remove` | Removes own photo file and clears the record/session avatar. |

Mutating legacy GET links become POST operations with the existing confirmation wording. Staff-number allocation uses the unchanged `generate_staff_number` helper under a named API lock; no schema changes. The legacy PHP creation page does not participate in that lock.

Profile saves lock the owning staff row before checking pending proposals. Personal/emergency fields and `profile_completed` update in the same transaction as the initial address or later `staff_update_requests` insert. Later address changes require a reason and leave the stored address unchanged. While a request is pending, other details still save and additional address input is ignored. The existing admin Address Requests screen reviews these proposals.

## React behavior

Small `StaffAccountsTable`, `ResidentAccountsTable`, `AddStaffModal`, `StaffCreatedModal`, `StaffRecord`, `StaffProfileForm` and `ProfileSuccessModal` components preserve source labels, order, badges, search fields, native constraints and messages. Existing CSS, logo and SVG paths are reused unchanged.

`PhotoUploader` and `PhotoCropModal` from Prompt 9 accept an optional endpoint; resident defaults remain unchanged. Staff uses `/staff/photo`, the same 320×320 JPEG crop, center-preserving zoom, drag/clamp behavior and header-avatar refresh. Staff replacement removes the previous file; resident replacement behavior is unchanged. Successful photo operations reload profile form values as the PHP reload does.

The one-time hand-over slip lives only in component memory. It is neither returned by subsequent roster reads nor placed in browser storage. The requested **Print** button is an explicit addition to the PHP slip. A separate print-only rendering of the same slip component hides the rest of the page during printing; closing the dialog removes it.

Source distinctions retained:

- Password change precedes profile completion for normal protected navigation. Complete Profile remains directly reachable during first login, matching the PHP onboarding exemption.
- Admins have an employment record and photo controls, with no personal-details editor. Existing routing behavior for unusual legacy admin onboarding flags is retained.
- A successful photo response exposes the staff sidebar during unfinished onboarding as PHP's `profile_saved` response does. The profile-save signal clears on unmount; route guards continue enforcing onboarding.
- Photo removal has no newly added success dialog. Profile saves use the source first/photo/requested/normal messages, Dashboard link and Stay Here control.
- Reset sets `is_first_login` without clearing the existing completed-profile flag. As in PHP, resetting an already completed staff account requires a new password but does not erase its profile.
- Staff reset preserves PHP's archived-target lookup behavior; activation/archive target checks and archive retention remain unchanged. The source toggle does not add a last-admin guard; the archive operation does.

## Verification

- Build and lint pass without warnings: `npm --prefix frontend run build`, `npm --prefix frontend run lint`.
- Eight new/changed PHP files pass syntax checks.
- `/Applications/XAMPP/xamppfiles/bin/php docs/tests/staff-users-api.php --react`: **139 assertions** plus **8 live React integration tests**, using XAMPP and disposable accounts. Coverage includes source validation/message/database parity, role isolation and self guards, issued credentials and flags, login/password/profile onboarding, subsequent login, contact/address separation, duplicate-request serialization, forced-insert rollback, admin address approval, image validation/5 MB cap, replacement/removal and staff reset/toggle/archive.
- React tests use the real login, AuthProvider, protected routes, staff shell and page components against the live API. They create a staff account, log in as that account, change its temporary password, complete onboarding and verify navigation becomes available. Both role tables and Admin Record match original PHP text. New-hire form text and native input IDs/names/types/constraints match the PHP DOM.
- Shared cropper integration exercises staff upload/zoom, JPEG export settings, avatar refresh, form reset and removal. Its canvas/image decoding is simulated in jsdom; API image-header/storage validation uses actual uploaded PNG bytes. Print invocation and print-only DOM/CSS are checked; native print-dialog output is not verified.
- **7 resident-profile regression tests** pass, including multipart transport and crop math, inside a `try/finally` disposable-fixture wrapper; **8 shared UI/auth regression tests** pass.
- All **57 protected file hashes** remain unchanged. Shared CSS/logo copies remain byte-identical. All test account/request/photo/history records and fixture registries are cleaned.

Full-browser desktop/mobile geometry and native validation bubbles were not measured in this step. No deployment was performed. Automatic review rejected the initial creator-wide cleanup query; the revised runner deletes only registered synthetic IDs with additional fixture creator/name guards and always cleans in `finally`.
