# Reusable React UI — Part 2, step 4

Read `AGENTS.md`, the migration analysis and progress log, both original headers, footer, all shared JavaScript, and the relevant survey/resident/account/report presentation markup before implementation. The PHP remains the appearance and behavior baseline. Original PHP, configuration, includes, assets, uploads and database files are unchanged.

## Components and integration

All paths below are relative to `frontend/src/`.

| Location | Contract |
| --- | --- |
| `components/layout/StaffLayout.jsx`, `ResidentLayout.jsx` | Route layouts share `PortalLayout` and its `Outlet`. Use session identity by default; the development demo supplies a synthetic `user`. Staff sections, active links, admin-only residency/archive entries and positive admin counts match the PHP. Resident has its own five navigation links and residency/type badges. Onboarding retains the previous PHP-derived header rules. |
| `components/layout/Topbar.jsx`, `AccountMenu.jsx` | Existing logo, chip, name, portal label and avatar/initial fallback; outside click and Escape dismiss the menu. Both portals expose My Profile, Change Password and Logout as requested. PHP's resident account dropdown originally had only My Profile and Logout; its Change Password sidebar entry is preserved, and the dropdown addition is explicitly requested in this step. |
| `components/layout/Footer.jsx` | Current year and original system wording/classes. Shared by public and protected layouts. |
| `components/common/PageHead.jsx`, `PageActionsRow.jsx`, `Card.jsx` | Original `.page-head > .page-head-text`, `.page-actions-row`, `.card`, `.card-head`, `.card-head-title`; optional title, subtitle, actions and original extra classes. Card accepts a heading element through `heading`. |
| `components/common/Alert.jsx`, `Toast.jsx` | Success/error alerts directly in `.container`, or first in `.card`/`.grid-2`, become toasts. Info and modal alerts remain inline; `inline` explicitly retains validation. React children preserve strong text and links without HTML injection. One shared polite/atomic live region. At 6000 ms toast fades/translates; at 6220 ms it is removed. Key each new feedback event to start a new lifetime. Position remains the PHP stylesheet's bottom-center toast position. |
| `components/common/Modal.jsx` | Controlled `open`, `onClose`, optional `beforeClose`, `onReset`, `keepOnClose`, `narrow`, `title`, `icon`, `id` and children. Escape, backdrop, close button and descendant `[data-modal-close]` controls dismiss. `beforeClose` may veto dismissal. Title is a direct child of `.modal-box`, preserving original CSS selectors. |
| `components/common/ConfirmModal.jsx`, `ConfirmProvider.jsx` | Original Please Confirm, warning icon, message, Cancel and Yes, Continue markup. App provider intercepts `[data-confirm]` links/form buttons, prevents premature navigation/submission, preserves link destination and submitter name/value, and uses native form validation. For JSON API actions, use controlled `ConfirmModal` and perform the API call in `onConfirm`. Demo actions only navigate among demo URLs or submit local synthetic forms. |
| `components/common/Badge.jsx`, `EmptyState.jsx` | Source `.badge`/`.badge-{variant}` styles; text-only `.empty-state` or titled `.empty-panel` with optional caller-supplied original icon. No default invented labels/icons. |
| `components/common/DataTable.jsx`, `TableSearch.jsx`, `FilterTabs.jsx` | Columns use `{key,label,render?,headerProps?,cellProps?}`; rows use unique `id`, `searchText`, space-separated `filter`, optional `className`, and column values. Filters use `{value,label}`. Exact original search/filter classes/data attributes, trimmed case-insensitive substring search, combined token filter and no-match row. Independent tables retain independent state. `searchId`, `searchPlaceholder`, `searchLabel`, `emptyMessage` come from the source page. Optional title puts search in the card head; filters put it in `.table-toolbar`. Optional `responsive` adds the existing `.table-responsive` wrapper. |
| `components/auth/PasswordField.jsx` | Reuses previous shared eye icons, toggle labels and classes. `id` is required; `name` defaults to `id` and can be supplied separately. `PasswordSuccessModal` now reuses `Modal`. |
| `hooks/useDirtyWarning.js`, `components/common/DirtyWarningProvider.jsx` | Spread `formProps` on a form; merge custom submit handlers with `clear()`. `markDirty()`, `clear()`, `dirty`, and `confirmDiscard()` are also exposed. Pass `confirmDiscard` to `Modal.beforeClose`, `clear` to its reset handler, and `enabled: open` for modal forms. One provider aggregates multiple forms behind React Router's single blocker. Browser unload, route changes, history navigation and native links are guarded; submit clears the warning. Exact discard message: “Discard the details you entered?” |

`Modal` clears ordinary text/file/textarea controls, resets selects to the first option, restores checkbox/radio defaults and preserves hidden/action fields. Ordinary modal children unmount on close. For **externally controlled fields and validation**, reset their React state in `onReset`; DOM resets cannot own external state. `onReset` runs on close and subsequent open. An initially open modal preserves its initial server-error/field state. `keepOnClose` preserves mounted child state and skips all field/error resets.

