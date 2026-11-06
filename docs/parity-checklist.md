# Functional parity checklist

Prompt 18 — 6 November 2026 (Asia/Manila).

260 behavior/action checks; 2177 live API assertions; 97 React tests; 124 browser scenarios, including paired PHP/React flows and React compatibility/guard checks. Final result: **PASS**. Browser console warnings/errors, uncaught exceptions, failed network requests and unexpected HTTP errors: **0**.

This inventory follows [migration-analysis.md](migration-analysis.md) §§1–8. Repeated table-row links/buttons are checked as action templates, with synthetic selected records; public, resident, staff and admin views are covered. R = resident, S = staff, A = admin, P = public. Unauthorized roles are tested for denial instead of being allowed to perform an action. PASS means the stated behavior is verified by the linked live API/source-oracle, React interaction, and/or browser evidence; it does not mean every check was performed manually.

The user explicitly authorized redirecting React’s obsolete family-request route to Address Requests during this task. The old nested-schema form/details are retired, not presented as working legacy CRUD. The original PHP still returns HTTP 500; its missing table/helper are untouched. Staff retains the source’s hidden family-review panel; admin uses the working integrated family review. Other earlier authorized differences remain: login tabs, resident first-login gate, root landing, survey status filters and credential-slip Print.

Native browser downloads were completed for all three roles and checked for failure/content/filename. Printing was verified through actual button invocation and browser print media; a physical printer or operating-system print-dialog output was not tested. Mail/tel targets were checked without sending messages or placing calls. PHP’s remote chart CDN was fulfilled with the unchanged installed Chart.js 4.4.4 distribution, so CDN availability is not claimed.

## Fixes and final verification

- Replaced the legacy family-route placeholder with the authorized guarded redirect to Address Requests. Staff/admin browser checks verify the destination and role visibility.
- Fixed rejected create-survey submissions: retain metadata and recreate one blank question, matching the original PHP POST rendering and script. Added a test that executes the unchanged PHP error page and compares its rebuilt editor.
- Fixed photo crop object-URL cleanup under React StrictMode: the discarded setup no longer starts a request for a revoked blob. Both resident and staff browser crop states now have clean network/console logs; geometry, JPEG export and pointer/zoom behavior remain covered.
- Corrected the outdated staff photo test to expect the PHP’s effective opening zoom of 2×, then verify changing it to 1×.
- Added self-cleaning --react lifetimes to six existing API runners, so the complete regression suite no longer needs retained fixtures. The runner rejects skipped tests.

[Machine-readable regression summary](verification/parity/regression.json) · [Browser results, rendered control inventory and diagnostics](verification/parity/browser-combined.json). Screenshots of modal states are beside the browser report.

## Public entry and authentication

