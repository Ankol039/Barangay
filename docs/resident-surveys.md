# Resident survey migration — Part 2, step 7

Implemented on 2026-11-05 from `resident/surveys.php`, `survey_form.php`, `submit.php`, `history.php`, `view_response.php`, `export_response.php`, and the answering handler in `assets/js/script.js`. Original files, shared CSS/logo and database schema are preserved.

## Routes and API

React routes: `/resident/surveys`, `/resident/survey-form?id=…`, `/resident/history`, `/resident/view-response?id=…`. Presence of `print` on the response route prints after response data renders; the Print Response button also calls `window.print()`. The original print CSS hides navigation and report actions.

All API paths below are relative to `/barangay_survey_system/api/`. They require the existing resident session, reject staff/anonymous callers, and use `{ok:true,data:…}` JSON except for CSV. Identity always comes from the session.

| Method | Path | Result |
| --- | --- | --- |
| GET | `resident/surveys?submitted=…` | `open`, `answered`, optional submitted title. Original inclusive CURDATE window, active status, start-date ordering, 80-column description truncation and formatted dates. |
| GET | `resident/survey-form?id=…` | Survey metadata and questions ordered by question order/id, with original choices. Closed/missing/already answered returns `redirect: /resident/surveys`. |
| POST | `resident/survey-submissions` | Original transaction and redirect destinations. Accepts JSON or form encoding with string `survey_id` and dynamic string `q<question_id>` fields. |
| GET | `resident/history` | Own responses, newest first, source date labels and 90-column description truncation. |
| GET | `resident/response?id=…` | Own response metadata and every current survey question with typed/missing answer display. Missing/not owned returns history destination. |
| GET | `resident/response-export?id=…` | Original UTF-8 CSV attachment, `survey-response-<response_id>.csv`. Missing/not owned is 404 with `Response not found.` |

Submission values remain `choice_<choice_id>`, `Yes`/`No`, string ratings 1–5, or text. The server reads only known question fields and preserves the original normalization: trim text, skip empty answers and invalid Yes/No or rating values. It requires at least one usable answer; every radio group is required only by browser validation, exactly as in PHP/shared JS. The original multiple-choice parsing behavior is retained, including its lack of per-question choice membership validation. No schema or business-rule changes were introduced.

Empty submissions return the form's `error=empty`; answer-insert failures roll back the response and return `error=failed`. Existing responses and unavailable surveys return to the list. MySQL 1062 rolls back and quietly returns to the list, retaining the winning response. No raw SQL/error details enter JSON.

## React structure and one-time confirmation

`Surveys`, `SurveyForm`, `History` and `ViewResponse` compose existing shared page/card/alert/icon/modal components. `SurveyListPanel` shares both list tables, `SubmissionSuccessModal` owns the confirmation markup, and **one `QuestionField`** selects multiple choice, Yes/No, rating or optional open text by `question_type`. Original labels, classes, required-group names, client alert text, missing highlights, option values and cancel flow are retained.

The confirmation removes the success query using the source's native `history.replaceState`, preserving React Router's history metadata. Router navigation would trigger the existing fresh-session guard and unmount the confirmation. Native address-bar cleanup keeps the modal visible, clears its title/content on dismissal, and prevents refresh from reopening it. The integration test includes `AuthProvider` and `ProtectedRoute` to cover this interaction. No authentication guard was weakened or modified.

CSV uses an ordinary attachment link with the environment-aware API URL. Response ownership is checked before querying answers or exporting; caller-supplied resident IDs have no effect. Standalone legacy `confirmation.php` remains outside this step's requested six-file conversion.

## Verification

- `npm run build` and `npm run lint` pass without warnings. New PHP files pass XAMPP PHP syntax checks.
- **87 live API assertions**: session/role isolation, open-window boundaries, question/choice ordering, empty and invalid-only rejection, successful typed-answer storage, history, missing-answer display, duplicate refusal, answered-list movement, cross-resident read/export denial, byte-identical CSV against the unchanged original exporter, partial-answer server policy, forced answer-insert rollback, and actual duplicate-key 1062 handling.
- The 1062 test uses a CLI-only worker that loads the real API/bootstrap before another connection holds an uncommitted winning response. The test verifies the losing insert waits on that unique key, commits the winner, and checks the API's quiet redirect. Starting the HTTP request while holding that row blocked the legacy bootstrap before the submit handler, so that setup was replaced. No triggers or schema changes are used.
- **Five React integration tests**, all passing, compare source-rendered labels/control names/options/table rows/dates/truncation and exercise required-group blocking, controlled text submission, history, confirmation URL/content cleanup, guarded navigation, print-on-data behavior, print button and export URL. Ten existing landing/auth/shared-UI tests also pass. Existing authentication API suite passes **266 assertions**, with its own fixture/schema cleanup checks.
- Browser: disposable resident answered multiple choice, Yes/No, rating and open text through the complete React app; confirmation stayed visible with a clean URL, dismissal/refresh did not reopen it, and history's View action displayed all saved values plus `No answer` for the skipped optional question. No browser errors/warnings were observed before the download attempt.
- Browser CSV download permission was declined. The browser download was stopped and was not retried or worked around. CSV transport/content had already been verified through the live API test before that denial. A browser-saved file and native print-dialog output were not verified; auto-print/button invocation is covered by the React integration test.
- PHP and React form card/control geometry matches exactly at **1280×720**, including every question, option list, rating group and textarea. Measurements and screenshots are in `docs/output/resident-surveys/`; `submission-confirmed.png` records the successful browser submission.
- SHA-256 checks confirm all **56 protected PHP/config/include/asset/upload/database files** are unchanged; copied CSS/logo remain byte-identical. Existing unrelated working-tree changes were preserved. Disposable survey/account/response/login-history records were removed after verification. No deployment was performed.

To repeat the disposable live checks (XAMPP Apache/MySQL must be running), from the project root:

```sh
/Applications/XAMPP/xamppfiles/bin/php docs/tests/resident-survey-api.php --keep
node --test docs/tests/resident-surveys.test.mjs
/Applications/XAMPP/xamppfiles/bin/php docs/tests/resident-survey-fixtures.php clean
```

Use fresh fixtures for each React run; that suite deliberately consumes the single attempt on its synthetic text survey. Without `--keep`, the API runner cleans its fixtures automatically. All fixture/source-reference/race runners are CLI-only and reject HTTP access.
