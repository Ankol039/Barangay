<?php
/** Boot first so the unchanged db.php initialization cannot obscure the race. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
session_save_path(sys_get_temp_dir());
require dirname(__DIR__, 2) . '/api/lib/bootstrap.php';
require dirname(__DIR__, 2) . '/api/lib/session.php';
require dirname(__DIR__, 2) . '/api/lib/resident.php';
require dirname(__DIR__, 2) . '/api/lib/profile.php';
$_SESSION = ['resident_id' => (int)$argv[1]];
register_shutdown_function(function (): void { session_unset(); session_destroy(); });
fwrite(STDERR, "ready\n");
$_POST = json_decode(trim(fgets(STDIN)), true);
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
api_resident_birthdate_request($conn);
