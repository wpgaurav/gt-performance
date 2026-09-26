<?php
namespace GTPerformance\Core {
	function chmod($path, $mode) { return $GLOBALS['mode'] !== 'permissions-fail' && \chmod($path, $mode); }
	function fwrite($stream, $data) {
		$path=stream_get_meta_data($stream)['uri'];
		clearstatcache(true,$path);
		$GLOBALS['write_event']=['suffix'=>pathinfo($path,PATHINFO_EXTENSION),'permissions'=>fileperms($path)&0777,'empty'=>filesize($path)===0];
		return \fwrite($stream, $GLOBALS['mode']==='short-write' ? substr($data,0,8) : $data);
	}
	function fflush($stream) { return $GLOBALS['mode'] !== 'flush-fail' && \fflush($stream); }
	function fclose($stream) { $ok=\fclose($stream); return $GLOBALS['mode'] !== 'close-fail' && $ok; }
	function rename($from,$to) {
		if (str_ends_with($from,'.php') && $GLOBALS['target']==='config') {
			$GLOBALS['temporary_output']=(string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($from));
		}
		return $GLOBALS['mode'] !== 'rename-fail' && \rename($from,$to);
	}
}
namespace {
	$mode=$argv[1]; $target=$argv[2];
	$root=sys_get_temp_dir().'/gtperf-atomic-'.bin2hex(random_bytes(6));
	mkdir($root.'/wp-content',0700,true);
	define('ABSPATH',$root.'/');define('WP_CONTENT_DIR',$root.'/wp-content');define('WP_CACHE',true);
	require dirname(__DIR__).'/bootstrap.php';
	$original='<?php /* original fixture */';
	$path=$root.'/wp-config.php';
	file_put_contents($path,$original);
	if ($target==='page') { $path=WP_CONTENT_DIR.'/advanced-cache.php'; $original='<?php /* GT Performance advanced-cache drop-in */'; }
	if ($target==='redis') { $path=WP_CONTENT_DIR.'/object-cache.php'; $original='<?php /* GT Performance Redis object-cache drop-in */'; }
	file_put_contents($path,$original);
	if ($target==='config') {
		$result=(new ReflectionMethod(\GTPerformance\Cache\WpCacheConstant::class,'publish'))->invoke(new \GTPerformance\Cache\WpCacheConstant(),$path,"<?php define('DB_PASSWORD', 'dummy-fixture-secret');\n");
	} elseif ($target==='page') {
		$result=(new \GTPerformance\Cache\DropinInstaller())->install();
	} else {
		$result=(new ReflectionMethod(\GTPerformance\Redis\ObjectCacheInstaller::class,'publish'))->invoke(new \GTPerformance\Redis\ObjectCacheInstaller());
	}
	$left=[];
	$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
	foreach($files as $file) if(str_contains($file->getFilename(),'.gtperf-')) $left[]=$file->getFilename();
	echo json_encode(['success'=>$result===true,'preserved'=>file_get_contents($path)===$original,'left'=>$left,'write'=>$write_event??null,'temporary_output'=>$temporary_output??null]);
	foreach($files as $file) ($file->isLink()||!$file->isDir())?unlink($file->getPathname()):rmdir($file->getPathname());
	rmdir($root);
}
