<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\Files;

/** File rollback and pathname races must not destroy a later successful change. */
final class FileRegressionTest extends TestCase {
	private $directory;
	private $filters = array();
	private $mu_file;

	protected function setUp(): void {
		$this->directory = WP_CONTENT_DIR . '/themes/site-agent-file-regression';
		wp_mkdir_p( $this->directory );
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], 10 );
		}
		if ( $this->mu_file && file_exists( $this->mu_file ) ) {
			unlink( $this->mu_file );
		}
		foreach ( glob( $this->directory . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->directory );
	}

	private function filter( string $name, callable $callback, int $arguments = 1 ): void {
		add_filter( $name, $callback, 10, $arguments );
		$this->filters[] = array( $name, $callback );
	}

	/** Interleave a successful second operation while the first request awaits its loopback. */
	private function fatal_after( callable $callback ): void {
		$called = false;
		$this->filter(
			'pre_http_request',
			function ( $pre, $arguments, $url ) use ( $callback, &$called ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				if ( empty( $query['wp_scrape_key'] ) ) {
					return $pre;
				}
				if ( ! $called ) {
					$called = true;
					add_filter( 'site_agent_health_check', '__return_false' );
					try {
						$callback();
					} finally {
						remove_filter( 'site_agent_health_check', '__return_false' );
					}
				}
				$key = $query['wp_scrape_key'];
				return array(
					'headers'  => array(),
					'body'     => "###### wp_scraping_result_start:$key ######" . wp_json_encode( array( 'type' => E_ERROR, 'message' => 'Earlier request failed', 'line' => 1 ) ) . "###### wp_scraping_result_end:$key ######",
					'response' => array( 'code' => 500 ),
					'cookies'  => array(),
				);
			},
			3
		);
	}

	public function test_new_php_rollback_preserves_a_later_successful_write(): void {
		$relative = 'themes/site-agent-file-regression/new.php';
		$original = '<?php return 1;';
		$later    = '<?php return 2;';
		$this->fatal_after(
			function () use ( $relative, $original, $later ) {
				$this->assertIsArray( Files::write( array( 'path' => $relative, 'content' => $later, 'expected_sha256' => hash( 'sha256', $original ) ) ) );
			}
		);
		$result = Files::write( array( 'path' => $relative, 'content' => $original, 'expected_sha256' => 'new' ) );
		$this->assertSame( 'php_fatal_not_reverted', $result->get_error_code() );
		$this->assertSame( $later, file_get_contents( $this->directory . '/new.php' ) );
	}

	public function test_move_rollback_does_not_replace_a_recreated_source(): void {
		$from     = 'themes/site-agent-file-regression/source.php';
		$to       = 'themes/site-agent-file-regression/destination.php';
		$original = '<?php return 1;';
		$later    = '<?php return 2;';
		file_put_contents( $this->directory . '/source.php', $original );
		$this->fatal_after(
			function () use ( $from, $later ) {
				$this->assertIsArray( Files::write( array( 'path' => $from, 'content' => $later, 'expected_sha256' => 'new' ) ) );
			}
		);
		$result = Files::move( array( 'from' => $from, 'to' => $to, 'expected_sha256' => hash( 'sha256', $original ) ) );
		$this->assertSame( 'php_fatal_not_reverted', $result->get_error_code() );
		$this->assertSame( $later, file_get_contents( $this->directory . '/source.php' ) );
		$this->assertSame( $original, file_get_contents( $this->directory . '/destination.php' ) );
	}

	public function test_move_rollback_preserves_a_later_destination_write(): void {
		$from     = 'themes/site-agent-file-regression/source.php';
		$to       = 'themes/site-agent-file-regression/destination.php';
		$original = '<?php return 1;';
		$later    = '<?php return 2;';
		file_put_contents( $this->directory . '/source.php', $original );
		$this->fatal_after(
			function () use ( $to, $original, $later ) {
				$this->assertIsArray( Files::write( array( 'path' => $to, 'content' => $later, 'expected_sha256' => hash( 'sha256', $original ) ) ) );
			}
		);
		$result = Files::move( array( 'from' => $from, 'to' => $to, 'expected_sha256' => hash( 'sha256', $original ) ) );
		$this->assertSame( 'php_fatal_not_reverted', $result->get_error_code() );
		$this->assertFileDoesNotExist( $this->directory . '/source.php' );
		$this->assertSame( $later, file_get_contents( $this->directory . '/destination.php' ) );
	}

	public function test_moving_a_mu_helper_out_restores_it_after_an_early_server_error(): void {
		wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
		$this->mu_file = WP_CONTENT_DIR . '/mu-plugins/site-agent-regression-helper.php';
		$content       = '<?php return 1;';
		file_put_contents( $this->mu_file, $content );
		$this->filter(
			'pre_http_request',
			static function () {
				return array( 'headers' => array(), 'body' => 'Early server failure', 'response' => array( 'code' => 500 ), 'cookies' => array() );
			}
		);
		$result = Files::move(
			array(
				'from'            => 'mu-plugins/site-agent-regression-helper.php',
				'to'              => 'themes/site-agent-file-regression/helper.php',
				'expected_sha256' => hash( 'sha256', $content ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'php_fatal_reverted', $result->get_error_code() );
		$this->assertSame( $content, file_get_contents( $this->mu_file ) );
		$this->assertFileDoesNotExist( $this->directory . '/helper.php' );
	}

	public function test_a_mutation_filter_cannot_reenter_and_deadlock_the_file_tools(): void {
		$relative = 'themes/site-agent-file-regression/reentrant.txt';
		$later    = null;
		$called   = false;
		file_put_contents( $this->directory . '/reentrant.txt', 'old' );
		$this->filter(
			'site_agent_file_blocked',
			static function ( $blocked ) use ( $relative, &$later, &$called ) {
				if ( ! $called ) {
					$called = true;
					$later  = Files::write( array( 'path' => $relative, 'content' => 'later', 'expected_sha256' => hash( 'sha256', 'old' ) ) );
				}
				return $blocked;
			}
		);
		$result = Files::delete( array( 'path' => $relative, 'expected_sha256' => hash( 'sha256', 'old' ) ) );
		$this->assertIsArray( $result );
		$this->assertInstanceOf( WP_Error::class, $later );
		$this->assertSame( 'lock_failed', $later->get_error_code() );
		$this->assertFileDoesNotExist( $this->directory . '/reentrant.txt' );
	}

	/** @dataProvider concurrent_operations */
	public function test_waiting_writers_and_path_mutations_cannot_lose_a_successful_write( string $operation ): void {
		if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'The concurrency regression requires pcntl and process support.' );
		}
		$runner = $this->directory . '/runner.php';
		file_put_contents( $runner, self::concurrency_runner() );
		$pipes   = array();
		$process = proc_open( array( PHP_BINARY, $runner, $operation, $this->directory, dirname( __DIR__ ) ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$result = json_decode( $output, true );
		$this->assertIsArray( $result, $error . $output );
		if ( 'replaced' === $operation ) {
			$this->assertSame( 'file_conflict', $result['writer']['error'] ?? '' );
			$this->assertSame( 'replacement', $result['source'] );
			$this->assertSame( 'old', $result['destination'] );
		} else {
			$this->assertArrayNotHasKey( 'error', $result['writer'] );
			$this->assertSame( 'file_conflict', $result['mutation']['error'] ?? '' );
			$this->assertSame( 'later', $result['source'] );
			$this->assertNull( $result['destination'] );
		}
	}

	public function concurrent_operations(): array {
		return array( 'delete' => array( 'delete' ), 'move' => array( 'move' ), 'replaced inode' => array( 'replaced' ) );
	}

	/** Separate, DB-free subprocesses use real flock waits and socket handshakes, without sleeps. */
	private static function concurrency_runner(): string {
		return <<<'PHP'
<?php
namespace SiteAgent {
    function flock($stream, $operation, &$would_block = null) {
        if (LOCK_EX === $operation && isset($GLOBALS['signal'])) {
            $uri = stream_get_meta_data($stream)['uri'];
            if ('*' === $GLOBALS['signal_path'] || $uri === $GLOBALS['signal_path']) {
                fwrite($GLOBALS['signal'], "locking\n");
            }
        }
        return \flock($stream, $operation, $would_block);
    }
}
namespace {
    $operation = $argv[1];
    $directory = realpath($argv[2]);
    define('ABSPATH', dirname($directory, 3) . '/');
    define('WP_CONTENT_DIR', dirname($directory, 2));
    define('SITE_AGENT_DIR', $argv[3]);
    class WP_Error {
        public $code;
        public function __construct($code, $message) { $this->code = $code; }
    }
    function __($value, $domain = '') { return $value; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function apply_filters($name, $value, ...$args) { return $value; }
    function get_temp_dir() { return sys_get_temp_dir() . '/'; }
    function wp_delete_file($path) { unlink($path); }
    function wp_opcache_invalidate($path, $force) { return true; }
    function result($value) { return is_wp_error($value) ? array('error' => $value->code) : $value; }
    require $argv[3] . '/includes/class-files.php';
    $source = $directory . '/locked.txt';
    $target = $directory . '/moved.txt';
    $relative = 'themes/' . basename($directory) . '/locked.txt';
    $destination = 'themes/' . basename($directory) . '/moved.txt';
    file_put_contents($source, 'old');
    $lock = fopen($source, 'r+b');
    \flock($lock, LOCK_EX);
    [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_timeout($parent, 10);
    $writer = pcntl_fork();
    if (0 === $writer) {
        fclose($parent);
        fclose($lock);
        $GLOBALS['signal'] = $child;
        $GLOBALS['signal_path'] = $source;
        $value = \SiteAgent\Files::write(array('path' => $relative, 'content' => 'later', 'expected_sha256' => hash('sha256', 'old')));
        file_put_contents($directory . '/writer.json', json_encode(result($value)));
        exit(0);
    }
    fclose($child);
    if (false === fgets($parent)) { throw new \RuntimeException('Writer did not reach the locked inode.'); }
    if ('replaced' === $operation) {
        rename($source, $target);
        file_put_contents($source, 'replacement');
    } else {
        [$waiter, $mutator] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($waiter, 10);
        $mutation = pcntl_fork();
        if (0 === $mutation) {
            fclose($waiter);
            fclose($lock);
            $GLOBALS['signal'] = $mutator;
            $GLOBALS['signal_path'] = '*';
            $value = 'delete' === $operation
                ? \SiteAgent\Files::delete(array('path' => $relative, 'expected_sha256' => hash('sha256', 'old')))
                : \SiteAgent\Files::move(array('from' => $relative, 'to' => $destination, 'expected_sha256' => hash('sha256', 'old')));
            file_put_contents($directory . '/mutation.json', json_encode(result($value)));
            fwrite($mutator, "done\n");
            exit(0);
        }
        fclose($mutator);
        if (false === fgets($waiter)) { throw new \RuntimeException('Mutation did not start.'); }
    }
    \flock($lock, LOCK_UN);
    fclose($lock);
    pcntl_waitpid($writer, $status);
    if (isset($mutation)) { pcntl_waitpid($mutation, $status); }
    clearstatcache();
    echo json_encode(array(
        'writer' => json_decode(file_get_contents($directory . '/writer.json'), true),
        'mutation' => isset($mutation) ? json_decode(file_get_contents($directory . '/mutation.json'), true) : null,
        'source' => file_exists($source) ? file_get_contents($source) : null,
        'destination' => file_exists($target) ? file_get_contents($target) : null,
    ));
}
PHP;
	}
}
