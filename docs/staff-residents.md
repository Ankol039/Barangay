# Resident management

Part 2, step 12 (Prompt 13), 2026-11-06.

Read AGENTS.md, migration-analysis.md, progress.md, all 2,470 lines of staff/resident_management.php, the family partial and relevant shared helpers/scripts before implementation. Original PHP/config/includes/assets/uploads/database files remain unchanged.

## API

`GET /api/staff/residents` supplies the original ordered non-archived list, including deactivated accounts. “Active” list means records not moved to Deleted Accounts: limiting SQL to is_active=1 would break the original Deactivated tab. Each allowlisted row includes source search text (Resident Number plus first/last name), space-separated filter tokens, displayed age/gender/category, account flags and pending-deletion state. It carries no edit lookup, password hash, or unrelated resident information. Filtering remains local, with the same All/Permanent/Temporary/Pending Login/Deactivated tabs and trimmed case-insensitive substring search. Shared person/address/title/contact/hotline metadata, gender/residence/category options, server date and the six-month minimum accompany the list.

`POST /api/staff/residents` accepts the original ResidentInput fields as a JSON object or form body. Both methods require an onboarded staff/admin session; residents/anonymous callers are refused. Malformed transport values are rejected before the legacy helpers run.

The add branch in api/lib/staff-resident-add.php follows the source validation precedence and SQL: required first/middle/last names, birthplace/birthdate/gender; person rows and family checks; missing required address parts; temporary residence type; permanent postal format; contact and address lengths; category reference; residency date, six-month eligibility and birthdate ordering. Age is derived, never stored or trusted from input. The original handler does not independently validate email format or impose extra name/date rules; the form retains its native email/date constraints. Temporary postal codes retain the original length-only server check. No stronger validation has been invented.

Generated numbers use the largest existing numeric suffix across all years, with the current server year and at least four suffix digits. The existing collision check and retry message remain. The number's hash becomes the default password; database defaults retain first-login and active status. Resident, children and references are committed together; insertion failures roll everything back. The response contains resident_id, resident_number, the original success sentence prefix and the React list destination. React renders the number with strong emphasis, producing the source's exact hand-over message without injecting HTML or storing a replayable success URL.

## React and scope

ResidentManagement composes ResidentTable (shared DataTable/search/filter tabs), AddResidentModal, ResidentPersonalFields and the shared FamilyFields/AddressFields. The AddressFields registration option preserves this page's required stars/native required controls and source address limits without changing profile defaults; FamilyFields accepts an ID prefix for the add form. Server errors retain all entered form values. Closing/reopening resets the registration; in-flight submission prevents duplicate clicks and modal dismissal. Source title/category clearing, age singular/plural, temporary visibility, household repeaters and residency duration/custom-validity hints are retained. Success refreshes the list and clears on dismissal/reload.

Step 13 completes the action controls in React; see the action notes below. Residency Records and Deleted Accounts remain separate screens for later steps. No deployment was performed.

## Verification

