<?php
/** CLI-only integration check. Uses curl and deletes only fixtures created here. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$root = dirname(__DIR__, 2);
$base = rtrim($argv[1] ?? 'http://localhost/barangay_survey_system/api', '/');
$temp = sys_get_temp_dir() . '/barangay-auth-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
session_save_path($temp);
require $root . '/config/db.php';
require $root . '/config/functions.php';
$fixtures = [];
$checks = 0;

function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
}

function sql(string $query, array $values = []): mysqli_stmt {
    global $conn;
    $stmt = $conn->prepare($query);
    if ($values) {
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    }
    $stmt->execute();
    return $stmt;
}

function fixture(string $type, string $number, string $password): int {
    global $fixtures;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($type === 'staff') {
        $stmt = sql("INSERT INTO staff (staff_number, username, full_name, first_name, courtesy_title, password, role, is_active, is_first_login, profile_completed) VALUES (?, ?, 'API Throwaway', 'API', 'Dr.', ?, 'staff', 1, 1, 0)", [$number, $number, $hash]);
    } else {
        $stmt = sql("INSERT INTO residents (resident_number, first_name, last_name, courtesy_title, password, residency_status, residence_type, is_active, is_first_login) VALUES (?, 'API', 'Throwaway', 'Dr.', ?, 'temporary', 'AR', 1, 1)", [$number, $hash]);
    }
    $id = $stmt->insert_id;
    $stmt->close();
    $fixtures[] = [$type, $id];
    return $id;
}

function request(string $route, string $method = 'GET', $body = null, string $jar = 'main', bool $form = false): array {
    global $base, $temp;
    $headers = "$temp/headers";
    $command = ['curl', '--silent', '--show-error', '--max-time', '15', '--request', $method,
        '--cookie', "$temp/$jar.cookies", '--cookie-jar', "$temp/$jar.cookies",
        '--dump-header', $headers, '--write-out', "\n%{http_code}", "$base/$route"];
    $input = '';
    if ($body !== null) {
        $command[] = '--header';
        $command[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
        $command[] = '--data-binary';
        $command[] = '@-';
        $input = is_string($body) ? $body : ($form ? http_build_query($body) : json_encode($body));
    }
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('curl could not reach the API: ' . trim($error));
    }
    $split = strrpos($output, "\n");
    $status = (int)substr($output, $split + 1);
    $raw = substr($output, 0, $split);
    $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $header = file_get_contents($headers);
    check(stripos($header, 'Content-Type: application/json') !== false, 'JSON content type');
    check(stripos($header, 'Access-Control-Allow-') === false, 'No CORS headers');
    check(stripos($header, 'Cache-Control: no-store') !== false, 'Session responses are not cached');
    check(!preg_match('/"(?:password|current_password|new_password|confirm_password)"\s*:/', $raw), 'No password fields in JSON');
    check(!str_contains($raw, '$2y$'), 'No password hashes in JSON');
    return [$status, $json, $header];
}

function login(string $identifier, string $password, string $jar = 'main'): array {
    return request('login', 'POST', ['identifier' => $identifier, 'password' => $password], $jar);
}

function cookie(string $jar = 'main'): string {
    global $temp;
    return (string)file_get_contents("$temp/$jar.cookies");
}

function history(string $type, int $id, string $event): int {
    $stmt = sql('SELECT COUNT(*) AS c FROM login_history WHERE user_type = ? AND user_id = ? AND event = ?', [$type, $id, $event]);
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count;
}

function schema(): array {
    global $conn;
    $result = [];
    foreach ($conn->query('SHOW TABLES')->fetch_all() as $table) {
        $name = str_replace('`', '``', $table[0]);
        $definition = $conn->query("SHOW CREATE TABLE `$name`")->fetch_row()[1];
        $result[$name] = preg_replace('/AUTO_INCREMENT=\d+/', 'AUTO_INCREMENT=<sequence>', $definition);
    }
    return $result;
}

$before = schema();
$failure = null;
try {
    $suffix = (string)random_int(100000, 999999);
    $resident_number = 'R-2099-' . $suffix;
    $staff_number = 'S-2099-' . $suffix;
    $password = 'TestA1' . bin2hex(random_bytes(12));
    $other_password = 'OtherB2' . bin2hex(random_bytes(12));
    $resident_id = fixture('resident', $resident_number, $password);
    $staff_id = fixture('staff', $staff_number, $other_password);

    [$status, $body] = request('session');
    check($status === 200 && $body['data']['user'] === null && !$body['data']['authenticated'], 'Anonymous session');
    $anonymous_cookie = cookie();
    [$status, $body] = request('change-password', 'POST', []);
    check($status === 401 && !$body['ok'], 'Password change requires authentication');
    [$status, $body, $headers] = request('login');
    check($status === 405 && str_contains($headers, 'Allow: POST'), 'Method guard');
    [$status] = request('missing');
    check($status === 404, 'Unknown endpoint');
    [$status] = request('login', 'POST', '{broken');
    check($status === 400, 'Malformed JSON');
    [$status] = request('login', 'POST', ['identifier' => [], 'password' => $password]);
    check($status === 400, 'Reject nonscalar credential');
    [$status, $body] = login('', '');
    check($status === 422 && $body['message'] === 'Please enter your username or Resident Number and password.', 'Empty credential wording');
    [$status, $wrong] = login($resident_number, 'wrong');
    [$missing_status, $missing] = login('missing-' . $suffix, 'wrong');
    check($status === 401 && $missing_status === 401 && $wrong === $missing && $wrong['message'] === 'Invalid username/Resident Number or password.', 'Generic login failure');
    check(history('resident', $resident_id, 'login') === 0, 'Failed logins are not audited as successful');

    [$status, $body] = login('  ' . $resident_number . '  ', $password);
    check($status === 200 && $body['ok'] && $body['data']['user']['id'] === $resident_id && $body['data']['user']['role'] === 'resident', 'Resident login');
    check(cookie() !== $anonymous_cookie, 'Session ID regenerated at login');
    check($body['redirect'] === '/resident/change-password' && $body['data']['user']['is_first_login'], 'Resident first-login route');
    check($body['data']['user']['initial'] === 'A' && $body['data']['user']['display_name'] === 'Dr. API Throwaway', 'Courtesy title and initial');
    check($body['data']['user']['residence_type'] === 'AR' && $body['data']['user']['photo'] === null && $body['data']['user']['profile_completed'], 'Resident header fields');
    check(history('resident', $resident_id, 'login') === 1, 'Resident login history');
    [$status, $session] = request('session');
    check($session['data']['user'] === $body['data']['user'] && count($session['data']['password_rules']) === count(password_rules()), 'Session persists with shared password rules');
    sql("UPDATE residents SET first_name = 'Updated' WHERE resident_id = ?", [$resident_id])->close();
    [, $body] = request('session');
    check($body['data']['user']['name'] === 'Updated Throwaway' && $body['data']['user']['initial'] === 'U', 'Fresh header database state');

    $new = 'NewC3' . bin2hex(random_bytes(12));
    $change = ['current_password' => 'wrong', 'new_password' => 'x', 'confirm_password' => 'different'];
    [, $body] = request('change-password', 'POST', $change);
    check($body['message'] === 'Current password is incorrect.', 'Current password validation precedence');
    $change['current_password'] = $password;
    [, $body] = request('change-password', 'POST', $change);
    check($body['message'] === 'Your new password does not meet the requirements: ' . implode('; ', password_policy_errors('x')) . '.', 'Shared password policy and rule order');
    $change['new_password'] = $password;
    [, $body] = request('change-password', 'POST', $change);
    check($body['message'] === 'Your new password must be different from your current one.', 'Same-password validation');
    $change['new_password'] = $new;
    [, $body] = request('change-password', 'POST', $change);
    check($body['message'] === 'New password and confirmation do not match.', 'Confirmation validation');
    $change['confirm_password'] = $new;
    [, $body] = request('change-password', 'POST', $change);
    check($body['ok'] && !$body['data']['user']['is_first_login'] && $body['ui']['outcome'] === 'first', 'Resident password update and first-login flag');
    request('logout', 'POST');
    check(history('resident', $resident_id, 'logout') === 1, 'Logout history row');
    [, $body] = request('session');
    check(!$body['data']['authenticated'], 'Logout destroys the session');
    [$status] = login($resident_number, $password);
    check($status === 401, 'Old password rejected');
    [, $body] = login($resident_number, $new);
    check($body['redirect'] === '/resident/dashboard', 'New password and dashboard route');
    request('logout', 'POST');

    // Deactivation and archive checks affect only our own fixture IDs.
    foreach ([['resident', $resident_id, $resident_number, $new], ['staff', $staff_id, $staff_number, $other_password]] as [$type, $id, $number, $pw]) {
        $table = $type === 'staff' ? 'staff' : 'residents';
        $key = $type === 'staff' ? 'staff_id' : 'resident_id';
        sql("UPDATE $table SET is_active = 0 WHERE $key = ?", [$id])->close();
        [$status, $body] = login($number, $pw);
        $contact = $type === 'staff' ? 'administrator' : 'Barangay Health Center';
        check($status === 403 && $body['message'] === "This account has been deactivated. Please contact the $contact.", 'Exact deactivation message');
        [$status, $body] = login($number, 'wrong');
        check($status === 401 && $body === $wrong, 'Wrong password does not reveal deactivation');
        sql("UPDATE $table SET is_active = 1, deleted_at = NOW() WHERE $key = ?", [$id])->close();
        [$status, $body] = login($number, $pw);
        check($status === 401 && $body === $wrong, 'Archived account uses generic failure');
        sql("UPDATE $table SET deleted_at = NULL WHERE $key = ?", [$id])->close();
    }

    // A resident-shaped collision picks the resident regardless of passwords.
    $collision_id = fixture('staff', 'collision-' . $suffix, $other_password);
    sql('UPDATE staff SET username = ? WHERE staff_id = ?', [$resident_number, $collision_id])->close();
    [$status] = login($resident_number, $other_password);
    check($status === 401, 'Collision cannot fall through to staff password');
    [, $body] = login($resident_number, $new);
    check($body['data']['user']['account_type'] === 'resident', 'Resident-format tie-breaking');
    request('logout', 'POST');
    fixture('resident', $staff_number, $password);
    [$status] = login($staff_number, $password);
    check($status === 401, 'Nonresident collision cannot fall through to resident password');
    [, $body] = login($staff_number, $other_password);
    check($body['data']['user']['account_type'] === 'staff' && $body['redirect'] === '/staff/change-password', 'Staff Number login and nonresident tie-breaking');
    $change = ['current_password' => $other_password, 'new_password' => strtolower($staff_number), 'confirm_password' => 'different'];
    [, $body] = request('change-password', 'POST', $change);
    check($body['message'] === 'Your new password cannot be your Staff Number. That is the temporary password printed on your hand-over slip.', 'Staff Number rejection before policy');
    $change['new_password'] = $change['confirm_password'] = $new;
    [, $body] = request('change-password', 'POST', $change);
    check($body['ok'] && $body['redirect'] === '/staff/complete-profile' && !$body['data']['user']['is_first_login'] && !$body['data']['user']['profile_completed'] && !$body['data']['user']['show_sidebar'], 'Staff password to profile onboarding');
    request('logout', 'POST');
    check(history('staff', $staff_id, 'logout') === 1, 'Staff logout history row');
    [, $body] = login($staff_number, $new);
    check($body['redirect'] === '/staff/complete-profile', 'Incomplete staff profile login gate');
    request('logout', 'POST');
    sql("UPDATE staff SET username = ?, profile_completed = 1 WHERE staff_id = ?", ['api-test-' . $suffix, $staff_id])->close();
    [, $body] = login($staff_number, $new);
    check($body['data']['user']['id'] === $staff_id && $body['redirect'] === '/staff/dashboard', 'Staff Number works independently of username');
    request('logout', 'POST');
    [, $body] = request('login', 'POST', ['identifier' => 'api-test-' . $suffix, 'password' => $new], 'main', true);
    check($body['redirect'] === '/staff/dashboard', 'Username and form-encoded login');
    $change['current_password'] = $new;
    $change['new_password'] = $change['confirm_password'] = $password;
    [, $body] = request('change-password', 'POST', $change);
    check($body['ui']['outcome'] === 'normal' && $body['message'] === 'Your password has been updated. Use it the next time you log in.', 'Normal staff password success');
    sql("UPDATE staff SET role = 'admin' WHERE staff_id = ?", [$staff_id])->close();
    [, $body] = request('session');
    check($body['data']['user']['role'] === 'admin' && isset($body['data']['user']['pending_deletions'], $body['data']['user']['archived_accounts']), 'Admin header counts');
    sql('UPDATE staff SET deleted_at = NOW() WHERE staff_id = ?', [$staff_id])->close();
    [$status] = request('session');
    check($status === 401 && history('staff', $staff_id, 'logout') === 4, 'Archived mid-session staff logs out');
    check(schema() === $before, 'Database schema unchanged');
} catch (Throwable $error) {
    // Only check labels are printed, never request data or database exceptions.
    $failure = $error instanceof mysqli_sql_exception ? 'Database operation failed.' : $error->getMessage();
} finally {
    foreach (array_reverse($fixtures) as [$type, $id]) {
        sql('DELETE FROM login_history WHERE user_type = ? AND user_id = ?', [$type, $id])->close();
        $table = $type === 'staff' ? 'staff' : 'residents';
        $key = $type === 'staff' ? 'staff_id' : 'resident_id';
        sql("DELETE FROM $table WHERE $key = ?", [$id])->close();
    }
    session_unset();
    session_destroy();
    foreach (glob($temp . '/*') as $file) {
        unlink($file);
    }
    rmdir($temp);
}
if ($failure) {
    fwrite(STDERR, "FAIL: $failure\n");
    exit(1);
}
echo "PASS: $checks assertions; curl login/session/password/logout, collision and account-state checks; schema unchanged; throwaway accounts and history removed.\n";
