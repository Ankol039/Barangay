<?php
/** CLI-only disposable data, reusing the Prompt 7 fixture cleanup. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/resident-dashboard-fixtures.php';
function staff_dashboard_file(): string { return sys_get_temp_dir() . '/barangay-staff-dashboard-fixtures.json'; }
function staff_dashboard_save(array $state): void {
    file_put_contents(staff_dashboard_file(), json_encode($state)); chmod(staff_dashboard_file(), 0600);
}
function staff_dashboard_create(mysqli $conn): array {
    if (is_file(staff_dashboard_file())) { throw new RuntimeException('Clean previous staff dashboard fixtures first.'); }
    $state = ['base' => dashboard_fixtures_create($conn), 'admin' => null];
    staff_dashboard_save($state);
    $number = 'S-2097-' . random_int(1000000, 9999999);
    $stmt = dashboard_sql($conn, "INSERT INTO staff (staff_number, username, full_name, first_name, password, role, is_first_login, profile_completed, is_active) VALUES (?, ?, 'Admin Dashboard Fixture', 'Admin', ?, 'admin', 0, 1, 1)", [$number, $number, password_hash('DashboardFixtureA1', PASSWORD_DEFAULT)]);
    $state['admin'] = ['id' => $stmt->insert_id, 'number' => $number]; $stmt->close(); staff_dashboard_save($state);
    $state['staff'] = $state['base']['staff'];
    $state['residents'] = $state['base']['residents'];
    staff_dashboard_save($state);
    foreach (['temporary' => ['2000-01-01', 'female', 'pregnant', 'Teacher', 'Purok Test A', 1], 'permanent' => ['1950-01-01', 'male', 'senior_citizen', 'teacher', 'Purok Test B', 0], 'empty' => [null, '', 'none', 'N/A', '', 0]] as $kind => $values) {
        dashboard_sql($conn, 'UPDATE residents SET birthdate = ?, gender = ?, special_category = ?, occupation = ?, purok = ?, has_children = ? WHERE resident_id = ?', [...$values, $state['residents'][$kind]['id']])->close();
    }
    foreach (['multiple_choice', 'yesno', 'rating', 'short_answer'] as $i => $type) {
        dashboard_sql($conn, 'INSERT INTO survey_questions (survey_id, question_text, question_type, question_order) VALUES (?, ?, ?, ?)', [$state['base']['surveys'][0], "Dashboard fixture question $i", $type, $i + 1])->close();
    }
    // Captured roles deliberately differ from live records on some events.
    $events = [
        ['staff', $state['admin']['id'], 'Admin'], ['staff', $state['staff']['id'], 'Staff'],
        ['resident', $state['residents']['temporary']['id'], 'Temporary Resident'],
        ['resident', $state['residents']['permanent']['id'], 'Permanent Resident'],
        ['staff', $state['staff']['id'], 'Admin'], ['resident', $state['residents']['temporary']['id'], 'Permanent Resident'],
        ['staff', $state['admin']['id'], ''], ['resident', $state['residents']['permanent']['id'], ''],
    ];
    foreach (['login', 'logout'] as $event) {
        foreach ($events as $i => [$type, $id, $role]) {
            dashboard_sql($conn, 'INSERT INTO login_history (user_type, user_id, account_role, event, login_time) VALUES (?, ?, ?, ?, ?)', [$type, $id, $role, $event, date('Y-m-d H:i:s', strtotime("-" . ($i + 1) . ' minutes'))])->close();
        }
    }
    return $state;
}
function staff_dashboard_clean(mysqli $conn, array $state): void {
    if (!empty($state['admin'])) {
        dashboard_sql($conn, "DELETE FROM login_history WHERE user_type = 'staff' AND user_id = ?", [$state['admin']['id']])->close();
        dashboard_sql($conn, 'DELETE FROM staff WHERE staff_id = ?', [$state['admin']['id']])->close();
    }
    dashboard_fixtures_clean($conn, $state['base']);
    if (is_file(staff_dashboard_file())) unlink(staff_dashboard_file());
}
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    session_save_path(sys_get_temp_dir());
    require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
    try {
        if (($argv[1] ?? '') === 'clean') {
            staff_dashboard_clean($conn, json_decode(file_get_contents(staff_dashboard_file()), true, 512, JSON_THROW_ON_ERROR));
            echo "Removed disposable staff dashboard data and audit history.\n";
        } else { throw new RuntimeException('Use clean; create through staff-dashboard-api.php.'); }
    } finally { session_unset(); session_destroy(); }
}