No PHP page or shared script uses pagination. No Pagination component, sorting, new page-size controls or server filtering was introduced.

As in `script.js`, the table's no-match row starts hidden and is evaluated after a search/filter event; source pages that need an initial empty-data message can render `EmptyState` separately.

`App` now uses a data router so route/history blocking works. Existing route definitions and authentication guards are retained. The root host uses `display: contents` in `frontend/index.html`; this lets the original body's flex layout reach `.app-main` and keeps the PHP sticky-footer positioning without altering the copied stylesheet.

Vite also forwards the existing `/barangay_survey_system/uploads/` and `/barangay_survey_system/assets/` image URLs to XAMPP, so real account photos can load during development with the same URLs used in production. Account photos are never copied into the frontend.

## Demo and repeatable checks

Start Vite with `npm run dev` in `frontend/`. Visit `/barangay_survey_system/app/dev/components` on its printed origin. This development-only route is excluded from the production build. It exercises the layouts, topbar/menu, footer, page heading/actions, cards, inline alerts, automatic and direct toasts, ordinary/preserved modals, link/form confirmations, badges, two independent tables, password visibility, empty states and dirty warning. Query options:

- `?portal=resident` / `?portal=staff`: resident and non-admin staff navigation; default is admin with synthetic counts 3/2.
- `?onboarding=1`: navigation withholding; combine with `portal=resident` for the plain resident bar.
- `?photo=1`: known local image in the avatar; `?broken-photo=1`: intentional image error/initial fallback.
- `?compare=1`: minimal survey-card comparison; suppresses synthetic counts. Combine with `portal=resident` to compare resident shell.

`docs/tests/shared-ui-reference.php` is CLI-only and never includes the database bootstrap. It renders the **actual original header/footer includes** and the **actual survey-management presentation segment**, with two synthetic survey rows. The resident reference uses the actual resident header around that same comparison card; it is a shell reference, not a migrated resident survey page. Generated HTML is in `docs/output/shared-ui/`. Comparison HTML embeds PHP on the left and React on the right; its React URL records the verification server at port 5185. Adjust that origin if rerunning on another port. Reference action links are relative to the output directory and do not target live staff mutations.

From the project root:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/shared-ui-reference.php staff > docs/output/shared-ui/reference-staff.html
/Applications/XAMPP/xamppfiles/bin/php docs/tests/shared-ui-reference.php resident > docs/output/shared-ui/reference-resident.html
node --test docs/tests/shared-ui.test.mjs docs/tests/react-auth.test.mjs
npm --prefix frontend run lint
npm --prefix frontend run build
```

The interaction tests use test-only `jsdom`; they run React effects, route blockers and DOM events without application records. Eight tests pass: five new shared-UI tests and three existing authentication tests. The new cases cover search/filter combinations and independent tables, declined/accepted dirty dismissal, beforeunload, route/history transitions, normal/keep reset, action/checkbox defaults, confirmation cancellation/link URL/submitter, exact toast thresholds, role navigation/count visibility, image failure, menu closing, backdrop/Escape/resize drawer closing and focus return.

## Visual verification — 2026-11-04, Asia/Manila

Compared both shells side by side against the database-free PHP reference in the browser. At a 1440×1000 iframe viewport, both implementations measured identically:

| Element | x | y | width | height |
| --- | --- | --- | --- | --- |
| Main column | 250 | 0 | 1190 | 1000 |
| Container | 274 | 70 | 1142 | 841.171875 |
| Page heading | 274 | 20 | 1142 | 82.5078125 |
| Card | 274 | 178.0703125 | 1142 | 238.3359375 |
| Table | 295 | 247.0703125 | 1100 | 148.3359375 |
| Footer | 250 | 939.171875 | 1190 | 60.828125 |

Table column widths differed by less than 0.01 CSS pixels (text/subpixel rounding). Existing classes, CSS and icon shapes are retained. Original CSS and logo copies are byte-identical. The initial comparison identified the React root's interrupted flex sizing; the root-host adjustment resolved it.

At a 390×844 iframe viewport, visually compared the mobile layout and both open drawers. PHP and React both use `.sidebar.mobile-open`, `body.nav-open`, and `.nav-drawer-backdrop.active`; React Escape closes and restores `#mobile-nav-toggle` focus. Browser interactions additionally verified combined table filtering, account outside/Escape close, modal validation reset/keep behavior, password eyes, cancel/proceed confirmations with named submitter and link destination, and toast appearance/removal. Native discard prompts are covered deterministically by the DOM interaction suite because the in-app browser's dialog API did not expose that native prompt reliably.

Screenshots: [staff](output/shared-ui/staff-side-by-side.jpg), [resident](output/shared-ui/resident-side-by-side.jpg), [mobile drawers](output/shared-ui/mobile-drawer.jpg). Visual comparison uses synthetic data; no account login, destructive PHP action or database fixture was required. No deployment performed.
