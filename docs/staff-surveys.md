# Staff survey and question management

Part 2, step 10 (Prompt 11), 2026-11-05. Sources: unchanged `staff/survey_management.php`, `staff/add_survey.php`, `staff/edit_survey.php`, `staff/question_management.php`, `assets/js/script.js`, shared headers/footer/icons and `valid_written_text()`.

## Implemented files

- `api/lib/staff-surveys.php`: nested JSON/form adapter, shared source metadata validation, prepared statements, survey CRUD/status and transactional creation.
- `api/lib/staff-questions.php`: question CRUD, ordered reads, adjacent moves, resequencing after deletion, stable existing choice IDs and explicit choice removals. Mutations lock the survey row and commit atomically.
- `api/index.php`: dispatch and per-endpoint method allowlists, including GET/POST on collection routes.
- `frontend/src/pages/staff/SurveyManagement.jsx`, `SurveyEditor.jsx`, `QuestionManagement.jsx`: four former placeholders, with one add/edit page and shared `SurveyForm`.
- `frontend/src/components/surveys/`: `SurveyTable`, `SurveyForm`, `SurveyMetadataFields`, `QuestionList`, `QuestionEditor`, `ChoiceRows`, `QuestionModal`, `SurveyPreview` and question type/payload helpers.
- Existing `ConfirmModal` now accepts an optional ID, avoiding duplicate IDs alongside the shell's shared confirmation. Existing callers retain their original default. Staff routes are lazy loaded in `AppRoutes.jsx`.

No original PHP, includes, configuration, CSS, logo, icon set, uploads or schema was changed. Business validation stays in PHP and reuses the existing meaningful-text helper. Account identity comes from the authenticated session, including created_by. Staff and admin are allowed; resident/anonymous requests are denied; onboarding redirects are enforced before mutations.

## API

All paths below are relative to `/barangay_survey_system/api`. POST accepts a JSON object or original form encoding, using the existing session cookie and JSON envelope. Unsupported methods return 405/Allow. Missing read/edit/question-parent surveys return `/staff/survey-management` as the original pages do.

| Method | Path | Request / result |
| --- | --- | --- |
| GET | `/staff/surveys` | Source newest-first rows with response_count; Manila opening/closing defaults for creation |
| POST | `/staff/surveys` | title, description, start_date, end_date, questions: [{text,type,choices: string[]}]. Creates active survey and ordered nonblank questions in one transaction; returns survey_id and list destination |
| GET | `/staff/survey?id=…` | Editable survey metadata |
| POST | `/staff/survey/update` | id, title, description, start_date, end_date, status; returns saved metadata and `Survey updated successfully.` |
| POST | `/staff/survey/toggle` | id; flips status; clean list destination, no invented success message |
| POST | `/staff/survey/delete` | id; original deletion success/error text and list destination; existing survey cascades |
| GET | `/staff/questions?survey_id=…` | Survey and questions ordered by question_order then question_id; choices ordered by choice_id |
| POST | `/staff/questions` | survey_id, question_text, question_type, choices: string[]; appends at MAX(order)+1 |
| POST | `/staff/question/update` | survey_id, question_id, question_text, question_type, existing_choices: object keyed by choice ID, new_choices: string[] |
| POST | `/staff/question/delete` | survey_id, question_id; owned deletion and 1..n resequencing |
| POST | `/staff/question/move` | survey_id, qid, move: up/down; adjacent swap and boundary no-op |

Question messages are exactly `Question added.`, `Question updated.`, `Question deleted.` and `Question order updated.` Both inline success and the original Success/Done dialog are retained. The original metadata/question validation messages and precedence are retained. Malformed nested transport, unsupported type/status values and foreign choice IDs are rejected at the API boundary rather than leaking database errors. A failure during question/choice insertion or update rolls back the whole edit.

## Preserved behavior and explicit additions

