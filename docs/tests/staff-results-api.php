<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/staff-results-fixtures.php';
$temp = sys_get_temp_dir() . '/barangay-results-check-' . bin2hex(random_bytes(6)); mkdir($temp, 0700); session_save_path($temp);
require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
$checks = 0; $state = null; $failed = false;
function result_check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function result_request(string $path, string $role = 'staff', string $method = 'GET', ?array $body = null): array {
    global $temp;
    $args = ['curl', '-sS', '--max-time', '15', '-X', $method, '-b', "$temp/$role.cookies", '-c', "$temp/$role.cookies", '-D', "$temp/headers", '-w', "\n%{http_code}", 'http://localhost/barangay_survey_system/api/' . $path];
    if ($body !== null) array_push($args, '-H', 'Content-Type: application/json', '--data-binary', '@-');
    $process = proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $body === null ? '' : json_encode($body)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)) throw new RuntimeException('HTTP request failed: ' . $err);
    $split = strrpos($out, "\n"); $raw = substr($out, 0, $split);
    result_check(!str_contains($raw, '$2y$') && !preg_match('/"password"\s*:/', $raw), 'No password/hash exposure');
    return [(int)substr($out, $split + 1), json_decode($raw, true), $raw, file_get_contents("$temp/headers")];
}
function result_reference(string $mode, int $id, string $query): string {
    $process = proc_open([PHP_BINARY, __DIR__ . '/staff-results-reference.php', $mode, (string)$id, $query], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)) throw new RuntimeException('Source reference failed: ' . $err);
    return $out;
}
try {
    $state = results_create($conn);
    $schema = []; foreach (['surveys', 'survey_questions', 'survey_choices', 'responses', 'survey_results'] as $table) $schema[$table] = $conn->query("SHOW CREATE TABLE $table")->fetch_row()[1];
    foreach (['staff/results', 'staff/report', 'staff/report-export', 'staff/stat-detail?view=total_surveys', 'staff/stats'] as $path) {
        result_check(result_request($path, 'anonymous')[0] === 401, 'Anonymous denied');
        result_check(result_request($path, 'anonymous', 'POST', [])[0] === 405, 'Wrong method denied');
    }
    foreach (['staff', 'admin', 'resident'] as $role) result_check(result_request('login', $role, 'POST', ['identifier' => $state[$role]['number'], 'password' => 'SurveyFixtureA1'])[0] === 200, 'Fixture login');
    foreach (['staff/results', 'staff/report', 'staff/report-export', 'staff/stat-detail?view=total_surveys', 'staff/stats'] as $path) result_check(result_request($path, 'resident')[0] === 403, 'Resident denied');
    foreach (['staff', 'admin'] as $role) foreach (['populated', 'empty'] as $kind) foreach (['results', 'report'] as $mode) {
        $query = 'survey_id=' . $state[$kind];
        [$status, $result] = result_request('staff/' . $mode . '?' . $query, $role); $data = $result['data'];
        $source = json_decode(result_reference($mode, $state[$role]['id'], $query), true, 512, JSON_THROW_ON_ERROR);
        result_check($status === 200 && $data['total_respondents'] === (int)$source['total_respondents'], "$role $kind $mode respondent parity");
        foreach ($source['questions'] as $i => $question) {
            $actual = $data['questions'][$i];
            if (isset($actual['breakdown'])) foreach ($actual['breakdown'] as &$bar) unset($bar['percent'], $bar['winning']);
            unset($bar);
            if (isset($actual['answers'])) foreach ($actual['answers'] as &$answer) unset($answer['submitted_at_label']);
            unset($answer); unset($actual['total_votes'], $actual['answered_here']);
            // mysqli string counts are deliberately cast to integers at the JSON bar boundary.
            if (isset($question['breakdown'])) foreach ($question['breakdown'] as &$bar) $bar['votes'] = (int)$bar['votes'];
            unset($bar);
            result_check($actual === $question, "$role $kind $mode question $i all raw figures/order match PHP");
        }
        foreach (['title', 'description', 'start_date', 'end_date', 'status'] as $field) result_check($data['survey'][$field] === $source['survey'][$field], 'Survey metadata parity');
    }
    $data = result_request('staff/results?survey_id=' . $state['populated'])[1]['data'];
    result_check($data['total_respondents'] === 3, 'Archived respondent retained');
    result_check(array_column($data['questions'][0]['breakdown'], 'percent') === [50, 50, 0], 'Per-question denominator and rounded percentages');
    result_check(array_column($data['questions'][0]['breakdown'], 'winning') === [true, true, false], 'Tied maxima highlighted');
    result_check(array_column($data['questions'][1]['breakdown'], 'votes') === [2, 0], 'Yes/No zero rows');
    result_check($data['questions'][2]['avg_rating'] == 3.67 && (int)$data['questions'][2]['rating_n'] === 3, 'Rating mean/count');
    result_check(array_column($data['questions'][2]['breakdown'], 'votes') === [1, 0, 0, 0, 2], 'Five rating rows');
    result_check(count($data['questions'][3]['answers']) === 200 && str_contains($data['questions'][3]['answers'][0]['answer_text'], 'Need more nurses'), 'Latest 200 nonblank text answers');
    result_check(count(result_request('staff/report?survey_id=' . $state['populated'])[1]['data']['questions'][3]['rows']) === 201, 'Report uncapped');
    $empty = result_request('staff/results?survey_id=' . $state['empty'])[1]['data']['questions'];
    result_check($empty[2]['avg_rating'] === 0 && $empty[3]['answers'] === [] && !in_array(true, array_column($empty[0]['breakdown'], 'winning'), true), 'Empty averages/text/bar colors');
    foreach (['populated', 'empty'] as $kind) {
        [$status, , $csv, $headers] = result_request('staff/report-export?survey_id=' . $state[$kind]);
        result_check($status === 200 && $csv === result_reference('export', $state['staff']['id'], 'survey_id=' . $state[$kind]), 'CSV byte-for-byte parity');
        result_check(str_contains($headers, 'Content-Type: text/csv') && str_contains($headers, 'Content-Disposition: attachment; filename="survey_report_'), 'CSV attachment headers');
    }
    foreach (['', '?survey_id=0', '?survey_id=-1', '?survey_id=999999999', '?survey_id%5B%5D=1'] as $query) foreach (['results', 'report'] as $mode) {
        $data = result_request('staff/' . $mode . $query)[1]['data']; result_check($data['survey'] === null && $data['questions'] === [], 'Absent/missing/malformed survey state');
    }
    $download = result_request('staff/report-export?survey_id=-1');
    result_check($download[0] === 302 && str_contains($download[3], '/app/staff/reports'), 'Invalid export redirects to React reports');
    $general = ['total_surveys', 'active_surveys', 'total_residents', 'total_responses'];
    $admin = ['staff_accounts', 'deactivated_residents', 'deactivated_staff', 'recent_logins'];
    foreach (['staff', 'admin'] as $role) foreach ([...$general, ...$admin] as $key) {
        $result = result_request('staff/stat-detail?view=' . $key, $role)[1];
        if ($role === 'staff' && in_array($key, $admin, true)) { result_check($result['redirect'] === '/staff/dashboard' && $result['data'] === null, 'Admin view denied before SQL/data'); continue; }
        $source = json_decode(result_reference('detail', $state[$role]['id'], 'view=' . $key), true, 512, JSON_THROW_ON_ERROR);
        $data = $result['data'];
        result_check($data['count'] === $source['count'] && $data['columns'] === $source['columns'], 'Detail count/columns parity: ' . $key);
        foreach ($source['rows'] as $i => $row) result_check(array_intersect_key($data['rows'][$i], $row) === $row, 'Detail exact SQL row: ' . $key);
    }
    foreach (['', 'unknown', 'recent_logouts', 'total_surveys%27', 'toString'] as $key) result_check(result_request('staff/stat-detail?view=' . $key)[1]['redirect'] === '/staff/dashboard', 'Whitelist redirect');
    result_check(result_request('staff/stat-detail?view%5B%5D=total_surveys')[1]['redirect'] === '/staff/dashboard', 'Array view rejected');
    $source = json_decode(result_reference('stats', $state['staff']['id'], 'from=2026-11-01&to=2026-11-06'), true, 512, JSON_THROW_ON_ERROR);
    $stats = result_request('staff/stats?from=2026-11-01&to=2026-11-06')[1]['data'];
    foreach ($source as $key => $series) result_check($stats[$key] === $series, 'Existing stats remains source-identical: ' . $key);
    result_check(result_request('resident/stats', 'anonymous')[0] === 401, 'Resident stats anonymous denied');
    result_check(result_request('resident/stats', 'staff')[0] === 403, 'Resident stats staff denied');
    result_check(result_request('resident/stats', 'resident', 'POST', [])[0] === 405, 'Resident stats method denied');
    $completion = result_request('resident/stats', 'resident')[1]['data'];
    result_check($completion === json_decode(result_reference('resident-stats', $state['resident']['id'], ''), true), 'Standalone resident completion source parity');
    result_check($completion['completion'] === result_request('resident/dashboard', 'resident')[1]['data']['completion'], 'Standalone resident stats matches existing dashboard series');
    foreach (['is_first_login' => 1, 'profile_completed' => 0] as $field => $value) {
        dashboard_sql($conn, "UPDATE staff SET $field = ? WHERE staff_id = ?", [$value, $state['staff']['id']])->close();
        foreach (['results', 'report', 'report-export', 'stat-detail?view=total_surveys'] as $path) result_check(result_request('staff/' . $path)[1]['redirect'] === ($field === 'is_first_login' ? '/staff/change-password' : '/staff/complete-profile'), 'Onboarding boundary');
        dashboard_sql($conn, "UPDATE staff SET $field = ? WHERE staff_id = ?", [$value ? 0 : 1, $state['staff']['id']])->close();
    }
    foreach ($schema as $table => $definition) result_check($conn->query("SHOW CREATE TABLE $table")->fetch_row()[1] === $definition, 'Schema unchanged');
    if (in_array('--react', $argv, true)) {
        passthru('node --test ' . escapeshellarg(__DIR__ . '/staff-results.test.mjs'), $reactExit);
        if ($reactExit !== 0) throw new RuntimeException('Live React parity checks failed.');
    }
    echo "Passed $checks staff results/report/stat API assertions.\n";
} catch (Throwable $error) {
    $failed = true; fwrite(STDERR, $error instanceof mysqli_sql_exception ? "Results test database operation failed.\n" : $error->getMessage() . "\n");
} finally {
    if (($failed || !in_array('--keep', $argv, true)) && is_file(results_file())) results_clean($conn, json_decode(file_get_contents(results_file()), true));
    foreach (glob($temp . '/*') as $file) unlink($file); rmdir($temp); session_unset(); session_destroy();
}
exit($failed ? 1 : 0);
