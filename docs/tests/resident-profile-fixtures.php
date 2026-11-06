<?php
/** CLI-only synthetic profile accounts; never changes an existing resident. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/resident-dashboard-fixtures.php';
function profile_fixture_file(): string { return sys_get_temp_dir() . '/barangay-profile-fixtures.json'; }
function profile_remove_fixture_photo(array $row): void {
    // Apache owns uploaded files. Ask the normal, session-scoped handler to
    // remove this fixture's image before deleting the fixture account.
    $jar = tempnam(sys_get_temp_dir(), 'profile-photo-clean-'); chmod($jar, 0600);
    try {
        foreach ([['login', ['identifier' => $row['number'], 'password' => 'ProfileFixtureA1']], ['resident/photo/remove', new stdClass()]] as [$route, $body]) {
            $p = proc_open(['curl', '-sS', '--max-time', '15', '-b', $jar, '-c', $jar, '-H', 'Content-Type: application/json', '--data-binary', '@-',
                'http://localhost/barangay_survey_system/api/' . $route], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            fwrite($pipes[0], json_encode($body)); fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
            if ($exit !== 0 || !(json_decode($out, true)['ok'] ?? false)) { throw new RuntimeException('Fixture photo cleanup failed; account retained for retry.'); }
        }
    } finally { unlink($jar); }
}
function profile_fixtures_create(mysqli $conn): array {
    if (is_file(profile_fixture_file())) { throw new RuntimeException('Clean previous profile fixtures first.'); }
    $state = ['residents' => [], 'staff' => null];
    $save = function () use (&$state): void { file_put_contents(profile_fixture_file(), json_encode($state)); chmod(profile_fixture_file(), 0600); };
    $suffix = random_int(1000000, 9999999); $hash = password_hash('ProfileFixtureA1', PASSWORD_DEFAULT);
    foreach (['temporary', 'permanent', 'empty'] as $type) {
        $number = 'R-2096-' . $suffix++;
        $stmt = dashboard_sql($conn, "INSERT INTO residents (resident_number, first_name, middle_name, last_name, name_extension, courtesy_title, gender, birthdate, password, is_first_login, is_active, residency_status, residence_type, household_number, purok, street_highway, barangay, municipality, postal_code, province, address, temporary_address, temp_household_number, temp_barangay, temp_municipality, temp_province, is_married, has_children, spouse_first_name, spouse_last_name, spouse_courtesy_title, occupation, hotline_number) VALUES (?, 'Profile', 'Source', 'Fixture', 'III', 'Dr.', 'non_binary', ?, ?, 0, 1, ?, ?, '12', 'Purok 3', 'Test Street', 'Test Barangay', 'Test City', '3010', 'Test Province', '12, Purok 3, Test Street, Test Barangay, Test City, 3010, Test Province', '34, Temporary Barangay, Temporary City, Temporary Province', '34', 'Temporary Barangay', 'Temporary City', 'Temporary Province', ?, ?, 'Spouse', 'Fixture', 'Engr.', 'Teacher', '(02) 8123-4567')",
            [$number, $type === 'empty' ? null : '1990-02-10', $hash, $type === 'temporary' ? 'temporary' : 'permanent', $type === 'temporary' ? 'BH' : '', $type === 'empty' ? 0 : 1, $type === 'empty' ? 0 : 1]);
        $state['residents'][$type] = ['id' => $stmt->insert_id, 'number' => $number]; $stmt->close(); $save();
        if ($type !== 'empty') {
            foreach (['resident_children', 'resident_character_references'] as $table) {
                foreach ([0, 1] as $order) { dashboard_sql($conn, "INSERT INTO $table (resident_id, courtesy_title, first_name, last_name, gender, display_order) VALUES (?, 'Atty.', 'Synthetic', 'Person', 'woman', ?)", [$state['residents'][$type]['id'], $order])->close(); }
            }
        }
    }
    $number = 'S-2096-' . $suffix;
    $stmt = dashboard_sql($conn, "INSERT INTO staff (staff_number, username, full_name, password, role, is_first_login, profile_completed, is_active) VALUES (?, ?, 'Profile Fixture', ?, 'staff', 0, 1, 1)", [$number, $number, $hash]);
    $state['staff'] = ['id' => $stmt->insert_id, 'number' => $number]; $stmt->close(); $save();
    return $state;
}
function profile_fixtures_clean(mysqli $conn, array $state): void {
    foreach ($state['residents'] as $row) {
        $photo = dashboard_sql($conn, 'SELECT photo_path FROM residents WHERE resident_id = ?', [$row['id']])->get_result()->fetch_assoc()['photo_path'] ?? '';
        // Delete only a file generated for this exact disposable fixture.
        if (preg_match('~^uploads/residents/resident_' . $row['id'] . '_[0-9]+_[a-f0-9]+\.(jpg|jpeg|png|gif|webp)$~', $photo)) {
            $path = dirname(__DIR__, 2) . '/' . $photo;
            if (is_file($path)) { profile_remove_fixture_photo($row); }
            if (is_file($path)) { throw new RuntimeException('Fixture photo still exists; account retained for retry.'); }
        }
        dashboard_sql($conn, "DELETE FROM login_history WHERE user_type = 'resident' AND user_id = ?", [$row['id']])->close();
        dashboard_sql($conn, 'DELETE FROM residents WHERE resident_id = ?', [$row['id']])->close();
    }
    if ($state['staff']) {
        dashboard_sql($conn, "DELETE FROM login_history WHERE user_type = 'staff' AND user_id = ?", [$state['staff']['id']])->close();
        dashboard_sql($conn, 'DELETE FROM staff WHERE staff_id = ?', [$state['staff']['id']])->close();
    }
    if (is_file(profile_fixture_file())) { unlink(profile_fixture_file()); }
}
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    session_save_path(sys_get_temp_dir());
    require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
    try {
        if (($argv[1] ?? '') === 'create') { profile_fixtures_create($conn); echo "Created profile fixtures.\n"; }
        elseif (($argv[1] ?? '') === 'clean') { profile_fixtures_clean($conn, json_decode(file_get_contents(profile_fixture_file()), true)); echo "Removed profile fixtures, proposals, photo and history.\n"; }
        else { throw new RuntimeException('Use create or clean.'); }
    } finally { session_unset(); session_destroy(); }
}
