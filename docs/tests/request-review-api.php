<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/staff-surveys-fixtures.php';
$temp = sys_get_temp_dir() . '/barangay-review-check-' . bin2hex(random_bytes(6));
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
function rr_rows(string $table, string $key, int $id): array {
    global $conn;
    $stmt = dashboard_sql($conn, "SELECT * FROM $table WHERE $key=?", [$id]);
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
}
function rr_insert(string $table, array $row): int {
    global $conn;
    $stmt=dashboard_sql($conn, "INSERT INTO $table (`".implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
    $id=$stmt->insert_id; $stmt->close(); return $id;
}
function rr_snapshot(): array {
    global $state;
    $result=[];
    foreach (['residents'=>'resident_id','resident_update_requests'=>'resident_id','resident_family_requests'=>'resident_id','resident_delete_requests'=>'resident_id','resident_children'=>'resident_id','resident_character_references'=>'resident_id','resident_residency_changes'=>'resident_id'] as $table=>$key) {
        $result[$table]=rr_rows($table,$key,$state['resident']['id']);
    }
    $result['staff']=rr_rows('staff','staff_id',$state['staff']['id']);
    $result['staff_update_requests']=rr_rows('staff_update_requests','staff_id',$state['staff']['id']);
    return $result;
}
function rr_restore(array $snapshot): void {
    global $conn,$state;
    foreach ($snapshot as $table=>$rows) {
        $staff = in_array($table,['staff','staff_update_requests'],true);
        $key = $staff ? 'staff_id' : 'resident_id'; $id = $state[$staff?'staff':'resident']['id'];
        if (in_array($table,['residents','staff'],true)) {
            $row=$rows[0]; unset($row[$key]);
            dashboard_sql($conn,"UPDATE $table SET ".implode(',',array_map(fn($k)=>"`$k`=?",array_keys($row)))." WHERE $key=?",[...array_values($row),$id])->close();
        } else {
            dashboard_sql($conn,"DELETE FROM $table WHERE $key=?",[$id])->close();
            foreach($rows as $row) rr_insert($table,$row);
        }
    }
}
function rr_normalize(array $snapshot): array {
    foreach($snapshot as $table=>&$rows) foreach($rows as &$row) {
        foreach(['reviewed_at','deleted_at'] as $key) if(array_key_exists($key,$row)) $row[$key]=$row[$key]!==null;
        foreach(['child_id','reference_id'] as $key) unset($row[$key]);
        if(in_array($table,['resident_children','resident_character_references'],true)) unset($row['created_at']);
    }
    return $snapshot;
}
function rr_compare(string $role, string $kind, int $id, string $action, string $comment=''): void {
    global $state;
    $page = $kind==='deletion'?'deletion_requests':'address_requests';
    $post=['request_id'=>(string)$id,'action'=>$action,'review_comment'=>$comment];
    if($kind==='staff') { $post['staff_request_id']=$post['request_id']; unset($post['request_id']); }
    if($kind==='family') $post=['family_request_id'=>(string)$id,'family_action'=>$action,'family_comment'=>$comment];
    $before=rr_snapshot();
    $process=proc_open(['/Applications/XAMPP/xamppfiles/bin/php',__DIR__.'/request-review-reference.php',(string)$state[$role]['id'],$page], [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode(['post'=>$post])); fclose($pipes[0]);
    $raw=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if(proc_close($process)) throw new RuntimeException('Original oracle failed: '.$err);
    $oracle=json_decode($raw,true,512,JSON_THROW_ON_ERROR); $expected=rr_normalize(rr_snapshot()); rr_restore($before);
    $prefix=['resident'=>'address','staff'=>'staff-address','family'=>'family','deletion'=>'deletion'][$kind];
    [$code,$result]=sm_request('staff/'.$prefix.'-request/review','POST',['request_id'=>(string)$id,'action'=>$action,'review_comment'=>$comment],$role);
    sm_check(($oracle['error']==='' ? $code===200 : $code>=400),'Review response matches PHP');
    sm_check($result['message']===($oracle['error']?:$oracle['message']),'Review message matches PHP');
    $actual=rr_normalize(rr_snapshot());
    if($actual!==$expected) {
        foreach($expected as $table=>$rows) if($rows!==$actual[$table]) {
            $keys=[];
            foreach($rows as $i=>$row) foreach($row as $key=>$value) if(($actual[$table][$i][$key]??null)!==$value) $keys[]=$key;
            throw new RuntimeException("$role $kind $action parity differs in $table: ".implode(',',array_unique($keys)));
        }
    }
    sm_check(true,'Every resident/staff/request/household row matches original PHP');
}
function rr_race(int $id): void {
    global $state,$conn;
    $workers=[];
    foreach(['staff'=>'approve','admin'=>'reject'] as $role=>$action) {
        $process=proc_open(['/Applications/XAMPP/xamppfiles/bin/php',__DIR__.'/request-review-race.php',(string)$state[$role]['id']], [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if(trim(fgets($pipes[2]))!=='ready') throw new RuntimeException('Race worker failed to initialize');
        $workers[]=[$process,$pipes,$action];
    }
    $conn->begin_transaction();
    dashboard_sql($conn,'SELECT resident_id FROM residents WHERE resident_id=? FOR UPDATE',[$state['resident']['id']])->close();
    foreach($workers as [$process,$pipes,$action]) { fwrite($pipes[0],json_encode(['request_id'=>(string)$id,'action'=>$action])."\n"); fclose($pipes[0]); }
    usleep(150000); $conn->commit();
    $success=0;
    foreach($workers as [$process,$pipes,$action]) {
        $raw=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if(proc_close($process)) throw new RuntimeException('Race worker failed: '.$error);
        $result=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if($result['ok']) { $success++; $winner=$action; }
        else sm_check($result['message']==='Request not found or already processed.','Competing reviewer receives already-decided error');
    }
    sm_check($success===1,'Exactly one concurrent reviewer succeeds');
    $row=rr_snapshot()['residents'][0];
    sm_check(($winner==='approve')===($row['birthdate']==='1992-03-04'),'Stored record agrees with winning decision only');
}
try {
    $state=sm_create($conn); $rid=$state['resident']['id']; $sid=$state['staff']['id'];
    foreach(['staff','admin','resident'] as $role) sm_check(sm_request('login','POST',['identifier'=>$state[$role]['number'],'password'=>'SurveyFixtureA1'],$role)[0]===200,'Fixture login');
    $reads=['address-requests','deletion-requests','deleted-accounts','residency-records'];
    $writes=['address-request/review','staff-address-request/review','family-request/review','deletion-request/review','deleted-account/restore','deleted-account/purge'];
    foreach($reads as $read) {
        sm_check(sm_request('staff/'.$read,'GET',null,'anonymous')[0]===401,'Anonymous read denied');
        sm_check(sm_request('staff/'.$read,'GET',null,'resident')[0]===403,'Resident read denied');
        sm_check(sm_request('staff/'.$read,'GET',null,'staff')[0]===(in_array($read,['deleted-accounts','residency-records'],true)?403:200),'Staff read scope');
        sm_check(sm_request('staff/'.$read,'GET',null,'admin')[0]===200,'Admin read access');
    }
    foreach($writes as $write) {
        sm_check(sm_request('staff/'.$write,'GET',null,'admin')[0]===405,'Mutation requires POST');
        foreach(['anonymous'=>401,'resident'=>403] as $role=>$status) sm_check(sm_request('staff/'.$write,'POST',[],$role)[0]===$status,'Mutation role boundary');
    }
    foreach(['staff-address-request/review','deletion-request/review','deleted-account/restore','deleted-account/purge'] as $write) sm_check(sm_request('staff/'.$write,'POST',[],'staff')[0]===403,'Admin-only mutation denied');
    $proposal=['first_name'=>'Corrected','middle_name'=>'Review','last_name'=>'Fixture','courtesy_title'=>'Dr.','household_number'=>'14','purok'=>'Purok 1','street_highway'=>'Review Street','barangay'=>'Anyatam','municipality'=>'San Ildefonso','postal_code'=>'3010','province'=>'Bulacan'];
    dashboard_sql($conn,"UPDATE residents SET birthdate='1990-01-01', temporary_address='Keep temporary', temp_household_number='45' WHERE resident_id=?",[$rid])->close();
    foreach(['reject','approve'] as $action) {
        $before=rr_snapshot();
        sm_check(sm_request('resident/name-address-requests','POST',$proposal,'resident')[0]===200,'Resident submitted name/address request');
        sm_check(rr_snapshot()['residents']===$before['residents'],'Submission leaves resident unchanged');
        $rows=rr_rows('resident_update_requests','resident_id',$rid); $id=(int)end($rows)['request_id'];
        $state['latest_resident_request']=$id; sm_save($state);
        rr_compare('staff','resident',$id,$action);
        if($action==='reject') sm_check(rr_snapshot()['residents']===$before['residents'],'Rejection leaves complete resident record unchanged');
        else {
            $resident=rr_snapshot()['residents'][0];
            sm_check($resident['first_name']==='Corrected' && $resident['household_number']==='14','Approval writes name/address');
            sm_check($resident['birthdate']==='1990-01-01' && $resident['temporary_address']==='Keep temporary','Uncarried birthdate/temporary address preserved');
        }
        sm_check(sm_request('staff/address-request/review','POST',['request_id'=>(string)$id,'action'=>'reject'],'admin')[0]===400,'Decided request cannot be reviewed again');
    }
    dashboard_sql($conn,"UPDATE residents SET residency_status='temporary', residence_type='BH' WHERE resident_id=?",[$rid])->close();
    $temporary=$proposal+['temp_household_number'=>'77','temp_purok'=>'Purok 2','temp_street_highway'=>'Temporary review street','temp_barangay'=>'Anyatam','temp_municipality'=>'San Ildefonso','temp_postal_code'=>'3010','temp_province'=>'Bulacan'];
    $before=rr_snapshot()['residents'];
    sm_check(sm_request('resident/name-address-requests','POST',$temporary,'resident')[0]===200,'Temporary resident submits both address sets');
    sm_check(rr_snapshot()['residents']===$before,'Temporary proposal leaves both stored addresses unchanged');
    $rows=rr_rows('resident_update_requests','resident_id',$rid); rr_compare('staff','resident',(int)end($rows)['request_id'],'approve');
    sm_check(rr_snapshot()['residents'][0]['temp_household_number']==='77','Carried temporary address applies on approval');
    dashboard_sql($conn,"UPDATE residents SET residency_status='permanent' WHERE resident_id=?",[$rid])->close();
    foreach(['reject','approve'] as $action) {
        $before=rr_snapshot()['residents'];
        sm_check(sm_request('resident/birthdate-requests','POST',['birthdate'=>'1991-02-03'],'resident')[0]===200,'Resident submitted birthdate request');
        sm_check(rr_snapshot()['residents']===$before,'Birthdate proposal leaves record unchanged');
        $rows=rr_rows('resident_update_requests','resident_id',$rid); $id=(int)end($rows)['request_id'];
        rr_compare('admin','resident',$id,$action);
        $row=rr_snapshot()['residents'][0];
        sm_check($row['address']===$before[0]['address'] && $row['first_name']===$before[0]['first_name'],'Birthdate-only review preserves name/address');
    }
    sm_check(sm_request('resident/birthdate-requests','POST',['birthdate'=>'1992-03-04'],'resident')[0]===200,'Resident submits competing-review fixture');
    $rows=rr_rows('resident_update_requests','resident_id',$rid); rr_race((int)end($rows)['request_id']);
    $family=['occupation'=>'New work','hotline_number'=>'','family_reason'=>'Correct household','is_married'=>'1','spouse_first_name'=>'Spouse','spouse_last_name'=>'Fixture','has_children'=>'1','children'=>[['first_name'=>'Child','last_name'=>'Fixture']], 'references'=>[['first_name'=>'Reference','last_name'=>'Fixture']]];
    foreach(['reject','approve'] as $action) {
        $before=rr_snapshot(); sm_check(sm_request('resident/family-requests','POST',$family,'resident')[0]===200,'Resident submitted active family proposal');
        $after=rr_snapshot(); sm_check($before['residents']===$after['residents'] && $before['resident_children']===$after['resident_children'],'Family proposal does not apply before approval');
        $rows=rr_rows('resident_family_requests','resident_id',$rid); $id=(int)end($rows)['request_id'];
        rr_compare($action==='approve'?'admin':'staff','family',$id,$action,'Reviewed');
        if($action==='reject') sm_check(rr_snapshot()['residents']===$before['residents'],'Rejected family leaves row unchanged');
    }
    foreach(['reject','approve'] as $action) {
        $id=rr_insert('staff_update_requests',['staff_id'=>$sid,'staff_name'=>'Survey Management Fixture','staff_number'=>$state['staff']['number'],'address'=>'Proposed staff address','previous_address'=>'Old staff address','household_number'=>'24','barangay'=>'Anyatam','municipality'=>'San Ildefonso','province'=>'Bulacan','reason'=>'Staff correction']);
        if($action==='reject') {
            [$code,$result]=sm_request('staff/staff-address-request/review','POST',['request_id'=>(string)$id,'action'=>'reject'],'admin');
            sm_check($code===400 && $result['message']==='Give a short reason when rejecting a request.','Staff rejection note enforced on server');
        }
        rr_compare('admin','staff',$id,$action,'Decision note');
    }
    $id=rr_insert('resident_family_requests',['resident_id'=>$rid,'payload'=>'invalid','reason'=>'Invalid payload']);
    rr_compare('admin','family',$id,'approve');
    dashboard_sql($conn,'UPDATE resident_family_requests SET payload=? WHERE request_id=?',[json_encode(['occupation'=>'Rollback work','children'=>[],'references'=>[['first_name'=>str_repeat('x',1000),'last_name'=>'Fixture']]]),$id])->close();
    $before=rr_snapshot();
    sm_check(sm_request('staff/family-request/review','POST',['request_id'=>(string)$id,'action'=>'approve'],'admin')[0]===500,'Family row failure contained');
    sm_check(rr_snapshot()===$before,'Failed family application rolls back resident, children, references and pending decision');
    dashboard_sql($conn,'DELETE FROM resident_family_requests WHERE request_id=?',[$id])->close();
    // Archive keeps an actual submitted response; restoration retains deactivation.
    $survey=rr_insert('surveys',['title'=>'Review fixture survey','description'=>'Review fixture','start_date'=>'2020-01-01','end_date'=>'2099-01-01','created_by'=>$sid]);
    $state['surveys'][]=$survey; sm_save($state);
    rr_insert('responses',['resident_id'=>$rid,'survey_id'=>$survey]);
    foreach(['reject','approve'] as $action) {
        sm_check(sm_request('staff/resident/request-delete','POST',['resident_id'=>(string)$rid,'reason'=>'Duplicate fixture'],'staff')[0]===200,'Staff raises deletion request');
        $rows=rr_rows('resident_delete_requests','resident_id',$rid); $id=(int)end($rows)['request_id'];
        if($action==='reject') rr_compare('admin','deletion',$id,'reject');
        else rr_insert('resident_delete_requests',['resident_id'=>$rid,'resident_number'=>$state['resident']['number'],'resident_name'=>'Duplicate Fixture','reason'=>'Concurrent old deletion','requested_by'=>$sid,'requested_by_name'=>'Survey Management Fixture']);
        rr_compare('admin','deletion',$id,$action,'Review decision');
    }
    sm_check(count(rr_rows('responses','resident_id',$rid))===1,'Approved deletion keeps survey response');
    dashboard_sql($conn,'UPDATE residents SET is_active=0 WHERE resident_id=?',[$rid])->close();
    sm_check(sm_request('staff/deleted-account/restore','POST',['kind'=>'residents','id'=>(string)$rid],'admin')[0]===200,'Admin restores resident');
    sm_check(rr_snapshot()['residents'][0]['deleted_at']===null && (int)rr_snapshot()['residents'][0]['is_active']===0,'Restore clears archive only and preserves inactive state');
    $queue=sm_request('staff/deletion-requests','GET',null,'admin')[1]['data']['requests'];
    $r=array_values(array_filter($queue,fn($r)=>(int)$r['request_id']===$id))[0];
    sm_check($r['was_restored'] && !$r['currently_deleted'] && !str_contains($r['review_comment'],'[Account later restored.]'),'Restored current state and cleaned note');
    sm_check(sm_request('staff/deleted-account/purge','POST',['kind'=>'residents','id'=>(string)$rid],'admin')[1]['message']==='That account was not found in the archive.','Unarchived resident cannot be purged');
    rr_insert('resident_residency_changes',['resident_id'=>$rid,'from_status'=>'temporary','to_status'=>'permanent','changed_by'=>$state['admin']['id'],'reason'=>'Historical proof','prev_address'=>'Archived review address','prev_temporary_address'=>'Prior temporary address']);
    rr_insert('resident_residency_changes',['resident_id'=>$rid,'from_status'=>'permanent','to_status'=>'temporary','changed_by'=>$state['admin']['id'],'reason'=>'Reversion proof','prev_address'=>'Restored review address']);
    $all=sm_request('staff/residency-records','GET',null,'admin')[1]['data'];
    foreach(['permanent','temporary','invalid'] as $filter) {
        $data=sm_request('staff/residency-records?filter='.$filter,'GET',null,'admin')[1]['data'];
        sm_check($data['promotions']===$all['promotions'] && $data['reversions']===$all['reversions'],'Log totals remain global');
        sm_check($filter==='invalid' ? $data['filter']==='' : !array_filter($data['changes'],fn($r)=>$r['to_status']!==$filter),'Log filters match source');
    }
    sm_check(sm_request('staff/residency-records','POST',[],'admin')[0]===405,'Residency history has no mutation');
    // Prepare live React flow fixtures; original source comparison runs while fixtures exist.
    dashboard_sql($conn,'UPDATE residents SET is_active=1 WHERE resident_id=?',[$rid])->close();
    $state['review_proposal']=$proposal;
    // Source timestamps have no tie-breaker; deterministic fixture times avoid ambiguous equal-second ordering.
    foreach(['resident_update_requests'=>'submitted_at','resident_family_requests'=>'requested_at','resident_delete_requests'=>'requested_at'] as $table=>$dateKey)
        dashboard_sql($conn,"UPDATE $table SET $dateKey=DATE_ADD('2020-01-01',INTERVAL request_id SECOND) WHERE resident_id=?",[$rid])->close();
    dashboard_sql($conn,"UPDATE staff_update_requests SET submitted_at=DATE_ADD('2020-01-01',INTERVAL request_id SECOND) WHERE staff_id=?",[$sid])->close();
    $state['ui_staff_request']=rr_insert('staff_update_requests',['staff_id'=>$sid,'staff_name'=>'Survey Management Fixture','staff_number'=>$state['staff']['number'],'address'=>'24 Staff Fixture Lane','previous_address'=>'Old staff address','household_number'=>'24','barangay'=>'Anyatam','municipality'=>'San Ildefonso','province'=>'Bulacan','reason'=>'UI staff correction']);
    sm_save($state);
    if(in_array('--react',$argv,true)) {
        passthru('node --test '.escapeshellarg(__DIR__.'/request-review.test.mjs'),$code);
        sm_check($code===0,'Live React request/review/archive/log checks');
    }
    // Permanent delete is exercised only on the disposable archived fixture.
    archive_account($conn,'residents',$rid,$state['admin']['id'],'Review fixture','Purge test');
    sm_check(sm_request('staff/deleted-account/purge','POST',['kind'=>'residents','id'=>(string)$rid],'admin')[0]===200,'Admin purges archived fixture');
    sm_check(!rr_rows('residents','resident_id',$rid) && !rr_rows('responses','resident_id',$rid),'Purge removes account and cascades response');
    $history=sm_request('staff/residency-records','GET',null,'admin')[1]['data']['changes'];
    sm_check(count(array_filter($history,fn($r)=>$r['name']==='Deleted resident #'.$rid))===2,'Historical residency proof survives permanent removal');
    archive_account($conn,'staff',$sid,$state['admin']['id'],'Review fixture','Staff restore test');
    sm_check(sm_request('staff/deleted-account/restore','POST',['kind'=>'staff','id'=>(string)$sid],'admin')[0]===200,'Admin restores staff archive');
    archive_account($conn,'staff',$sid,$state['admin']['id'],'Review fixture','Staff purge test');
    sm_check(sm_request('staff/deleted-account/purge','POST',['kind'=>'staff','id'=>(string)$sid],'admin')[0]===200,'Admin purges archived staff fixture');
    sm_check(sm_request('staff/deleted-account/purge','POST',['kind'=>'staff','id'=>(string)$state['admin']['id']],'admin')[0]===400,'Signed-in admin cannot be purged');
    echo "Passed $checks live request review, PHP parity, authorization, transaction and archive assertions.\n";
} catch(Throwable $error) { $failed=true; fwrite(STDERR,$error->getMessage()."\n"); }
finally {
    if($state) {
        foreach($state['surveys'] as $surveyId) dashboard_sql($conn,'DELETE FROM surveys WHERE survey_id=?',[$surveyId])->close();
        foreach(['resident_delete_requests','resident_residency_changes'] as $table) dashboard_sql($conn,"DELETE FROM $table WHERE resident_id=?",[$state['resident']['id']])->close();
        dashboard_sql($conn,'DELETE FROM staff_update_requests WHERE staff_id=?',[$state['staff']['id']])->close();
        sm_clean($conn,$state);
    }
    foreach(glob($temp.'/*')?:[] as $file) unlink($file); rmdir($temp); session_unset(); session_destroy();
}
exit($failed?1:0);
