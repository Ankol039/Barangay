<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/staff-surveys-fixtures.php';
$temp = sys_get_temp_dir() . '/barangay-management-check-' . bin2hex(random_bytes(6));
mkdir($temp, 0700); session_save_path($temp);
require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
$checks = 0; $state = null; $failed = false;
function sm_check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function sm_request(string $path, string $method = 'GET', ?array $body = null, string $jar = 'staff'): array {
    global $temp;
    $args = ['curl', '-sS', '--max-time', '15', '-X', $method, '-b', "$temp/$jar.cookies", '-c', "$temp/$jar.cookies", '-w', "\n%{http_code}", 'http://localhost/barangay_survey_system/api/' . $path];
    if ($body !== null) array_push($args, '-H', 'Content-Type: application/json', '--data-binary', '@-');
    $process = proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $body === null ? '' : json_encode($body)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)) throw new RuntimeException('HTTP request failed: ' . $err);
    $split = strrpos($out, "\n"); $raw = substr($out, 0, $split);
    sm_check(!str_contains($raw, '$2y$') && !preg_match('/"password"\s*:/', $raw), 'No password/hash exposure');
    return [(int)substr($out, $split + 1), json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}
function sm_error(string $path, array $body, string $message): void {
    [$status, $result] = sm_request($path, 'POST', $body);
    sm_check($status === 400 && $result['message'] === $message, 'Exact validation: ' . $message);
}
function sm_questions(int $id): array { return sm_request('staff/questions?survey_id=' . $id)[1]['data']['questions']; }
function sm_count(mysqli $conn, string $table, string $key, int $id): int {
    $stmt = dashboard_sql($conn, "SELECT COUNT(*) c FROM $table WHERE $key = ?", [$id]);
    $count = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close(); return $count;
}
try {
    $state = sm_create($conn);
    $schema = [];
    foreach (['surveys', 'survey_questions', 'survey_choices', 'responses', 'survey_results'] as $table) $schema[$table] = $conn->query("SHOW CREATE TABLE $table")->fetch_row()[1];
    foreach (['staff/surveys' => 'GET', 'staff/survey?id=0' => 'GET', 'staff/survey/update' => 'POST', 'staff/survey/toggle' => 'POST', 'staff/survey/delete' => 'POST', 'staff/questions?survey_id=0' => 'GET', 'staff/questions' => 'POST', 'staff/question/update' => 'POST', 'staff/question/delete' => 'POST', 'staff/question/move' => 'POST'] as $path => $method) {
        sm_check(sm_request($path, $method, $method === 'POST' ? [] : null, 'anonymous')[0] === 401, 'Anonymous denied');
        sm_check(sm_request($path, 'DELETE', null, 'anonymous')[0] === 405, 'Wrong method denied');
    }
    foreach (['staff', 'admin', 'resident'] as $role) sm_check(sm_request('login', 'POST', ['identifier' => $state[$role]['number'], 'password' => 'SurveyFixtureA1'], $role)[0] === 200, 'Fixture login');
    foreach (['staff/surveys', 'staff/questions?survey_id=0'] as $path) sm_check(sm_request($path, 'GET', null, 'resident')[0] === 403, 'Resident denied');
    sm_check(sm_request('staff/surveys', 'POST', [], 'resident')[0] === 403, 'Resident mutations denied');
    $valid = ['title' => 'Community Health Survey Fixture', 'description' => 'Synthetic survey for migration verification.', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+7 days')),
        'questions' => [['text' => 'Choose a service', 'type' => 'multiple_choice', 'choices' => ['Consultation', 'Vaccination', '  ']], ['text' => 'Were you satisfied?', 'type' => 'yesno'], ['text' => 'Rate the service', 'type' => 'rating'], ['text' => 'Share your feedback', 'type' => 'short_answer']]];
    foreach (['staff/surveys', 'staff/survey/update'] as $path) {
        $body = $valid;
        if ($path !== 'staff/surveys') {
            $id = sm_request('staff/surveys', 'POST', $valid)[1]['data']['survey_id']; $body['id'] = $id;
        }
        sm_error($path, array_replace($body, ['title' => '']), 'Title, start date, and end date are required.');
        sm_error($path, array_replace($body, ['title' => 'aaaaaaaa']), 'Give the survey a real title - at least eight characters that describe what it is about.');
        sm_error($path, array_replace($body, ['description' => 'bad']), 'Either write a proper description of at least ten characters, or leave it blank.');
        sm_error($path, array_replace($body, ['end_date' => '2000-01-01']), 'Start date cannot be after end date.');
    }
    sm_error('staff/surveys', array_replace($valid, ['questions' => [['text' => '  ']]]), 'Please add at least one question, and give it a question to ask.');
    // Creation deliberately retains the original weaker question validation.
    $loose = sm_request('staff/surveys', 'POST', array_replace($valid, ['questions' => [['text' => 'x', 'type' => 'multiple_choice', 'choices' => ['One']]]]))[1]['data']['survey_id'];
    sm_check(count(sm_questions($loose)[0]['choices']) === 1, 'Builder does not invent question-management validation');
    [$status, $result] = sm_request('staff/surveys', 'POST', $valid);
    $id = $result['data']['survey_id']; $state['surveys'][] = $id; sm_save($state);
    sm_check($status === 200 && !isset($result['message']) && $result['redirect'] === '/staff/survey-management', 'Create redirects without invented success');
    $row = sm_request('staff/survey?id=' . $id)[1]['data']['survey'];
    sm_check($row['status'] === 'active' && count(sm_questions($id)) === 4, 'All four types created active');
    $createdBy = dashboard_sql($conn, 'SELECT created_by FROM surveys WHERE survey_id = ?', [$id])->get_result()->fetch_assoc()['created_by'];
    sm_check((int)$createdBy === $state['staff']['id'], 'Creator comes from staff session');
    $q = sm_questions($id); [$mc, $yesno, $rating, $short] = $q;
    sm_check(count($mc['choices']) === 2, 'Blank creation choices ignored');
    $list = sm_request('staff/surveys')[1]['data']['surveys'];
    sm_check(in_array($id, array_column($list, 'survey_id')), 'Survey included in list');
    $update = array_replace($valid, ['id' => $id, 'title' => 'Updated Community Survey Fixture', 'status' => 'inactive']);
    sm_check(sm_request('staff/survey/update', 'POST', $update)[1]['message'] === 'Survey updated successfully.', 'Metadata exact success');
    sm_check(sm_request('staff/survey?id=' . $id)[1]['data']['survey']['status'] === 'inactive', 'Metadata saved');
    for ($i = 0; $i < 2; $i++) {
        $toggle = sm_request('staff/survey/toggle', 'POST', ['id' => $id])[1];
        sm_check(!isset($toggle['message']), 'Toggle has no invented flash');
        sm_check(sm_request('staff/survey?id=' . $id)[1]['data']['survey']['status'] === ($i === 0 ? 'active' : 'inactive'), 'Toggle persisted');
    }
    $base = ['survey_id' => $id, 'question_text' => 'Choose another service', 'question_type' => 'multiple_choice', 'choices' => ['First', 'Second', ' ']];
    sm_error('staff/questions', array_replace($base, ['question_text' => '']), 'Question text is required.');
    sm_error('staff/questions', array_replace($base, ['question_text' => 'aaaaa']), 'Write the question out in words: at least five characters, and not one repeated character.');
    sm_error('staff/questions', array_replace($base, ['choices' => ['First', ' ']]), 'A multiple-choice question needs at least two choices. Blank boxes are ignored.');
    $added = sm_request('staff/questions', 'POST', $base)[1];
    sm_check($added['message'] === 'Question added.' && count($added['data']['questions']) === 5, 'Question add appends with success');
    $newId = $added['data']['question_id'];
    $beforeQuestions = sm_questions($id);
    $failedQuestion = sm_request('staff/questions', 'POST', array_replace($base, ['choices' => ['Valid choice', str_repeat('x', 200)]]));
    sm_check($failedQuestion[0] === 500, 'Question insertion failure is contained');
    sm_check(sm_questions($id) === $beforeQuestions, 'Question and choices roll back together');
    foreach (['up', 'down', 'down'] as $direction) {
        $move = sm_request('staff/question/move', 'POST', ['survey_id' => $id, 'qid' => $short['question_id'], 'move' => $direction])[1];
        sm_check($move['message'] === 'Question order updated.', 'Move message');
    }
    $ordered = sm_questions($id);
    sm_check(array_column($ordered, 'question_id') === [$mc['question_id'], $yesno['question_id'], $rating['question_id'], $newId, $short['question_id']], 'Adjacent moves and boundary');
    $response = dashboard_sql($conn, 'INSERT INTO responses (survey_id, resident_id) VALUES (?, ?)', [$id, $state['resident']['id']])->insert_id;
    $choiceId = $mc['choices'][0]['choice_id']; $otherId = $mc['choices'][1]['choice_id'];
    $answerId = dashboard_sql($conn, 'INSERT INTO survey_results (response_id, question_id, choice_id) VALUES (?, ?, ?)', [$response, $mc['question_id'], $choiceId])->insert_id;
    $edit = ['survey_id' => $id, 'question_id' => $mc['question_id'], 'question_text' => 'Choose your preferred service', 'question_type' => 'multiple_choice', 'existing_choices' => [$choiceId => 'Consultation corrected', $otherId => 'Vaccination'], 'new_choices' => ['New service']];
    sm_check(sm_request('staff/question/update', 'POST', $edit)[1]['message'] === 'Question updated.', 'Question update success');
    $updated = sm_questions($id)[0];
    sm_check($updated['choices'][0]['choice_id'] === $choiceId && $updated['choices'][0]['choice_text'] === 'Consultation corrected', 'Choice ID preserved on edit');
    sm_check(sm_count($conn, 'survey_results', 'answer_id', $answerId) === 1, 'Existing answer retained on typo edit');
    sm_error('staff/question/update', array_replace($edit, ['question_text' => '']), 'Question text is required.');
    sm_error('staff/question/update', array_replace($edit, ['question_text' => 'aaaaa']), 'Write the question out in words: at least five characters, and not one repeated character.');
    sm_error('staff/question/update', array_replace($edit, ['existing_choices' => [$choiceId => '', $otherId => 'Only one'], 'new_choices' => []]), 'A multiple-choice question needs at least two choices. Blank boxes are ignored.');
    sm_error('staff/question/update', array_replace($edit, ['survey_id' => $loose]), 'That question does not belong to this survey.');
    $before = sm_questions($id);
    sm_request('staff/question/delete', 'POST', ['survey_id' => $loose, 'question_id' => $mc['question_id']]);
    sm_request('staff/question/move', 'POST', ['survey_id' => $loose, 'qid' => $mc['question_id'], 'move' => 'down']);
    sm_check(sm_questions($id) === $before, 'Cross-survey delete/move cannot change question');
    sm_error('staff/question/update', array_replace($edit, ['existing_choices' => [$newId => 'Foreign choice']]), 'Invalid request field: existing_choices.');
    $third = $updated['choices'][2]['choice_id'];
    sm_request('staff/question/update', 'POST', array_replace($edit, ['existing_choices' => [$choiceId => 'Consultation corrected', $otherId => '', $third => 'New service'], 'new_choices' => []]));
    sm_check(array_column(sm_questions($id)[0]['choices'], 'choice_id') === [$choiceId, $third], 'Explicit blank deletes only that choice');
    sm_request('staff/question/update', 'POST', array_replace($edit, ['question_type' => 'yesno', 'existing_choices' => [], 'new_choices' => []]));
    sm_check(sm_count($conn, 'survey_choices', 'question_id', $mc['question_id']) === 0, 'Changing away from MC clears choices');
    sm_check(sm_count($conn, 'survey_results', 'answer_id', $answerId) === 1, 'Original schema retains raw answers when a choice is removed');
    $deleted = sm_request('staff/question/delete', 'POST', ['survey_id' => $id, 'question_id' => $newId])[1];
    sm_check($deleted['message'] === 'Question deleted.', 'Exact delete message');
    sm_check(array_map('intval', array_column(sm_questions($id), 'question_order')) === [1, 2, 3, 4], 'Delete resequences');
    sm_check(sm_request('staff/surveys', 'GET', null, 'admin')[0] === 200, 'Admin list allowed');
    sm_check(sm_request('staff/survey/update', 'POST', $update, 'admin')[0] === 200, 'Admin edits allowed');
    foreach (['is_first_login = 1' => '/staff/change-password', 'is_first_login = 0, profile_completed = 0' => '/staff/complete-profile'] as $assignment => $destination) {
        dashboard_sql($conn, "UPDATE staff SET $assignment WHERE staff_id = ?", [$state['staff']['id']])->close();
        sm_check(sm_request('staff/surveys')[1]['redirect'] === $destination, 'Read onboarding guard');
        sm_check(sm_request('staff/survey/delete', 'POST', ['id' => $id])[1]['redirect'] === $destination, 'Mutation onboarding guard');
        sm_check(sm_count($conn, 'surveys', 'survey_id', $id) === 1, 'Onboarding cannot mutate');
    }
    dashboard_sql($conn, 'UPDATE staff SET is_first_login = 0, profile_completed = 1 WHERE staff_id = ?', [$state['staff']['id']])->close();
    $deleted = sm_request('staff/survey/delete', 'POST', ['id' => $id])[1];
    sm_check($deleted['message'] === 'Survey “Updated Community Survey Fixture” was deleted successfully.', 'Exact survey delete flash');
    foreach (['surveys' => ['survey_id', $id], 'survey_questions' => ['survey_id', $id], 'responses' => ['survey_id', $id], 'survey_results' => ['response_id', $response]] as $table => [$key, $value]) sm_check(sm_count($conn, $table, $key, $value) === 0, 'Survey cascades: ' . $table);
    sm_error('staff/survey/delete', ['id' => $id], 'The selected survey could not be found or deleted.');
    sm_check(sm_request('staff/survey/delete', 'POST', ['id' => 0])[1]['redirect'] === '/staff/survey-management', 'Nonpositive delete preserves clean redirect');
    foreach (['staff/survey?id=0', 'staff/questions?survey_id=0'] as $path) sm_check(sm_request($path)[1]['redirect'] === '/staff/survey-management', 'Invalid survey redirects');
    $bad = $valid; $bad['questions'][1]['text'] = str_repeat('x', 300);
    $before = sm_count($conn, 'surveys', 'created_by', $state['staff']['id']);
    [$status, $result] = sm_request('staff/surveys', 'POST', $bad);
    sm_check($status === 500 && $result['message'] === 'An error occurred while creating the survey.', 'Exact creation rollback message');
    sm_check(sm_count($conn, 'surveys', 'created_by', $state['staff']['id']) === $before, 'Failed creation rolls back survey and questions');
    foreach ($schema as $table => $definition) {
        // AUTO_INCREMENT naturally advances during disposable CRUD.
        $normalize = fn($v) => preg_replace('/AUTO_INCREMENT=\d+/', 'AUTO_INCREMENT=N', $v);
        sm_check($normalize($conn->query("SHOW CREATE TABLE $table")->fetch_row()[1]) === $normalize($definition), 'Schema unchanged: ' . $table);
    }
    if (in_array('--react', $argv, true)) {
        passthru('node --test ' . escapeshellarg(__DIR__ . '/staff-surveys.test.mjs'), $reactExit);
        if ($reactExit !== 0) throw new RuntimeException('Live React parity checks failed.');
    }
    echo "Survey management API: $checks assertions passed.\n";
    if (in_array('--keep', $argv, true)) { echo "Disposable fixtures retained for React/browser verification.\n"; $state = null; }
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof mysqli_sql_exception ? 'Database check failed (' . $error->getCode() . ', line ' . $error->getLine() . ").\n" : $error->getMessage() . "\n"); $failed = true;
} finally {
    if ($state) sm_clean($conn, $state);
    session_unset(); session_destroy();
    foreach (glob($temp . '/*') as $file) unlink($file); rmdir($temp);
}
if ($failed) exit(1);
