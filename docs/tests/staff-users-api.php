<?php
/** Self-cleaning integration/parity check against the existing XAMPP database. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/staff-surveys-fixtures.php';
$temp = sys_get_temp_dir() . '/barangay-staff-users-' . bin2hex(random_bytes(6)); mkdir($temp, 0700); session_save_path($temp);
require dirname(__DIR__, 2) . '/config/db.php'; require dirname(__DIR__, 2) . '/config/functions.php';
$checks = 0; $state = null; $failed = false;
function su_check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function su_process(array $args, string $input = ''): string {
    $p = proc_open($args, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0], $input); fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p)) throw new RuntimeException('Process failed: ' . $err); return $out;
}
function su_request(string $path, string $method = 'GET', ?array $body = null, string $jar = 'admin', ?string $file = null, string $filename = 'photo.png'): array {
    global $temp;
    $args = ['curl','-sS','--max-time','15','-X',$method,'-b',"$temp/$jar.cookies",'-c',"$temp/$jar.cookies",'-w',"\n%{http_code}",'http://localhost/barangay_survey_system/api/'.$path];
    if ($file !== null) array_push($args, '-F', 'profile_photo=@'.$file.';filename='.$filename);
    elseif ($body !== null) array_push($args,'-H','Content-Type: application/json','--data-binary','@-');
    $out = su_process($args, $body === null ? '' : json_encode($body)); $split = strrpos($out,"\n"); $raw=substr($out,0,$split);
    su_check(!str_contains($raw,'$2y$') && !preg_match('/"password"\s*:/',$raw), 'No hashes/passwords in response');
    return [(int)substr($out,$split+1),json_decode($raw,true,512,JSON_THROW_ON_ERROR)];
}
function su_row(int $id): array {
    global $conn; $stmt=dashboard_sql($conn,'SELECT * FROM staff WHERE staff_id=?',[$id]); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return $row;
}
function su_oracle(int $id, string $page, array $input): array {
    return json_decode(su_process(['/Applications/XAMPP/xamppfiles/bin/php',__DIR__.'/staff-users-reference.php',(string)$id,$page],json_encode($input)),true,512,JSON_THROW_ON_ERROR);
}
function su_restore(int $id,array $row): void {
    global $conn; unset($row['staff_id']); dashboard_sql($conn,'UPDATE staff SET '.implode(',',array_map(fn($k)=>"`$k`=?",array_keys($row))).' WHERE staff_id=?',[...array_values($row),$id])->close();
    dashboard_sql($conn,'DELETE FROM staff_update_requests WHERE staff_id=?',[$id])->close();
}
function su_profile_race(int $id, array $profile): void {
    global $conn;
    $workers=[];
    for($i=0;$i<2;$i++) {
        $p=proc_open([PHP_BINARY,__DIR__.'/staff-profile-race.php',(string)$id],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        su_check(trim(fgets($pipes[2]))==='ready','Profile race worker initialized');$workers[]=[$p,$pipes];
    }
    $conn->begin_transaction();dashboard_sql($conn,'SELECT staff_id FROM staff WHERE staff_id=? FOR UPDATE',[$id])->close();
    foreach($workers as $i=>[$p,$pipes]) {fwrite($pipes[0],json_encode(array_replace($profile,['household_number'=>'Concurrent '.($i+1),'reason'=>'Concurrent fixture correction']))."\n");fclose($pipes[0]);}
    $waiting=0;
    for($attempt=0;$attempt<100;$attempt++) {
        $waiting=0;
        foreach($conn->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $process) {
            $sql=$process['Info']??'';
            if(str_contains($sql,'SELECT * FROM staff WHERE staff_id =') && str_contains($sql,'FOR UPDATE')) $waiting++;
        }
        if($waiting>=2) break; usleep(50000);
    }
    $conn->commit();$outcomes=[];
    foreach($workers as [$p,$pipes]) {
        $raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        su_check(proc_close($p)===0,'Concurrent save worker exits cleanly');
        $result=json_decode($raw,true,512,JSON_THROW_ON_ERROR);su_check($result['ok'],'Concurrent save succeeds');$outcomes[]=$result['outcome'];
    }
    sort($outcomes);
    $stmt=dashboard_sql($conn,"SELECT COUNT(*) AS n FROM staff_update_requests WHERE staff_id=? AND status='pending'",[$id]);$count=(int)$stmt->get_result()->fetch_assoc()['n'];$stmt->close();
    su_check($waiting>=2 && $outcomes===['normal','requested'] && $count===1,'Two contending saves create exactly one pending address request');
}
try {
    $state=sm_create($conn); $aid=$state['admin']['id']; $sid=$state['staff']['id'];
    foreach(['admin','staff','resident'] as $role) su_check(su_request('login','POST',['identifier'=>$state[$role]['number'],'password'=>'SurveyFixtureA1'],$role)[0]===200,'Fixture login');
    su_check(su_request('staff/users','GET',null,'anonymous')[0]===401,'Anonymous denied');
    su_check(su_request('staff/profile','GET',null,'resident')[0]===403,'Resident profile denied');
    $staffData=su_request('staff/users','GET',null,'staff')[1]['data'];
    su_check(!$staffData['is_admin'] && !$staffData['staff'] && $staffData['pending_deletions']===0,'Staff cannot read roster/admin counts');
    $create=['first_name'=>'Step15','middle_name'=>'Synthetic','last_name'=>'New Hire','name_extension'=>'Jr.','courtesy_title'=>'Others','courtesy_title_other'=>'Dr.','date_hired'=>date('Y-m-d'),'role'=>'admin','username'=>'ignored','password'=>'ignored'];
    su_check(su_request('staff/users','POST',$create,'staff')[0]===403,'Staff creation denied');
    foreach(['toggle','delete','password-reset'] as $action) {
        $input=['staff_id'=>(string)$sid];
        $legacy=['toggle'=>'toggle_staff','delete'=>'delete_staff','password-reset'=>'reset_staff'][$action];
        $expected=su_oracle($sid,'user_management',['get'=>[$legacy=>$aid]])['error'];
        $res=su_request('staff/user/'.$action,'POST',['staff_id'=>(string)$aid],'staff');
        su_check($res[0]===403 && $res[1]['message']===$expected,'Role denial matches PHP '.$action);
        $expected=su_oracle($aid,'user_management',['get'=>[$legacy=>$aid]])['error'];
        su_check(su_request('staff/user/'.$action,'POST',['staff_id'=>(string)$aid])[1]['message']===$expected,'Self guard matches PHP '.$action);
    }
    foreach([['first_name'=>''],['date_hired'=>date('Y-m-d',strtotime('+1 day'))]] as $bad) {
        $input=array_replace($create,$bad); $expected=su_oracle($aid,'user_management',['post'=>$input+['add_staff'=>'1']])['error'];
        su_check(su_request('staff/users','POST',$input)[1]['message']===$expected,'Create validation matches PHP');
    }
    [$code,$created]=su_request('staff/users','POST',$create); su_check($code===200,'Admin creates staff');
    $number=$created['data']['new_staff']['staff_number'];
    $stmt=dashboard_sql($conn,'SELECT staff_id FROM staff WHERE staff_number=?',[$number]); $nid=(int)$stmt->get_result()->fetch_assoc()['staff_id'];$stmt->close();
    $state['new_staff']=['id'=>$nid,'number'=>$number]; $state['created_ids']=[$nid]; sm_save($state);
    $row=su_row($nid);
    su_check($row['role']==='staff' && $row['username']===$number && password_verify($number,$row['password']),'Fixed role and issued username/temporary password');
    su_check((int)$row['is_first_login']===1 && (int)$row['profile_completed']===0 && (int)$row['created_by']===$aid,'New staff flags and creator');
    su_check($created['data']['new_staff']['full_name']==='Dr. Step15 Synthetic New Hire Jr.','Courtesy full name on slip');
    su_check(!isset(su_request('staff/users')[1]['data']['new_staff']),'Slip is one-time creation response only');
    $login=su_request('login','POST',['identifier'=>$number,'password'=>$number],'new')[1];
    su_check($login['redirect']==='/staff/change-password' && !$login['data']['user']['show_sidebar'],'Temporary login begins step 1 without navigation');
    su_check(su_request('staff/users','GET',null,'new')[1]['redirect']==='/staff/change-password','Roster gated during step 1');
    su_check(su_request('staff/profile','GET',null,'new')[1]['data']['onboarding'],'Profile retains source onboarding exemption');
    $changed=su_request('change-password','POST',['current_password'=>$number,'new_password'=>'OnboardFixtureA1','confirm_password'=>'OnboardFixtureA1'],'new')[1];
    su_check($changed['redirect']==='/staff/complete-profile' && !$changed['data']['user']['is_first_login'],'Password change enters step 2');
    su_check(su_request('staff/users','GET',null,'new')[1]['redirect']==='/staff/complete-profile','Roster gated during step 2');
    $profile=['contact_number'=>'0917 123 4567','email'=>'fixture@example.test','birthdate'=>'1995-03-15','gender'=>'female','license_number'=>'PRC-123/456','emergency_contact_name'=>'Synthetic Contact','emergency_contact_number'=>'0918 123 4567','household_number'=>'44','purok'=>'Purok 3','street_highway'=>'Fixture Lane','barangay'=>'Sapang Puke','municipality'=>'Malolos','postal_code'=>'3000','province'=>'Bulacan','reason'=>''];
    $before=su_row($nid);
    $badInputs=[['contact_number'=>''],['contact_number'=>'abc'],['email'=>'bad'],['birthdate'=>date('Y-m-d')],['license_number'=>str_repeat('a',51)],['license_number'=>'bad@'],['emergency_contact_number'=>'bad'],['household_number'=>''],['postal_code'=>'123']];
    foreach($badInputs as $bad) {
        $input=array_replace($profile,$bad); $expected=su_oracle($nid,'complete_profile',['post'=>$input])['error'];
        $actual=su_request('staff/profile','POST',$input,'new');
        su_check($actual[0]===400 && $actual[1]['message']===$expected,'Profile validation matches PHP: '.array_key_first($bad));
        su_check(su_row($nid)===$before,'Invalid profile leaves record intact');
    }
    su_oracle($nid,'complete_profile',['post'=>$profile]); $expected=su_row($nid); su_restore($nid,$before);
    $saved=su_request('staff/profile','POST',$profile,'new')[1];
    su_check($saved['outcome']==='first' && !$saved['data']['onboarding'],'Initial profile finishes onboarding');
    su_check(su_row($nid)===$expected,'Initial profile record equals original PHP');
    su_check(su_row($nid)['emergency_contact']==='Synthetic Contact - 0918 123 4567','Legacy emergency contact synchronized');
    $session=su_request('session','GET',null,'new')[1]; su_check($session['redirect']==='/staff/dashboard' && $session['data']['user']['show_sidebar'],'Full portal access after setup');
    su_check(su_request('staff/users','GET',null,'new')[1]['data']['is_admin']===false,'New hire gets staff-only user view');
    su_request('logout','POST',[],'new');
    su_check(su_request('login','POST',['identifier'=>$number,'password'=>$number],'oldtemp')[0]===401,'Temporary password no longer works');
    su_check(su_request('login','POST',['identifier'=>$number,'password'=>'OnboardFixtureA1'],'new')[1]['redirect']==='/staff/dashboard','New password login goes to dashboard');
    $before=su_row($nid); su_profile_race($nid,$profile); su_restore($nid,$before);
    $before=su_row($nid); $request=array_replace($profile,['household_number'=>'45','contact_number'=>'0919 123 4567','reason'=>'Moved household']);
    su_check(su_request('staff/profile','POST',array_replace($request,['reason'=>'']),'new')[1]['message']==='Give a short reason for the address change so the administrator can act on it.','Later address change requires reason');
    su_oracle($nid,'complete_profile',['post'=>$request]); $expected=su_row($nid);
    $stmt=dashboard_sql($conn,'SELECT * FROM staff_update_requests WHERE staff_id=?',[$nid]);$expectedRequest=$stmt->get_result()->fetch_assoc();$stmt->close();su_restore($nid,$before);
    $rollback=su_request('staff/profile','POST',array_replace($request,['household_number'=>str_repeat('X',300)]),'new');
    su_check($rollback[0]===500 && su_row($nid)===$before,'Failed request insert rolls back personal detail updates');
    $saved=su_request('staff/profile','POST',$request,'new')[1];
    su_check($saved['outcome']==='requested' && $saved['data']['pending_request']!==null,'Later address change queues request');
    su_check(su_row($nid)===$expected && su_row($nid)['address']===$before['address'],'Contact saves, address unchanged, PHP parity');
    $stmt=dashboard_sql($conn,'SELECT * FROM staff_update_requests WHERE staff_id=?',[$nid]);$actualRequest=$stmt->get_result()->fetch_assoc();$requestId=$actualRequest['request_id'];$stmt->close();
    foreach(['request_id','submitted_at'] as $key) {unset($actualRequest[$key],$expectedRequest[$key]);}
    su_check($actualRequest===$expectedRequest,'Address proposal equals PHP');
    $saved=su_request('staff/profile','POST',['contact_number'=>'0920 123 4567'],'new')[1];
    su_check($saved['outcome']==='normal' && su_row($nid)['address']===$before['address'],'Pending address locks fields while contact saves');
    $stmt=dashboard_sql($conn,'SELECT COUNT(*) AS n FROM staff_update_requests WHERE staff_id=? AND status=?',[$nid,'pending']);su_check((int)$stmt->get_result()->fetch_assoc()['n']===1,'Only one pending request');$stmt->close();
    su_check(su_request('staff/staff-address-request/review','POST',['request_id'=>(string)$requestId,'action'=>'approve'],'new')[0]===403,'Staff cannot approve own address proposal');
    su_check(su_request('staff/staff-address-request/review','POST',['request_id'=>(string)$requestId,'action'=>'approve'])[0]===200,'Administrator approves new staff address');
    su_check(su_row($nid)['household_number']==='45' && su_row($nid)['contact_number']==='0920 123 4567','Approval changes address and preserves latest contact');
    $adminBefore=su_row($aid);su_check(su_request('staff/profile','POST',$profile)[0]===403 && su_row($aid)===$adminBefore,'Admin record read-only');
    $photo="$temp/photo.png";file_put_contents($photo,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lS0AAAAASUVORK5CYII='));
    su_check(su_request('staff/photo','POST',null,'new')[1]['message']==='Please select a photo to upload.','Missing photo rejected');
    su_check(su_request('staff/photo','POST',null,'new',$photo,'photo.txt')[1]['message']==='Only JPG, PNG, GIF, or WEBP images are allowed.','Photo extension checked');
    $large="$temp/large.png";file_put_contents($large,file_get_contents($photo).str_repeat('x',5*1024*1024));
    su_check(su_request('staff/photo','POST',null,'new',$large)[1]['message']==='Please choose a photo under 5 MB.','Staff 5 MB limit');
    $uploaded=su_request('staff/photo','POST',null,'new',$photo)[1];$old=$uploaded['data']['account']['photo_path'];
    su_check($uploaded['outcome']==='photo' && is_file(dirname(__DIR__,2).'/'.$old),'Staff image uploads');
    $uploaded=su_request('staff/photo','POST',null,'new',$photo)[1];$new=$uploaded['data']['account']['photo_path'];clearstatcache();
    su_check($old!==$new && !is_file(dirname(__DIR__,2).'/'.$old),'Replacement removes previous file');
    su_request('staff/photo/remove','POST',[],'new');clearstatcache();su_check(!su_row($nid)['photo_path'] && !is_file(dirname(__DIR__,2).'/'.$new),'Removal clears record and file');
    // Same target reset/toggle/archive outcomes as the original page, with snapshot restore.
    foreach(['toggle'=>'toggle_staff','password-reset'=>'reset_staff','delete'=>'delete_staff'] as $action=>$legacy) {
        $before=su_row($nid); $expectedMessage=su_oracle($aid,'user_management',['get'=>[$legacy=>$nid]])['message'];$expected=su_row($nid);su_restore($nid,$before);
        $result=su_request('staff/user/'.$action,'POST',['staff_id'=>(string)$nid]);$actual=su_row($nid);
        unset($actual['password'],$expected['password']); if(isset($expected['deleted_at']) && $expected['deleted_at']) {$expected['deleted_at']=true;$actual['deleted_at']=$actual['deleted_at']!==null;}
        su_check($result[0]===200 && $result[1]['message']===$expectedMessage,'Action message matches PHP '.$action);
        su_check($actual===$expected,'Action record matches PHP '.$action);
        if($action==='password-reset') su_check(password_verify($number,su_row($nid)['password']),'Reset restores number hash');
        su_restore($nid,$before);
    }
    $input=['resident_id'=>(string)$state['resident']['id']];
    su_check(su_request('staff/user/resident-toggle','POST',$input,'staff')[1]['message']==='Resident account deactivated.','User page resident toggle exact message');
    su_check(su_request('staff/user/resident-toggle','POST',$input,'staff')[1]['message']==='Resident account reactivated.','User page resident reactivation');
    // Live React checks run inside this finally-protected fixture lifetime.
    if(in_array('--react',$argv,true)) { passthru('node --test '.escapeshellarg(__DIR__.'/staff-users.test.mjs'),$code);su_check($code===0,'Live React onboarding and user management'); }
    echo "Passed $checks live staff user/profile, source parity, onboarding, authorization and photo assertions.\n";
} catch(Throwable $error) { $failed=true;fwrite(STDERR,$error->getMessage()."\n"); }
finally {
    if($state) {
        // Delete only explicitly tracked synthetic IDs, never a creator-wide set.
        $registered=is_file(sm_file()) ? json_decode(file_get_contents(sm_file()),true) : $state;
        foreach(array_unique(array_merge($state['created_ids']??[], $registered['created_ids']??[])) as $createdId) {
            $stmt=dashboard_sql($conn,'SELECT staff_id, photo_path FROM staff WHERE staff_id=? AND created_by=? AND first_name IN (?,?)',[$createdId,$state['admin']['id'],'Step15','Step15React']);
            $row=$stmt->get_result()->fetch_assoc();$stmt->close(); if(!$row) continue;
            if($row['photo_path'] && is_file(dirname(__DIR__,2).'/'.$row['photo_path'])) unlink(dirname(__DIR__,2).'/'.$row['photo_path']);
            dashboard_sql($conn,'DELETE FROM staff_update_requests WHERE staff_id=?',[$createdId])->close();
            dashboard_sql($conn,"DELETE FROM login_history WHERE user_type='staff' AND user_id=?",[$createdId])->close();
            dashboard_sql($conn,'DELETE FROM staff WHERE staff_id=? AND created_by=? AND first_name IN (?,?)',[$createdId,$state['admin']['id'],'Step15','Step15React'])->close();
        }
        sm_clean($conn,$state);
    }
    foreach(glob($temp.'/*')?:[] as $file) unlink($file);rmdir($temp);session_unset();session_destroy();
}
exit($failed?1:0);
