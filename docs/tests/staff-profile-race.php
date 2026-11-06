<?php
/** CLI-only concurrent profile saves; initialize before the parent takes its row lock. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
session_save_path(sys_get_temp_dir());
$root = dirname(__DIR__, 2);
foreach (['bootstrap','session','profile','staff-dashboard','staff-surveys','staff-resident-actions','staff-profile','staff-profile-save'] as $file) require $root . '/api/lib/' . $file . '.php';
$_SESSION = ['staff_id' => (int)$argv[1], 'staff_role' => 'staff', 'staff_name' => 'Step15 Synthetic New Hire'];
register_shutdown_function(function (): void { session_unset(); session_destroy(); });
fwrite(STDERR, "ready\n");
$_POST = json_decode(trim(fgets(STDIN)), true); $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
api_staff_profile_save($conn, api_staff_profile_user($conn));
