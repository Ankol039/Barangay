<?php
/** Live parity: fixtures share the original XAMPP database; originals are never changed. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/resident-dashboard-fixtures.php';
$root = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . '/barangay-dashboard-check-' . bin2hex(random_bytes(6));
mkdir($temp, 0700); session_save_path($temp);
require $root . '/config/db.php'; require $root . '/config/functions.php';
$base = 'http://localhost/barangay_survey_system/api/';
$checks = 0; $state = null; $failed = false;
function dashboard_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); } $checks++;
}
function dashboard_process(array $command, string $input = ''): string {
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Verification subprocess failed. ' . $error); }
    return $output;
}
function dashboard_request(string $path, string $jar = 'main', string $method = 'GET', ?array $body = null): array {
    global $base, $temp;
    $args = ['curl', '-sS', '--max-time', '15', '-X', $method, '-b', "$temp/$jar.cookies", '-c', "$temp/$jar.cookies", '-w', "\n%{http_code}", $base . $path];
    if ($body !== null) { array_push($args, '-H', 'Content-Type: application/json', '--data-binary', '@-'); }
    $output = dashboard_process($args, $body === null ? '' : json_encode($body));
    $split = strrpos($output, "\n"); $raw = substr($output, 0, $split);
    dashboard_check(!str_contains($raw, '$2y$') && !preg_match('/"password"\s*:/', $raw), 'No hash or password exposure');
    return [(int)substr($output, $split + 1), json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}
function dashboard_reference(string $mode, int $id): array {
    return json_decode(dashboard_process([PHP_BINARY, __DIR__ . '/resident-dashboard-reference.php', $mode, (string)$id]), true, 512, JSON_THROW_ON_ERROR);
}
try {
    $state = dashboard_fixtures_create($conn);
    dashboard_check(dashboard_request('resident/dashboard', 'anonymous')[0] === 401, 'Anonymous dashboard denied');
    dashboard_check(dashboard_request('resident/stat-detail?view=available', 'anonymous')[0] === 401, 'Anonymous detail denied');
    dashboard_request('login', 'staff', 'POST', ['identifier' => $state['staff']['number'], 'password' => 'DashboardFixtureA1']);
    dashboard_check(dashboard_request('resident/dashboard', 'staff')[0] === 403, 'Staff dashboard denied');
    dashboard_check(dashboard_request('resident/stat-detail?view=completed', 'staff')[0] === 403, 'Staff detail denied');
    foreach ($state['residents'] as $type => $resident) {
        dashboard_check(dashboard_request('login', $type, 'POST', ['identifier' => $resident['number'], 'password' => 'DashboardFixtureA1'])[0] === 200, 'Fixture login');
        [$status, $result] = dashboard_request('resident/dashboard?resident_id=' . $state['residents']['empty']['id'], $type);
        dashboard_check($status === 200 && $result['ok'], 'Dashboard status');
        $data = $result['data']; $expected = dashboard_reference('dashboard', $resident['id']);
        foreach ($expected as $key => $value) {
            $actual = $data[$key];
            if (in_array($key, ['upcoming', 'recent_submissions'], true)) {
                $actual = array_map(fn($row) => array_filter($row, fn($field) => !str_ends_with($field, '_label'), ARRAY_FILTER_USE_KEY), $actual);
            }
            if ($key === 'me') { $value = array_map(fn($part) => (string)($part ?? ''), $value); }
            dashboard_check($key === 'me' ? $actual == $value : $actual === $value, "$type dashboard matches original: $key");
        }
        dashboard_check($data['completion'] === dashboard_reference('stats', $resident['id'])['completion'], 'Completion matches ajax_stats.php');
        dashboard_check(count($data['upcoming']) === 5, 'Closing rows limited to five');
        dashboard_check(count($data['activity_months']) === 6, 'Six filled months');
        dashboard_check($data['has_activity'] === ($type !== 'empty') && $data['has_household'] === ($type !== 'empty') && $data['has_residency_progress'] === ($type !== 'empty'), 'Conditional panels');
        foreach (['available', 'completed', 'assigned'] as $view) {
            [$status, $result] = dashboard_request('resident/stat-detail?view=' . $view, $type);
            dashboard_check($status === 200, 'Detail status');
            $expectedDetail = dashboard_reference($view, $resident['id']);
            $actualDetail = $result['data'];
            $actualDetail['rows'] = array_map(fn($row) => array_filter($row, fn($field) => !str_ends_with($field, '_label'), ARRAY_FILTER_USE_KEY), $actualDetail['rows']);
            dashboard_check($actualDetail == $expectedDetail, "$type $view matches original query rows/order/count");
        }
        foreach (['', '?view=unknown', '?view[]=available'] as $query) {
            dashboard_check(dashboard_request('resident/stat-detail' . $query, $type)[1]['redirect'] === '/resident/dashboard', 'Invalid view redirects');
        }
        dashboard_check(dashboard_request('resident/dashboard', $type, 'POST')[0] === 405, 'Wrong method denied');
    }
    // Empty, reached-minimum and future-date rules without touching any existing record.
    $resident = $state['residents']['temporary'];
    foreach (['-12 months', '+1 month'] as $date) {
        dashboard_sql($conn, 'UPDATE residents SET residency_start_date = ?, residence_type = ? WHERE resident_id = ?', [date('Y-m-d', strtotime($date)), 'ZZ', $resident['id']])->close();
        $data = dashboard_request('resident/dashboard', 'temporary')[1]['data'];
        $expected = dashboard_reference('dashboard', $resident['id']);
        dashboard_check($data['residency_pct'] === $expected['residency_pct'] && $data['residency_months'] === $expected['residency_months'], 'Residency boundary matches PHP');
        dashboard_check($data['is_temporary'] && $data['residence_name'] === '', 'No automatic promotion; unknown type has no label');
    }
    dashboard_sql($conn, 'UPDATE residents SET residency_start_date = ?, residence_type = ? WHERE resident_id = ?', [date('Y-m-d', strtotime('-3 months')), 'BH', $resident['id']])->close();
    foreach (['is_active = 0', "deleted_at = NOW()"] as $assignment) {
        dashboard_sql($conn, "UPDATE residents SET $assignment WHERE resident_id = ?", [$resident['id']])->close();
        dashboard_check(in_array(dashboard_request('resident/dashboard', 'temporary')[0], [401, 403], true), 'Inactive or archived session denied');
        dashboard_sql($conn, 'UPDATE residents SET is_active = 1, deleted_at = NULL WHERE resident_id = ?', [$resident['id']])->close();
        dashboard_request('login', 'temporary', 'POST', ['identifier' => $resident['number'], 'password' => 'DashboardFixtureA1']);
    }
    if (in_array('--react', $argv, true)) {
        passthru('node --test ' . escapeshellarg(__DIR__ . '/resident-dashboard.test.mjs'), $reactExit);
        if ($reactExit !== 0) throw new RuntimeException('Live React parity checks failed.');
    }
    echo "Dashboard API: $checks assertions passed against unchanged PHP source and ajax_stats.php.\n";
    if (in_array('--keep', $argv, true)) {
        foreach ($state['residents'] as $type => $row) { echo "$type: {$row['number']}\n"; }
        $state = null; // explicit keep only for the following browser checks
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof mysqli_sql_exception ? "Dashboard database check failed (" . $error->getCode() . ", line " . $error->getLine() . ").\n" : $error->getMessage() . "\n"); $failed = true;
} finally {
    if ($state) { dashboard_fixtures_clean($conn, $state); }
    elseif (!in_array('--keep', $argv, true) && is_file(dashboard_fixture_file())) { dashboard_fixtures_clean($conn, json_decode(file_get_contents(dashboard_fixture_file()), true)); }
    session_unset(); session_destroy();
    foreach (glob($temp . '/*') as $file) { unlink($file); } rmdir($temp);
}

if ($failed) { exit(1); }
