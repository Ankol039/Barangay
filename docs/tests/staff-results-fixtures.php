<?php
/** CLI-only synthetic surveys for source/API/React parity. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/staff-surveys-fixtures.php';
function results_file(): string { return sys_get_temp_dir() . '/barangay-staff-results-fixtures.json'; }
function results_save(array $state): void { file_put_contents(results_file(), json_encode($state)); chmod(results_file(), 0600); }
function results_create(mysqli $conn): array {
    if (is_file(results_file())) throw new RuntimeException('Clean previous results fixtures first.');
    $state = sm_create($conn); $state['extra_residents'] = []; results_save($state);
    foreach ([1, 2] as $i) {
        $number = 'R-2096-' . random_int(1000000, 9999999);
        $stmt = dashboard_sql($conn, "INSERT INTO residents (resident_number, first_name, last_name, password, residency_status, is_first_login, is_active) VALUES (?, 'Results', 'Fixture', ?, 'permanent', 0, 1)", [$number, password_hash('SurveyFixtureA1', PASSWORD_DEFAULT)]);
        $state['extra_residents'][] = $stmt->insert_id; $stmt->close(); results_save($state);
    }
    foreach (['populated', 'empty'] as $kind) {
        $stmt = dashboard_sql($conn, "INSERT INTO surveys (title, description, start_date, end_date, status, created_by) VALUES (?, 'Synthetic results verification.', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'active', ?)", ["Results Fixture $kind, café <test>", $state['staff']['id']]);
        $id = $stmt->insert_id; $stmt->close(); $state[$kind] = $id; $state['surveys'][] = $id; results_save($state);
        $qs = [];
        foreach (['multiple_choice', 'yesno', 'rating', 'short_answer'] as $i => $type) {
            $stmt = dashboard_sql($conn, 'INSERT INTO survey_questions (survey_id, question_text, question_type, question_order) VALUES (?, ?, ?, ?)', [$id, ['Choose your service', 'Was the visit helpful?', 'Rate the service', 'Share your feedback'][$i], $type, $i + 1]);
            $qs[$type] = $stmt->insert_id; $stmt->close();
        }
        $choices = [];
        foreach (['Consultation', 'Vaccination', 'Unused option'] as $label) {
            $stmt = dashboard_sql($conn, 'INSERT INTO survey_choices (question_id, choice_text) VALUES (?, ?)', [$qs['multiple_choice'], $label]);
            $choices[] = $stmt->insert_id; $stmt->close();
        }
        if ($kind === 'empty') continue;
        $state['questions'] = $qs; results_save($state);
        foreach ([$state['resident']['id'], ...$state['extra_residents']] as $i => $resident) {
            $stmt = dashboard_sql($conn, 'INSERT INTO responses (resident_id, survey_id, submitted_at) VALUES (?, ?, ?)', [$resident, $id, date('Y-m-d H:i:s', strtotime("-$i days"))]);
            $response = $stmt->insert_id; $stmt->close();
            if ($i < 2) {
                dashboard_sql($conn, 'INSERT INTO survey_results (response_id, question_id, choice_id) VALUES (?, ?, ?)', [$response, $qs['multiple_choice'], $choices[$i]])->close();
                dashboard_sql($conn, "INSERT INTO survey_results (response_id, question_id, answer_text) VALUES (?, ?, 'Yes')", [$response, $qs['yesno']])->close();
            }
            dashboard_sql($conn, 'INSERT INTO survey_results (response_id, question_id, rating_value) VALUES (?, ?, ?)', [$response, $qs['rating'], $i === 0 ? 1 : 5])->close();
            if ($i === 0) {
                // Legacy schema permits multiple text rows; exercise the exact 200-row cap.
                for ($n = 0; $n < 201; $n++) dashboard_sql($conn, 'INSERT INTO survey_results (response_id, question_id, answer_text) VALUES (?, ?, ?)', [$response, $qs['short_answer'], $n === 200 ? 'Need more nurses, café "quoted" <script>alert(1)</script>' : sprintf('Feedback answer %03d', $n)])->close();
                foreach (['', null] as $blank) dashboard_sql($conn, 'INSERT INTO survey_results (response_id, question_id, answer_text) VALUES (?, ?, ?)', [$response, $qs['short_answer'], $blank])->close();
            }
        }
    }
    // Source count queries must continue including historical/archived respondents.
    dashboard_sql($conn, 'UPDATE residents SET deleted_at = NOW() WHERE resident_id = ?', [$state['extra_residents'][1]])->close();
    results_save($state); return $state;
}
function results_clean(mysqli $conn, array $state): void {
    sm_clean($conn, $state);
    foreach ($state['extra_residents'] ?? [] as $id) dashboard_sql($conn, 'DELETE FROM residents WHERE resident_id = ?', [$id])->close();
    if (is_file(results_file())) unlink(results_file());
}
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    session_save_path(sys_get_temp_dir());
    require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
    try {
        if (($argv[1] ?? '') === 'clean') { results_clean($conn, json_decode(file_get_contents(results_file()), true)); echo "Cleaned results fixtures.\n"; }
        else { results_create($conn); echo "Created results fixtures.\n"; }
    } finally { session_unset(); session_destroy(); }
}
