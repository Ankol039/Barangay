<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/resident-survey-fixtures.php';
$temp = sys_get_temp_dir() . '/barangay-survey-check-' . bin2hex(random_bytes(6));
mkdir($temp, 0700); session_save_path($temp);
require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
$state = null; $checks = 0; $failed = false;
function survey_check(bool $ok, string $label): void {
    global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++;
}
function survey_process(array $args, string $input = ''): string {
    $p = proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0) { throw new RuntimeException('Subprocess failed: ' . $err); } return $out;
}
function survey_request(string $path, string $jar = 'main', ?array $body = null, bool $csv = false): array {
    global $temp;
    $args = ['curl', '-sS', '--max-time', '15', '-b', "$temp/$jar.cookies", '-c', "$temp/$jar.cookies", '-w', "\n%{http_code}",
        'http://localhost/barangay_survey_system/api/' . $path];
    if ($body !== null) { array_push($args, '-H', 'Content-Type: application/json', '--data-binary', '@-'); }
    $out = survey_process($args, $body === null ? '' : json_encode($body)); $at = strrpos($out, "\n");
    $raw = substr($out, 0, $at);
    survey_check(!str_contains($raw, '$2y$') && !preg_match('/"password"\s*:/', $raw), 'No password/hash exposure');
    return [(int)substr($out, $at + 1), $csv ? $raw : json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}
function survey_count(mysqli $conn, int $id): int {
    return (int)dashboard_sql($conn, 'SELECT COUNT(*) AS n FROM responses WHERE survey_id = ?', [$id])->get_result()->fetch_assoc()['n'];
}
try {
    $state = survey_fixtures_create($conn);
    foreach (['resident/surveys', 'resident/survey-form', 'resident/history', 'resident/response', 'resident/response-export', 'resident/survey-submissions'] as $path) {
        survey_check(survey_request($path, 'anon', str_ends_with($path, 'submissions') ? [] : null)[0] === 401, 'Anonymous access refused');
    }
    foreach (['main', 'other', 'staff'] as $jar) {
        $row = $jar === 'staff' ? $state['staff'] : $state['residents'][$jar];
        survey_check(survey_request('login', $jar, ['identifier' => $row['number'], 'password' => 'SurveyFixtureA1'])[0] === 200, 'Fixture login');
    }
    foreach (['resident/surveys', 'resident/survey-form', 'resident/history', 'resident/response', 'resident/response-export', 'resident/survey-submissions'] as $path) {
        survey_check(survey_request($path, 'staff', str_ends_with($path, 'submissions') ? [] : null)[0] === 403, 'Staff access refused');
    }
    $list = survey_request('resident/surveys')[1]['data'];
    foreach (['expired', 'future', 'inactive'] as $kind) {
        $id = $state['surveys'][$kind];
        survey_check(!in_array($id, array_column($list['open'], 'survey_id')), 'Closed survey absent');
        survey_check(survey_request('resident/survey-form?id=' . $id)[1]['redirect'] === '/resident/surveys', 'Closed form redirects');
        survey_check(survey_request('resident/survey-submissions', 'main', ['survey_id' => (string)$id])[1]['redirect'] === '/resident/surveys' && survey_count($conn, $id) === 0, 'Closed submission refused');
    }
    $id = $state['surveys']['main']; $q = $state['questions']['main'];
    $form = survey_request('resident/survey-form?id=' . $id)[1]['data'];
    survey_check(array_column($form['questions'], 'question_id') === $q, 'Question ordering');
    survey_check(array_column($form['questions'][0]['choices'], 'choice_id') === $state['choices']['main'], 'Choices preserved');
    foreach ([[], ['q' . $q[1] => 'invalid', 'q' . $q[2] => '6', 'q' . $q[3] => " \n "]] as $answers) {
        survey_check(str_ends_with(survey_request('resident/survey-submissions', 'main', ['survey_id' => (string)$id] + $answers)[1]['redirect'], 'error=empty'), 'Empty/invalid submission rejected');
        survey_check(survey_count($conn, $id) === 0, 'Empty submission does not consume attempt');
    }
    $payload = ['survey_id' => (string)$id, 'q' . $q[0] => 'choice_' . $state['choices']['main'][0], 'q' . $q[1] => 'Yes', 'q' . $q[2] => '4', 'q' . $q[3] => "  Helpful, \"thanks\"\nSecond line  "];
    survey_check(survey_request('resident/survey-submissions', 'main', $payload)[1]['redirect'] === '/resident/surveys?submitted=' . $id, 'Submission succeeds');
    survey_check(survey_count($conn, $id) === 1, 'Exactly one response');
    $history = survey_request('resident/history')[1]['data']['responses'];
    survey_check(count($history) === 1 && $history[0]['survey_id'] === $id, 'Submission appears in history');
    $rid = $history[0]['response_id'];
    $data = survey_request('resident/response?id=' . $rid)[1]['data'];
    survey_check(array_column($data['answers'], 'display_answer') === ['Consultation', 'Yes', '4 / 5', "Helpful, \"thanks\"\nSecond line", 'No answer'], 'Typed/missing answers match source');
    [$status, $csv] = survey_request('resident/response-export?id=' . $rid, 'main', null, true);
    $reference = survey_process([PHP_BINARY, __DIR__ . '/resident-survey-reference.php', 'export_response', (string)$state['residents']['main']['id'], (string)$rid]);
    survey_check($status === 200 && $csv === $reference, 'CSV is byte-identical to original export');
    survey_check(survey_request('resident/response?id=' . $rid, 'other')[1]['redirect'] === '/resident/history', 'Other resident cannot read response');
    survey_check(survey_request('resident/response-export?id=' . $rid, 'other', null, true) === [404, 'Response not found.'], 'Other resident cannot export response');
    survey_check(survey_request('resident/history', 'other')[1]['data']['responses'] === [], 'History scoped to session');
    survey_check(survey_request('resident/survey-submissions', 'main', $payload)[1]['redirect'] === '/resident/surveys' && survey_count($conn, $id) === 1, 'Second answer refused');
    survey_check(survey_request('resident/survey-form?id=' . $id)[1]['redirect'] === '/resident/surveys', 'Answered form blocked');
    $list = survey_request('resident/surveys?submitted=' . $id)[1]['data'];
    survey_check(in_array($id, array_column($list['answered'], 'survey_id')) && !in_array($id, array_column($list['open'], 'survey_id')), 'Survey moves to already answered');
    survey_check($list['submitted']['title'] === 'Survey Fixture main', 'Confirmation title');
    $today = $state['surveys']['today'];
    survey_check(survey_request('resident/survey-form?id=' . $today)[1]['data']['survey']['survey_id'] === $today, 'Inclusive date boundary');
    // Browser requires all radios, but source server accepts one usable optional answer.
    survey_check(str_contains(survey_request('resident/survey-submissions', 'main', ['survey_id' => (string)$today,
        'q' . $state['questions']['today'][3] => 'Only text'])[1]['redirect'], '?submitted='), 'Server preserves partial-answer policy');
    $rollback = $state['surveys']['rollback'];
    survey_check(str_ends_with(survey_request('resident/survey-submissions', 'main', ['survey_id' => (string)$rollback,
        'q' . $state['questions']['rollback'][0] => str_repeat('x', 70000)])[1]['redirect'], 'error=failed'), 'Insert failure handled');
    survey_check(survey_count($conn, $rollback) === 0, 'Failed answer insert rolls response back');
    // Force an actual 1062 race without changing schema: uncommitted winning response
    // is invisible to the API precheck, then its insert waits on our unique-key lock.
    $race = $state['surveys']['race'];
    $p = proc_open([PHP_BINARY, __DIR__ . '/resident-survey-race.php', (string)$state['residents']['main']['id']],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    survey_check(trim(fgets($pipes[2])) === 'ready', 'Race worker bootstrapped');
    $conn->begin_transaction();
    dashboard_sql($conn, 'INSERT INTO responses (survey_id, resident_id) VALUES (?, ?)', [$race, $state['residents']['main']['id']])->close();
    fwrite($pipes[0], json_encode(['survey_id' => (string)$race, 'q' . $state['questions']['race'][3] => 'Concurrent answer']) . "\n"); fclose($pipes[0]);
    $waiting = false;
    for ($i = 0; $i < 100; $i++) {
        foreach ($conn->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $process) {
            if (str_starts_with($process['Info'] ?? '', 'INSERT INTO responses (survey_id, resident_id) VALUES (')) { $waiting = true; break; }
        }
        if ($waiting) { break; } usleep(50000);
    }
    $conn->commit();
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
    survey_check($waiting && $exit === 0, 'Actual duplicate-key race reached');
    survey_check(json_decode($out, true)['redirect'] === '/resident/surveys' && survey_count($conn, $race) === 1, '1062 rolls back quietly');
    if (in_array('--react', $argv, true)) {
        passthru('node --test ' . escapeshellarg(__DIR__ . '/resident-surveys.test.mjs'), $reactExit);
        if ($reactExit !== 0) throw new RuntimeException('Live React parity checks failed.');
    }
    echo "Resident survey API: $checks assertions passed.\n";
    if (in_array('--keep', $argv, true)) { $state = null; echo "Fixtures retained for React/browser verification.\n"; }
} catch (Throwable $error) {
    $conn->rollback();
    fwrite(STDERR, $error instanceof mysqli_sql_exception ? 'Database check failed (' . $error->getCode() . ").\n" : $error->getMessage() . "\n"); $failed = true;
} finally {
    if ($state) { survey_fixtures_clean($conn, $state); }
    elseif (!in_array('--keep', $argv, true) && is_file(survey_fixture_file())) { survey_fixtures_clean($conn, json_decode(file_get_contents(survey_fixture_file()), true)); }
    session_unset(); session_destroy();
    foreach (glob($temp . '/*') as $file) { unlink($file); } rmdir($temp);
}
if ($failed) { exit(1); }
