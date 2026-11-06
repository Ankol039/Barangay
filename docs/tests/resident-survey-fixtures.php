<?php
/** CLI-only disposable survey fixtures. Never alters existing accounts or surveys. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/resident-dashboard-fixtures.php';
function survey_fixture_file(): string { return sys_get_temp_dir() . '/barangay-survey-fixtures.json'; }
function survey_fixtures_create(mysqli $conn): array {
    if (is_file(survey_fixture_file())) { throw new RuntimeException('Clean prior survey fixtures first.'); }
    $state = ['residents' => [], 'staff' => [], 'surveys' => [], 'questions' => [], 'choices' => []];
    $save = function () use (&$state): void {
        file_put_contents(survey_fixture_file(), json_encode($state)); chmod(survey_fixture_file(), 0600);
    };
    $suffix = random_int(1000000, 9999999);
    $hash = password_hash('SurveyFixtureA1', PASSWORD_DEFAULT);
    foreach (['main', 'other'] as $type) {
        $number = 'R-2097-' . $suffix++;
        $stmt = dashboard_sql($conn, "INSERT INTO residents (resident_number, first_name, last_name, password, is_first_login, is_active) VALUES (?, 'Survey', 'Fixture', ?, 0, 1)", [$number, $hash]);
        $state['residents'][$type] = ['id' => $stmt->insert_id, 'number' => $number]; $stmt->close(); $save();
    }
    $number = 'S-2097-' . $suffix++;
    $stmt = dashboard_sql($conn, "INSERT INTO staff (staff_number, username, full_name, password, role, is_first_login, profile_completed, is_active) VALUES (?, ?, 'Survey Fixture', ?, 'staff', 0, 1, 1)", [$number, $number, $hash]);
    $state['staff'] = ['id' => $stmt->insert_id, 'number' => $number]; $stmt->close(); $save();
    foreach (['main', 'today', 'expired', 'future', 'inactive', 'text', 'race', 'rollback', 'browser', 'noquestions'] as $i => $type) {
        $stmt = dashboard_sql($conn, 'INSERT INTO surveys (title, description, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)',
            ['Survey Fixture ' . $type, "Synthetic survey, with \"quotes\"\nSecond line " . str_repeat('説明', 50),
             date('Y-m-d', strtotime($type === 'future' ? '+1 day' : ($type === 'today' ? 'today' : '-2 days'))),
             date('Y-m-d', strtotime($type === 'expired' ? '-1 day' : ($type === 'today' ? 'today' : '+7 days'))),
             $type === 'inactive' ? 'inactive' : 'active']);
        $id = $stmt->insert_id; $stmt->close(); $state['surveys'][$type] = $id; $save();
        $state['questions'][$type] = [];
        foreach ($type === 'noquestions' ? [] : ($type === 'text' || $type === 'rollback' ? ['short_answer'] : ['multiple_choice', 'yesno', 'rating', 'short_answer', 'short_answer']) as $order => $question_type) {
            $stmt = dashboard_sql($conn, 'INSERT INTO survey_questions (survey_id, question_text, question_type, question_order) VALUES (?, ?, ?, ?)',
                [$id, ['Choose your service', 'Was the service helpful?', 'Rate the service', 'Any other comments?', 'Optional comments?'][$order], $question_type, $order]);
            $qid = $stmt->insert_id; $stmt->close(); $state['questions'][$type][] = $qid; $save();
            if ($question_type === 'multiple_choice') {
                foreach (['Consultation', 'Immunization'] as $choice) {
                    $stmt = dashboard_sql($conn, 'INSERT INTO survey_choices (question_id, choice_text) VALUES (?, ?)', [$qid, $choice]);
                    $state['choices'][$type][] = $stmt->insert_id; $stmt->close(); $save();
                }
            }
        }
    }
    return $state;
}
function survey_fixtures_clean(mysqli $conn, array $state): void {
    foreach ($state['surveys'] as $id) { dashboard_sql($conn, 'DELETE FROM surveys WHERE survey_id = ?', [$id])->close(); }
    foreach ($state['residents'] as $row) {
        dashboard_sql($conn, "DELETE FROM login_history WHERE user_type = 'resident' AND user_id = ?", [$row['id']])->close();
        dashboard_sql($conn, 'DELETE FROM residents WHERE resident_id = ?', [$row['id']])->close();
    }
    if ($state['staff']) {
        dashboard_sql($conn, "DELETE FROM login_history WHERE user_type = 'staff' AND user_id = ?", [$state['staff']['id']])->close();
        dashboard_sql($conn, 'DELETE FROM staff WHERE staff_id = ?', [$state['staff']['id']])->close();
    }
    if (is_file(survey_fixture_file())) { unlink(survey_fixture_file()); }
}
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    session_save_path(sys_get_temp_dir());
    require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
    try {
        if (($argv[1] ?? '') === 'create') { survey_fixtures_create($conn); echo "Created survey fixtures.\n"; }
        elseif (($argv[1] ?? '') === 'clean') { survey_fixtures_clean($conn, json_decode(file_get_contents(survey_fixture_file()), true)); echo "Removed survey fixtures and login history.\n"; }
        else { throw new RuntimeException('Use create or clean.'); }
    } catch (Throwable $e) { fwrite(STDERR, 'Fixture operation failed (' . get_class($e) . ").\n"); exit(1); }
    finally { session_unset(); session_destroy(); }
}
