<?php
/** CLI-only synthetic rows for the dashboard parity checks. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
function dashboard_sql(mysqli $conn, string $query, array $values = []): mysqli_stmt {
    $stmt = $conn->prepare($query);
    if ($values) { $stmt->bind_param(str_repeat('s', count($values)), ...$values); }
    $stmt->execute();
    return $stmt;
}
function dashboard_fixture_file(): string { return sys_get_temp_dir() . '/barangay-dashboard-fixtures.json'; }
function dashboard_fixtures_create(mysqli $conn): array {
    if (is_file(dashboard_fixture_file())) { throw new RuntimeException('Clean the previous dashboard fixtures first.'); }
    $state = ['residents' => [], 'surveys' => [], 'staff' => []];
    $save = function () use (&$state): void {
        file_put_contents(dashboard_fixture_file(), json_encode($state));
        chmod(dashboard_fixture_file(), 0600);
    };
    $suffix = random_int(1000000, 9999999);
    // Public test-only password; no real credentials are used or returned.
    $hash = password_hash('DashboardFixtureA1', PASSWORD_DEFAULT);
    foreach (['temporary', 'permanent', 'empty'] as $type) {
        $number = 'R-2098-' . $suffix++;
        $stmt = dashboard_sql($conn, "INSERT INTO residents (resident_number, first_name, last_name, password, residency_status, residence_type, is_first_login, is_active, is_married, address, temporary_address, household_number, barangay, municipality, province, temp_barangay, temp_municipality, temp_province, residency_start_date) VALUES (?, 'Dashboard', 'Fixture', ?, ?, ?, 0, 1, ?, '12 Test Street', '34 Test Lane', 'HH-TEST', 'Test Barangay', 'Test City', 'Test Province', 'Temporary Barangay', 'Temporary City', 'Temporary Province', ?)", [$number, $hash, $type === 'temporary' ? 'temporary' : 'permanent', $type === 'temporary' ? 'BH' : '', $type === 'empty' ? 0 : 1, $type === 'empty' ? null : date('Y-m-d', strtotime($type === 'temporary' ? '-3 months' : '-20 months'))]);
        $state['residents'][$type] = ['id' => $stmt->insert_id, 'number' => $number];
        $stmt->close(); $save();
        if ($type !== 'empty') {
            foreach (['resident_children' => 2, 'resident_character_references' => 3] as $table => $count) {
                for ($i = 0; $i < $count; $i++) {
                    dashboard_sql($conn, "INSERT INTO $table (resident_id, first_name, last_name, display_order) VALUES (?, 'Synthetic', 'Person', ?)", [$state['residents'][$type]['id'], $i])->close();
                }
            }
        }
    }
    $number = 'S-2098-' . $suffix++;
    $stmt = dashboard_sql($conn, "INSERT INTO staff (staff_number, username, full_name, first_name, password, role, is_first_login, profile_completed, is_active) VALUES (?, ?, 'Dashboard Fixture', 'Dashboard', ?, 'staff', 0, 1, 1)", [$number, $number, $hash]);
    $state['staff'] = ['id' => $stmt->insert_id, 'number' => $number]; $stmt->close(); $save();
    // Seven available rows exercise LIMIT 5; distinct deadlines avoid tie assumptions.
    // Additional answered, inactive, future and expired surveys exercise each predicate.
    foreach (range(0, 17) as $i) {
        $status = $i === 16 ? 'inactive' : 'active';
        $start = date('Y-m-d', strtotime($i === 17 ? '+10 days' : '-1 year'));
        $end = date('Y-m-d', strtotime($i >= 9 && $i <= 15 ? '-1 day' : "+$i days"));
        $stmt = dashboard_sql($conn, 'INSERT INTO surveys (title, description, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)', ["Dashboard Fixture $i", $i === 0 ? '0' : 'Synthetic dashboard verification', $start, $end, $status]);
        $state['surveys'][$i] = $stmt->insert_id; $stmt->close(); $save();
    }
    foreach (['temporary', 'permanent'] as $type) {
        // Two open answered + seven historical responses, including a cutoff-day boundary.
        foreach (range(7, 15) as $i) {
            $submitted = date('Y-m-d H:i:s', strtotime(['-1 day', '-2 days', '-1 month', '-2 months', '-3 months', '-4 months', '-5 months -1 day', '-5 months', '-8 months'][$i - 7]));
            dashboard_sql($conn, 'INSERT INTO responses (resident_id, survey_id, submitted_at) VALUES (?, ?, ?)', [$state['residents'][$type]['id'], $state['surveys'][$i], $submitted])->close();
        }
    }
    return $state;
}
function dashboard_fixtures_clean(mysqli $conn, array $state): void {
    foreach ($state['surveys'] as $id) { dashboard_sql($conn, 'DELETE FROM surveys WHERE survey_id = ?', [$id])->close(); }
    foreach ($state['residents'] as $row) {
        dashboard_sql($conn, "DELETE FROM login_history WHERE user_type = 'resident' AND user_id = ?", [$row['id']])->close();
        dashboard_sql($conn, 'DELETE FROM residents WHERE resident_id = ?', [$row['id']])->close();
    }
    if ($state['staff']) {
        dashboard_sql($conn, "DELETE FROM login_history WHERE user_type = 'staff' AND user_id = ?", [$state['staff']['id']])->close();
        dashboard_sql($conn, 'DELETE FROM staff WHERE staff_id = ?', [$state['staff']['id']])->close();
    }
    if (is_file(dashboard_fixture_file())) { unlink(dashboard_fixture_file()); }
}
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    session_save_path(sys_get_temp_dir());
    require dirname(__DIR__, 2) . '/config/db.php';
    require dirname(__DIR__, 2) . '/config/functions.php';
    $action = $argv[1] ?? '';
    try {
        if ($action === 'create') {
            $state = dashboard_fixtures_create($conn);
            foreach ($state['residents'] as $type => $row) { echo "$type: {$row['number']}\n"; }
        } elseif ($action === 'clean') {
            $state = json_decode(file_get_contents(dashboard_fixture_file()), true, 512, JSON_THROW_ON_ERROR);
            dashboard_fixtures_clean($conn, $state);
            echo "Removed dashboard fixtures and their login history.\n";
        } else { throw new RuntimeException('Use create or clean.'); }
    } catch (Throwable $error) {
        fwrite(STDERR, $error instanceof mysqli_sql_exception ? "Dashboard fixture database operation failed.\n" : $error->getMessage() . "\n"); exit(1);
    } finally { session_unset(); session_destroy(); }
}
