# Resident profile migration

Part 2, step 8, completed 2026-11-05 (Asia/Manila). React route: `/resident/profile`.
Read the complete 928-line `resident/profile.php`, `includes/family_fields.php`, the cropper/repeater/modal behavior in `assets/js/script.js`, the shared PHP person/address helpers, and the migration guidance before implementation. Existing PHP, configuration, includes, assets, upload files and database definitions were not edited.

## API

All endpoints use the existing PHP session and infer the resident from it. Anonymous access returns 401; staff access returns 403. Submitted resident identifiers cannot select another account. Profile responses explicitly allowlist displayed/form fields; passwords, hashes and unrelated account columns are excluded.

| Method | Path under `/api` | Behavior |
| --- | --- | --- |
| GET | `/resident/profile` | Own profile, ordered children/references, server-formatted names/age/date/residence labels, both pending queues, today's date and shared field metadata. |
| POST | `/resident/contact` | JSON `email`, `contact_number`; immediate update with original validation and success text. |
| POST | `/resident/birthdate-requests` | JSON `birthdate`; creates a pending `resident_update_requests` row without changing the resident birthdate. |
| POST | `/resident/name-address-requests` | JSON name fields, seven address parts, and seven `temp_` parts for temporary residents; stores derived lines and previous address in a pending update row. |
| POST | `/resident/family-requests` | JSON occupation/hotline/flags, flat spouse/parent fields, person arrays and `family_reason`; stores the original flat JSON payload in `resident_family_requests`. |
| POST | `/resident/photo` | Multipart `profile_photo`; retains original image/extension and PHP upload-limit policy. |
| POST | `/resident/photo/remove` | Clears the stored photo and removes its file, after the original UI confirmation. |

Birthdate and name/address requests share one pending queue. Family requests have their own queue, matching PHP. Each request mutation locks the owning resident before checking and inserting, so two concurrent API requests cannot create competing pending rows. No schema, new queue or replacement business-rule layer was introduced. The original PHP helpers still collect/validate people, resolve titles, check contact/hotline/address fields and build server address lines.

## React

`Profile.jsx` composes small `ProfileOverview`, `ProfileDetails`, `FamilyOverview`, `ContactForm`, `RequestChangeModal`, `FamilyRequestModal`, `FamilyFields`, `PersonFields`, `RepeatablePersonRows`, `AddressFields`, `PhotoUploader` and `PhotoCropModal` components. The profile route loads as a separate build chunk. Shared `Modal` now supports the existing `modal-wide` class; the API client accepts FormData without setting its multipart boundary.

`api_profile_metadata()` exposes **the existing `person_field_spec()`** and address helpers as the canonical configuration. Every relative/repeater form and person summary uses that specification. `frontend/src/config/personFields.js` provides editable custom titles, blank rows and the ported `collectPersonRows`; final collection and business validation still run through PHP `collect_person_rows()` and `person_rows_errors()`. Repeater labels, names and IDs renumber after removal; the last row is retained. No hides spouse/children inputs, and the server clears their payloads. Custom title boxes clear when switching away from Others.

`AddressFields` supports the seven configured parts and optional derived-line output through `config/addressFields.js`. This screen preserves the PHP form layout; its profile tables display the authoritative stored complete lines. Temporary residents see both saved addresses and fourteen address inputs in the request; permanent residents see seven inputs.

`usePhotoCropper` ports the original cover scaling, center-preserving zoom from 1–3, pointer-coordinate scaling, pointer capture, position clamping, dark fill and smoothing. It exports a **320×320 square JPEG at quality 0.9** named `profile-photo.jpg`. The circular guide and preview reuse unchanged CSS. Object URLs and image callbacks clean up on close/unmount. Successful upload refreshes the header avatar and opens the original Profile Updated confirmation. Upload filenames have a random suffix to avoid overwriting another upload made in the same second.

Source quirks retained:

- Contact email validation remains browser-only; the server retains its original checks and precedence. Temporary postal-code validation is not expanded beyond PHP.
- Family forms clear text/select fields on open, retaining initial Yes/No choices and row counts, because PHP omitted `data-keep-on-close`. The source `data-unsaved-warning` does not activate the shared dirty guard; contact/name/birthdate forms do use it.
- PHP family success and photo-removal flashes are not displayed on the original profile. React retains that visible behavior: the saved family card shows its pending notice, removal shows the initial, and neither gets a newly invented success banner.
- Replacing a resident photo retains the previous file, as PHP does. Removal deletes the current file. No staff-only 5 MB rule is added.

The asynchronous upload keeps errors visible inside the crop modal so a failed API call can be retried with the selected crop; it does not reproduce the legacy full-page reload's inaccessible upload-error overlay.

## Verification

- `npm --prefix frontend run build` and `npm --prefix frontend run lint`: clean, with no chunk-size or lint warnings.
- PHP syntax checks pass for API and CLI verification files.
- `resident-profile-api.php --keep`: **117 assertions**, including role/session isolation, shared metadata, original validation text/order, immediate contact persistence, pending rows for every request type, unchanged unapproved details, Others/blank-row normalization, independent family queue, image/extension checks, square JPEG storage, avatar refresh/removal, and two pre-booted workers actually contending for the resident lock. The race yielded exactly one successful proposal and one pending refusal.
- `node --test docs/tests/resident-profile.test.mjs docs/tests/react-auth.test.mjs docs/tests/shared-ui.test.mjs docs/tests/landing.test.mjs`: **17 passed, no skips**. Covers all three fixture profile variants against original PHP tables/text, real React contact and request submissions, pending warnings, failure retention, dirty dismissal, repeaters, Others, conditional fields, family JSON, multipart transport, and deterministic crop cover/zoom/drag/JPEG/object-URL cleanup.
- Existing `authentication-api.php`: **266 assertions passed**, schema unchanged and authentication fixtures/history removed.
- Browser selected a synthetic 640×400 PNG, repositioned it, uploaded the canvas JPEG, displayed Profile Updated, and loaded a resulting **320×320** resident image and refreshed avatar. Final browser logs had no warnings/errors. Crop zoom math was additionally checked by the deterministic hook test.
- Original-PHP and React page/card/footer geometry matches exactly at desktop and phone sizes; see `output/resident-profile/measurements.json` and screenshots. The CLI reference renderer generated fixture-only HTML with a base URL so original assets/JS resolved; that temporary HTML was removed after comparison. Browser window overrides produced 1265×712 desktop and 375×812 phone content areas; measurements record the actual rendered rectangles.
- SHA-256 comparison: all **63 pre-task protected files unchanged**, including pre-existing working-tree changes. Shared CSS and logo remain byte-identical. Disposable accounts, pending/rejected proposals, generated photo and login history are cleaned after verification. No deployment performed.

To repeat with XAMPP running:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/resident-profile-api.php --keep
node --test docs/tests/resident-profile.test.mjs docs/tests/react-auth.test.mjs docs/tests/shared-ui.test.mjs docs/tests/landing.test.mjs
/Applications/XAMPP/xamppfiles/bin/php docs/tests/resident-profile-fixtures.php clean
```

The mutation tests expect fresh fixtures. Clean and recreate them before repeating. CLI helpers deny HTTP execution. Original-PHP reference rendering performs no profile mutation.
