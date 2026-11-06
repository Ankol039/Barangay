<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/resident-profile-fixtures.php';
$temp = sys_get_temp_dir() . '/barangay-profile-check-' . bin2hex(random_bytes(5));
mkdir($temp, 0700); session_save_path($temp);
require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
$state = null; $checks = 0; $failed = false;
function profile_check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function profile_process(array $args, string $input = ''): string {
    $p = proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0) { throw new RuntimeException('Subprocess failed: ' . $err); } return $out;
}
function profile_request(string $path, string $jar = 'temporary', ?array $body = null, ?string $photo = null): array {
    global $temp;
    $args = ['curl', '-sS', '--max-time', '15', '-b', "$temp/$jar.cookies", '-c', "$temp/$jar.cookies", '-w', "\n%{http_code}", 'http://localhost/barangay_survey_system/api/' . $path];
    if ($photo) { array_push($args, '-F', 'profile_photo=@' . $photo); }
    elseif ($body !== null) { array_push($args, '-H', 'Content-Type: application/json', '--data-binary', '@-'); }
    $out = profile_process($args, $body === null ? '' : json_encode((object)$body)); $at = strrpos($out, "\n"); $raw = substr($out, 0, $at);
    profile_check(!str_contains($raw, '$2y$') && !preg_match('/"password"\s*:/', $raw), 'No password/hash exposure');
    return [(int)substr($out, $at + 1), json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}
function profile_error(string $path, array $body, string $message): void {
    [$status, $response] = profile_request('resident/' . $path, 'temporary', $body);
    profile_check($status === 400 && $response['message'] === $message, 'Validation: ' . $message);
}
function profile_pending(mysqli $conn, int $id, string $table): array {
    return dashboard_sql($conn, "SELECT * FROM $table WHERE resident_id = ? AND status = 'pending'", [$id])->get_result()->fetch_all(MYSQLI_ASSOC);
}
try {
    $state = profile_fixtures_create($conn); $id = $state['residents']['temporary']['id'];
    $routes = ['profile', 'contact', 'birthdate-requests', 'name-address-requests', 'family-requests', 'photo', 'photo/remove'];
    foreach ($routes as $path) { profile_check(profile_request('resident/' . $path, 'anon', $path === 'profile' ? null : [])[0] === 401, 'Anonymous refused'); }
    foreach (array_merge($state['residents'], ['staff' => $state['staff']]) as $jar => $row) {
        profile_check(profile_request('login', $jar, ['identifier' => $row['number'], 'password' => 'ProfileFixtureA1'])[0] === 200, 'Fixture login');
    }
    foreach ($routes as $path) { profile_check(profile_request('resident/' . $path, 'staff', $path === 'profile' ? null : [])[0] === 403, 'Staff refused'); }
    $data = profile_request('resident/profile?resident_id=' . $state['residents']['permanent']['id'])[1]['data'];
    profile_check($data['resident']['resident_number'] === $state['residents']['temporary']['number'], 'Identity scoped to session');
    profile_check($data['fields']['person'] === person_field_spec() && count($data['fields']['address']) === 7, 'Shared PHP metadata');
    profile_check($data['display']['full_name'] === 'Dr. Profile Source Fixture III' && $data['display']['gender'] === 'Non-binary', 'Server display labels');
    profile_check(count($data['resident']['children']) === 2 && count($data['resident']['references']) === 2, 'Family rows loaded');
    profile_error('contact', [], 'Please provide your email or contact number to update.');
    profile_error('contact', ['contact_number' => 'letters'], 'A contact number can only contain digits, spaces, +, -, and brackets.');
    $contact = profile_request('resident/contact', 'temporary', ['email' => ' fixture@example.test ', 'contact_number' => ' +63 (917) 123-4567 ']);
    profile_check($contact[0] === 200 && $contact[1]['data']['resident']['email'] === 'fixture@example.test', 'Contact saved immediately');
    profile_check(profile_request('resident/profile')[1]['data']['resident']['contact_number'] === '+63 (917) 123-4567', 'Contact survives reload');
    profile_check(!profile_pending($conn, $id, 'resident_update_requests'), 'Contact creates no proposal');
    profile_error('birthdate-requests', [], 'Choose the corrected birthdate.');
    profile_error('birthdate-requests', ['birthdate' => date('Y-m-d')], 'Birthdate must be in the past.');
    profile_error('birthdate-requests', ['birthdate' => '1990-02-10'], 'That is the birthdate already on record.');
    $name = $data['resident']; $name['courtesy_title'] = 'Others'; $name['courtesy_title_other'] = 'Dr.';
    profile_error('name-address-requests', $name, 'Nothing has changed yet. Edit a name or address field before sending the request.');
    profile_error('name-address-requests', array_replace($name, ['first_name' => '']), 'First name and last name cannot be blank.');
    profile_error('name-address-requests', array_replace($name, ['barangay' => '']), 'Please fill in: Barangay.');
    profile_error('name-address-requests', array_replace($name, ['postal_code' => '123']), 'A Philippine postal code is four digits.');
    profile_error('name-address-requests', array_replace($name, ['purok' => str_repeat('x', 61)]), 'These entries are too long: Purok (max 60 characters).');
    $name['temp_purok'] = 'Corrected temporary purok';
    profile_check(profile_request('resident/name-address-requests', 'temporary', $name)[0] === 200, 'Temporary-only address change accepted');
    $pending = profile_pending($conn, $id, 'resident_update_requests');
    profile_check(count($pending) === 1 && $pending[0]['status'] === 'pending' && $pending[0]['courtesy_title'] === 'Dr.' && $pending[0]['temp_purok'] === $name['temp_purok'], 'Pending identity row stored');
    profile_check($pending[0]['previous_address'] === $name['address'] && str_contains($pending[0]['temporary_address'], $name['temp_purok']), 'Derived/previous address stored');
    profile_error('birthdate-requests', ['birthdate' => '1991-01-01'], 'You already have a pending request under review.');
    profile_error('name-address-requests', $name, 'You already have a pending name/address request under review.');
    profile_check(profile_request('resident/profile')[1]['data']['resident']['temp_purok'] === '', 'Unapproved address unchanged');
    dashboard_sql($conn, "UPDATE resident_update_requests SET status = 'rejected' WHERE resident_id = ?", [$id])->close();
    profile_check(profile_request('resident/birthdate-requests', 'temporary', ['birthdate' => '1991-01-01'])[0] === 200, 'Birthdate request accepted after prior review');
    $pending = profile_pending($conn, $id, 'resident_update_requests');
    profile_check(count($pending) === 1 && $pending[0]['birthdate'] === '1991-01-01', 'Pending birthdate row stored');
    profile_check(profile_request('resident/profile')[1]['data']['resident']['birthdate'] === '1990-02-10', 'Unapproved birthdate unchanged');
    $family = ['occupation' => 'Corrected occupation', 'is_married' => '0', 'has_children' => '0', 'family_reason' => 'Spelling correction',
        'spouse_first_name' => 'Ignored spouse', 'children' => [['first_name' => 'Ignored child']],
        'references' => [['courtesy_title' => 'Others', 'courtesy_title_other' => 'Engr.', 'first_name' => ' Reference ', 'last_name' => ' Person ', 'gender' => 'woman'], ['gender' => 'man']]];
    profile_error('family-requests', array_replace($family, ['references' => [['first_name' => 'Only']]]), 'Character reference 1 needs a surname.');
    profile_error('family-requests', array_replace($family, ['family_reason' => '']), 'Give a short reason so staff know what you are correcting.');
    profile_error('family-requests', array_replace($family, ['references' => []]), 'At least one character reference is required.');
    profile_error('family-requests', array_replace($family, ['is_married' => '1', 'spouse_first_name' => '']), 'Enter your spouse\'s first name, or answer No to "Are you married?".');
    profile_error('family-requests', array_replace($family, ['hotline_number' => 'bad']), 'Check the hotline number. ' . hotline_format_hint());
    profile_check(profile_request('resident/family-requests', 'temporary', $family)[0] === 200, 'Family queue independent of identity queue');
    $pending = profile_pending($conn, $id, 'resident_family_requests'); $payload = json_decode($pending[0]['payload'], true);
    profile_check(count($pending) === 1 && $pending[0]['status'] === 'pending' && $payload['occupation'] === 'Corrected occupation', 'Pending JSON family row stored');
    profile_check($payload['spouse_first_name'] === '' && $payload['children'] === [] && count($payload['references']) === 1 && $payload['references'][0]['courtesy_title'] === 'Engr.' && $payload['references'][0]['first_name'] === 'Reference', 'Original collection, trimming, Others and No clearing');
    profile_error('family-requests', $family, 'You already have a change waiting for review. Staff will look at that one first.');
    profile_check(profile_request('resident/profile')[1]['data']['resident']['occupation'] === 'Teacher', 'Unapproved family unchanged');
    profile_error('photo', [], 'Please select a photo to upload.');
    file_put_contents("$temp/invalid.jpg", 'invalid');
    profile_check(profile_request('resident/photo', 'temporary', null, "$temp/invalid.jpg")[1]['message'] === 'Uploaded file must be a valid image.', 'Invalid image refused');
    $image = imagecreatetruecolor(320, 320); imagejpeg($image, "$temp/square.jpg", 90); imagedestroy($image);
    copy("$temp/square.jpg", "$temp/square.txt");
    profile_check(profile_request('resident/photo', 'temporary', null, "$temp/square.txt")[1]['message'] === 'Only JPG, PNG, GIF, or WEBP images are allowed.', 'Wrong extension refused');
    $photo = profile_request('resident/photo', 'temporary', null, "$temp/square.jpg");
    profile_check($photo[0] === 200 && $photo[1]['message'] === 'Profile photo uploaded successfully.', 'JPEG upload succeeds');
    $path = dirname(__DIR__, 2) . '/' . $photo[1]['data']['resident']['photo_path']; $info = getimagesize($path);
    profile_check($info[0] === 320 && $info[1] === 320 && $info['mime'] === 'image/jpeg', 'Stored square JPEG dimensions and MIME');
    profile_check(profile_request('session')[1]['data']['user']['photo'] === $photo[1]['data']['resident']['photo_path'], 'Fresh avatar reflects upload');
    profile_check(profile_request('resident/photo/remove', 'temporary', [])[0] === 200 && !is_file($path), 'Removal clears record and fixture file');
    profile_check(profile_request('session')[1]['data']['user']['photo'] === null, 'Avatar falls back to initial');
    // Boot workers before locking: unchanged db.php initialization also writes
    // resident rows, so HTTP workers would block there before reaching our code.
    $empty = $state['residents']['empty']['id'];
    $workers = [];
    foreach ([0, 1] as $index) {
        $p = proc_open([PHP_BINARY, __DIR__ . '/resident-profile-race.php', (string)$empty], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        profile_check(trim(fgets($pipes[2])) === 'ready', 'Race worker bootstrapped');
        $workers[] = [$p, $pipes];
    }
    $conn->begin_transaction();
    dashboard_sql($conn, 'SELECT resident_id FROM residents WHERE resident_id = ? FOR UPDATE', [$empty])->close();
    foreach ($workers as $index => [$p, $pipes]) {
        fwrite($pipes[0], json_encode(['birthdate' => '198' . $index . '-01-01']) . "\n"); fclose($pipes[0]);
    }
    $waiting = 0;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $waiting = 0;
        foreach ($conn->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $process) {
            $sql = $process['Info'] ?? '';
            if (str_contains($sql, 'SELECT * FROM residents WHERE resident_id =') && str_contains($sql, 'FOR UPDATE')) { $waiting++; }
        }
        if ($waiting >= 2) { break; } usleep(50000);
    }
    $conn->commit(); $outcomes = [];
    foreach ($workers as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        $outcomes[] = json_decode($out, true)['ok'];
    }
    sort($outcomes);
    profile_check($waiting >= 2 && $outcomes === [false, true] && count(profile_pending($conn, $empty, 'resident_update_requests')) === 1,
        'Concurrent test: waiting=' . $waiting . ', outcomes=' . json_encode($outcomes) . ', pending=' . count(profile_pending($conn, $empty, 'resident_update_requests')));
    dashboard_sql($conn, "UPDATE resident_update_requests SET status = 'rejected' WHERE resident_id = ?", [$empty])->close();
    if (in_array('--react', $argv, true)) {
        passthru('node --test ' . escapeshellarg(__DIR__ . '/resident-profile.test.mjs'), $reactExit);
        if ($reactExit !== 0) throw new RuntimeException('Live React parity checks failed.');
    }
    echo "Resident profile API: $checks assertions passed.\n";
    if (in_array('--keep', $argv, true)) { $state = null; echo "Fixtures retained for React/browser verification.\n"; }
} catch (Throwable $error) {
    $conn->rollback(); fwrite(STDERR, $error instanceof mysqli_sql_exception ? 'Database check failed (' . $error->getCode() . ").\n" : $error->getMessage() . "\n"); $failed = true;
} finally {
    if ($state) { profile_fixtures_clean($conn, $state); }
    elseif (!in_array('--keep', $argv, true) && is_file(profile_fixture_file())) { profile_fixtures_clean($conn, json_decode(file_get_contents(profile_fixture_file()), true)); }
    session_unset(); session_destroy(); foreach (glob($temp . '/*') as $file) { unlink($file); } rmdir($temp);
}
if ($failed) { exit(1); }
