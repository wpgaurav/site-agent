<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\Developer;
use SiteAgent\Media;
use SiteAgent\Process;

final class OutputMediaRegressionTest extends TestCase {
	private $attachments = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		wp_set_current_user( 0 );
	}

	public function test_php_output_remains_json_serializable_when_truncated_inside_a_character(): void {
		$result = Developer::php( array( 'code' => 'echo str_repeat("€", 22000); return null;' ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['truncated'] );
		$this->assertLessThanOrEqual( Developer::MAX_OUTPUT, strlen( $result['output'] ) );
		$this->assertSame( 1, preg_match( '//u', $result['output'] ) );
		$this->assertIsString( wp_json_encode( $result, JSON_THROW_ON_ERROR ) );
	}

	public function test_cli_output_remains_json_serializable_when_truncated_inside_a_character(): void {
		$result = Process::run( array( PHP_BINARY, '-r', 'echo str_repeat("€", 22000);' ), 5 );
		$this->assertTrue( $result['started'] );
		$this->assertSame( 0, $result['exit_code'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertLessThanOrEqual( Process::MAX_OUTPUT, strlen( $result['stdout'] ) );
		$this->assertSame( 1, preg_match( '//u', $result['stdout'] ) );
		$this->assertIsString( wp_json_encode( $result, JSON_THROW_ON_ERROR ) );
	}

	public function test_php_return_preserves_object_and_list_json_shapes(): void {
		$empty = Developer::php( array( 'code' => 'return (object) array();' ) );
		$this->assertInstanceOf( stdClass::class, $empty['return_value'] );
		$this->assertSame( '{}', wp_json_encode( $empty['return_value'] ) );
		$numeric = Developer::php( array( 'code' => 'return (object) array("0" => "value");' ) );
		$this->assertSame( '{"0":"value"}', wp_json_encode( $numeric['return_value'] ) );
		$list = Developer::php( array( 'code' => 'return array("value");' ) );
		$this->assertSame( array( 'value' ), $list['return_value'] );
	}

	public function test_disabled_termination_is_reported_unavailable_before_starting_a_child(): void {
		$code = 'define("ABSPATH", ' . var_export( ABSPATH, true ) . '); require ' . var_export( SITE_AGENT_DIR . 'includes/class-process.php', true ) . '; echo SiteAgent\\Process::available() ? "available" : "unavailable";';
		$probe = Process::run( array( PHP_BINARY, '-d', 'disable_functions=proc_terminate', '-r', $code ), 5 );
		$this->assertSame( 0, $probe['exit_code'] );
		$this->assertSame( 'unavailable', $probe['stdout'] );
	}

	public function test_media_import_preserves_literal_backslashes_in_all_supplied_text(): void {
		$caption = 'Equation \\alpha + \\beta';
		$description = 'Path C:\\Tools';
		$result = Media::upload(
			array(
				'data_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
				'filename' => 'output-regression.png',
				'title' => 'C:\\Tools',
				'caption' => $caption,
				'description' => $description,
			)
		);
		$this->assertIsArray( $result );
		$this->attachments[] = $result['id'];
		$this->assertSame( 'C:\\Tools', $result['title'] );
		$this->assertSame( $caption, $result['caption'] );
		$this->assertSame( $description, get_post( $result['id'] )->post_content );
	}
}
