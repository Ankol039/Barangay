<?php
/** CLI-only visual fixtures; every inserted ID is registered for bounded cleanup. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/resident-dashboard-fixtures.php';
session_save_path(sys_get_temp_dir());
require dirname(__DIR__, 2) . '/config/db.php';
require dirname(__DIR__, 2) . '/config/functions.php';
$file = sys_get_temp_dir() . '/barangay-visual-audit.json';
$state = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : ['rows'=>[], 'accounts'=>[]];
function va_save(): void { global $file,$state; file_put_contents($file,json_encode($state, JSON_THROW_ON_ERROR)); chmod($file,0600); }
function va_insert(string $table, string $key, array $values): int {
    global $conn,$state;
    $stmt=dashboard_sql($conn,'INSERT INTO '.$table.' (`'.implode('`,`',array_keys($values)).'`) VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
    $id=$stmt->insert_id; $stmt->close(); $state['rows'][]=[$table,$key,$id]; va_save(); return $id;
}
try {
    if (($argv[1]??'')==='clean') {
        foreach ($state['accounts'] as $account) dashboard_sql($conn,'DELETE FROM login_history WHERE user_type=? AND user_id=?',[$account['type'],$account['id']])->close();
        foreach (array_reverse($state['rows']) as [$table,$key,$id]) dashboard_sql($conn,"DELETE FROM `$table` WHERE `$key`=?",[$id])->close();
        if (is_file($file)) unlink($file);
        echo "Cleaned registered visual fixtures and login history.\n";
    } elseif (($argv[1]??'')==='create') {
        if (is_file($file)) throw new RuntimeException('Clean previous visual fixtures first.');
        va_save();
        $hash=password_hash('VisualFixtureA1',PASSWORD_DEFAULT); $n=random_int(1000000,9999900);
        foreach (['resident','temporary','empty','residentFirst','staff','admin','staffFirst','staffProfile'] as $label) {
            $staff=str_starts_with($label,'staff')||$label==='admin'; $number=($staff?'S':'R').'-2095-'.$n++;
            $values=['password'=>$hash,'is_active'=>1,'is_first_login'=>str_ends_with($label,'First')?1:0];
            if ($staff) $values+=['staff_number'=>$number,'username'=>$number,'full_name'=>'Visual Audit Fixture','first_name'=>'Visual','last_name'=>'Audit Fixture','role'=>$label==='admin'?'admin':'staff','profile_completed'=>in_array($label,['staffFirst','staffProfile'],true)?0:1,'date_hired'=>'2024-01-15'];
            else $values+=['resident_number'=>$number,'first_name'=>'Visual','middle_name'=>'Test','last_name'=>'Audit Fixture','residency_status'=>$label==='temporary'?'temporary':'permanent','residence_type'=>$label==='temporary'?'BH':'','residency_start_date'=>'2024-01-01','birthdate'=>'1994-04-12','gender'=>'female','birthplace'=>'Test City','occupation'=>'Health Worker','is_married'=>0,'has_children'=>0];
            if (!in_array($label,['empty','residentFirst','staffFirst','staffProfile','admin'],true)) $values+=['contact_number'=>'09123456789','email'=>'visual@example.test','household_number'=>'12','purok'=>'Purok 2','street_highway'=>'Test Street','barangay'=>'Test Barangay','municipality'=>'Test City','province'=>'Test Province','postal_code'=>'1000','address'=>'12, Purok 2, Test Street, Test Barangay, Test City, 1000, Test Province'];
            if ($label==='temporary') $values+=['temp_household_number'=>'34','temp_barangay'=>'Temporary Barangay','temp_municipality'=>'Temporary City','temp_province'=>'Temporary Province','temporary_address'=>'34, Temporary Barangay, Temporary City, Temporary Province'];
            $id=va_insert($staff?'staff':'residents',$staff?'staff_id':'resident_id',$values);
            $state['accounts'][$label]=['id'=>$id,'number'=>$number,'type'=>$staff?'staff':'resident']; va_save();
            if ($label==='resident') va_insert('resident_character_references','reference_id',['resident_id'=>$id,'first_name'=>'Sample','last_name'=>'Reference','gender'=>'male','display_order'=>0]);
        }
        foreach (['answered','available'] as $kind) {
            $sid=va_insert('surveys','survey_id',['title'=>'Visual Audit Health Services','description'=>'Help us improve barangay health services.','start_date'=>date('Y-m-d',strtotime('-10 days')),'end_date'=>date('Y-m-d',strtotime('+20 days')),'status'=>'active','created_by'=>$state['accounts']['staff']['id']]);
            $state[$kind]=$sid; va_save();
            $response=$kind==='answered'?va_insert('responses','response_id',['resident_id'=>$state['accounts']['resident']['id'],'survey_id'=>$sid,'submitted_at'=>'2026-11-01 09:30:00']):null;
            if ($response) { $state['response']=$response; va_save(); }
            foreach (['multiple_choice'=>'Which service did you use?','yesno'=>'Was your visit helpful?','rating'=>'How would you rate our service?','short_answer'=>'What could we improve?'] as $type=>$text) {
                $qid=va_insert('survey_questions','question_id',['survey_id'=>$sid,'question_text'=>$text,'question_type'=>$type,'question_order'=>array_search($type,['multiple_choice','yesno','rating','short_answer'])+1]);
                $cid=null;
                if ($type==='multiple_choice') foreach (['Consultation','Vaccination','Other service'] as $choice) { $c=va_insert('survey_choices','choice_id',['question_id'=>$qid,'choice_text'=>$choice]); $cid??=$c; }
                if ($response) va_insert('survey_results','answer_id',['response_id'=>$response,'question_id'=>$qid]+match($type){'multiple_choice'=>['choice_id'=>$cid],'yesno'=>['answer_text'=>'Yes'],'rating'=>['rating_value'=>4],default=>['answer_text'=>"More appointment times.\nThank you for your help."]});
            }
        }
        $rid=$state['accounts']['resident']['id']; $staff=$state['accounts']['staff'];
        va_insert('resident_update_requests','request_id',['resident_id'=>$rid,'first_name'=>'Visual Corrected','last_name'=>'Audit Fixture','household_number'=>'15','barangay'=>'New Barangay','municipality'=>'Test City','province'=>'Test Province','address'=>'15, New Barangay, Test City, Test Province','status'=>'pending']);
        va_insert('staff_update_requests','request_id',['staff_id'=>$staff['id'],'staff_number'=>$staff['number'],'staff_name'=>'Visual Audit Fixture','household_number'=>'16','barangay'=>'New Barangay','municipality'=>'Test City','province'=>'Test Province','address'=>'16, New Barangay, Test City, Test Province','previous_address'=>'12, Test Barangay','reason'=>'Moved to a new address','status'=>'pending']);
        va_insert('resident_family_requests','request_id',['resident_id'=>$rid,'payload'=>json_encode(['occupation'=>'Teacher','is_married'=>0,'has_children'=>0,'references'=>[['first_name'=>'Sample','last_name'=>'Reference']]]),'reason'=>'Updated employment details','status'=>'pending']);
        va_insert('resident_delete_requests','request_id',['resident_id'=>$state['accounts']['temporary']['id'],'resident_number'=>$state['accounts']['temporary']['number'],'resident_name'=>'Visual Test Audit Fixture','reason'=>'Duplicate test record for visual review','requested_by'=>$staff['id'],'requested_by_name'=>'Visual Audit Fixture','status'=>'pending']);
        va_insert('resident_residency_changes','change_id',['resident_id'=>$rid,'from_status'=>'temporary','to_status'=>'permanent','reason'=>'Completed residency period','changed_by'=>$state['accounts']['admin']['id'],'prev_address'=>'12, Test Barangay','prev_temporary_address'=>'34, Temporary Barangay','prev_residence_type'=>'BH']);
        $archived=va_insert('residents','resident_id',['resident_number'=>'R-2095-'.$n++,'first_name'=>'Archived','last_name'=>'Visual Fixture','password'=>$hash,'deleted_at'=>date('Y-m-d H:i:s'),'deleted_by_name'=>'Visual Audit Fixture','delete_reason'=>'Visual archive state']);
        va_insert('staff','staff_id',['staff_number'=>'S-2095-'.$n,'username'=>'S-2095-'.$n,'full_name'=>'Archived Visual Fixture','password'=>$hash,'role'=>'staff','deleted_at'=>date('Y-m-d H:i:s'),'deleted_by_name'=>'Visual Audit Fixture','delete_reason'=>'Visual archive state']);
        echo "Created registered visual fixtures.\n";
    } else throw new RuntimeException('Use create or clean.');
} catch (Throwable $error) { fwrite(STDERR,$error instanceof mysqli_sql_exception?"Visual fixture database operation failed.\n":$error->getMessage()."\n"); exit(1); }
finally { session_unset(); session_destroy(); }
