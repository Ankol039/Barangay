# React / PHP visual parity audit

Prompt 17 — 6 November 2026 (Asia/Manila).

Compared all **28 React route entries** against their PHP originals with Playwright at **1440×900, 1024×900 and 390×844**. There are **27 working routes**; the separate legacy Family & Household Requests PHP page returns HTTP 500 and remains blocked.

The [screenshot gallery](verification/visual-audit/index.html) contains **816 final screenshots / 408 paired comparisons**, covering **74 page/data/role scenarios and 62 interaction scenarios**. The [complete route/scenario matrix](verification/visual-audit/matrix.md), [machine-readable results](verification/visual-audit/summary.json), and [computed hover/focus styles](verification/visual-audit/interaction-styles.json) provide the evidence.

## Fixes

| Area | Difference found | Correction |
| --- | --- | --- |
| Standalone submission confirmation | `/resident/confirmation` still displayed a migration placeholder. | Added the original centered 480px card, 40px margin, green check glyph, exact message and Back to Surveys link. A resident-only GET API reads the title and preserves the original “the survey” fallback. It makes no submission or ownership claim beyond the PHP behavior. |
| Resident account dropdown | React added Change Password and an extra divider to the resident menu. | Restored the PHP menu: My Profile and Logout. Staff retains its original Change Password item. |
| Address Requests | React combined resident approval/rejection into one form. On a phone this changed table width and reduced the request-card height from the PHP's 659.67px to 415.67px for the captured data. | Restored two inline forms with the original whitespace, button classes and wrapping. The final phone table/card/footer geometry matches PHP. |
| Question management | Adjacent order arrows and edit/delete controls lost the HTML whitespace between them, shifting table columns and controls. | Restored the inline gaps without changing class names, icons or handlers. |
| Resident form footer | The reference repeater's add button ran into the Add/Update Resident button, also changing the wrapped Cancel position on phones. | Restored the whitespace between the family fields and the form's submit control in both Add and Edit forms. |
| Add Staff dialog | React opened Date Hired with today selected; PHP's generic modal clear-on-open empties the date. | Open with a blank Date Hired field, preserving the original maximum date, validation and submitted-value retention. |
| Photo crop dialog | React opened at 1×; PHP resets its 1–3 range to the native midpoint and dispatches input after loading the image, so its visible initial crop is 2×. The crop, circular preview and slider differed. | Match that effective 2× opening state for resident and staff crop dialogs. The hook still supports the original cover/center/clamp/zoom calculations and its standalone 1× default. Both crop dialogs now match at all three widths. |
| Staff account empty search | The shared table injected a blank no-match row where the PHP staff table has none. | Only render a no-match row when that source table supplies an empty-state message. Resident/search/filter empty states retain their existing messages. |
| Text positioning | Split JSX text nodes changed kerning and small column/label measurements, despite identical fonts and CSS. | Preserve contiguous source strings for numbered survey questions and submitted answers; survey/request/account headings and counts; profile resident numbers and temporary-residence badges; results/report headings, totals, percentages, quoted answers and metadata; family/staff pending-request text; and staff first-login reminder punctuation. Nullable source text still renders blank. |

Representative earlier captures are retained under [before/](verification/visual-audit/before/report.json), including [mobile Address Requests before](verification/visual-audit/before/staff-address_requests-390-react.png), [mobile Add Staff before](verification/visual-audit/before/admin-add-staff-390-react.png), and [mobile crop before](verification/visual-audit/before/temporary-photo-crop-390-react.png). Their matching final captures are in the gallery.

## Coverage and results

- **387 pairs match** using Pixelmatch threshold 0.15 with its default antialias exclusion. Raw PNG byte equality is not asserted. Reports also include landmark geometry, colors, margins and padding for inspection.
- **18 pairs retain earlier explicitly requested additions:** resident/staff login tabs, portal-specific login hints/labels and switch link (Prompt 4); survey All/Active/Inactive filters (Prompt 11), including that table's hover/focus/no-match scenarios. These intentional layout differences remain visible in the evidence and are not counted as unexplained regressions.
- **3 pairs document the unavailable legacy baseline:** `staff/family_requests.php` at the three widths. Its React route remains a placeholder. The original HTTP 500 was confirmed, and read-only metadata checks confirmed that `resident_family_update_requests` and `collect_family_person()` are absent. No table/helper was invented, and the working integrated family review inside Address Requests is verified separately.
- **60 computed hover/focus comparisons pass**, covering portal navigation, password fields/eyes, survey options, resident/account/survey row actions and residency cards. Drawer focus captures wait for the opening transition and verify that the navigation link actually owns keyboard focus.
- Page variants include staff/admin shells, permanent/temporary residents, first-login password/profile views, every whitelisted stat detail, populated and zero-response results/reports, unselected states, empty resident history, filtered residency history, and confirmation fallback. Interactions include contact/name/birthdate/family dialogs, add/edit/chooser/delete-request/residency dialogs, add/edit questions, survey preview, review/purge/confirmation dialogs, no-match states, password rules and crop previews.
- Charts use the installed original Chart.js **4.4.4** UMD file in place of the PHP CDN request during capture; its code is unchanged. Dashboard animations are allowed to finish before comparing. Main page screenshots are full-page; dialog/interaction screenshots capture the viewport, with explicit scroll positions for long family forms.
- Source/React captures use the same registered synthetic accounts and live database data. Temporary records and their login history are cleaned in `finally`; the combined runner waits for its interaction process before cleanup. Recapture provenance is recorded in `summary.json` for transient login-history/native-select rendering and drawer-focus readiness checks.

## Verification and changed files

- `npm run build` and `npm run lint` pass.
- **10 existing UI/auth/landing tests** and **3 nullable-text rendering checks** pass; **266 authentication API assertions** pass with schema unchanged and fixtures removed.
- **5 confirmation endpoint checks** pass: anonymous denial, staff denial, POST method denial, missing-title fallback and known-title lookup. The valid/fallback screens are captured at every width.
- PHP syntax checks pass for `api/index.php`, `api/lib/surveys.php` and the new CLI-only fixture helper.
- All **63 protected-file hashes** are unchanged. The frontend copies of `style.css` and `Logo.png` remain byte-identical to the original assets. Original CSS class names and SVG geometry are retained.

Application changes are confined to `frontend/src/` and the confirmation read in `api/index.php` / `api/lib/surveys.php`. New verification helpers are `docs/tests/visual-audit-fixtures.php`, `visual-audit.mjs`, `visual-audit-scenarios.mjs` and `visual-audit-report.mjs`. This report, its screenshot artifacts and `docs/progress.md` record the work. No deployment was performed.

## Reproducing the screenshots

With XAMPP Apache/MySQL available and Vite serving port 5173:

```sh
npm install --prefix /tmp/barangay-visual-tools playwright pngjs pixelmatch
AUDIT_COMBINED=1 AUDIT_STAGE=final node docs/tests/visual-audit.mjs
node docs/tests/visual-audit-report.mjs
```

The runner uses the installed Google Chrome and XAMPP PHP paths on this Mac. `VISUAL_TOOLS` can override the temporary package directory. `AUDIT_ONLY` accepts a scenario-name regular expression; `AUDIT_STATES=1` selects interactions. The reuse-fixtures option is reserved for the coordinated child/recapture process while the owning runner is alive. Never run it against an absent fixture registry or clean the registry while a capture process is using it.
