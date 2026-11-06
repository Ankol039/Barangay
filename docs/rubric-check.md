# Rubric check and XAMPP build

Prompt 19 · 2026-11-06 (Asia/Manila).

Read `AGENTS.md`, `docs/migration-analysis.md` and `docs/progress.md` before the review. Inspected the React route tree, components/hooks, API dispatcher and relevant unchanged PHP dashboard, profile and request markup. Existing audits supplied the broader source/behavior baseline. This task authorizes the generated root `app/` deployment; application source/documentation changes stay in `frontend/` and `docs/`. The root legacy README and PHP/configuration/assets/uploads/database files are unchanged.

## Findings and fixes

| Review item | Finding and final result |
| --- | --- |
| Unused components | Removed unused `PublicPlaceholder`, unreachable `ResidentPlaceholder` / `StaffPlaceholder` route fallbacks, and the superseded resident dashboard-facts component. Every resident/staff route has a concrete screen; the structural audit enforces full screen-map coverage. The live not-found page/layout and development component gallery remain reachable. |
| Duplicated markup | Extracted `KeyValueList` for resident/staff dashboard facts, `MetricCell` for linked/static dashboard metrics and `DetailsTable` for profile/family-review tables. DOM elements, classes, text, links and styles are preserved. No repeated composed JSX subtree remains in the normalized AST scan (threshold: five nested JSX elements); manual review also covered equivalent markup with differing bindings. Tiny field labels, source-specific SVG geometry and deliberately different table layouts remain appropriate to their callers. |
| Components over 200 lines | None before or after the review. Final inventory: **175 components**, largest **105 lines** (`LandingDecoration`, unchanged). No arbitrary splitting or minification was used to meet the limit. |
| Prop validation/defaults | Added development `propTypes` to **all 132 prop-bearing components**, including forwarded layout contracts. Shared contracts cover identifiers, field specifications, table columns/filters, chart series, users and request queues; per-component contracts cover callbacks, flags, strings and nodes. Required data/callbacks use explicit required validation; optional variants retain existing parameter defaults. Endpoint-specific business validation stays on PHP. HTML attributes forwarded through rest props remain native attributes. |
| Inconsistent naming | Shared cross-feature primitives now have purpose-based names (`KeyValueList`, `MetricCell`, `DetailsTable`) instead of separate resident/staff facts or profile-only table names. Preserved existing PascalCase component filenames, camelCase hooks/handlers, PHP payload keys and route labels. Moved late React imports to the import section. |
| Dead code | Removed the redundant question-save redirect branch whose two paths called the same callback, stale fallback imports and the unused `Fragment` import in the choices editor. No API or server business rule changed. All **177 source modules** are reachable from the application entry (including development-only imports); lint catches unused bindings and unreachable statements. |
| Missing list keys | None found. All **85 JSX-producing list mappings** have keys; list-key lint is an error. Existing controlled-row/index keys and immutable snapshot lists retain their behavior. |

`npm run lint` now rejects warnings and runs `docs/tests/rubric-audit.mjs` after Oxlint. The structural audit checks component sizes, prop-contract coverage, source reachability, concrete route mappings, composed markup duplication and list keys. `no-unused-vars`, `no-undef`, `no-unreachable`, JSX keys and hook rules are enforced by the lint configuration. Shared validation is guarded by Vite's development flag; it does not change user-facing production behavior.

The updated [frontend README](../frontend/README.md) documents Node/npm, XAMPP Apache/MySQL, use of the existing database, development proxy, production build/copy instructions, folder structure, every screen/compatibility route, all **63 actual API paths**, access and input summaries, verification commands and remaining source limitations. It replaces outdated scaffold instructions without editing the original root README.

## Verification

- `npm run build` and `npm run lint` pass. The build writes `frontend/dist/`; all **33 generated files**, including `.htaccess`, are copied byte-for-byte to root `app/` ([deployment hashes](verification/rubric/deployment-files.json)). Apache serves [the built app](http://localhost/barangay_survey_system/app/).
- Apache returns **HTTP 200** for `/app/`, `/app/login` and `/app/staff/dashboard`; direct nested paths serve the SPA entry. Browser role guards then resolve protected pages correctly.
- The final complete API/React regression run passes **2,177 live API assertions and 97 React tests across 12 suites**, with no skips or prop/list-key warnings. The runner now treats those warnings as failures. [Regression results](verification/parity/regression.json).
- A production browser smoke run checks landing → sign-in, real same-origin `/api/session`, original empty-login validation (expected HTTP 422), direct reload, anonymous portal guard, mobile portal tabs and generated assets. No unexpected console/page/network diagnostics. [Smoke result](verification/rubric/apache-public.json) · [mobile screenshot](verification/rubric/apache-login-mobile.png).
- [Structural report](verification/rubric/structure.json) records zero oversized components, uncovered prop contracts, unused modules, repeated composed JSX or missing keys. [Source change inventory](verification/rubric/source-changes.json) lists created/changed/removed React files.
- SHA-256 comparison verifies **58 original protected files unchanged**; shared CSS and logo copies remain byte-identical. Existing `.DS_Store` working-tree changes were left alone. Audit fixtures use their established registered synthetic records and `finally` cleanup; no existing database reset or import was performed.

- The complete authenticated browser audit against **the Apache build** passes **124 checks**, zero failures and zero unexpected diagnostics: navigation, modal/form actions, role/onboarding gates, all stat views/compatibility entries, real CSV downloads and print invocation/media. [Apache browser results](verification/parity/browser-apache.json).
- Fresh visual comparisons of permanent/temporary resident dashboards/profiles, staff/admin dashboards and admin address/family review pass at **1440, 1024 and 390 px**: **21 pairs / 42 screenshots**, zero differing pixels at the established antialias-aware **0.15** threshold and identical page heights, with no capture errors. [Visual report](verification/visual-audit/rubric-apache/report.json).
- All registered browser/visual fixture records and login history were cleaned. Final build/deployment hashes and protected originals were rechecked after cleanup. [Final verification summary](verification/rubric/final.json).

## Self-assessment

| Criterion | Assessment |
| --- | --- |
| React Components | Meets: small function components, shared layouts/primitives, hooks and complete prop contracts; maximum 105 lines. |
| JSX & React Syntax | Meets: keyed lists, valid JSX, guarded routes, hook lint and clean development prop checks. |
| UI & Design | Meets: unchanged source CSS/logo/SVG system and behavior-preserving DOM extraction; responsive built login verified. Existing source quirks and earlier authorized additions remain documented. |
| Functionality | Meets within tested scope: resident/staff/admin API and React regressions pass; compiled app uses the live XAMPP backend and SPA routing. |
| Code Quality | Meets: unused scaffold/dead branches removed, shared markup consolidated, stricter repeatable lint/audit, current setup/route/API documentation. |

These assessments are evidence-based checks against the named criteria, not a prediction of an external numeric grade. Physical print-dialog output and mail/telephone delivery remain outside automated verification; the unrelated legacy PHP family page still has its original missing dependencies, while its React entry uses the previously authorized working review redirect.
