<?php
/** Offline-provider checks inside a real, disposable local WordPress site. */
use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\Purger;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Cloudflare\CloudflareModule;
use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\PurgeVerifier;

if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost','127.0.0.1'], true)) {
    throw new RuntimeException('Use only an isolated local WordPress site.');
}
$checks=[];
$check=static function(bool $ok,string $name) use (&$checks):void {
    if (!$ok) { throw new RuntimeException($name); }
    $checks[]=$name;
};
$saved=[];
foreach ([CloudflareModule::STATUS_OPTION,'gt_performance_purge_receipts','cron'] as $key) { $saved[$key]=get_option($key,null); }
$settings=Settings::defaults();
$settings['cloudflare']['enabled']=true;
$settings['cloudflare']['zone_id']='local-fixture-zone';
$settings['cloudflare']['api_token']=(new TokenCipher())->encrypt('not-a-real-token');
$settings['cache']['separate_mobile']=true;
$settings['xcloud']['enabled']=false;
$settings['cache']['preload']=false;
$override=static function() use ($settings) { return $settings; };
add_filter('pre_option_'.Settings::OPTION,$override);
$requests=[];$apiStatus=200;
$mock=static function($pre,$args,$url) use (&$requests,&$apiStatus) {
    if (str_contains($url,'api.cloudflare.com')) {
        $requests[]=['kind'=>'purge','body'=>json_decode($args['body']??'{}',true)];
        return ['response'=>['code'=>$apiStatus],'headers'=>['retry-after'=>'120'],'body'=>wp_json_encode(['success'=>200===$apiStatus,'errors'=>[['message'=>'Local fixture failure']]])];
    }
    if (str_contains($url,'/gtperf-cloudflare-fixture/')) {
        $requests[]=['kind'=>'fetch'];
        return ['response'=>['code'=>200],'headers'=>['cf-cache-status'=>'MISS'],'body'=>'<html><body>Fixture</body></html>'];
    }
    return new WP_Error('fixture_external_request','External network disabled in integration fixture.');
};
add_filter('pre_http_request',$mock,PHP_INT_MIN,3);
try {
    $url=home_url('/gtperf-cloudflare-fixture/');
    $config=$settings['cache'];$config['generation']=$settings['generation'];
    $key=new CacheKey();$store=new FileStore();
    $desktop=$key->hash($key->make(RequestContext::fromUrl($url),$config));
    $mobile=$key->hash($key->make(RequestContext::fromUrl($url,[],[],'GT Performance Mobile'),$config));
    $control=$key->hash($key->make(RequestContext::fromUrl(home_url('/gtperf-control-fixture/')),$config));
    foreach ([$desktop,$mobile,$control] as $hash) {
        $check($store->write($hash,'<html>Cached fixture</html>',['fresh_until'=>time()+120,'stale_until'=>time()+300]),'Cache fixture write '.substr($hash,0,8));
    }
    $purger=new Purger();$purger->purgeUrl($url);
    $check(!is_file($store->pagePath($desktop))&&!is_file($store->pagePath($mobile))&&is_file($store->pagePath($control)),'Only targeted desktop and mobile origin entries removed');
    $check([]===$requests,'Native action queues Cloudflare until explicit flush');
    $check(true===$purger->flushEdge(),'Native explicit edge flush succeeds');
    $check(4===count($requests[0]['body']['files']),'Native module sends ordinary and three device keys');
    $apiStatus=429;$purger->purgeUrl($url);
    $check(is_wp_error($purger->flushEdge()),'Rate limit is propagated to caller');
    $receipt=get_option(CloudflareModule::STATUS_OPTION);
    $check('retrying'===$receipt['status']&&$receipt['next_retry']>=time()+119,'Durable receipt records delayed retry');
    $retryArgs=null;
    foreach (_get_cron_array() as $timestamp=>$hooks) {
        foreach ($hooks[CloudflareModule::RETRY_HOOK]??[] as $event) {
            if ('local-fixture-zone'===$event['args'][0]) { $retryArgs=$event['args'];wp_unschedule_event($timestamp,CloudflareModule::RETRY_HOOK,$retryArgs); }
        }
    }
    $check(is_array($retryArgs),'WordPress cron stores retry arguments');
    $apiStatus=200;do_action_ref_array(CloudflareModule::RETRY_HOOK,$retryArgs);
    $check('accepted'===get_option(CloudflareModule::STATUS_OPTION)['status'],'Native cron hook successfully retries');
    $purger->purgeAll();
    $check(true===$purger->flushEdge(),'Full purge completes synchronously');
    $check(!is_file($store->pagePath($control)),'Full purge removes remaining origin cache');
    $check(['purge_everything'=>true]===end($requests)['body'],'Full purge uses Cloudflare purge_everything');
    $requests=[];$verified=(new PurgeVerifier())->verify($url);
    $check(is_array($verified)&&'verified'===$verified['status'],'Native verifier succeeds');
    $check(['purge','fetch','fetch']===array_column($requests,'kind'),'Native verifier HTTP order is purge then two reads');
    $apiStatus=403;$requests=[];$failed=(new PurgeVerifier())->verify($url);
    $check(is_wp_error($failed)&&['purge']===array_column($requests,'kind'),'Failed purge is not fetched or verified');
    $check('failed'===get_option('gt_performance_purge_receipts')[0]['status'],'Failed verification receipt persists without debug mode');
} finally {
    remove_filter('pre_option_'.Settings::OPTION,$override);
    remove_filter('pre_http_request',$mock,PHP_INT_MIN);
    foreach ($saved as $key=>$value) { null===$value?delete_option($key):update_option($key,$value,false); }
}
echo wp_json_encode(['version'=>GTPERF_VERSION,'wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
