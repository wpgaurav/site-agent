<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\Process;

final class UninstallRegressionTest extends TestCase {
	public function test_uninstall_removes_site_local_oauth_credentials_without_opt_in(): void {
		$script = SITE_AGENT_DIR . 'uninstall.php';
		$code = '<?php
$root = getenv("SITE_AGENT_WP_DIR");
if (!$root || !is_file($root . "/.site-agent-test-install")) exit(2);
require $root . "/wp-load.php";
$original = get_option("site_agent_settings");
$suffix = bin2hex(random_bytes(8));
$grant = "site_agent_oauth_grant_1_" . $suffix;
$client = "site_agent_oauth_client_sa-" . $suffix . $suffix;
try {
    update_option("site_agent_settings", array("delete_data" => false), false);
    add_option($grant, array("revoked" => true, "refresh_expires" => time() + 60), "", false);
    add_option($client, array("client_name" => "Uninstall fixture"), "", false);
    define("WP_UNINSTALL_PLUGIN", "site-agent/site-agent.php");
    require ' . var_export( $script, true ) . ';
    echo wp_json_encode(array(
        "grant_deleted" => false === get_option($grant),
        "client_deleted" => false === get_option($client),
        "settings_retained" => array("delete_data" => false) === get_option("site_agent_settings")
    ));
} finally {
    delete_option($grant);
    delete_option($client);
    if (false === $original) delete_option("site_agent_settings");
    else update_option("site_agent_settings", $original, false);
}';
		$file = tempnam( sys_get_temp_dir(), 'site-agent-uninstall-regression-' );
		file_put_contents( $file, $code );
		try {
			$result = Process::run( array( PHP_BINARY, $file ), 20 );
		} finally {
			unlink( $file );
		}
		$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
		$this->assertSame(
			array( 'grant_deleted' => true, 'client_deleted' => true, 'settings_retained' => true ),
			json_decode( $result['stdout'], true )
		);
	}
}
