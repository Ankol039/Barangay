<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__, 2);
session_save_path(sys_get_temp_dir());
require $root . '/config/db.php'; require $root . '/config/functions.php';
$_SESSION = ['resident_id' => (int)$argv[1], 'resident_name' => 'Profile Fixture'];
$_SERVER['PHP_SELF'] = '/resident/profile.php'; $_SERVER['REQUEST_METHOD'] = 'GET';
chdir($root . '/resident'); require 'profile.php';
session_unset(); session_destroy();
