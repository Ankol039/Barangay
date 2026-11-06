<?php
/** CLI-only competing reviewers; initialization finishes before the runner releases them. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
session_save_path(sys_get_temp_dir());
$root=dirname(__DIR__,2);
foreach(['bootstrap','session','staff-dashboard','staff-surveys','staff-resident-actions','request-apply','request-review'] as $file) require $root.'/api/lib/'.$file.'.php';
$id=(int)$argv[1];
$row=$conn->query("SELECT role FROM staff WHERE staff_id=$id")->fetch_assoc();
$_SESSION=['staff_id'=>$id,'staff_role'=>$row['role'],'staff_name'=>'Survey Management Fixture'];
register_shutdown_function(function (): void { session_unset(); session_destroy(); });
fwrite(STDERR,"ready\n");
$_POST=json_decode(trim(fgets(STDIN)),true); $_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';
api_request_review($conn,'resident');
