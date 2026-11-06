<?php
/** CLI-only fixtures. All rows are disposable; no real credentials are used. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/resident-dashboard-fixtures.php';
function sm_file(): string { return sys_get_temp_dir() . '/barangay-survey-management-fixtures.json'; }
function sm_save(array $state): void { file_put_contents(sm_file(), json_encode($state)); chmod(sm_file(), 0600); }
function sm_create(mysqli $conn): array {
    if (is_file(sm_file())) throw new RuntimeException('Clean previous survey-management fixtures first.');
    $state = ['staff' => null, 'admin' => null, 'resident' => null, 'surveys' => []]; sm_save($state);
    $hash = password_hash('SurveyFixtureA1', PASSWORD_DEFAULT);
    foreach (['staff', 'admin'] as $role) {
        $number = 'S-2096-' . random_int(1000000, 9999999);
        $stmt = dashboard_sql($conn, "INSERT INTO staff (staff_number, username, full_name, first_name, password, role, is_first_login, profile_completed, is_active) VALUES (?, ?, 'Survey Management Fixture', 'Survey', ?, ?, 0, 1, 1)", [$number, $number, $hash, $role]);
        $state[$role] = ['id' => $stmt->insert_id, 'number' => $number]; $stmt->close(); sm_save($state);
    }
    $number = 'R-2096-' . random_int(1000000, 9999999);
    $stmt = dashboard_sql($conn, "INSERT INTO residents (resident_number, first_name, last_name, password, residency_status, is_first_login, is_active) VALUES (?, 'Survey', 'Fixture', ?, 'permanent', 0, 1)", [$number, $hash]);
    $state['resident'] = ['id' => $stmt->insert_id, 'number' => $number]; $stmt->close(); sm_save($state);
    return $state;
}
function sm_clean(mysqli $conn, array $state): void {
    // All API/UI surveys carry this fixture's created_by; cleanup also covers interrupted tests.
    foreach (['staff', 'admin'] as $role) if (!empty($state[$role])) {
        dashboard_sql($conn, 'DELETE FROM surveys WHERE created_by = ?', [$state[$role]['id']])->close();
        dashboard_sql($conn, "DELETE FROM login_history WHERE user_type = 'staff' AND user_id = ?", [$state[$role]['id']])->close();
        dashboard_sql($conn, 'DELETE FROM staff WHERE staff_id = ?', [$state[$role]['id']])->close();
    }
    if (!empty($state['resident'])) {
        dashboard_sql($conn, "DELETE FROM login_history WHERE user_type = 'resident' AND user_id = ?", [$state['resident']['id']])->close();
        dashboard_sql($conn, 'DELETE FROM residents WHERE resident_id = ?', [$state['resident']['id']])->close();
    }
    if (is_file(sm_file())) unlink(sm_file());
}
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    session_save_path(sys_get_temp_dir());
    require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
    try {
        if (($argv[1] ?? '') === 'clean') { sm_clean($conn, json_decode(file_get_contents(sm_file()), true)); echo "Cleaned survey management fixtures.\n"; }
        else { sm_create($conn); echo "Created disposable survey management fixtures.\n"; }
    } finally { session_unset(); session_destroy(); }
}