- PHP actually has **four** question types: Multiple Choice, Yes/No, Rating Scale (1-5), Short Answer. All four remain available and are tested.
- Creation validates survey metadata and requires one nonblank question, but does not impose question-management's meaningful-text/five-character/two-filled-choice rules. Blank builder questions and blank choices are ignored as in PHP. The add dialog and question editor retain their stricter rules.
- Choice fields appear only for Multiple Choice. Add Choice adds one row. Remove clears a box when only two remain; otherwise it removes the row. Existing IDs stay attached to text edits. Removed existing rows carry an explicit blank value to the server, so Remove persists as intended; the legacy JS dropping an existing input would otherwise omit its ID and leave it stored. Switching away from Multiple Choice clears stored choices.
- The local preview, field labels/hints/limits/default dates, unsaved-change warning, Cancel, arrows, Back to Survey List and Manage Questions flows use source markup/classes and wording. Existing edit fields and failed dialog entries remain available for retry. Source-specific create/edit limits remain different.
- The requested **All / Active / Inactive** filters are an explicit addition. They combine with the original trimmed, case-insensitive title/date/status search. No pagination/sorting was introduced. Mutation anchors become buttons with the same icons and confirmation messages, using POST transport.
- Creation and toggling have no invented success message. Survey deletion returns the exact source flash text, but the list still does not display the legacy unconsumed success flash. Question feedback and edit-survey feedback display as in their PHP pages. API failures remain visible for retry.
- Existing PHP says deleting a question or clearing a choice removes related answers, but the source handler only deletes question/choice rows and the existing schema only links `survey_results` to `responses`. **Raw answer rows therefore remain after individual question/choice deletion**, as in PHP. This migration preserves that existing behavior and warning text; it does not add schema migrations or reinterpret answer history. Deleting the entire survey does cascade through questions, choices, responses and results. Fixing individual answer cleanup needs a separate authorized business-rule change.

## Verification

- `docs/tests/staff-surveys-api.php`: **183 assertions passed** against live XAMPP CRUD, all four types, exact validation/success text, choice ID/answer retention, blank choice removal, type changes, cross-survey isolation, order boundaries/resequencing, rollback, roles/onboarding, response counts, cascade deletion and schema integrity. Only synthetic accounts/surveys are created. `--keep` retains fixtures for UI testing; default cleans them.
- `docs/tests/staff-surveys.test.mjs`: **eight passing integration tests** against the live API using React StrictMode and a data router. Includes source-rendered labels/attributes/table parity, builder/preview, two-box minimum, dirty cancellation, combined search/filters, confirmation cancellation, complete four-type creation, metadata save/error, question add/edit/move/delete, explicit removed-choice payloads, server failure/retry, survey deletion and missing-ID redirects.
- `docs/tests/staff-surveys-reference.php`: CLI-only unchanged PHP GET renderer; never exposes application credentials or executes mutation query parameters. `staff-surveys-fixtures.php` creates/cleans only disposable records, including associated login audit rows.
- Browser: saved four-type survey, preview, list search, persisted reorder with `Question order updated.`, and choice text update with `Question updated.`; the existing input was `existing_choices[96]`. Browser console warning/error logs were empty at completion.
- `docs/output/staff-surveys/measurements.json`: create form and question table/card/footer match PHP exactly at **1440×900** and **390×844**, including full page heights. Screenshots and source-rendered HTML are alongside the measurements. Existing narrow phone table overflow matches PHP.
- Existing authentication API regression suite: 266 assertions. Shared UI/auth/landing React regression suite: 10 tests. Build, lint, PHP syntax and protected-file/asset integrity checks are recorded in the progress log.

Run from the project root with XAMPP Apache/MySQL available:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/staff-surveys-api.php --keep
node --test docs/tests/staff-surveys.test.mjs
/Applications/XAMPP/xamppfiles/bin/php docs/tests/staff-surveys-fixtures.php clean
npm --prefix frontend run build
npm --prefix frontend run lint
```

No deployment was performed. Results/report/resident-management and other remaining staff placeholders retain their existing destinations for later steps.
