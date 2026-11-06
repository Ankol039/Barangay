<?php
/** Live source parity and authorization for both staff endpoints. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/staff-dashboard-fixtures.php';
$temp = sys_get_temp_dir() . '/barangay-staff-dashboard-check-' . bin2hex(random_bytes(6));
mkdir($temp, 0700); session_save_path($temp);
require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
$checks = 0; $failed = false; $state = null;
function sd_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function sd_process(array $args, string $input = ''): string {
    $process = proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)) throw new RuntimeException('Subprocess failed: ' . $err);
    return $out;
}
function sd_request(string $path, string $jar = 'staff', string $method = 'GET', ?array $body = null): array {
    global $temp;
    $args = ['curl', '-sS', '--max-time', '15', '-X', $method, '-b', "$temp/$jar.cookies", '-c', "$temp/$jar.cookies", '-w', "\n%{http_code}", 'http://localhost/barangay_survey_system/api/' . $path];
    if ($body !== null) array_push($args, '-H', 'Content-Type: application/json', '--data-binary', '@-');
    $out = sd_process($args, $body === null ? '' : json_encode($body));
    $split = strrpos($out, "\n"); $raw = substr($out, 0, $split);
    sd_check(!str_contains($raw, '$2y$') && !preg_match('/"password"\s*:/', $raw), 'No password/hash exposure');
    return [(int)substr($out, $split + 1), json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}
function sd_reference(string $mode, int $id, string $query = ''): array {
    return json_decode(sd_process([PHP_BINARY, __DIR__ . '/staff-dashboard-reference.php', $mode, (string)$id, 'json', $query]), true, 512, JSON_THROW_ON_ERROR);
}
function sd_login(array $row, string $jar): void {
    sd_check(sd_request('login', $jar, 'POST', ['identifier' => $row['number'], 'password' => 'DashboardFixtureA1'])[0] === 200, 'Fixture login');
}
try {
    $state = staff_dashboard_create($conn);
    foreach (['staff/dashboard', 'staff/stats'] as $path) {
        sd_check(sd_request($path, 'anonymous')[0] === 401, 'Anonymous denied');
        sd_check(sd_request($path, 'anonymous', 'POST')[0] === 405, 'Method denied');
    }
    sd_login($state['residents']['permanent'], 'resident');
    foreach (['staff/dashboard', 'staff/stats'] as $path) sd_check(sd_request($path, 'resident')[0] === 403, 'Resident denied');
    foreach (['staff', 'admin'] as $role) {
        sd_login($state[$role], $role);
        [$status, $result] = sd_request('staff/dashboard?role=admin&staff_id=' . $state['admin']['id'], $role);
        sd_check($status === 200, 'Dashboard status');
        $data = $result['data'];
        foreach (sd_reference('dashboard', $state[$role]['id']) as $key => $value) sd_check($data[$key] == $value, "$role matches source: $key");
        sd_check($data['is_admin_view'] === ($role === 'admin'), 'Role comes from session/account');
        sd_check(array_key_exists('admin', $data) === ($role === 'admin'), 'Privileged fields omitted for staff');
        sd_check(count($data['completion_rates']) === 6 && count($data['surveys_overview']) === 8 && count($data['top_responders']) <= 5, 'Source limits preserved');
        foreach (['', 'from=2026-01-05&to=2026-01-07', 'from=2026-01-07&to=2026-01-05', 'from=2020-01-01&to=2026-01-07', 'from=invalid&to=2026-02-30', 'from=2024-02-28&to=2024-03-01', 'to=2026-01-07', 'from=2026-01-07&to=2026-01-07'] as $query) {
            $stats = sd_request('staff/stats?' . $query, $role)[1]['data'];
            sd_check($stats === sd_reference('stats', $state[$role]['id'], $query), "$role all eleven series exactly match AJAX: $query");
            sd_check(count($stats['response_trend']) <= 366, 'Trend capped to 366 inclusive points');
        }
    }
    $id = $state['staff']['id'];
    foreach (['is_first_login = 1' => '/staff/change-password', 'is_first_login = 0, profile_completed = 0' => '/staff/complete-profile'] as $assignment => $destination) {
        dashboard_sql($conn, "UPDATE staff SET $assignment WHERE staff_id = ?", [$id])->close();
        foreach (['staff/dashboard', 'staff/stats'] as $path) sd_check(sd_request($path)[1]['redirect'] === $destination, 'Onboarding gate on both endpoints');
    }
    dashboard_sql($conn, 'UPDATE staff SET is_first_login = 0, profile_completed = 1 WHERE staff_id = ?', [$id])->close();
    // Soft deletion affects demographics/denominator, yet the original distinct responder count still includes all responses.
    dashboard_sql($conn, 'UPDATE residents SET deleted_at = NOW() WHERE resident_id = ?', [$state['residents']['temporary']['id']])->close();
    $data = sd_request('staff/dashboard')[1]['data'];
    $expected = sd_reference('dashboard', $id);
    sd_check($data['eligible_residents'] == $expected['eligible_residents'] && $data['responding_residents'] == $expected['responding_residents'], 'Original participation numerator and denominator');
    sd_check(sd_request('staff/stats')[1]['data'] === sd_reference('stats', $id), 'All demographics exclude archived resident');
    dashboard_sql($conn, 'UPDATE residents SET deleted_at = NULL WHERE resident_id = ?', [$state['residents']['temporary']['id']])->close();
    dashboard_sql($conn, 'UPDATE staff SET deleted_at = NOW() WHERE staff_id = ?', [$id])->close();
    sd_check(sd_request('staff/dashboard')[0] === 401, 'Archived staff session invalidated');
    dashboard_sql($conn, 'UPDATE staff SET deleted_at = NULL WHERE staff_id = ?', [$id])->close();
    if (in_array('--react', $argv, true)) {
        passthru('node --test ' . escapeshellarg(__DIR__ . '/staff-dashboard.test.mjs'), $reactExit);
        if ($reactExit !== 0) throw new RuntimeException('Live React parity checks failed.');
    }
    echo "Staff dashboard API: $checks assertions passed against original dashboard.php and ajax_stats.php.\n";
    if (in_array('--keep', $argv, true)) { echo "Disposable fixtures retained for React/browser verification.\n"; $state = null; }
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof mysqli_sql_exception ? 'Database verification failed (' . $error->getCode() . ', line ' . $error->getLine() . ").\n" : $error->getMessage() . "\n"); $failed = true;
} finally {
    if ($state) staff_dashboard_clean($conn, $state);
    elseif ($failed && is_file(staff_dashboard_file())) staff_dashboard_clean($conn, json_decode(file_get_contents(staff_dashboard_file()), true));
    session_unset(); session_destroy();
    foreach (glob($temp . '/*') as $file) unlink($file);
    rmdir($temp);
}
if ($failed) exit(1);
