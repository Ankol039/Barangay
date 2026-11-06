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
function ra_reference(string $role, string $action, array $body): array {
    global $state;
    $legacy = ['password-reset'=>'reset', 'toggle'=>'toggle', 'delete'=>'delete'];
    $request = isset($legacy[$action]) ? ['get'=>[$legacy[$action]=>$body['resident_id']]]
        : ['post'=>$body + [(['update'=>'update_resident','request-delete'=>'request_delete','promote'=>'promote_resident','revert'=>'revert_resident'][$action])=>'1']];
    $process = proc_open(['/Applications/XAMPP/xamppfiles/bin/php', __DIR__.'/staff-residents-reference.php', (string)$state[$role]['id'], 'action'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0],json_encode($request)); fclose($pipes[0]);
    $raw=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if(proc_close($process)) throw new RuntimeException('PHP oracle failed: '.$err);
    return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
}
function ra_snapshot(int $id): array {
    global $conn;
    $row=dashboard_sql($conn,'SELECT * FROM residents WHERE resident_id=?',[$id])->get_result()->fetch_assoc();
    $data=['row'=>$row];
    foreach(['resident_children','resident_character_references','resident_delete_requests','resident_residency_changes'] as $table)
        $data[$table]=dashboard_sql($conn,"SELECT * FROM $table WHERE resident_id=?",[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
    return $data;
}
function ra_restore(int $id, array $snapshot): void {
    global $conn;
    if (!$snapshot['row']) return;
    $row=$snapshot['row']; unset($row['resident_id']);
    dashboard_sql($conn,'UPDATE residents SET '.implode(', ',array_map(fn($key)=>"`$key`=?",array_keys($row))).' WHERE resident_id=?',[...array_values($row),$id])->close();
    foreach(['resident_children','resident_character_references','resident_delete_requests','resident_residency_changes'] as $table) {
        dashboard_sql($conn,"DELETE FROM $table WHERE resident_id=?",[$id])->close();
        foreach($snapshot[$table] as $row) dashboard_sql($conn,"INSERT INTO $table (`".implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row))->close();
    }
}
function ra_normalize(array $snapshot): array {
    if (!$snapshot['row']) return $snapshot;
    $row=&$snapshot['row']; $row['password']=password_verify($row['resident_number'],$row['password']);
    $row['deleted_at']=$row['deleted_at']!==null;
    foreach(['resident_children','resident_character_references','resident_delete_requests','resident_residency_changes'] as $table) {
        foreach($snapshot[$table] as &$item) {
            foreach(['child_id','reference_id','request_id','change_id','created_at','requested_at','changed_at'] as $key) unset($item[$key]);
            if(array_key_exists('reviewed_at',$item)) $item['reviewed_at']=$item['reviewed_at']!==null;
        } unset($item);
    }
    return $snapshot;
}
function ra_compare(string $role,string $action,array $body): array {
    global $conn;
    $id=(int)$body['resident_id']; $before=ra_snapshot($id);
    $reference=ra_reference($role,$action,$body); $expected=ra_normalize(ra_snapshot($id)); ra_restore($id,$before);
    [$code,$actual]=sm_request('staff/resident/'.$action,'POST',$body,$role);
    $denied=$role==='staff' && in_array($action,['promote','revert'],true);
    sm_check($denied ? $code===403 : (($reference['error']!=='') ? $code>=400 : $code===200),"$role $action status matches PHP permissions/outcome");
    if(!$denied) sm_check(($actual['message']??'')===($reference['error']?:$reference['message']),"$role $action exact PHP message");
    sm_check(ra_normalize(ra_snapshot($id))===$expected,"$role $action resident, family, requests and address archive match PHP");
    return $actual;
}
try {
    $state=sm_create($conn); $marker='ResidentStep13_'.$state['staff']['id']; $ids=[];
    foreach(['staff','admin','resident'] as $role) sm_request('login','POST',['identifier'=>$state[$role]['number'],'password'=>'SurveyFixtureA1'],$role);
    $valid=['first_name'=>$marker,'middle_name'=>'Santos','last_name'=>'Fixture','courtesy_title'=>'Others','courtesy_title_other'=>'Engr.',
        'birthdate'=>'1990-01-02','birthplace'=>'Bulacan','gender'=>'man','household_number'=>'12','purok'=>'Purok 1','street_highway'=>'Old Street',
        'barangay'=>'Old Barangay','municipality'=>'Old City','postal_code'=>'3010','province'=>'Old Province',
        'temp_household_number'=>'45','temp_purok'=>'Purok 2','temp_street_highway'=>'New Street','temp_barangay'=>'New Barangay',
        'temp_municipality'=>'New City','temp_postal_code'=>'3011','temp_province'=>'New Province','residence_type'=>'BH',
        'residency_status'=>'temporary','residency_start_date'=>'2020-01-01','occupation'=>'Teacher','is_married'=>'1','has_children'=>'1',
        'spouse_first_name'=>'Spouse','spouse_last_name'=>'Fixture','spouse_courtesy_title'=>'Others','spouse_courtesy_title_other'=>'Dr.',
        'children'=>[['first_name'=>'One','last_name'=>'Child','courtesy_title'=>'Others','courtesy_title_other'=>'Atty.'],['first_name'=>'Two','last_name'=>'Child']],
        'references'=>[['first_name'=>'One','last_name'=>'Reference'],['first_name'=>'Two','last_name'=>'Reference','courtesy_title'=>'Others','courtesy_title_other'=>'Engr.']]];
    foreach(['staff','admin'] as $role) {
        $result=sm_request('staff/residents','POST',$valid,$role)[1]; $id=(int)$result['data']['resident_id']; $ids[]=$id;
        $body=$valid+['resident_id'=>(string)$id]; $baseline=ra_snapshot($id);
        foreach(['update','password-reset','toggle','delete','request-delete','promote','revert'] as $action) {
            foreach(['anonymous','resident'] as $outsider) sm_check(sm_request('staff/resident/'.$action,'POST',['resident_id'=>(string)$id],$outsider)[0]===($outsider==='anonymous'?401:403),'Mutation access denied to '.$outsider);
            sm_check(sm_request('staff/resident/'.$action,'GET',null,$role)[0]===405,'Mutating GET refused');
        }
        $detail=sm_request('staff/resident?id='.$id,'GET',null,$role)[1]['data'];
        sm_check($detail['resident']['children']===fetch_person_rows($conn,'resident_children',$id),'Complete children edit payload');
        sm_check(count($detail['resident']['references'])===2 && !isset($detail['resident']['password']),'Complete sanitized references payload');
        $state[$role.'_target']=$id; sm_save($state);
        // Edit failures retain exact first error, and the stored status ignores posted status.
        foreach([['first_name'=>''],['references'=>[]],['children'=>[]],['spouse_first_name'=>''],['hotline_number'=>'123'],['household_number'=>''],['residence_type'=>''],['contact_number'=>'bad'],['postal_code'=>'12345'],['residency_start_date'=>''],['residency_start_date'=>'2020-02-30'],['residency_start_date'=>'1980-01-01']] as $changes)
            ra_compare($role,'update',array_replace($body,$changes));
        ra_compare($role,'update',array_replace($body,['residency_status'=>'permanent','occupation'=>'Updated','postal_code'=>'ABCD']));
        sm_check(ra_snapshot($id)['row']['residency_status']==='temporary','Edit never relabels residency; edit postal has source length-only validation');
        ra_compare($role,'update',array_replace($body,['is_married'=>'0','has_children'=>'0','references'=>[['first_name'=>'Only','last_name'=>'Reference']]]));
        sm_check(fetch_person_rows($conn,'resident_children',$id)===[] && ra_snapshot($id)['row']['spouse_first_name']==='','No clears spouse and children');
        ra_restore($id,$baseline);
        // Mid-edit related-row failure must roll the resident and previous household back.
        $before=ra_snapshot($id);
        $failure=sm_request('staff/resident/update','POST',array_replace($body,['occupation'=>'Should roll back','references'=>[['first_name'=>str_repeat('x',1000),'last_name'=>'Reference']]]),$role);
        sm_check($failure[0]===500 && $failure[1]['message']==='The changes could not be saved. Please try again.','Edit save failure message');
        sm_check(ra_snapshot($id)===$before,'Edit rolls back resident and household rows');
        ra_compare($role,'password-reset',['resident_id'=>(string)$id]);
        sm_check(password_verify($before['row']['resident_number'],ra_snapshot($id)['row']['password']) && (int)ra_snapshot($id)['row']['is_first_login']===1,'Reset hashes Resident Number and forces password change');
        ra_compare($role,'toggle',['resident_id'=>(string)$id]); ra_compare($role,'toggle',['resident_id'=>(string)$id]);
        ra_compare($role,'request-delete',['resident_id'=>(string)$id,'reason'=>'   ']);
        ra_compare($role,'request-delete',['resident_id'=>(string)$id,'reason'=>' Duplicate record ']);
        ra_compare($role,'request-delete',['resident_id'=>(string)$id,'reason'=>'Again']);
        sm_check(count(ra_snapshot($id)['resident_delete_requests'])===1,'Only one pending deletion request');
        ra_compare($role,'promote',['resident_id'=>(string)$id,'reason'=>'Verified documents']);
        if($role==='admin') {
            $promoted=ra_snapshot($id); sm_check($promoted['row']['address']===$baseline['row']['temporary_address'] && $promoted['row']['temporary_address']===null,'Promotion moves temporary address and clears fields');
            sm_check($promoted['resident_residency_changes'][0]['prev_address']===$baseline['row']['address'],'Original address archived');
            sm_check(sm_request('staff/resident?id='.$id,'GET',null,$role)[1]['data']['restore_address']===$baseline['row']['address'],'Restore preview is latest unreverted promotion');
            ra_compare($role,'promote',['resident_id'=>(string)$id]);
            ra_compare($role,'revert',['resident_id'=>(string)$id,'reason'=>'']);
            ra_compare($role,'revert',['resident_id'=>(string)$id,'reason'=>'Claim disproved']);
            $reverted=ra_snapshot($id); sm_check($reverted['row']['address']===$baseline['row']['address'] && $reverted['row']['residence_type']==='BH','Archived permanent address and residence type restored');
            sm_check((int)$reverted['resident_residency_changes'][0]['is_reverted']===1 && count($reverted['resident_residency_changes'])===2,'Promotion marked reverted and reversion archived');
            ra_compare($role,'revert',['resident_id'=>(string)$id,'reason'=>'Again']);
            // Every promotion eligibility branch compares against the PHP oracle.
            foreach([['temp_household_number'=>'','temporary_address'=>''],['residency_start_date'=>null],['residency_start_date'=>date('Y-m-d')]] as $changes) {
                ra_restore($id,$baseline);
                dashboard_sql($conn,'UPDATE residents SET '.implode(', ',array_map(fn($key)=>"$key=?",array_keys($changes))).' WHERE resident_id=?',[...array_values($changes),$id])->close();
                ra_compare($role,'promote',['resident_id'=>(string)$id]);
            }
            // Long structured temporary address causes an update failure after archive insertion.
            ra_restore($id,$baseline);
            dashboard_sql($conn,'UPDATE residents SET temp_street_highway=?, temp_barangay=?, temp_municipality=?, temp_province=? WHERE resident_id=?',[str_repeat('a',120),str_repeat('b',80),str_repeat('c',80),str_repeat('d',80),$id])->close();
            $before=ra_snapshot($id); $failure=sm_request('staff/resident/promote','POST',['resident_id'=>(string)$id],$role);
            sm_check($failure[0]===500 && ra_snapshot($id)===$before,'Promotion update failure rolls back inserted address archive');
            // No prior promotion: source keeps the permanent address and copies it to temporary.
            ra_restore($id,$baseline); dashboard_sql($conn,"UPDATE residents SET residency_status='permanent' WHERE resident_id=?",[$id])->close();
            ra_compare($role,'revert',['resident_id'=>(string)$id,'reason'=>'Never promoted']);
            sm_check(ra_snapshot($id)['row']['address']===$baseline['row']['address'] && ra_snapshot($id)['row']['residence_type']==='','No-snapshot revert carries address and asks for type');
            // Existing under-minimum permanent date remains editable; a new such date is refused.
            dashboard_sql($conn,"UPDATE residents SET residency_status='permanent', residency_start_date=? WHERE resident_id=?",[date('Y-m-d'),$id])->close();
            ra_compare($role,'update',array_replace($body,['residency_start_date'=>date('Y-m-d')]));
            ra_compare($role,'update',array_replace($body,['residency_start_date'=>date('Y-m-d',strtotime('-1 day'))]));
        } else ra_compare($role,'revert',['resident_id'=>(string)$id,'reason'=>'Claim disproved']);
        if (!ra_snapshot($id)['resident_delete_requests']) ra_compare($role,'request-delete',['resident_id'=>(string)$id,'reason'=>'Pending archive request']);
        // Archive must keep real synthetic response rows and close pending requests.
        $survey=dashboard_sql($conn,"INSERT INTO surveys(title,description,start_date,end_date,created_by) VALUES('Resident action fixture','Disposable',CURDATE(),CURDATE(),?)",[$state[$role]['id']]);
        $sid=$survey->insert_id; $survey->close();
        $response=dashboard_sql($conn,'INSERT INTO responses(survey_id,resident_id) VALUES(?,?)',[$sid,$id]); $responseId=$response->insert_id; $response->close();
        ra_compare($role,'delete',['resident_id'=>(string)$id]);
        sm_check(sm_count($conn,'responses','response_id',$responseId)===1,'Archive preserves survey responses');
        if($role==='admin') {
            sm_check(ra_snapshot($id)['resident_delete_requests'][0]['status']==='approved','Direct archive approves pending deletion');
            sm_check(sm_request('staff/resident?id='.$id,'GET',null,$role)[0]===404,'Archived records absent from edit lookup');
        }
        ra_restore($id,$baseline); // Leave a clean household target for live React tests inside this runner.
    }
    foreach(['staff','admin'] as $role) foreach(['password-reset','toggle','delete','request-delete','promote','revert'] as $action)
        ra_compare($role,$action,['resident_id'=>'2147483647','reason'=>'Missing target']);
    if(in_array('--react',$argv,true)) {
        passthru('node --test '.escapeshellarg(__DIR__.'/staff-resident-actions.test.mjs'),$exitCode);
        sm_check($exitCode===0,'React action checks pass');
    }
    echo "Passed $checks resident action API/original-PHP parity assertions.\n";
} catch(Throwable $error) { $failed=true; fwrite(STDERR,$error->getMessage()."\n"); }
finally {
    if($state) {
        $targets=dashboard_sql($conn,'SELECT resident_id FROM residents WHERE first_name=?',[$marker])->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach($targets as $row) foreach(['resident_delete_requests','resident_residency_changes'] as $table) dashboard_sql($conn,"DELETE FROM $table WHERE resident_id=?",[$row['resident_id']])->close();
        dashboard_sql($conn,'DELETE FROM residents WHERE first_name=?',[$marker])->close(); sm_clean($conn,$state);
    }
    foreach(glob($temp.'/*')?:[] as $file) unlink($file); rmdir($temp); session_unset(); session_destroy();
}
exit($failed?1:0);
