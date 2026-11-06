# Resident dashboard migration

Part 2, step 6. Verified on 2026-11-05 (Asia/Manila) against the unchanged `resident/dashboard.php`, `resident/stat_detail.php`, and `resident/ajax_stats.php` using the same synthetic residents in the existing XAMPP database.

## Files and routes

- `api/index.php` dispatches the two new GET routes to `api/lib/resident.php`.
- `frontend/src/pages/resident/Dashboard.jsx` composes the metric strip, overview, conditional address panel, personal charts, closing surveys and recent submissions from `frontend/src/components/resident/`.
- `frontend/src/pages/resident/StatDetail.jsx` uses `statViews.jsx` for the title, subtitle, unit, columns and row renderer. Missing or unknown views return to Dashboard.
- `frontend/src/components/charts/` provides `ChartCard`, `DoughnutChart`, `BarChart`, shared Chart.js configuration and the `centreText` plugin. Per-chart plugin options keep the centre text current when props change; React Chart.js handles canvas destruction on unmount.
- `frontend/src/hooks/useApiData.js` loads credentialed JSON, aborts requests on navigation/unmount and prevents a previous query's rows from flashing during a view change.
- `frontend/src/routes/AppRoutes.jsx` replaces only the dashboard and stat-detail placeholders. Survey/profile/history destinations retain their existing React routes and await their own migration steps.

No original PHP, config, include, asset, upload or database file was changed. The existing CSS/logo remain byte-identical. No database/schema was recreated or reset and no deployment was performed.

## API contract

Both routes use the existing session, return the established `{ok:true,data:...}` envelope, disable caching, and reject anonymous requests (401), staff accounts (403), inactive/archived residents (401/403), and unsupported methods (405). The resident id always comes from the session; a request's `resident_id` is ignored. The fresh account checks reuse the existing API authentication boundary.

`GET /api/resident/dashboard` returns:

- `resident_name` from the PHP session, `available_count`, `answered_count`, `assigned_total`, `completion_pct`, `next_deadline`, `next_deadline_label`, `days_left`.
- `completion` in the original `ajax_stats.php` shape: Completed and Available label/value rows. Its two queries are the original dashboard/AJAX queries; React consumes this field without a second chart-only round trip.
- `upcoming`: original five soonest open unanswered surveys, with `end_date_label`; `recent_submissions`: original five latest joined submissions with `submitted_at_label`.
- `me`: only the ten address/residency-start fields rendered by this screen. The PHP's `SELECT *` is preserved internally, but hashes and unrelated record fields never enter JSON.
- `is_temporary`, `residence_code`, `residence_name`, `activity_months`, `household_rows`, `residency_months`, `residency_minimum`, `residency_pct`, `residency_start_label`, plus the three original panel-visibility flags.

`GET /api/resident/stat-detail?view=available|completed|assigned` returns `{view,count,rows}`. Rows retain each view's original selected fields/order and add the original PHP-formatted date label. Invalid or absent views return `{ok:true,data:null,redirect:'/resident/dashboard'}`; React also checks its view whitelist before loading.

All displayed dates are formatted on the PHP server, preserving its timezone, date-only interpretation and `M j, Y` / `M j, Y g:i A` conventions across browser timezones.

## Preserved source rules

- Available means active and inclusively date-open, with no response by this resident. Completed counts every own response. Assigned on the dashboard is available plus **all** historical responses; assigned detail lists only currently open surveys. The fixture dashboard reads **16 assigned**, while assigned detail reads **9**. This intentional source discrepancy remains.
- Activity uses the exact `DATE_SUB(CURDATE(), INTERVAL 5 MONTH)` cutoff, not the first day of that month. The original PHP month-label loop and zero filling remain. An account with historical submissions still shows Activity even if all six bars are zero.
- Household includes one spouse when married, the child-row count and the character-reference-row count. Existing `fetch_person_rows()` supplies the family rows; the endpoint sends only chart counts.
- Temporary addresses show the current temporary address and type, three temporary geographic facts and the permanent address below. Permanent addresses show the registered address, household number and three permanent geographic facts. Blank parts/fallback messages follow PHP.
- Residency counts complete years/months from the start date to PHP `today`. Temporary progress is rounded/capped against `permanent_residency_min_months()`; permanent progress is 100%. Future dates yield zero completed months. Passing the minimum never promotes a resident.
- Progress, activity, residency and household retain Chart.js **4.4.4**, source colours/fonts, legend settings, cutouts, spacing, half-ring rotation/circumference and centre-text coordinates. The residency tooltip/legend stay disabled.
- No additional search, pagination, sorting, filters or UI labels were introduced on the detail tables.

## Verification

- `npm --prefix frontend run build` and `npm --prefix frontend run lint`: passed with no warnings.
- Live dashboard API suite: **156 assertions passed**, including original PHP variable/query parity, AJAX completion parity, all three stat views, resident isolation, role/account guards, date/status boundaries, household counts, future/reached residency dates, unknown type labels, invalid views and method enforcement.
- React suite: **18 tests passed** (10 existing and 8 new). New tests compare rendered text, every metric/address fact/table row, chart visibility and datasets to the unchanged PHP presentation for temporary, permanent and empty residents. They cover all stat-view columns/dates/badges/actions, empty completed results, invalid views and centre-text coordinates/updated options. Chart props are captured at the React Chart.js boundary in these tests; real canvases are separately checked in the browser.
- Existing authentication API suite: **266 assertions passed**; its throwaway records/history were removed and schema checks passed.
- Browser comparison used the same temporary resident in PHP and React: **7 waiting, 9 completed, 16 assigned, 56% completion, 3 residency months, 50% of the minimum**, spouse 1 / children 2 / references 3. All 17 metric/panel/chart/canvas rectangles matched exactly at the normal desktop viewport and at **390×844**. Completed detail rows/dates/count matched and metric/back navigation worked. No new errors appeared after the final refresh/navigation check.
- A Vite test run initially invalidated the development dependency cache and caused stale-module hook errors on browser history restoration. The new test server now uses its own temporary Vite cache; a browser reload restored the current app and subsequent navigation was checked again.
- SHA-256 comparison: all **60 protected files** in the pre-task snapshot are unchanged. CSS/logo copies are identical to their original assets. `git diff --check` passed.
- Disposable dashboard accounts, surveys, responses, household rows and login history were removed after browser verification. Existing records were never updated or deleted.

Screenshots and exact rectangle measurements are in `docs/output/resident-dashboard/`: PHP/React desktop and phone images, `measurements.json`, and `phone-measurements.json`. These show synthetic data only.

## Repeatable checks

Run from the project root with XAMPP Apache/MySQL available:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/resident-dashboard-api.php --keep
node --test docs/tests/*.test.mjs
/Applications/XAMPP/xamppfiles/bin/php docs/tests/resident-dashboard-fixtures.php clean
npm --prefix frontend run build
npm --prefix frontend run lint
```

The API suite normally cleans up in `finally`; `--keep` deliberately retains only its synthetic fixtures for the React/browser checks. Always run the explicit cleanup after using `--keep`, including after failed browser work. React dashboard tests skip with a prerequisite message when no dashboard fixtures exist; the remaining tests remain usable without a database. The CLI-only reference runner executes the original source's queries or presentation without editing it, and never serializes the resident's full record.

Existing account records and credentials are not used. Only fixture identities and the public test-only password defined in the runner are used for verification.
