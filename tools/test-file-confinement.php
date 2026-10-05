<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
if (PHP_SAPI!=="cli") { http_response_code(404); exit; }   // command line only
// Offline check of the file-manager symlink confinement (safe_path / safe_path_new): php tools/test-file-confinement.php
$src=str_replace("
","
",file_get_contents(__DIR__."/../panel/api/endpoints/files.php"));
preg_match('/function safe_path\(.*?\n}\n/s',$src,$a); preg_match('/function safe_path_new\(.*?\n}\n/s',$src,$b);
eval($a[0].$b[0]);
$t=sys_get_temp_dir().'/fm'.getmypid(); mkdir("$t/home/pub",0777,true); mkdir("$t/other"); file_put_contents("$t/other/secret","x");
symlink("$t/other/secret","$t/home/pub/lnk"); symlink("$t/other/nothere","$t/home/pub/dangling"); symlink("$t/other","$t/home/pub/dirlnk");
$base=realpath("$t/home"); $baseDirPrefix=rtrim($base,'/');
function tryit($f,...$a){ try{ $f(...$a); return 'ALLOWED'; }catch(RuntimeException $e){ return 'refused'; } }
$r=[
 'read via symlink outside'=>tryit('safe_path',$base,'/pub/lnk')=='refused',
 'new via symlink to existing outside'=>tryit('safe_path_new',$base,'/pub/lnk')=='refused',
 'new via dangling symlink'=>tryit('safe_path_new',$base,'/pub/dangling')=='refused',
 'new under symlinked dir outside'=>tryit('safe_path_new',$base,'/pub/dirlnk/new.txt')=='refused',
 'new regular file ok'=>tryit('safe_path_new',$base,'/pub/new.txt')=='ALLOWED',
 'dotdot escape'=>tryit('safe_path_new',$base,'/../other/new')=='refused',
];
foreach($r as $k=>$v) echo ($v?'PASS ':'FAIL ').$k."\n";
exec("rm -rf ".escapeshellarg($t));
