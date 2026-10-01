<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\Abilities;
use SiteAgent\Audit;
use SiteAgent\Config;
use SiteAgent\Developer;
use SiteAgent\Files;

final class ToolsTest extends TestCase {
	private $directory;
	private $posts = array();
	private $scrape;

	protected function setUp(): void {
		wp_set_current_user( 1 );
		update_option( Config::OPTION, array_merge( Config::defaults(), array( 'enabled' => true ), array_fill_keys( array( 'content_write', 'file_read', 'file_write', 'php_execute', 'cli_execute' ), true ) ), false );
		delete_option( Audit::OPTION );
		$this->directory = WP_CONTENT_DIR . '/themes/site-agent-tools';
		wp_mkdir_p( $this->directory );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		$this->remove( $this->directory );
		update_option( Config::OPTION, Config::defaults(), false );
		wp_set_current_user( 0 );
	}

	private function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $name ) {
				$this->remove( $path . '/' . $name );
			}
			rmdir( $path );
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	private function call( string $tool, array $input ) {
		$result = Abilities::execute( $tool, $input );
		if ( is_array( $result ) && isset( $result['id'] ) && in_array( $tool, array( 'save-content', 'upload-media' ), true ) ) {
			$this->posts[] = $result['id'];
		}
		return $result;
	}

	private function assertMatchesOutputSchema( string $tool, $result ): void {
		$this->assertIsArray( $result, $tool );
		$schema = Abilities::definitions()[ $tool ]['output'];
		$this->assertTrue( rest_validate_value_from_schema( $result, $schema, 'output' ), $tool . ' output must match its declared schema.' );
	}

	/** Answer the post-change loopback with a scraped fatal error, as WordPress would print it. */
	private function simulate_fatal(): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				if ( empty( $query['wp_scrape_key'] ) ) {
					return $pre;
				}
				$key   = $query['wp_scrape_key'];
				$error = array(
					'type'    => E_ERROR,
					'message' => 'Uncaught Error: Call to undefined function broken()',
					'file'    => 'wp-content/themes/site-agent-tools/functions.php',
					'line'    => 3,
				);
				return array(
					'headers'  => array(),
					'body'     => "<html>\n###### wp_scraping_result_start:$key ######\n" . wp_json_encode( $error ) . "\n###### wp_scraping_result_end:$key ######\n",
					'response' => array( 'code' => 500 ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	public function test_php_errors_report_message_and_line(): void {
		$thrown = $this->call( 'execute-php', array( 'code' => "\n\nthrow new RuntimeException( 'boom' );" ) );
		$this->assertInstanceOf( WP_Error::class, $thrown );
		$this->assertStringContainsString( 'RuntimeException: boom (line 3)', $thrown->get_error_message() );
		$syntax = $this->call( 'execute-php', array( 'code' => "return 1;\nreturn (;" ) );
		$this->assertStringContainsString( 'line 2', $syntax->get_error_message() );
		$rows = get_option( Audit::OPTION );
		$this->assertSame( 'php_execution_failed', $rows[0]['error'] );
	}

	public function test_exit_is_rejected_and_wp_die_becomes_a_tool_error(): void {
		foreach ( array( 'exit;', 'die("x");', '\exit();', "if ( false ) {\n exit( 1 );\n}" ) as $code ) {
			$this->assertInstanceOf( WP_Error::class, $this->call( 'execute-php', array( 'code' => $code ) ), $code );
		}
		$died = $this->call( 'execute-php', array( 'code' => 'echo "partial"; wp_die( "Stopped <b>here</b>" ); return 1;' ) );
		$this->assertSame( 'php_wp_die', $died->get_error_code() );
		$this->assertStringContainsString( 'Stopped here', $died->get_error_message() );
		$this->assertFalse( has_filter( 'wp_die_handler', array( Developer::class, 'halt_handler' ) ), 'wp_die() handlers must be restored.' );
		$this->assertSame( 2, $this->call( 'execute-php', array( 'code' => 'return 2;' ) )['return_value'] );
	}

	public function test_exit_and_fatal_errors_during_php_execution_are_audited(): void {
		$cases = array(
			// PHP 8.4 made exit a function, so it can be reached without the exit token.
			'call_user_func( "exit" );'                                         => PHP_VERSION_ID >= 80400 ? 'php_exit' : 'php_execution_failed',
			// A compile error inside eval() cannot be caught on any PHP version.
			'function site_agent_dup() {} function site_agent_dup() {}'       => 'php_fatal',
		);
		foreach ( $cases as $code => $error ) {
			$output = array();
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/exit-guard.php' ) . ' ' . escapeshellarg( base64_encode( $code ) ) . ' 2>/dev/null', $output );
			preg_match( '/AUDIT=(\w+)/', implode( "\n", $output ), $match );
			$this->assertSame( $error, $match[1] ?? implode( "\n", $output ), $code );
		}
	}

	public function test_wp_cli_rejects_aliases_and_honors_configuration(): void {
		foreach ( array( array( '@production', 'option', 'get', 'home' ), array( 'option', '--user=2' ), array( 'plugin', '--ssh=host' ) ) as $arguments ) {
			$this->assertSame( 'protected_argument', $this->call( 'run-wp-cli', array( 'arguments' => $arguments ) )->get_error_code() );
		}
		$timeout = static function () {
			return 999;
		};
		add_filter( 'site_agent_wp_cli_timeout', $timeout );
		$this->assertSame( 300, Developer::cli_timeout() );
		remove_filter( 'site_agent_wp_cli_timeout', $timeout );
		$missing = static function () {
			return array( '/nonexistent/wp' );
		};
		add_filter( 'site_agent_wp_cli_binary', $missing );
		$this->assertSame( 'cli_unavailable', $this->call( 'run-wp-cli', array( 'arguments' => array( 'core', 'version' ) ) )->get_error_code() );
		remove_filter( 'site_agent_wp_cli_binary', $missing );
	}

	public function test_wp_cli_runs_as_the_authenticated_user(): void {
		if ( '' === Developer::cli_binary() ) {
			$this->markTestSkipped( 'WP-CLI is not installed.' );
		}
		$result = $this->call( 'run-wp-cli', array( 'arguments' => array( 'eval', 'echo get_current_user_id(), "|", get_bloginfo( "version" );' ) ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
		// Older WP-CLI releases print PHP deprecation notices before the output on newer PHP.
		$lines = preg_split( '/\R/', trim( $result['stdout'] ) );
		$this->assertSame( '1|' . get_bloginfo( 'version' ), end( $lines ) );
		$this->assertMatchesOutputSchema( 'run-wp-cli', $result );
		$this->assertSame( 'wp eval', get_option( Audit::OPTION )[0]['target'], 'Audit targets keep command words only.' );
	}

	public function test_php_writes_are_compile_checked_with_line_details(): void {
		$path = 'themes/site-agent-tools/functions.php';
		$this->assertStringContainsString( 'line 3', $this->call( 'write-file', array( 'path' => $path, 'content' => "<?php\n\nreturn (;", 'expected_sha256' => 'new' ) )->get_error_message() );
		$compile = $this->call( 'write-file', array( 'path' => $path, 'content' => "<?php\nfunction a() {}\nfunction a() {}", 'expected_sha256' => 'new' ) );
		$this->assertSame( 'invalid_php', $compile->get_error_code() );
		$this->assertStringContainsString( 'line 3', $compile->get_error_message() );
		$this->assertStringContainsString( 'redeclare', strtolower( $compile->get_error_message() ) );
		$this->assertFileDoesNotExist( $this->directory . '/functions.php' );
		$written = $this->call( 'write-file', array( 'path' => $path, 'content' => '<?php return 1;', 'expected_sha256' => 'new' ) );
		$this->assertSame( 'passed', $written['checks']['lint'] );
		$this->assertContains( $written['checks']['health'], array( 'ok', 'unverified' ) );
		$this->assertMatchesOutputSchema( 'write-file', $written );
	}

	public function test_fatal_php_changes_are_reverted(): void {
		$path = 'themes/site-agent-tools/functions.php';
		file_put_contents( $this->directory . '/functions.php', '<?php return 1;' );
		$this->simulate_fatal();
		$changed = $this->call( 'write-file', array( 'path' => $path, 'content' => '<?php broken();', 'expected_sha256' => hash( 'sha256', '<?php return 1;' ) ) );
		$this->assertSame( 'php_fatal_reverted', $changed->get_error_code() );
		$this->assertStringContainsString( 'Call to undefined function broken() in wp-content/themes/site-agent-tools/functions.php on line 3', $changed->get_error_message() );
		$this->assertSame( '<?php return 1;', file_get_contents( $this->directory . '/functions.php' ) );
		$created = $this->call( 'write-file', array( 'path' => 'themes/site-agent-tools/new.php', 'content' => '<?php broken();', 'expected_sha256' => 'new' ) );
		$this->assertSame( 'php_fatal_reverted', $created->get_error_code() );
		$this->assertFileDoesNotExist( $this->directory . '/new.php' );
		$deleted = $this->call( 'delete-file', array( 'path' => $path, 'expected_sha256' => hash( 'sha256', '<?php return 1;' ) ) );
		$this->assertSame( 'php_fatal_reverted', $deleted->get_error_code() );
		$this->assertSame( '<?php return 1;', file_get_contents( $this->directory . '/functions.php' ) );
		$this->assertSame( 'php_fatal_reverted', get_option( Audit::OPTION )[0]['error'] );
	}

	public function test_directories_moves_and_deletes_are_confined_and_hash_checked(): void {
		$made = $this->call( 'create-directory', array( 'path' => 'themes/site-agent-tools/parts/blocks' ) );
		$this->assertSame( array( 'themes/site-agent-tools/parts', 'themes/site-agent-tools/parts/blocks' ), $made['created'] );
		$this->assertMatchesOutputSchema( 'create-directory', $made );
		$this->assertInstanceOf( WP_Error::class, $this->call( 'create-directory', array( 'path' => 'themes/site-agent-tools/.hidden' ) ) );
		file_put_contents( $this->directory . '/parts/a.css', 'a{}' );
		$hash = hash( 'sha256', 'a{}' );
		$this->assertSame( 'file_conflict', $this->call( 'move-file', array( 'from' => 'themes/site-agent-tools/parts/a.css', 'to' => 'themes/site-agent-tools/parts/b.css', 'expected_sha256' => str_repeat( '0', 64 ) ) )->get_error_code() );
		$moved = $this->call( 'move-file', array( 'from' => 'themes/site-agent-tools/parts/a.css', 'to' => 'themes/site-agent-tools/parts/blocks/b.css', 'expected_sha256' => $hash ) );
		$this->assertMatchesOutputSchema( 'move-file', $moved );
		$this->assertFileExists( $this->directory . '/parts/blocks/b.css' );
		$this->assertSame( 'file_conflict', $this->call( 'delete-file', array( 'path' => 'themes/site-agent-tools/parts/blocks', 'expected_sha256' => $hash ) )->get_error_code() );
		$this->assertSame( 'delete_failed', $this->call( 'delete-file', array( 'path' => 'themes/site-agent-tools/parts/blocks', 'expected_sha256' => 'directory' ) )->get_error_code() );
		$deleted = $this->call( 'delete-file', array( 'path' => 'themes/site-agent-tools/parts/blocks/b.css', 'expected_sha256' => $hash ) );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertTrue( $this->call( 'delete-file', array( 'path' => 'themes/site-agent-tools/parts/blocks', 'expected_sha256' => 'directory' ) )['deleted'] );
		$this->assertInstanceOf( WP_Error::class, $this->call( 'delete-file', array( 'path' => 'themes', 'expected_sha256' => 'directory' ) ) );
		$listing = $this->call( 'list-files', array( 'path' => 'themes/site-agent-tools' ) );
		$this->assertSame( 'directory', $listing['entries'][0]['type'] );
		$this->assertArrayHasKey( 'modified_gmt', $listing['entries'][0] );
	}

	public function test_mu_plugins_are_a_file_root(): void {
		$created = ! is_dir( WP_CONTENT_DIR . '/mu-plugins' );
		wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
		$this->assertIsString( Files::resolve( 'mu-plugins/site-agent-test.php', true ) );
		if ( $created ) {
			rmdir( WP_CONTENT_DIR . '/mu-plugins' );
		}
	}

	public function test_titles_keep_literal_markup_and_percent_sequences(): void {
		$title   = 'Fix the <head> tag & why %20 appears';
		$created = $this->call( 'save-content', array( 'title' => $title, 'content' => 'x' ) );
		$this->assertSame( $title, $created['title'] );
		$this->assertMatchesOutputSchema( 'save-content', $created );
	}

	public function test_edits_to_live_posts_are_staged_unless_status_is_explicit(): void {
		$created   = $this->call( 'save-content', array( 'title' => 'Live fixture', 'content' => 'original', 'status' => 'publish' ) );
		$this->assertSame( 'publish', $created['status'] );
		$staged = $this->call( 'save-content', array( 'post_id' => $created['id'], 'content' => 'proposed', 'expected_content_sha256' => $created['content_sha256'] ) );
		$this->assertTrue( $staged['staged'] );
		$this->assertSame( 'original', $staged['content'] );
		$this->assertSame( hash( 'sha256', 'proposed' ), $staged['autosave']['content_sha256'] );
		$this->assertSame( 'stage_unsupported', $this->call( 'save-content', array( 'post_id' => $created['id'], 'slug' => 'moved', 'expected_content_sha256' => $created['content_sha256'] ) )->get_error_code() );
		$live = $this->call( 'save-content', array( 'post_id' => $created['id'], 'content' => 'proposed', 'status' => 'publish', 'expected_content_sha256' => $created['content_sha256'] ) );
		$this->assertFalse( $live['staged'] );
		$this->assertSame( 'proposed', $live['content'] );
	}

	public function test_scheduling_requires_a_future_date(): void {
		$this->assertSame( 'invalid_date', $this->call( 'save-content', array( 'title' => 'Never', 'status' => 'future' ) )->get_error_code() );
		$this->assertSame( 'invalid_date', $this->call( 'save-content', array( 'title' => 'Bad', 'date_gmt' => 'tomorrow' ) )->get_error_code() );
		$scheduled = $this->call( 'save-content', array( 'title' => 'Later', 'status' => 'publish', 'date_gmt' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ) ) );
		$this->assertSame( 'future', $scheduled['status'] );
	}

	public function test_terms_featured_image_meta_and_lookup(): void {
		register_post_meta(
			'post',
			'site_agent_test_summary',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			)
		);
		$category = wp_insert_term( 'Site Agent Category ' . uniqid(), 'category' );
		$saved    = $this->call(
			'save-content',
			array(
				'title' => 'Terms fixture',
				'slug'  => 'site-agent-terms-fixture',
				'terms' => array(
					'category' => array( $category['term_id'] ),
					'post_tag' => array( 'site-agent-new-tag' ),
				),
				'meta'  => array( 'site_agent_test_summary' => 'Summary' ),
			)
		);
		$this->assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
		$this->assertSame( array( $category['term_id'] ), $saved['terms']->category );
		$this->assertCount( 1, $saved['terms']->post_tag );
		$this->assertSame( 'Summary', $saved['meta']->site_agent_test_summary );
		$this->assertSame( 'invalid_meta', $this->call( 'save-content', array( 'title' => 'x', 'meta' => array( '_edit_lock' => '1' ) ) )->get_error_code() );
		$this->assertSame( 'invalid_term', $this->call( 'save-content', array( 'title' => 'x', 'terms' => array( 'category' => array( 999999 ) ) ) )->get_error_code() );
		$this->assertSame( 'invalid_featured_media', $this->call( 'save-content', array( 'title' => 'x', 'featured_media' => $saved['id'] ) )->get_error_code() );
		$by_slug = $this->call( 'get-content', array( 'slug' => 'site-agent-terms-fixture' ) );
		$this->assertSame( $saved['id'], $by_slug['id'] );
		$this->assertMatchesOutputSchema( 'get-content', $by_slug );
		$terms = $this->call( 'list-terms', array( 'taxonomy' => 'post_tag', 'search' => 'site-agent-new-tag' ) );
		$this->assertSame( 'site-agent-new-tag', $terms['terms'][0]['name'] );
		$this->assertMatchesOutputSchema( 'list-terms', $terms );
		$this->assertSame( 'missing_identifier', $this->call( 'get-content', array() )->get_error_code() );
		wp_delete_term( $category['term_id'], 'category' );
		wp_delete_term( $terms['terms'][0]['id'], 'post_tag' );
	}

	public function test_content_tools_ignore_internal_post_types(): void {
		$id            = wp_insert_post(
			array(
				'post_type'   => 'user_request',
				'post_title'  => 'person@example.test',
				'post_status' => 'request-pending',
			)
		);
		$this->posts[] = $id;
		$this->assertSame( 'post_unavailable', $this->call( 'get-content', array( 'post_id' => $id ) )->get_error_code() );
	}

	public function test_listing_defaults_to_recently_modified(): void {
		$first  = $this->call( 'save-content', array( 'title' => 'Older' ) );
		$second = $this->call( 'save-content', array( 'title' => 'Newer' ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array(
				'post_modified'     => '2000-01-01 00:00:00',
				'post_modified_gmt' => '2000-01-01 00:00:00',
			), array( 'ID' => $first['id'] ) );
		clean_post_cache( $first['id'] );
		$listing = $this->call( 'list-content', array( 'status' => 'draft' ) );
		$ids     = wp_list_pluck( $listing['posts'], 'id' );
		$this->assertLessThan( array_search( $first['id'], $ids, true ), array_search( $second['id'], $ids, true ) );
		$this->assertMatchesOutputSchema( 'list-content', $listing );
	}

	public function test_media_imports_from_base64_and_urls_with_alt_text(): void {
		// A 1x1 PNG.
		$png      = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
		$uploaded = $this->call( 'upload-media', array( 'data_base64' => base64_encode( $png ), 'filename' => 'pixel.png', 'alt' => 'A single pixel', 'title' => 'Pixel' ) );
		$this->assertIsArray( $uploaded, is_wp_error( $uploaded ) ? $uploaded->get_error_message() : '' );
		$this->assertSame( 'image/png', $uploaded['mime_type'] );
		$this->assertSame( 'A single pixel', $uploaded['alt'] );
		$this->assertMatchesOutputSchema( 'upload-media', $uploaded );
		$this->assertSame( 'missing_filename', $this->call( 'upload-media', array( 'data_base64' => base64_encode( $png ) ) )->get_error_code() );
		$this->assertSame( 'invalid_source', $this->call( 'upload-media', array() )->get_error_code() );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $png ) {
				if ( 'https://images.example.test/photo' !== $url ) {
					return $pre;
				}
				file_put_contents( $args['filename'], $png );
				return array(
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => '',
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			},
			10,
			3
		);
		$imported = $this->call( 'upload-media', array( 'url' => 'https://images.example.test/photo', 'alt' => 'Photo' ) );
		$this->assertIsArray( $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );
		$this->assertStringEndsWith( '.png', $imported['url'] );
		$this->assertSame( 'images.example.test', get_option( Audit::OPTION )[ count( get_option( Audit::OPTION ) ) - 1 ]['target'] );
		$updated = $this->call( 'update-media', array( 'id' => $uploaded['id'], 'alt' => 'Updated alt', 'caption' => 'Caption' ) );
		$this->assertSame( 'Updated alt', $updated['alt'] );
		$this->assertSame( 'Caption', $updated['caption'] );
		$listing = $this->call( 'list-media', array( 'mime_type' => 'image/png' ) );
		$this->assertContains( $uploaded['id'], wp_list_pluck( $listing['media'], 'id' ) );
		$this->assertMatchesOutputSchema( 'list-media', $listing );
	}

	public function test_context_and_reads_match_output_schemas(): void {
		$this->assertMatchesOutputSchema( 'site-context', $this->call( 'site-context', array() ) );
		file_put_contents( $this->directory . '/style.css', 'body{}' );
		$this->assertMatchesOutputSchema( 'read-file', $this->call( 'read-file', array( 'path' => 'themes/site-agent-tools/style.css' ) ) );
		$this->assertMatchesOutputSchema( 'list-files', $this->call( 'list-files', array( 'path' => 'themes/site-agent-tools' ) ) );
		$this->assertMatchesOutputSchema( 'execute-php', $this->call( 'execute-php', array( 'code' => 'return array( "a" => 1 );' ) ) );
		foreach ( Abilities::definitions() as $name => $definition ) {
			foreach ( $definition['input'] as $property => $schema ) {
				$this->assertNotEmpty( $schema['description'] ?? '', "$name.$property needs a description." );
			}
		}
	}
}
