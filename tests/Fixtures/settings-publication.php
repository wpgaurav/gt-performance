<?php
namespace GTPerformance\Cache {
	function chmod($path,$mode) {
		++$GLOBALS['writes'];
		$fail=$GLOBALS['failure'];
		return !(($fail==='page'||$fail==='read-only')&&$GLOBALS['writes']===1 || $fail==='redis'&&$GLOBALS['writes']===2) && \chmod($path,$mode);
	}
}
namespace GTPerformance\Core {
	function wp_delete_file($path) { if($GLOBALS['failure']!=='read-only') \wp_delete_file($path); }
}
namespace {
	function update_option(string $name,mixed $value,bool $autoload=false): bool {
		if($name==='gt_performance_settings' && $GLOBALS['failure']==='database') return false;
		$GLOBALS['gtperf_test_options'][$name]=$value;
		return !($name==='gt_performance_settings' && $GLOBALS['failure']==='same-db');
	}
	$root=sys_get_temp_dir().'/gtperf-settings-publication-'.bin2hex(random_bytes(6));
	mkdir($root.'/wp-content',0700,true);define('ABSPATH',$root.'/');define('WP_CONTENT_DIR',$root.'/wp-content');
	require dirname(__DIR__).'/bootstrap.php';
	$failure='';$writes=0;
	$settings=\GTPerformance\Core\Settings::defaults();$settings['cache']['enabled']=true;
	\GTPerformance\Core\Settings::save($settings);
	$old=\GTPerformance\Core\Settings::all();
	$failure=$argv[1];$writes=0;
	$settings['cache']['enabled']=false;
	if(($argv[2]??'')==='admin') {
		$next=(new \GTPerformance\Admin\AdminModule())->sanitize($settings);
		$success=empty($GLOBALS['gtperf_test_settings_errors']);
		update_option(\GTPerformance\Core\Settings::OPTION,$next);
	} else $success=\GTPerformance\Core\Settings::save($settings);
	$runtime=\GTPerformance\Cache\ConfigFile::read(\GTPerformance\Core\Paths::config());
	echo json_encode(['success'=>$success,'database_unchanged'=>$old===\GTPerformance\Core\Settings::all(),'runtime_enabled'=>$runtime['cache']['enabled']??null,'redis_present'=>is_file(\GTPerformance\Core\Paths::redisConfig()),'error'=>get_option(\GTPerformance\Core\Settings::CONFIG_ERROR,false),'admin_error'=>!empty($GLOBALS['gtperf_test_settings_errors'])]);
	$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
	foreach($files as $file) ($file->isLink()||!$file->isDir())?unlink($file->getPathname()):rmdir($file->getPathname());
	rmdir($root);
}
