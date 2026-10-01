<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\License;
use SiteAgent\Updater;
use SiteAgent\Config;
use SiteAgent\Permissions;

final class LicensingTest extends TestCase {
	private $response;
	private $calls;
	private $lastBody;
	protected function setUp(): void {
		wp_set_current_user(1);delete_option(License::OPTION);Updater::clear_cache();
		$this->calls=0;
		$this->response=array('status'=>'valid','product_id'=>License::PRODUCT_ID,'activation_hash'=>'test-activation','variation_title'=>'Free Update License');
		add_filter('pre_http_request',array($this,'http'),10,3);
	}
	protected function tearDown(): void {
		remove_filter('pre_http_request',array($this,'http'),10);delete_option(License::OPTION);Updater::clear_cache();wp_set_current_user(0);
	}
	public function http($pre,$args,$url) {
		if(strpos($url,License::STORE.'?fluent-cart=')!==0){return $pre;}
		$this->calls++;$this->lastBody=$args['body'];
		return is_wp_error($this->response)?$this->response:array('response'=>array('code'=>200),'body'=>wp_json_encode($this->response),'headers'=>array());
	}
	private function activate(): void {
		$this->assertTrue(License::activate('test-license-secret'));
	}
	private function metadata(string $version='0.2.0',string $status='valid'): void {
		$this->response=array('slug'=>'site-agent','product_id'=>License::PRODUCT_ID,'new_version'=>$version,'license_status'=>$status,'package'=>'https://gauravtiwari-org-fluentcart.'.str_repeat('a',32).'.r2.cloudflarestorage.com/site-agent-'.$version.'.zip?signature=test','sections'=>array('changelog'=>'<p>Update</p>'));
	}
	public function test_no_license_means_no_outbound_update_request(): void {
		$this->assertNull(Updater::metadata());$this->assertSame(0,$this->calls);
	}
	public function test_activation_is_encrypted_site_bound_and_product_specific(): void {
		$this->activate();$state=License::state();
		$this->assertStringNotContainsString('test-license-secret',wp_json_encode($state));
		$this->assertSame('test-license-secret',License::credentials()['license_key']);
		$this->assertSame(License::PRODUCT_ID,$this->lastBody['item_id']);
		$this->assertSame(home_url('/'),$this->lastBody['site_url']);
		$state['site']='https://copied.test/';update_option(License::OPTION,$state,false);
		$this->assertSame(array(),License::credentials());$this->assertNull(Updater::metadata());
	}
	public function test_wrong_product_license_is_rejected(): void {
		$this->response['product_id']=1178439;
		$this->assertInstanceOf(WP_Error::class,License::activate('test-key'));$this->assertSame(array(),License::state());
	}
	public function test_network_errors_preserve_credentials(): void {
		$this->activate();$before=License::state();$this->response=new WP_Error('http_request_failed','private key must not leak');
		$this->assertInstanceOf(WP_Error::class,License::check());$this->assertSame($before,License::state());
		$this->assertInstanceOf(WP_Error::class,License::disconnect());$this->assertSame($before,License::state());
	}
	public function test_invalid_license_does_not_disable_tools(): void {
		$this->activate();$this->response=array('status'=>'invalid','product_id'=>License::PRODUCT_ID);
		$this->assertInstanceOf(WP_Error::class,License::check());
		update_option(Config::OPTION,array_merge(Config::defaults(),array('enabled'=>true)),false);
		$this->assertTrue(Permissions::allowed());update_option(Config::OPTION,Config::defaults(),false);
	}
	public function test_metadata_cache_excludes_credentials_and_refreshes(): void {
		$this->activate();$this->metadata();$this->response['license_key']='must-not-be-cached';
		$this->assertIsArray(Updater::metadata());$calls=$this->calls;Updater::metadata();$this->assertSame($calls,$this->calls);
		$this->assertStringNotContainsString('must-not-be-cached',wp_json_encode(get_transient(Updater::CACHE)));
		Updater::metadata(true);$this->assertSame($calls+1,$this->calls);
	}
	public function test_only_expected_https_package_hosts_are_allowed(): void {
		$this->assertTrue(Updater::package_allowed('https://gauravtiwari.org/download.zip'));
		$this->assertTrue(Updater::package_allowed('https://gauravtiwari-org-fluentcart.'.str_repeat('a',32).'.r2.cloudflarestorage.com/plugin.zip?signature=test'));
		foreach(array('http://gauravtiwari.org/a','https://attacker.test/a','https://gauravtiwari.org.attacker.test/a','https://user@gauravtiwari.org/a','https://gauravtiwari.org:8443/a','https://gauravtiwari.org/a#fragment') as $url){$this->assertFalse(Updater::package_allowed($url));}
	}
	public function test_updates_reject_invalid_entitlement_and_downgrades(): void {
		$file=plugin_basename(SITE_AGENT_FILE);$headers=array('Version'=>SITE_AGENT_VERSION);
		$this->assertFalse(Updater::update(false,$headers,$file),'No license means no update entry.');
		$this->activate();$this->metadata('99.0.0','invalid');
		$result=Updater::update(false,$headers,$file);
		$this->assertSame(SITE_AGENT_VERSION,$result['version']);$this->assertSame('',$result['package']);
		Updater::clear_cache();$this->metadata('0.1.0');
		$this->assertSame(SITE_AGENT_VERSION,Updater::update(false,$headers,$file)['version']);
		Updater::clear_cache();$this->metadata('99.0.0');$result=Updater::update(false,$headers,$file);
		$this->assertSame('99.0.0',$result['version']);$this->assertStringStartsWith('https://gauravtiwari-org-fluentcart.',$result['package']);
		$this->assertSame('another',Updater::update('another',$headers,'other-plugin/other-plugin.php'),'Other plugins on the same update host are untouched.');
	}
	public function test_failed_update_requests_are_cached_briefly(): void {
		$this->activate();$this->response=new WP_Error('http_request_failed','offline');$calls=$this->calls;
		$this->assertInstanceOf(WP_Error::class,Updater::metadata());$this->assertSame($calls+1,$this->calls);
		$this->assertInstanceOf(WP_Error::class,Updater::metadata());$this->assertSame($calls+1,$this->calls,'A cached failure must not trigger another request.');
		$this->metadata('99.0.0');$this->assertIsArray(Updater::metadata(true));$this->assertSame($calls+2,$this->calls);
	}
	public function test_forced_refresh_failure_keeps_validated_metadata(): void {
		// On the store's own site the upgrader's refresh hits maintenance mode (HTTP 503).
		$this->activate();$this->metadata('99.0.0');$this->assertSame('99.0.0',Updater::metadata()['new_version']);
		$this->response=new WP_Error('http_request_failed','maintenance');$calls=$this->calls;
		$this->assertSame('99.0.0',Updater::metadata(true)['new_version']);$this->assertSame($calls+1,$this->calls);
		$this->assertSame('99.0.0',Updater::metadata()['new_version'],'The validated cache must survive the failed refresh.');
		Updater::clear_cache();$this->assertInstanceOf(WP_Error::class,Updater::metadata(true),'Without validated metadata the failure is reported.');
	}
	public function test_signed_packages_must_match_exactly(): void {
		require_once dirname(__DIR__).'/bin/sign-package.php';
		$pair=sodium_crypto_sign_keypair();$public=base64_encode(sodium_crypto_sign_publickey($pair));
		$folder=sys_get_temp_dir().'/site-agent-signature-test-'.uniqid();mkdir($folder.'/includes',0777,true);
		file_put_contents($folder.'/site-agent.php','<?php // main');file_put_contents($folder.'/includes/class-a.php','<?php // a');
		$files=site_agent_package_files($folder);
		$this->assertSame(Updater::package_message('9.9.9',$files),site_agent_package_message('9.9.9',$files),'Signer and verifier must build the same message.');
		site_agent_sign_package($folder,'9.9.9',sodium_crypto_sign_secretkey($pair));
		$this->assertTrue(Updater::verify_package($folder,'9.9.9',$public));
		$this->assertInstanceOf(WP_Error::class,Updater::verify_package($folder,'9.9.8',$public),'The signature binds the version.');
		$this->assertInstanceOf(WP_Error::class,Updater::verify_package($folder,'9.9.9',base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))),'Another key must fail.');
		file_put_contents($folder.'/includes/extra.php','<?php // injected');
		$this->assertInstanceOf(WP_Error::class,Updater::verify_package($folder,'9.9.9',$public),'Unsigned extra files must fail.');
		unlink($folder.'/includes/extra.php');file_put_contents($folder.'/includes/class-a.php','<?php // changed');
		$this->assertInstanceOf(WP_Error::class,Updater::verify_package($folder,'9.9.9',$public),'Changed files must fail.');
		foreach(array('/includes/class-a.php','/site-agent.php','/signature.json') as $f){unlink($folder.$f);}rmdir($folder.'/includes');rmdir($folder);
	}
	public function test_wrong_update_identity_and_wrong_extracted_plugin_are_rejected(): void {
		$this->activate();$this->metadata();$this->response['slug']='another-plugin';
		$this->assertInstanceOf(WP_Error::class,Updater::metadata());Updater::clear_cache();$this->metadata();
		$folder=sys_get_temp_dir().'/site-agent-updater-test-'.uniqid();mkdir($folder);
		file_put_contents($folder.'/site-agent.php',"<?php\n/* Plugin Name: Another Plugin\nVersion: 0.2.0 */");
		$this->assertInstanceOf(WP_Error::class,Updater::source($folder,'',(object)array(),array('plugin'=>plugin_basename(SITE_AGENT_FILE))));
		unlink($folder.'/site-agent.php');rmdir($folder);
	}
	public function test_disconnect_clears_only_after_server_confirmation(): void {
		$this->activate();$this->response=array('status'=>'deactivated');
		$this->assertTrue(License::disconnect());$this->assertSame(array(),License::state());
	}
}
