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
function rm_reference(array $body): array {
    global $state;
    $process = proc_open(['/Applications/XAMPP/xamppfiles/bin/php', __DIR__ . '/staff-residents-reference.php', (string)$state['staff']['id'], 'post'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode($body)); fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)) throw new RuntimeException('Original reference failed: ' . $err);
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}
function rm_row(mysqli $conn, int $id): array {
    $row = dashboard_sql($conn, 'SELECT * FROM residents WHERE resident_id = ?', [$id])->get_result()->fetch_assoc();
    sm_check(password_verify($row['resident_number'], $row['password']), 'Default password is Resident Number');
    unset($row['password'], $row['resident_number'], $row['resident_id'], $row['created_at']);
    return $row;
}
try {
    $state = sm_create($conn);
    $marker = 'ResidentStep12_' . $state['staff']['id'];
    $before = preg_replace('/ AUTO_INCREMENT=\d+/', '', $conn->query('SHOW CREATE TABLE residents')->fetch_row()[1]);
    foreach (['GET','POST'] as $method) {
        sm_check(sm_request('staff/residents', $method, $method === 'POST' ? [] : null, 'anonymous')[0] === 401, 'Anonymous denied');
    }
    sm_check(sm_request('staff/residents', 'DELETE', null, 'anonymous')[0] === 405, 'Wrong method denied');
    foreach (['staff','admin','resident'] as $role) sm_request('login','POST',['identifier'=>$state[$role]['number'],'password'=>'SurveyFixtureA1'],$role);
    foreach (['GET','POST'] as $method) sm_check(sm_request('staff/residents',$method,$method==='POST'?[]:null,'resident')[0] === 403,'Residents denied');
    $valid = ['first_name'=>$marker, 'middle_name'=>' Santos ', 'last_name'=>'Fixture', 'birthdate'=>'1990-05-06', 'birthplace'=>'Bulacan', 'gender'=>'woman',
        'household_number'=>' 12 ', 'purok'=>'Purok 3', 'street_highway'=>'Rizal Street', 'barangay'=>'Anyatam', 'municipality'=>'San Ildefonso', 'postal_code'=>'3010','province'=>'Bulacan',
        'residency_status'=>'permanent', 'residency_start_date'=>'2020-01-01', 'references'=>[['first_name'=>'Reference','last_name'=>'Person']],
        'courtesy_title'=>'Others','courtesy_title_other'=>'Engr.', 'contact_number'=>'0917 123 4567', 'email'=>'fixture@example.test',
        'occupation'=>'Teacher', 'hotline_number'=>'(02) 8123-4567','is_married'=>'1','spouse_first_name'=>'Spouse','spouse_last_name'=>'Fixture',
        'spouse_courtesy_title'=>'Others','spouse_courtesy_title_other'=>'Dr.', 'has_children'=>'1','children'=>[['first_name'=>'Child','last_name'=>'Fixture'], []]];
    $cases = [ ['first_name'=>''], ['middle_name'=>''], ['birthplace'=>''], ['gender'=>'unknown'], ['birthdate'=>''], ['references'=>[]], ['references'=>[['last_name'=>'Person']]],
        ['references'=>[['first_name'=>'Person']]], ['children'=>[['last_name'=>'Child']]], ['children'=>[]], ['spouse_last_name'=>''], ['spouse_first_name'=>''],
        ['hotline_number'=>'123'], ['occupation'=>str_repeat('x',101)], ['household_number'=>'','province'=>''],
        ['residency_status'=>'temporary'], ['postal_code'=>'123'], ['contact_number'=>'words'], ['purok'=>str_repeat('x',61)],
        ['special_category'=>'pwd'], ['special_category'=>'pwd','category_reference'=>str_repeat('x',61)], ['special_category'=>'pwd','category_reference'=>'bad'],
        ['special_category'=>'senior_citizen','category_reference'=>'bad'], ['special_category'=>'pregnant','category_reference'=>'bad'],
        ['residency_start_date'=>''], ['residency_start_date'=>'2020-02-30'], ['residency_start_date'=>date('Y-m-d',strtotime('+1 day'))],
        ['residency_start_date'=>date('Y-m-d')], ['residency_start_date'=>'1989-01-01'], ['birthdate'=>date('Y-m-d',strtotime('+1 day'))] ];
    foreach ($cases as $changes) {
        $body = array_replace($valid,$changes); $reference = rm_reference($body);
        [$status,$actual] = sm_request('staff/residents','POST',$body);
        sm_check($reference['error'] !== '' && $status === 400 && $actual['message'] === $reference['error'], 'Original first validation error matches');
    }
    // Success: compare every stored non-identity field, children/references, and exact hand-over text.
    foreach (['permanent','temporary'] as $status) {
        $body = $valid;
        if ($status === 'temporary') {
            $body += ['temp_household_number'=>'45','temp_purok'=>'Purok 2','temp_street_highway'=>'New Street','temp_barangay'=>'Anyatam','temp_municipality'=>'San Ildefonso','temp_postal_code'=>'ABCD','temp_province'=>'Bulacan','residence_type'=>'BH'];
            $body['residency_status']='temporary'; $body['residency_start_date']=date('Y-m-d');
        } else { $body += ['temp_household_number'=>'stale','residence_type'=>'BH','special_category'=>'none','category_reference'=>'stale']; }
        $reference = rm_reference($body);
        $original = dashboard_sql($conn,'SELECT resident_id, resident_number FROM residents WHERE first_name=? ORDER BY resident_id DESC LIMIT 1',[$marker])->get_result()->fetch_assoc();
        [$code,$actual] = sm_request('staff/residents','POST',$body);
        sm_check($code===200 && isset($actual['data']['resident_id']), 'Add accepted'); $id = $actual['data']['resident_id'];
        sm_check(rm_row($conn,$id) === rm_row($conn,(int)$original['resident_id']), 'Every stored resident field matches PHP');
        foreach (['resident_children','resident_character_references'] as $table) sm_check(fetch_person_rows($conn,$table,$id) === fetch_person_rows($conn,$table,(int)$original['resident_id']), 'Related person rows match PHP');
        sm_check($reference['message'] === $actual['message'] . '<strong>' . h($original['resident_number']) . '</strong>', 'Exact original hand-over message');
        sm_check((int)substr($actual['data']['resident_number'],strrpos($actual['data']['resident_number'],'-')+1) === (int)substr($original['resident_number'],strrpos($original['resident_number'],'-')+1)+1, 'Global Resident Number suffix increases');
        sm_check(str_starts_with($actual['data']['resident_number'],'R-'.date('Y').'-'), 'Number uses current year');
        $state[$status] = $id; sm_save($state);
    }
    // Whitelists, stale fields, all reference formats, No clearing and rollback.
    foreach (['pwd'=>'03-1408-000-0000123','senior_citizen'=>'SC-26-004512','pregnant'=>'prc no. 123456'] as $category=>$reference) {
        $body=array_replace($valid,['special_category'=>$category,'category_reference'=>$reference,'is_married'=>'0','has_children'=>'0']);
        $result=sm_request('staff/residents','POST',$body,$category==='pregnant'?'admin':'staff')[1]; $id=$result['data']['resident_id'];
        $row=rm_row($conn,$id); sm_check($row['category_reference']===$reference && $row['spouse_first_name']==='' && !(int)$row['has_children'], 'Category accepted and No clears spouse/children');
        sm_check(fetch_person_rows($conn,'resident_children',$id)===[], 'No clears posted child rows');
    }
    $count = (int)dashboard_sql($conn,'SELECT COUNT(*) c FROM residents WHERE first_name=?',[$marker])->get_result()->fetch_assoc()['c'];
    [$code,$result]=sm_request('staff/residents','POST',array_replace($valid,['references'=>[['first_name'=>str_repeat('x',1000),'last_name'=>'Fixture']]]));
    sm_check($code===500 && $result['message']==='The resident could not be saved. Please try again.', 'Insert failure is contained');
    sm_check((int)dashboard_sql($conn,'SELECT COUNT(*) c FROM residents WHERE first_name=?',[$marker])->get_result()->fetch_assoc()['c'] === $count, 'Resident rolls back with failed reference insert');
    dashboard_sql($conn, 'UPDATE residents SET is_active=0 WHERE resident_id=?', [$state['permanent']])->close();
    dashboard_sql($conn, 'UPDATE residents SET deleted_at=NOW() WHERE resident_id=?', [$original['resident_id']])->close();
    foreach (['staff','admin'] as $role) {
        $data=sm_request('staff/residents','GET',null,$role)[1]['data'];
        sm_check($data['can_delete']===($role==='admin'),'Role presentation matches');
        $row=array_values(array_filter($data['residents'],fn($r)=>(int)$r['resident_id']===$state['temporary']))[0];
        sm_check($row['searchText']===$row['resident_number'].' '.$marker.' Fixture' && $row['filter']==='temporary pending-login','Source search/filter data');
        sm_check(!in_array((int)$original['resident_id'],array_map('intval',array_column($data['residents'],'resident_id'))),'Archived resident excluded');
        $inactive=array_values(array_filter($data['residents'],fn($r)=>(int)$r['resident_id']===$state['permanent']))[0];
        sm_check(!$inactive['is_active'] && str_contains($inactive['filter'],'deactivated'),'Deactivated resident retained with filter');
        sm_check(count($data['fields']['address'])===7 && $data['fields']['min_months']===6,'Shared metadata');
    }
    sm_check(preg_replace('/ AUTO_INCREMENT=\d+/', '', $conn->query('SHOW CREATE TABLE residents')->fetch_row()[1]) === $before,'Schema unchanged');
    if (in_array('--react', $argv, true)) {
        passthru('node --test ' . escapeshellarg(__DIR__ . '/staff-residents.test.mjs'), $exitCode);
        sm_check($exitCode === 0, 'React resident flow tests pass');
    }
    echo "Passed $checks resident list/add API and original-PHP parity assertions.\n";
} catch (Throwable $error) { $failed=true; fwrite(STDERR,$error->getMessage()."\n"); }
finally {
    if ($state) {
        dashboard_sql($conn,'DELETE FROM residents WHERE first_name=?',[$marker])->close(); sm_clean($conn,$state);
    }
    foreach (glob($temp.'/*') ?: [] as $file) unlink($file); rmdir($temp); session_unset(); session_destroy();
}
exit($failed?1:0);