Evidence: [shared](verification/parity/shared.log), [authentication](verification/parity/authentication.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C001 | Root and /landing | P/R/S/A | Public landing, hash entry and reveal behavior; root landing is the earlier authorized addition. | PASS |
| C002 | admin/index.php compatibility link | P/R/S/A | React /admin and /admin/index.php reach the public root. | PASS |
| C003 | resident/login.php compatibility link | P/R/S/A | React resident/login and resident/login.php reach the public root. | PASS |
| C004 | staff/login.php compatibility link | P/R/S/A | React staff/login and staff/login.php reach the public root. | PASS |
| C005 | staff/statistics.php compatibility link | P/R/S/A | Both statistics aliases reach the guarded staff dashboard. | PASS |
| C006 | About link | P/R/S/A | #benefits exists and scrolls into view. | PASS |
| C007 | Features header/footer links | P/R/S/A | #features exists and scrolls into view. | PASS |
| C008 | Sign In link | P/R/S/A | Opens React /login; signed-in login resolves the correct portal/onboarding. | PASS |
| C009 | About Us footer link | P/R/S/A | #about exists. | PASS |
| C010 | Careers footer link | P/R/S/A | #careers exists. | PASS |
| C011 | Contact Us footer link | P/R/S/A | #contact exists. | PASS |
| C012 | How It Works footer link | P/R/S/A | #benefits exists. | PASS |
| C013 | Careers email link | P/R/S/A | mailto:jobs@barangayhealth.example is preserved; external mail delivery is outside this app. | PASS |
| C014 | Contact phone link | P/R/S/A | tel:+63255550123 is preserved; no call is placed. | PASS |
| C015 | Contact email link | P/R/S/A | mailto:info@barangayhealth.example is preserved; no email is sent. | PASS |
| C016 | Log In form/button (login.php and index.php) | P/R/S/A | Identifier trimming, empty/invalid/deactivated errors, role detection/collision precedence, audit and session regeneration match PHP. | PASS |
| C017 | Login password eye | P/R/S/A | Show password / Hide password changes type and accessible label. | PASS |
| C018 | Resident Account / Admin & Staff tabs and switch link | P/R/S/A | Earlier authorized additions clear inputs/error; keyboard arrows/Home/End select the tab; server still determines identity. | PASS |
| C019 | Logout link (normal and onboarding headers) | P/R/S/A | Both PHP logout chains and React POST destroy the session, log the event and reach landing. | PASS |
| C020 | Protected direct links / cross-portal URLs | P/R/S/A | Resident/staff boundaries, admin-only denial, archived/deactivated checks and onboarding follow the documented rules. | PASS |

## Shared resident/staff/admin navigation and JavaScript

Evidence: [shared](verification/parity/shared.log), [authentication](verification/parity/authentication.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C021 | Resident sidebar Dashboard | R/S/A | /resident/dashboard. | PASS |
| C022 | Resident sidebar Available Surveys | R/S/A | /resident/surveys. | PASS |
| C023 | Resident sidebar Submitted Surveys | R/S/A | /resident/history. | PASS |
| C024 | Resident sidebar My Profile | R/S/A | /resident/profile. | PASS |
| C025 | Resident sidebar Change Password | R/S/A | /resident/change-password. | PASS |
| C026 | Staff/admin sidebar Dashboard | R/S/A | /staff/dashboard. | PASS |
| C027 | Staff/admin sidebar Surveys | R/S/A | /staff/survey-management. | PASS |
| C028 | Staff/admin sidebar Results | R/S/A | /staff/results-dashboard. | PASS |
| C029 | Staff/admin sidebar Reports | R/S/A | /staff/reports. | PASS |
| C030 | Staff/admin sidebar Registered Resident | R/S/A | /staff/resident-management. | PASS |
| C031 | Staff/admin sidebar Address Requests | R/S/A | /staff/address-requests. | PASS |
| C032 | Staff/admin sidebar User Management | R/S/A | /staff/user-management; staff sees residents only. | PASS |
| C033 | Staff/admin sidebar Deletion Requests | R/S/A | /staff/deletion-requests; decision actions are admin-only. | PASS |
| C034 | Admin sidebar Residency Records | R/S/A | /staff/residency-records; denied to staff/residents. | PASS |
| C035 | Admin sidebar Deleted Accounts | R/S/A | /staff/deleted-accounts; denied to staff/residents. | PASS |
| C036 | Admin navigation count refresh | R/S/A | Pending deletion/archive badges refresh after actions. | PASS |
| C037 | Open navigation button | R/S/A | Drawer/body/backdrop classes and aria-expanded change. | PASS |
| C038 | Close navigation button | R/S/A | Closes drawer and restores toggle focus. | PASS |
| C039 | Drawer backdrop | R/S/A | Closes drawer. | PASS |
| C040 | Drawer Escape | R/S/A | Closes drawer and restores toggle focus. | PASS |
| C041 | Drawer navigation-item click | R/S/A | Follows destination and closes drawer. | PASS |
| C042 | Drawer resize above 1100px | R/S/A | Closes drawer and removes body navigation state. | PASS |
| C043 | Account chip | R/S/A | Toggles account menu and aria-expanded. | PASS |
| C044 | Account menu outside click / Escape | R/S/A | Closes the dropdown. | PASS |
| C045 | Account-menu My Profile link | R/S/A | Resident profile or staff complete-profile, with source name/role/avatar fallback. | PASS |
| C046 | Staff/admin account-menu Change Password link | R/S/A | Opens staff change-password; absent from the resident source menu. | PASS |
| C047 | Shared confirmation Cancel | R/S/A | Dismisses without a request or mutation. | PASS |
| C048 | Shared confirmation Yes, Continue | R/S/A | Carries original submitter/action or link target exactly once. | PASS |
| C049 | Modal Close × | R/S/A | Dismisses active modal; dirty guarded forms can refuse. | PASS |
| C050 | Modal backdrop | R/S/A | Dismisses active modal with the same guard. | PASS |
| C051 | Modal Escape | R/S/A | Dismisses active overlays with the same guard. | PASS |
| C052 | Modal clear-on-open / clear-on-close | R/S/A | Clears ordinary fields/errors, restores radio defaults and retains action/id inputs. | PASS |
| C053 | Persistent success/confirmation modals | R/S/A | data-keep-on-close retains required content; mutation is never replayed. | PASS |
| C054 | Eligible success/error alert toasts | R/S/A | Polite live region; 6000ms display plus 220ms removal; info/modal errors remain inline. | PASS |
| C055 | Dirty Cancel / route / Back / unload | R/S/A | Discard the details you entered? cancellation preserves data; confirmation permits navigation. | PASS |
| C056 | Table search | R/S/A | Trimmed case-insensitive matching and no-match state. | PASS |
| C057 | Table filter tabs | R/S/A | Combine token filters with search without sorting/pagination. | PASS |
| C058 | All password eyes | R/S/A | Change password/text, visible class and Show/Hide labels. | PASS |
| C059 | Unrendered legacy residency-select confirmation | R/S/A | No source page renders .confirm-residency-change; no new UI is invented. | PASS |

## Resident dashboard, surveys and responses

Evidence: [resident-dashboard](verification/parity/resident-dashboard.log), [resident-survey](verification/parity/resident-survey.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C060 | Answer a Survey link | R | Opens Available Surveys. | PASS |
| C061 | Available metric / available-detail link | R | Open unanswered surveys ordered by earliest close; Answer Survey action. | PASS |
| C062 | Completed metric / completed-detail link | R | All own submissions ordered latest first; Submitted badges. | PASS |
| C063 | Assigned metric / assigned-detail link | R | Headline retains historical-response denominator; detail lists currently open surveys and answered state. | PASS |
| C064 | Stat-detail Back to Dashboard | R | Returns to resident dashboard. | PASS |
| C065 | Unknown/missing stat view | R | Redirects to dashboard. | PASS |
| C066 | Closing-survey Answer Survey links | R | Open the selected unanswered survey. | PASS |
| C067 | Recent-submission/history links | R | Open own submitted history. | PASS |
| C068 | View all submissions link | R | Opens Submitted Surveys. | PASS |
| C069 | Dashboard address-panel My Profile links | R | Opens the profile from either permanent or temporary address guidance. | PASS |
| C070 | Resident chart/stats read action | R | Session-scoped completion, six-month activity, household and temporary-residency series match source. | PASS |
| C071 | Available Surveys Submitted Surveys link | R | Opens history. | PASS |
| C072 | Waiting-survey Answer Survey links | R | Open ordered questions for the chosen survey. | PASS |
| C073 | Answer-survey form | R | Multiple choice, Yes/No, rating 1–5 and optional text use original names/values/order. | PASS |
| C074 | Submit Survey button | R | Missing required radio groups alert and mark missing; valid POST inserts response/answers atomically. | PASS |
| C075 | Answer-survey Cancel link | R | Returns to Available Surveys. | PASS |
| C076 | Closed/inactive/already-answered/missing survey | R | Redirect to surveys; duplicates never produce a second response. | PASS |
| C077 | Empty/failed submission retry | R | Distinct source error text, transaction rollback and quiet duplicate-key race. | PASS |
| C078 | Submission confirmed modal | R | Correct survey title, dismissible, query consumed once and refresh does not replay. | PASS |
| C079 | Standalone confirmation Back to Surveys | R | Guarded title/fallback view and working return link. | PASS |
| C080 | History View links | R | Open only the session resident’s response. | PASS |
| C081 | Response Back to Submitted Surveys | R | Returns to history. | PASS |
| C082 | Print Response button | R | Calls print; shell/actions are hidden in print media. | PASS |
| C083 | Response ?print action | R | Prints once after response data loads. | PASS |
| C084 | Download CSV link | R | Browser attachment and bytes/filename/content match PHP for own response. | PASS |
| C085 | Other resident/missing response | R | View redirects to history; CSV returns original 404. | PASS |

## Resident profile and shared household/photo controls

Evidence: [resident-profile](verification/parity/resident-profile.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C086 | Identity Request Change button | R | Opens name/address request with saved permanent/temporary values. | PASS |
| C087 | Name/address request form / Send Request | R | Custom title and names/addresses validated in source order; queues proposal without applying it. | PASS |
| C088 | Name/address pending request warning | R | Preserves the one-pending-update constraint across name/address and birthdate. | PASS |
| C089 | Birthdate Request Change button | R | Opens corrected birthdate dialog. | PASS |
| C090 | Birthdate request form / Send Request | R | Before-today and changed-date checks; queues proposal without changing birthdate. | PASS |
| C091 | Edit Contact button | R | Opens contact editor; Cancel/dirty dismissal preserves or clears as source requires. | PASS |
| C092 | Contact form / Save Changes | R | Immediate scoped contact save; source at-least-one/contact checks and browser email constraint. | PASS |
| C093 | Family Request Change button | R | Opens family request, or pending notice; generic clear-on-open behavior retained. | PASS |
| C094 | Family request form / Submit Request | R | Valid reason/references/person names/hotline required; No clears spouse/children; stores JSON proposal only. | PASS |
| C095 | Family pending state | R | Existing pending family proposal replaces the request form with its notice. | PASS |
| C096 | Courtesy title Others | R | Reveals custom title; choosing another option hides and clears it. | PASS |
| C097 | Married Yes / No | R | Reveals/enables spouse fields or hides/disables them. | PASS |
| C098 | Children Yes / No | R | Reveals/enables child rows or hides/disables them. | PASS |
| C099 | Add another child button | R | Adds a cleared enabled row, renumbers names/labels and focuses first input. | PASS |
| C100 | Child Remove button | R | Removes and renumbers; final row remains. | PASS |
| C101 | Add another reference button | R | Adds a cleared row with indexed person fields. | PASS |
| C102 | Reference Remove button | R | Removes and renumbers; final row remains. | PASS |
| C103 | Father/Mother and relative custom-title controls | R | Shared person metadata, values, limits and optional parent behavior preserved. | PASS |
| C104 | Upload Photo label/file form | R | Opens image picker; invalid MIME/read errors use source messages. | PASS |
| C105 | Photo crop dialog | R | 320×320 cover crop, effective initial 2×, 1–3 zoom, preview and clamped scaled-pointer dragging. | PASS |
| C106 | Photo Choose another button | R | Clears selected image/preview and reopens picker. | PASS |
| C107 | Photo Confirm & Upload button | R | Single pending multipart JPEG upload at quality .9; correct endpoint and avatar refresh. | PASS |
| C108 | Photo upload failure | R | Source error stays visible for retry; previously documented React retry improvement retained. | PASS |
| C109 | Profile Updated success modal | R | Correct photo success, dismissible without replay. | PASS |
| C110 | Remove Photo form/button | R | Shared Cancel/proceed confirmation; removes file/path/avatar and preserves original unconsumed flash behavior. | PASS |

## Resident/staff/admin password forms

Evidence: [authentication](verification/parity/authentication.log), [shared](verification/parity/shared.log), [staff-users](verification/parity/staff-users.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C111 | Current/new/confirm form and Update Password | R/S/A | Current hash, policy, different password and confirmation precedence; values clear after response. | PASS |
| C112 | Password requirement indicators | R/S/A | Eight characters, uppercase, lowercase, number and no spaces. | PASS |
| C113 | Passwords match / mismatch hint | R/S/A | Updates with confirmation input. | PASS |
| C114 | Resident first-login password save | R/S/A | Clears first-login; success modal/dashboard access and hidden first-login navigation. | PASS |
| C115 | Staff first-login Set Password | R/S/A | Staff Number cannot be new password; incomplete profile moves to step 2. | PASS |
| C116 | Password success Go to Dashboard | R/S/A | Navigates to the proper dashboard. | PASS |
| C117 | Password success Stay Here | R/S/A | Closes success modal. | PASS |
| C118 | Password success × / Escape / backdrop | R/S/A | Dismisses persistent success without replaying password mutation. | PASS |

## Staff/admin dashboard and detail links

Evidence: [staff-dashboard](verification/parity/staff-dashboard.log), [staff-results](verification/parity/staff-results.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C119 | New Survey link | S/A | Opens create-survey form. | PASS |
| C120 | Total Surveys metric | S/A | Opens total_surveys detail. | PASS |
| C121 | Active Surveys metric | S/A | Opens active_surveys detail with open-date window. | PASS |
| C122 | Registered Residents metric | S/A | Opens total_residents detail. | PASS |
| C123 | Responses Received metric | S/A | Opens total_responses detail, source limit 200. | PASS |
| C124 | Address Requests metric/action | S/A | Opens request queue. | PASS |
| C125 | Date-range Apply button | S/A | Updates trend only; strict dates, reversed range swap, 365-day clamp and zero-fill. | PASS |
| C126 | Last 30 days reset button | S/A | Restores source browser-date range. | PASS |
| C127 | Trend/stats fetch failure | S/A | Preserves range/content; original question-mix error state. | PASS |
| C128 | Completion View All button | S/A | Expands beyond top three. | PASS |
| C129 | Completion Show top 3 only button | S/A | Restores collapsed list. | PASS |
| C130 | Survey overview View Results links | S/A | Select the corresponding results survey. | PASS |
| C131 | Dashboard View all survey/results links | S/A | Open corresponding list/detail destinations. | PASS |
| C132 | Admin Staff Accounts metric | S/A | Opens staff_accounts detail; staff cannot access. | PASS |
| C133 | Admin Deactivated Residents metric | S/A | Opens deactivated_residents detail. | PASS |
| C134 | Admin Deactivated Staff metric | S/A | Opens deactivated_staff detail. | PASS |
| C135 | Admin Deletion Requests link/metric | S/A | Opens deletion queue. | PASS |
| C136 | Admin Deleted Accounts link/metric | S/A | Opens archive. | PASS |
| C137 | Recent Login Activity View all | S/A | Opens recent_logins detail; retains unfiltered source events/100-row cap. | PASS |
| C138 | Recent Logout Activity View all | S/A | Unknown recent_logouts view redirects to dashboard, as PHP does. | PASS |
| C139 | Stat-detail Back to Dashboard | S/A | Correct return destination. | PASS |
| C140 | Invalid/missing/admin-restricted stat view | S/A | Source dashboard redirect. | PASS |
| C141 | All dashboard chart reads | S/A | Eleven staff datasets and admin-only activity/account data preserve source query scopes; staff payload omits admin data. | PASS |

## Survey and question management

Evidence: [staff-surveys](verification/parity/staff-surveys.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C142 | Create/New Survey links | S/A | Open add-survey. | PASS |
| C143 | Survey search | S/A | Matches source title/search text; earlier requested All/Active/Inactive filters remain. | PASS |
| C144 | Survey Edit link | S/A | Loads metadata for selected survey. | PASS |
| C145 | Survey Manage Questions link | S/A | Loads selected survey’s question table. | PASS |
| C146 | Survey View Results link | S/A | Loads selected results. | PASS |
| C147 | Activate / Deactivate survey button | S/A | Source confirmation text; Cancel is inert; proceed toggles status and refreshes list. | PASS |
| C148 | Delete survey button | S/A | Source destructive warning; Cancel inert; proceed cascades survey/questions/choices/responses/results. | PASS |
| C149 | Create-survey form / Save Survey | S/A | Source title/description/date/question validation; transactional four-type create; list destination. | PASS |
| C150 | Rejected create-survey POST | S/A | Metadata retained; question editor rebuilds one blank row exactly like PHP (fixed in this audit). | PASS |
| C151 | Builder + Add Question | S/A | Appends and numbers new unsaved question. | PASS |
| C152 | Builder Remove Question | S/A | Removes selected question and renumbers. | PASS |
| C153 | Question type select | S/A | Multiple Choice reveals choices; Yes/No, Rating Scale and Short Answer hide them. | PASS |
| C154 | Add choice button | S/A | Adds one max150 choice with original name. | PASS |
| C155 | Remove choice button | S/A | At two choices clears; above two removes row. | PASS |
| C156 | Preview Survey button/modal | S/A | Shows escaped unsaved title/description/dates/questions/choices; no server save. | PASS |
| C157 | Preview Close / × / Escape / backdrop | S/A | Closes while retaining builder values. | PASS |
| C158 | Create-survey Cancel link | S/A | Dirty discard prompt and survey-list return. | PASS |
| C159 | Edit-survey form / Save Changes | S/A | Updates metadata/status only; exact success/error and retained data. | PASS |
| C160 | Edit-survey Manage Questions / back links | S/A | Keep selected survey id. | PASS |
| C161 | Missing survey edit/questions | S/A | Source survey-list redirect. | PASS |
| C162 | Question + Add Question button | S/A | Opens add dialog. | PASS |
| C163 | Add-question form / Add Question | S/A | Meaningful five-character question, at least two nonblank multiple choices and append order. | PASS |
| C164 | Question Edit button | S/A | Prefills correct question/type/choices. | PASS |
| C165 | Edit-question form / Save Changes | S/A | Owned question check; retained choice ids, new/removal rows and source validation. | PASS |
| C166 | Question Move up button | S/A | Swaps with previous question; first row remains disabled. | PASS |
| C167 | Question Move down button | S/A | Swaps with next question; last row remains disabled. | PASS |
| C168 | Question Delete button | S/A | Source confirmation, ownership and resequencing. | PASS |
| C169 | Question dialog Cancel / × / Escape / backdrop | S/A | Dirty guard and reset; failed submit retains values/errors. | PASS |
| C170 | Question success modal | S/A | Exact added/updated/deleted/moved message plus original success alert; dismisses without replay. | PASS |

## Results, reports and exports

Evidence: [staff-results](verification/parity/staff-results.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C171 | Results survey selector GET form | S/A | Auto-load, selected URL, Back/Forward and per-question search reset. | PASS |
| C172 | Results View Report link | S/A | Selected report with correct survey id. | PASS |
| C173 | Open-text answer search | S/A | Untrimmed case-insensitive matching; per-question no-match row and clear behavior. | PASS |
| C174 | No Result Selected / invalid survey | S/A | Exact source unselected/not-found state. | PASS |
| C175 | Results aggregation action | S/A | Per-question denominator, tied maxima, zero choices/Yes-No/rating bands, rounded mean and latest200 text cap. | PASS |
| C176 | Report survey selector GET form | S/A | Selected/no-report/not-found states preserve source. | PASS |
| C177 | Print Report button | S/A | Print invocation and no-print shell/actions; uncapped text and all zero rows. | PASS |
| C178 | Report Download CSV link | S/A | Real attachment with sanitized filename, source observed-only Yes-No/rating rows, uncapped text and exact API bytes. | PASS |
| C179 | Missing export survey | S/A | Source report destination. | PASS |

## Resident records and account actions

Evidence: [staff-residents](verification/parity/staff-residents.log), [staff-resident-actions](verification/parity/staff-resident-actions.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C180 | Resident search | S/A | Trimmed case-insensitive name/number matching. | PASS |
| C181 | All / Permanent / Temporary / Pending Login / Deactivated tabs | S/A | Combine with search; nonarchived inactive records remain listed. | PASS |
| C182 | + Add Resident button | S/A | Opens clear registration form. | PASS |
| C183 | Add-resident form / Add Resident | S/A | Generated number/default password; source required names/family/addresses/reference/category/date/contact/length validation and atomic related rows. | PASS |
| C184 | Permanent / Temporary select | S/A | Temporary blocks reveal/enable/require source fields. | PASS |
| C185 | Special category select | S/A | None clears reference; category reveals exact label/pattern/hint and validation. | PASS |
| C186 | Birthdate input action | S/A | Singular/plural age and date limits. | PASS |
| C187 | Residing Here Since action | S/A | Calendar duration/minimum hint and native validity; never automatically promotes. | PASS |
| C188 | Resident household/title/repeater controls | S/A | Same shared marriage/child/reference/parent/custom-title behavior in Add and Edit. | PASS |
| C189 | Resident form Cancel / × / Escape / backdrop | S/A | Clear fresh registration or retain rejected input as source requires. | PASS |
| C190 | Edit Resident chooser button/modal | S/A | Lists residents and selected id. | PASS |
| C191 | Show Edit Form button | S/A | Missing selection exact alert; selected resident opens prefilled record/family rows. | PASS |
| C192 | Row Edit resident button | S/A | Correct target custom titles, arrays and read-only residency status. | PASS |
| C193 | ?edit compatibility state | S/A | Prefills without inventing automatic overlay activation. | PASS |
| C194 | Edit-resident form / Update Resident | S/A | Source validation differences preserved; replaces related rows atomically; number/password/status unchanged. | PASS |
| C195 | Reset password button | S/A | Source confirmation, reset to Resident Number and first-login flag. | PASS |
| C196 | Deactivate / Reactivate resident button | S/A | Source confirmation/messages; responses retained and status refreshed. | PASS |
| C197 | Staff Request deletion button | S/A | Target/reason dialog; pending request becomes disabled clock. | PASS |
| C198 | Deletion-request form / Send Request | S/A | Requires reason; snapshots identity; duplicate pending outcome and no account mutation. | PASS |
| C199 | Admin Delete resident button | S/A | Preserves stale source warning; actually archives and retains responses. | PASS |
| C200 | Admin Make Permanent Resident button/modal | S/A | Preview current/temporary addresses and optional reason; six-month and address eligibility. | PASS |
| C201 | Promotion form / Confirm | S/A | Snapshots original addresses/type, copies temporary to permanent, clears temporary fields and logs change atomically. | PASS |
| C202 | Admin Revert to Temporary Resident button/modal | S/A | Preview restored archived address and require reason. | PASS |
| C203 | Reversion form / Confirm | S/A | Restores snapshot or source no-snapshot fallback; retains audit and changes status atomically. | PASS |
| C204 | Resident success modal | S/A | Exact operation feedback, refreshed list/status/counts and no replay. | PASS |
| C205 | Resident action role denials | S/A | Residents denied; staff cannot archive/promote/revert. | PASS |

## User accounts and staff profile/onboarding

Evidence: [staff-users](verification/parity/staff-users.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C206 | User Management pending-deletion review link | S/A | Admin queue notice points to deletion reviews. | PASS |
| C207 | Staff-account search | S/A | Independent table; source has no injected no-match row. | PASS |
| C208 | Resident-account search | S/A | Independent table with source no-match behavior. | PASS |
| C209 | Resident management link | S/A | Opens Registered Resident. | PASS |
| C210 | Resident account toggle button | S/A | Staff/admin source messages and refreshed state. | PASS |
| C211 | + Add Staff Account button | S/A | Admin-only; blank Date Hired on open as PHP reset requires. | PASS |
| C212 | Add-staff form / Create Account | S/A | Name/title/hire-date checks, fixed staff role and generated Staff Number/default password. | PASS |
| C213 | New-staff credential modal | S/A | One-time number/handover instructions, persistent content, no hash leakage. | PASS |
| C214 | Credential Print button | S/A | Earlier requested addition prints only the handover slip. | PASS |
| C215 | Credential Done / close | S/A | Dismisses slip; remount does not repeat it. | PASS |
| C216 | Staff toggle account button | S/A | Admin-only; cannot deactivate self; exact source confirmation/message. | PASS |
| C217 | Staff Reset password button | S/A | Admin-only/not self; Staff Number reset and first-login requirement. | PASS |
| C218 | Staff Delete account button | S/A | Admin-only; self/last-admin checks; archive with source message. | PASS |
| C219 | User-operation success modal | S/A | Exact source feedback and refreshed roster/counts. | PASS |
| C220 | Complete Profile Admin Record | S/A | Read-only employment/profile record except photo; no invented details form. | PASS |
| C221 | Complete Profile Staff Record | S/A | Source number/employment/onboarding badges and dates. | PASS |
| C222 | Staff profile form / Save / Complete Setup | S/A | Required contact, optional email/license/emergency phone, past birthdate and address rules; transactional save. | PASS |
| C223 | Staff profile age action | S/A | Updates source age label from birthdate. | PASS |
| C224 | Initial profile setup | S/A | Saves address immediately and completes step 2/navigation. | PASS |
| C225 | Later staff address-change form/reason | S/A | Queues proposal; personal contact saves immediately; stored address changes only after admin approval. | PASS |
| C226 | Pending staff address lock | S/A | Disables address changes while permitting personal saves and avoids duplicate proposals. | PASS |
| C227 | Staff/admin Upload Photo / crop / Choose another / Confirm & Upload | S/A | Shared 2× crop and JPEG multipart; staff5MB cap; replacement deletes prior photo and refreshes avatar. | PASS |
| C228 | Staff/admin Remove Photo button/form | S/A | Confirmation and file/path cleanup; source unconsumed success behavior retained. | PASS |
| C229 | Staff profile success Go to Dashboard / Stay Here / close | S/A | Exact first/normal/requested/photo outcome; no repeated save. | PASS |

## Request review, archive and residency history

Evidence: [request-review](verification/parity/request-review.log); [browser audit](verification/parity/browser-combined.json).

| ID | Link, button, form, modal or action | Roles | Expected PHP behavior / accepted change | Result |
| --- | --- | --- | --- | --- |
| C230 | Resident request Approve form/button | S/A | Staff/admin decision applies only proposed name/address/temp/date fields atomically. | PASS |
| C231 | Resident request Reject form/button | S/A | Leaves resident record unchanged; optional handler note preserved. | PASS |
| C232 | Resident-review success modal | S/A | Exact approved/rejected message and refreshed decision state. | PASS |
| C233 | Staff-address Approve button/modal | S/A | Admin-only target/decision; optional approval note. | PASS |
| C234 | Staff-address Reject button/modal | S/A | Admin-only and rejection note required by browser/API. | PASS |
| C235 | Staff-address review form / submit | S/A | Only pending request; status/reviewer/time/note; approved address applied. | PASS |
| C236 | Family inline review note | S/A | Active flat JSON proposal shown only to admin, matching source role rendering. | PASS |
| C237 | Family Approve form/button | S/A | Occupation/hotline/person/children/references applied atomically; malformed payload stays pending. | PASS |
| C238 | Family Reject form/button | S/A | Optional note; resident/family rows unchanged; original unconsumed flash preserved. | PASS |
| C239 | Concurrent/repeated request decisions | S/A | Only one pending decision succeeds; failure rolls back all related writes. | PASS |
| C240 | Legacy /staff/family-requests link | S/A | User-authorized redirect to Address Requests for staff/admin; original broken PHP remains untouched. | PASS |
| C241 | Legacy View full proposed details / optional note / Approve / Reject form | S/A | Retired legacy nested-schema UI is explicitly replaced by that authorized redirect; working active family review is verified above. | PASS |
| C242 | Deletion Requests staff view | S/A | Read-only awaiting-admin state; no review action exposed. | PASS |
| C243 | Deletion Approve button/modal | S/A | Target/reason/archive warning and optional approval note. | PASS |
| C244 | Deletion Reject button/modal | S/A | Requires nonblank rejection note. | PASS |
| C245 | Deletion review form / submit | S/A | Admin-only; only pending; approval archives and resolves other pending deletions; rejection keeps record. | PASS |
| C246 | Deletion review Cancel / × / Escape / backdrop | S/A | Inert cancellation; reopen resets target/action/note. | PASS |
| C247 | Deletion outcome In archive link | S/A | Admin sees archive link; staff text, restored marker, reviewer/date and removed-record fallback match PHP. | PASS |
| C248 | Archived resident Restore form/button | S/A | Direct restore; keeps response history and previous active/first-login flags; adds restoration review marker. | PASS |
| C249 | Archived staff Restore form/button | S/A | Direct restore; archive metadata cleared; existing active state retained. | PASS |
| C250 | Resident Delete Forever button | S/A | Opens purge modal with held response count and irreversible warning. | PASS |
| C251 | Staff Delete Forever button | S/A | Opens purge modal with target and irreversible warning. | PASS |
| C252 | Purge form / Delete Forever confirmation | S/A | Archived-only hard deletion, database cascades and self protection; verified only on registered fixture ids. | PASS |
| C253 | Purge Cancel / × / Escape / backdrop | S/A | Closes without mutation. | PASS |
| C254 | Archive feedback and empty states | S/A | Exact source not-found/success messages; removed rows/counts refresh. | PASS |
| C255 | Residency Records Back link | S/A | Returns to Registered Resident. | PASS |
| C256 | Residency Permanent metric/filter link | S/A | Shows transitions to permanent; full-log count stays unchanged. | PASS |
| C257 | Residency Temporary metric/filter link | S/A | Shows transitions to temporary; full-log count stays unchanged. | PASS |
| C258 | Residency all/invalid filter | S/A | Invalid values show all; URL/Back/Forward retain selected filter. | PASS |
| C259 | Residency history search | S/A | Name/number only, case-insensitive and trimmed; no-match state. | PASS |
| C260 | Residency history read action | S/A | Current status separate from historical change, undone/address/reason/actor/time/deleted fallback; no mutation controls. | PASS |

## Exact form and modal declaration coverage

All **35 forms** and **30 modal declarations** recorded in analysis §8 are included below. Shared includes and loop-generated rows are not counted as new declarations. One legacy form is covered by the explicitly authorized retirement/redirect. Source line numbers refer to the unchanged PHP baseline. The behavior checks above and linked suites exercise submits, errors, successful decisions, cancellation/reset, and persistent success state; a declaration entry is not a claim that source inspection alone proves behavior.

| PHP source | Form declaration | Evidence | Result |
| --- | --- | --- | --- |
| index.php:154 | <form method="POST" novalidate> | [authentication](verification/parity/authentication.log) | PASS |
| login.php:150 | <form method="POST" novalidate> | [authentication](verification/parity/authentication.log) | PASS |
| resident/change_password.php:97 | <form method="POST"> | [authentication](verification/parity/authentication.log) | PASS |
| resident/profile.php:647 | <form method="POST" class="photo-remove-form"> | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php:655 | <form id="photo-upload-form" method="POST" enctype="multipart/form-data" class="profile-photo-form"> | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php:704 | <form method="POST" class="profile-request-form" data-dirty-warn> | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php:730 | <form method="POST" class="profile-request-form" data-dirty-warn> | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php:823 | <form method="POST" class="profile-request-form" data-dirty-warn> | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php:887 | <form method="POST" data-unsaved-warning> | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/survey_form.php:64 | <form method="POST" action="submit.php" id="survey-answer-form"> | [resident-survey](verification/parity/resident-survey.log) | PASS |
| staff/add_survey.php:82 | <form method="POST" id="survey-builder-form" data-dirty-warn> | [staff-surveys](verification/parity/staff-surveys.log) | PASS |
| staff/address_requests.php:381 | <form method="POST" style="display:inline-flex; gap:8px; align-items:center;"> | [request-review](verification/parity/request-review.log) | PASS |
| staff/address_requests.php:386 | <form method="POST" style="display:inline-flex; gap:8px; align-items:center;"> | [request-review](verification/parity/request-review.log) | PASS |
| staff/address_requests.php:488 | <form method="POST"> | [request-review](verification/parity/request-review.log) | PASS |
| staff/address_requests.php:575 | <form method="POST" class="inline-review-form"> | [request-review](verification/parity/request-review.log) | PASS |
| staff/change_password.php:115 | <form method="POST"> | [authentication](verification/parity/authentication.log) | PASS |
| staff/complete_profile.php:348 | <form method="POST" class="photo-remove-form"> | [staff-users](verification/parity/staff-users.log) | PASS |
| staff/complete_profile.php:356 | <form id="photo-upload-form" method="POST" enctype="multipart/form-data" class="profile-photo-form"> | [staff-users](verification/parity/staff-users.log) | PASS |
| staff/complete_profile.php:365 | <form method="POST"> | [staff-users](verification/parity/staff-users.log) | PASS |
| staff/deleted_accounts.php:176 | <form method="POST" class="inline-select-form"> | [request-review](verification/parity/request-review.log) | PASS |
| staff/deleted_accounts.php:236 | <form method="POST" class="inline-select-form"> | [request-review](verification/parity/request-review.log) | PASS |
| staff/deleted_accounts.php:270 | <form method="POST"> | [request-review](verification/parity/request-review.log) | PASS |
| staff/deletion_requests.php:261 | <form method="POST"> | [request-review](verification/parity/request-review.log) | PASS |
| staff/edit_survey.php:57 | <form method="POST"> | [staff-surveys](verification/parity/staff-surveys.log) | PASS |
| staff/family_requests.php:212 | <form method="POST" class="review-inline-form"> — retired by authorized redirect | [request-review](verification/parity/request-review.log) | PASS |
| staff/question_management.php:314 | <form method="POST" id="add-question-form" data-dirty-warn> | [staff-surveys](verification/parity/staff-surveys.log) | PASS |
| staff/question_management.php:360 | <form method="POST" id="edit-question-form" data-dirty-warn> | [staff-surveys](verification/parity/staff-surveys.log) | PASS |
| staff/reports.php:81 | <form method="GET"> | [staff-results](verification/parity/staff-results.log) | PASS |
| staff/resident_management.php:1276 | <form method="POST"> | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php:1487 | <form method="POST"> | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php:2070 | <form method="POST"> | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php:2323 | <form method="POST"> | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php:2360 | <form method="POST"> | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/results_dashboard.php:121 | <form method="GET"> | [staff-results](verification/parity/staff-results.log) | PASS |
| staff/user_management.php:401 | <form method="POST"> | [staff-users](verification/parity/staff-users.log) | PASS |

| PHP source | Modal | Evidence | Result |
| --- | --- | --- | --- |
| includes/footer.php | confirm-modal | [shared](verification/parity/shared.log) | PASS |
| resident/change_password.php | password-success-modal | [authentication](verification/parity/authentication.log) | PASS |
| resident/profile.php | photo-confirm-modal | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php | edit-contact-modal | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php | request-change-modal | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php | request-birthdate-modal | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php | profile-success-modal | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/profile.php | family-request-modal | [resident-profile](verification/parity/resident-profile.log) | PASS |
| resident/surveys.php | submitted-modal | [resident-survey](verification/parity/resident-survey.log) | PASS |
| staff/add_survey.php | survey-preview-modal | [staff-surveys](verification/parity/staff-surveys.log) | PASS |
| staff/address_requests.php | staff-review-modal | [request-review](verification/parity/request-review.log) | PASS |
| staff/address_requests.php | address-request-success-modal | [request-review](verification/parity/request-review.log) | PASS |
| staff/change_password.php | password-success-modal | [authentication](verification/parity/authentication.log) | PASS |
| staff/complete_profile.php | photo-confirm-modal | [staff-users](verification/parity/staff-users.log) | PASS |
| staff/complete_profile.php | profile-success-modal | [staff-users](verification/parity/staff-users.log) | PASS |
| staff/deleted_accounts.php | purge-modal | [request-review](verification/parity/request-review.log) | PASS |
| staff/deletion_requests.php | review-request-modal | [request-review](verification/parity/request-review.log) | PASS |
| staff/question_management.php | add-question-modal | [staff-surveys](verification/parity/staff-surveys.log) | PASS |
| staff/question_management.php | edit-question-modal | [staff-surveys](verification/parity/staff-surveys.log) | PASS |
| staff/question_management.php | question-success-modal | [staff-surveys](verification/parity/staff-surveys.log) | PASS |
| staff/resident_management.php | add-resident-modal | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php | edit-resident-chooser-modal | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php | edit-resident-info-modal | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php | resident-success-modal | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php | request-delete-modal | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php | promote-resident-modal | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/resident_management.php | revert-resident-modal | [staff-resident-actions](verification/parity/staff-resident-actions.log), [staff-residents](verification/parity/staff-residents.log) | PASS |
| staff/user_management.php | add-staff-modal | [staff-users](verification/parity/staff-users.log) | PASS |
| staff/user_management.php | new-staff-modal | [staff-users](verification/parity/staff-users.log) | PASS |
| staff/user_management.php | user-success-modal | [staff-users](verification/parity/staff-users.log) | PASS |

## Executed suites

| Suite | Live API assertions | React tests (no skips) | Result |
| --- | --- | --- | --- |
| [shared](verification/parity/shared.log) | 0 | 10 | PASS |
| [authentication](verification/parity/authentication.log) | 266 | 0 | PASS |
| [resident-dashboard](verification/parity/resident-dashboard.log) | 156 | 8 | PASS |
| [resident-survey](verification/parity/resident-survey.log) | 87 | 5 | PASS |
| [resident-profile](verification/parity/resident-profile.log) | 117 | 7 | PASS |
| [staff-dashboard](verification/parity/staff-dashboard.log) | 139 | 7 | PASS |
| [staff-surveys](verification/parity/staff-surveys.log) | 183 | 9 | PASS |
| [staff-results](verification/parity/staff-results.log) | 364 | 16 | PASS |
| [staff-residents](verification/parity/staff-residents.log) | 122 | 8 | PASS |
| [staff-resident-actions](verification/parity/staff-resident-actions.log) | 392 | 9 | PASS |
| [request-review](verification/parity/request-review.log) | 212 | 10 | PASS |
| [staff-users](verification/parity/staff-users.log) | 139 | 8 | PASS |
| [Chrome PHP/React comparisons](verification/parity/browser-combined.json) | — | 124 browser scenarios | PASS |

## Reproduction

With the existing XAMPP Apache/MySQL and Vite on port 5173 available:

```sh
node docs/tests/parity-regression.mjs
node docs/tests/parity-browser.mjs
node docs/tests/parity-report.mjs
npm --prefix frontend run build
npm --prefix frontend run lint
```

Run the regression and browser runners sequentially: both use disposable records in the live database, and unrelated rows with identical source sort timestamps can otherwise alter tie order between two snapshots. Each runner owns cleanup in finally; no existing account/password/survey was modified. Browser dependencies reuse /tmp/barangay-visual-tools (Playwright/pngjs) and installed Google Chrome, as in the prior visual audit. API path spellings in analysis §6 are proposals; actual wired paths are in api/index.php, and transport changes preserve the action outcomes. No separate API is invented for local modal/search/print/reveal/preview actions or retired legacy schema.

Build and lint pass: [production build log](verification/parity/build.log), [lint log](verification/parity/lint.log). Modified PHP test helpers pass PHP syntax checks. [Protected-file integrity](verification/parity/integrity.json) confirms no original PHP/config/include/asset/upload/database file changed or was added, and shared CSS/logo copies remain byte-identical. Synthetic fixture registries, records, test photo files and account audit rows were cleaned; no deployment was performed.
