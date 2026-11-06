# Staff and admin dashboard

Part 2, step 9 (Prompt 10), 2026-11-05. The unchanged `staff/dashboard.php` and `staff/ajax_stats.php` are the UI/query reference.

`GET /api/staff/dashboard` serves the original counts, seven-day response delta, active-resident participation, six completion meters, latest eight surveys, top five respondents, residency facts, formatted dates and demographic visibility flag. `admin` is omitted entirely for staff. An admin receives account counts and the latest ten login/logout events, labels resolved by the existing `login_type_label()` helper, and portal counts calculated from those same lists. Captured event roles take precedence over current roles/statuses, as in PHP. No password hashes or complete account records are serialized.

`GET /api/staff/stats?from=YYYY-MM-DD&to=YYYY-MM-DD` returns the eleven original AJAX series inside the established `{ok, data}` envelope. The SQL, ordering, limits, date validation, default 30-day range, reversed-date swap, 365-day span limit (366 inclusive points) and zero-filled gaps are retained. Dates supplied as arrays fall back to defaults. Both routes enforce the existing staff session/account and password/profile onboarding gates; residents receive 403 and anonymous callers 401. Query parameters cannot select a different user or elevate a staff account.

The React route `/staff/dashboard` composes small metric, trend/filter, participation, volume/status/residency, completion, survey table, facts, ranking, question mix, demographic and admin panels. It reuses Prompt 7's ChartCard, BarChart, DoughnutChart and centreText plugin. New optional chart props preserve resident defaults while supporting the staff gauge's arc anchoring and 26px centre text, bar end labels, datasets and filter placement. A reusable LineChart supplies the original filled trend configuration. The original stylesheet, logo and icons remain unchanged.

Apply fetches only the trend series; other panels and figures retain their initial values. Reset uses the original JavaScript date/ISO conversion. Failed filters retain the previous series. Initial chart failure leaves AJAX canvases undrawn and shows the original question-mix error. Completion initially shows three rows, with the original View All / Show top 3 only behavior. The entire six-chart demographic section is absent when no residents exist.

Preserved source details:

- Participation counts **all** distinct responders and divides by active, non-archived residents, clamped to 100%; its SQL does not restrict the numerator to active residents.
- Completion includes active-status surveys even if outside their answer window, capped at six, matching PHP.
- Top responders retain the original JOIN without an archive predicate. Demographic queries exclude archived residents.
- Survey status and open-for-answers counts use their distinct original definitions.
- The original admin logout “View all” target is retained; staff stat-detail/results/management destinations remain the routes established in earlier steps and await their own migration prompts.

Verification:

- `docs/tests/staff-dashboard-api.php`: 139 live assertions against independently executed original PHP for both roles, all eleven series, date edge cases, authorization/onboarding, limits, archive effects and privileged-field omission.
- `docs/tests/staff-dashboard.test.mjs`: seven React checks compare original rendered labels, figures, tables, canvas IDs and all eleven complete chart datasets/options. Exercises expansion, filters/reset/failure, missing dates, empty demographics, source error and centre-text drawing positions. Uses a recorded chart boundary; actual Chart.js rendering is additionally checked in the browser.
- Existing resident dashboard, authentication transport/guards, shared UI and landing checks are included in the 25-test React regression run. All 266 existing live authentication API assertions also passed.
- Test fixtures use only disposable accounts/surveys/questions/responses/audit events in the existing XAMPP database. Cleanup removes only fixture-owned records. CLI helpers reject HTTP execution. No database recreation/reset or source edits.

Run from the project root with XAMPP Apache/MySQL running:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/staff-dashboard-api.php --keep
node --test docs/tests/staff-dashboard.test.mjs docs/tests/resident-dashboard.test.mjs docs/tests/react-auth.test.mjs docs/tests/shared-ui.test.mjs docs/tests/landing.test.mjs
/Applications/XAMPP/xamppfiles/bin/php docs/tests/staff-dashboard-fixtures.php clean
cd frontend
npm run build
npm run lint
```

Browser verification used the same logged-in accounts in PHP and React. At 1440×900, staff and admin panel coordinates, dimensions, chart sizes and total page heights match exactly (staff 2,985px; admin 4,474px). At 390×844 the admin panel geometry and total page height also match exactly (9,175px). Apply/reset and View All were exercised in the live browser. Final React desktop/phone browser logs contain no warnings or errors. Screenshots and JSON measurements are in `docs/output/staff-dashboard/`.

Final build, lint, six new/changed PHP syntax checks and protected-source integrity checks pass. All 60 protected source files are unchanged; CSS/logo copies remain byte-identical. Disposable records and their history were removed after verification. Vite emits a nonblocking chunk-size warning for the 509.42 kB main chunk; the staff dashboard itself is lazy loaded. No deployment was performed.