Run the self-cleaning suite with XAMPP Apache/MySQL available:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/staff-residents-api.php --react
node --test docs/tests/shared-ui.test.mjs docs/tests/react-auth.test.mjs
npm --prefix frontend run build
npm --prefix frontend run lint
```

- 122 live assertions passed: anonymous/resident/method guards; 30 rejected bodies compared directly with the unchanged PHP add branch; permanent/temporary records compared field for field (excluding generated identities/timestamps/hashes), matching child/reference rows, default password verification, exact hand-over text, suffix sequencing, category variants/No clearing, rollback, deactivated/archive list rules, metadata and role flags. Table structure is unchanged; auto-increment counters naturally advance during test inserts.
- Eight React tests passed against the live API: original list cells/search/filter tokens/labels; all combined search/filter states against the original script; form labels/requirements, title/category/temporary/duration controls; family repeaters/reset; real permanent and temporary submissions with validation retention, refreshed rows, success/dismissal/reload; age/calendar boundaries and onboarding redirect.
- Eight shared UI/authentication regression tests passed. Five changed/new PHP files passed syntax checks. Production build and lint passed without warnings.
- Protected-file SHA-256 comparison found zero changes; shared CSS and logo copies remain byte-identical.
- Browser navigation verified the anonymous protected-page redirect. Authenticated desktop/phone visual geometry and browser-native validation bubbles were not measured in this step; React DOM/source parity and native constraint properties are tested.
- All uniquely marked resident fixtures, related person rows, fixture accounts and their login history were removed in the runner's finally block. The runner has no keep option. Automatic review rejected an earlier request to retain fixtures, so follow-up tests run inside the self-cleaning workflow instead.

## Part 2, step 13 — resident actions (Prompt 14, 2026-11-06)

The table and Edit Resident chooser now open the React edit dialog. `GET /api/staff/resident?id=...` supplies an explicitly selected record with children/references and an admin-only restoration preview; it never serializes password hashes, archive metadata or unrelated account fields. Custom courtesy titles reopen as Others plus the saved text. Each opening rebuilds child/reference arrays from the record, with one blank template when the set is empty. Errors retain the submitted values in Edit Resident Info. The saved status appears as a badge; it is never an editable dropdown.

The small ResidentActions, ResidentChooserModal, EditResidentModal, ResidencySwitch, RequestDeletionModal and ResidencyActionModal components share the existing fields and confirmation/success dialogs. ResidentAddressFields is shared with Add Resident; AddressFields accepts a separate ID prefix without changing submitted names. useResidentActions handles record loading and confirmed actions; useResidentSubmission handles edit transport/errors. Duplicate submissions and dismissal during requests are blocked. Success refreshes the list and session/sidebar counts before exposing the next action, and disappears on dismissal/reload.

All mutation endpoints below are POST beneath `/api/staff/resident/`, with the original `resident_id` and form field names. The existing authenticated/onboarding checks apply to reads and writes. Anonymous/resident callers and mutating GET requests are refused.

| Action | Staff | Admin | Original behavior retained |
| --- | --- | --- | --- |
| `update` | Yes | Yes | Original validation order and messages; stored residency status overrides input; transaction updates the resident and replaces children/references together. Edit keeps the source's length-only postal validation and allows an existing under-minimum permanent start date to remain unchanged on the server. |
| `password-reset` | Yes | Yes | Hash Resident Number and set is_first_login=1. |
| `toggle` | Yes | Yes | Activate/deactivate non-archived records; preserve history. |
| `delete` | No | Yes | Reuse archive_account; close pending deletion requests as approved with the source reviewer/comment. No hard deletion. |
| `request-delete` | Yes | Handler accepts; UI hidden | Required trimmed reason; snapshot number/name/requester; preserve the account; duplicate-pending success message. The PHP handler accepts both roles even though its form is staff-only. |
| `promote` | No | Yes | Require temporary status, temporary address, valid start date and six months; archive both addresses, move temporary parts into permanent fields, clear temporary parts/type. |
| `revert` | No | Yes | Require permanent status and reason; restore the latest unreverted permanent-address snapshot, carry the current address into temporary fields, restore a valid residence type, mark the promotion reverted and log the reversion. Without a snapshot, retain the current permanent address and also copy it into temporary fields; return the original warning to enter address/type. |

Edit, promotion and reversion serialize on the resident row. Both residency transitions explicitly roll back exceptions, so an archive insert, address update or promotion-reverted flag cannot be partially committed. Business validation and address/person functions stay in PHP and reuse config/functions.php. Source-specific validation, duration text, optional promotion note, required reversion reason, restore-address fallback and missing-record outcomes are retained.

Preserved source discrepancies: Delete's confirmation still warns of irreversible response deletion while its handler archives and preserves history; no wording correction was authorized. Staff POSTs to promote/revert have no effect in PHP; the JSON API explicitly returns 403 without changing data. The original browser duration hint still applies its minimum to an existing early permanent record even though the PHP update handler allows its unchanged start date; the same hint is retained. These are documented rather than silently changing the source behavior.

Run the action suite (it always cleans up its own fixtures):

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/staff-resident-actions-api.php --react
```

Verification: 392 live assertions compare every action under staff/admin with the unchanged PHP handler's exact messages and resident, household, deletion-request and address-history results. Tests include unauthorized/no-op actions, all promotion eligibility branches, duplicate requests, archive preservation of synthetic survey responses, pending-request approval, no-snapshot reversion, edit's fixed residency status and changed/unchanged date rule, failed household-save rollback and failed promotion-update rollback of the inserted archive. The PHP reference runner now accepts action requests but never outputs its original embedded hash-bearing lookup.

The existing list/add regression also passes 122 API assertions and eight React tests. Nine action React interaction tests pass against the live API: both roles' chooser and row edit; every named prefilled control compared with the original PHP JavaScript loader running in its original markup; custom titles and rebuilt household rows; retained edit errors and successful family updates; confirmation cancellation/reset/deactivation/reactivation; staff reason/pending icon; admin promote/revert previews and required reason; archive/removal from the refreshed list. The suite exercises DOM interactions with jsdom, not a full browser. Native validation bubbles and desktop/mobile pixel geometry were not measured in this step.

Build/lint, seven PHP syntax checks, eight shared UI/auth regression tests, and byte-identical shared CSS/logo checks pass. SHA-256 verification found no changes to 57 protected source/asset/upload/database files. The fixtures, their requests/history, temporary test accounts and login rows are removed in finally; the existing schema is retained. Production artifacts remain under frontend/dist; no deployment was performed.
