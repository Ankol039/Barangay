# Staff results, reports and stat details

Part 2, step 11 — 2026-11-06 (Asia/Manila).

Implemented `/staff/results-dashboard?survey_id=…`, `/staff/reports?survey_id=…` and `/staff/stat-detail?view=…` using the existing React staff layout, source stylesheet and original labels. The existing Statistics compatibility redirects still lead to Dashboard.

## Files and data boundaries

- `frontend/src/pages/staff/Results.jsx` composes the Results and Reports screens. `frontend/src/components/results/` contains the shared survey selector, original empty-state icons, summary, question cards, bars, live text search and printable report sections.
- `frontend/src/pages/staff/StatDetail.jsx` and `statCells.jsx` render the eight source views with badges and React result links. `frontend/src/routes/AppRoutes.jsx` replaces the three placeholders with lazy-loaded screens.
- `api/lib/staff-results.php` contains the original, separate Results and Reports loaders. Percentages, tied winners, rating aggregates, response bases and dates are calculated on the server. Survey serialization omits fields not needed by the screens.
- `api/lib/staff-report-export.php` preserves the CSV SQL, filename substitution, row order, `fputcsv` output and attachment type. Output is buffered before headers so a query failure remains inside the existing JSON error boundary. Missing surveys redirect to `/barangay_survey_system/app/staff/reports` at the configured production mount.
- `api/lib/staff-stat-detail.php` contains the eight original SQL/metadata definitions. Unknown views and unauthorized administrator views return the Dashboard destination before executing a detail query. It returns typed data and date labels; React escapes text rather than inserting server HTML.
- `api/lib/resident-stats.php` supplies the remaining standalone resident completion endpoint. The resident dashboard already includes this same series and continues to use its existing payload.
- `api/index.php` registers these read-only routes. All source SQL strings are unchanged; an automated source comparison checks every query against the original PHP, including the existing staff chart stats loader.

## API routes

| GET endpoint beneath `/api` | Access and result |
| --- | --- |
| `/staff/results?survey_id=…` | Staff/admin with completed onboarding; selector, survey, respondent count, original question aggregates and latest 200 nonblank text answers with submission dates. |
| `/staff/report?survey_id=…` | Staff/admin with completed onboarding; selector, creator, generated time, respondent count, report-specific rows and uncapped text answers. |
| `/staff/report-export?survey_id=…` | Same staff boundary; source CSV attachment. Missing survey returns the reports redirect. |
| `/staff/stat-detail?view=…` | Four general views and four administrator views, exactly as whitelisted in PHP. SQL is never returned. |
| `/staff/stats?from=…&to=…` | Previously migrated chart endpoint retained unchanged; all eleven series compared with original `staff/ajax_stats.php`. |
| `/resident/stats` | Resident session only; original Completed/Available series, matching `resident/ajax_stats.php` and the existing dashboard payload. |

The four general detail keys are `total_surveys`, `active_surveys`, `total_residents`, and `total_responses`. Administrator keys are `staff_accounts`, `deactivated_residents`, `deactivated_staff`, and `recent_logins`. The source's `recent_logouts` link still returns to Dashboard: there is no such whitelisted detail view. Login Activity retains the source query's lack of event/account-role filtering and its 100-row cap; response detail retains its 200-row cap.

## Preserved differences

- Results percentages use answers to that question, not survey respondents. Tied positive maxima receive the accent color; all-zero options retain primary color. Yes/No and rating bands include zeros. The rating average keeps PHP's two-decimal rounding and display without forced trailing zeros.
- Results open-text answers keep the exact newest-first `LIMIT 200`; search only filters those loaded answers. Matching is case-insensitive and **does not trim** the input. Each question has independent search state and the original no-match message. Answer text is escaped, including literal HTML, quotes and Unicode.
- Reports include every nonblank text answer, both Yes/No rows and all five rating rows. Per-question response counts retain the report's own definition.
- CSV retains **observed-only** Yes/No and rating rows, all multiple-choice options, and uncapped text answers. It intentionally differs from the report's zero-filled tables.
- The selector changes the query string automatically. Browser Back/Forward restores the selected survey. A newly loaded question resets its search, as a PHP reload does.
- Print Report calls `window.print()`. The original `.no-print` classes hide the heading, selector/actions and existing portal shell in print. No replacement print stylesheet or PDF generation was added.

## Verification

The CLI-only helpers in `docs/tests/staff-results-*` create disposable staff/admin/resident accounts, two surveys, all four question types, unused choices, tied winning bars, an archived respondent, blank text rows and 201 nonblank text rows. They execute the **unchanged original PHP** as the reference. The source schema permits multiple text rows for one response, which is used solely to exercise the 200-row cap without creating hundreds of accounts.

Completed checks:

- **364 live API assertions**: source figure/order/metadata parity for populated and empty surveys, exact CSV bytes and headers, question denominators/ties/rounding, cap versus uncapped reports, eight detail views, access/method/onboarding boundaries, invalid inputs, chart stats parity, standalone resident stats and unchanged table definitions.
- **26 React/source checks**: 16 results/report/stat/query checks plus ten existing authentication/shared-UI/landing regressions. Compared every question card's text, answer/date, bar width/color and report table against rendered PHP; tested live search/no-match/clear, selector and Back/Forward, print invocation, export link, redirects, administrator denial, missing survey and transport failure.
- Existing authentication API suite: **266 assertions passed**, with its own disposable records cleaned.
- Browser verified the approved disposable staff login, result navigation, live search/no-match and report navigation with no warning/error logs. Results, Reports and Total Surveys detail card geometry and page height match PHP exactly at **1280×720**. Results card geometry, bars and page height also match at **393×883**. Evidence is in `docs/verification/step-11/measurements.json` and neighboring PNGs.
- Frontend build and lint passed without warnings; PHP syntax checks passed for all eight new/changed PHP files. All **56 protected files** retain their starting SHA-256 values, and the shared CSS/logo copies remain byte-identical.

The populated source comparison shows 3 respondents; multiple-choice votes 1/1/0 (50/50/0%); Yes/No 2/0 (100/0%); rating average 3.67 from 3 ratings with votes 1/0/0/0/2 (33/0/0/0/67%); Results shows 200 text answers while Reports/CSV contain 201. These figures match the PHP page figure for figure.

Run from the project root using the existing XAMPP database, with Apache and MySQL running:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/authentication-api.php
/Applications/XAMPP/xamppfiles/bin/php docs/tests/staff-results-api.php --keep
node --test docs/tests/staff-results.test.mjs docs/tests/react-auth.test.mjs docs/tests/shared-ui.test.mjs docs/tests/landing.test.mjs
/Applications/XAMPP/xamppfiles/bin/php docs/tests/staff-results-fixtures.php clean
npm run build --prefix frontend
npm run lint --prefix frontend
```

Run account-mutation suites before the read-only parity suite so the API and original PHP see the same account states. Without `--keep`, the API suite cleans its records automatically. Original PHP/config/includes/assets/uploads/database files are untouched; no database was recreated or reset and nothing was deployed.

Native print-dialog output and a browser-initiated download were not exercised. Print invocation/classes and CSV transport/content were verified by the React/API checks above.
