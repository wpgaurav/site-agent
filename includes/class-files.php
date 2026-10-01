<?php
/**
 * Confined, bounded theme and plugin file operations.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Bounded native reads and locked compare-and-write require local file handles.
/** Confined, bounded theme and plugin file operations. */
final class Files {
	const MAX_BYTES  = 262144;
	const ROOTS      = array( 'plugins', 'themes', 'mu-plugins' );
	const EXTENSIONS = array( 'php', 'css', 'js', 'json', 'html', 'txt', 'md', 'svg', 'xml', 'yaml', 'yml', 'scss' );
	/** Exact credential and configuration file names. Hidden (dot) files are blocked separately. */
	const PROTECTED_NAMES = '/^(?:wp-config[\w.-]*\.php|auth\.json|credentials?\.json|client_secrets?[\w.-]*\.json|service[-_]account[\w.-]*\.json|secrets?\.(?:json|ya?ml|php|xml|txt)|id_(?:rsa|dsa|ecdsa|ed25519)[\w.-]*|[\w.-]+\.(?:pem|key|p12|pfx|jks|keystore|kdbx))$/i';

	/** Resolve beneath wp-content/{plugins,themes,mu-plugins}; never follow symlinks. */
	public static function resolve( string $path, bool $new_file = false ) {
		if ( strlen( $path ) > 1024 || ! preg_match( '#^(?:' . implode( '|', self::ROOTS ) . ')(/[A-Za-z0-9_@+.-]+)*$#D', $path ) ) {
			return new \WP_Error( 'invalid_path', __( 'Use a path relative to wp-content, beginning with plugins/, themes/ or mu-plugins/.', 'site-agent' ) );
		}
		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( '.' === $segment || '..' === $segment || '.' === substr( $segment, 0, 1 ) || preg_match( self::PROTECTED_NAMES, $segment ) ) {
				return new \WP_Error( 'protected_path', __( 'Hidden, credential and traversal paths are blocked.', 'site-agent' ) );
			}
		}
		/**
		 * Filters whether a file tool path is blocked.
		 *
		 * @param bool   $blocked Whether the path is blocked.
		 * @param string $path    Path relative to wp-content.
		 */
		if ( apply_filters( 'site_agent_file_blocked', false, $path ) ) {
			return new \WP_Error( 'protected_path', __( 'This path is blocked on this site.', 'site-agent' ) );
		}
		$root = realpath( WP_CONTENT_DIR );
		if ( false === $root ) {
			return new \WP_Error( 'missing_root', __( 'The content directory is unavailable.', 'site-agent' ) );
		}
		$target = $root;
		foreach ( $segments as $segment ) {
			$target .= '/' . $segment;
			if ( is_link( $target ) ) {
				return new \WP_Error( 'symlink_path', __( 'Symlinks are not accessible through file tools.', 'site-agent' ) );
			}
		}
		$check = $new_file && ! file_exists( $target ) ? realpath( dirname( $target ) ) : realpath( $target );
		if ( false === $check || strpos( $check . '/', $root . '/' ) !== 0 ) {
			return new \WP_Error( 'missing_path', __( 'The file or parent directory does not exist.', 'site-agent' ) );
		}
		return $target;
	}

	private static function permitted_file( string $path ): bool {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
	}

	private static function is_php( string $path ): bool {
		return 'php' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/** Never let file tools rewrite Site Agent's own authorization or bundled runtime. */
	private static function own_file( string $path ): bool {
		return strpos( $path . '/', realpath( SITE_AGENT_DIR ) . '/' ) === 0;
	}

	public static function listing( array $input ) {
		$path = self::resolve( $input['path'] );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! is_dir( $path ) ) {
			return new \WP_Error( 'not_directory', __( 'This path is not a directory.', 'site-agent' ) );
		}
		$names = scandir( $path );
		if ( false === $names ) {
			return new \WP_Error( 'read_failed', __( 'The directory could not be read.', 'site-agent' ) );
		}
		$entries = array();
		foreach ( $names as $name ) {
			$relative = $input['path'] . '/' . $name;
			$resolved = self::resolve( $relative );
			if ( is_wp_error( $resolved ) || ( ! is_dir( $resolved ) && ! self::permitted_file( $resolved ) ) ) {
				continue;
			}
			$directory = is_dir( $resolved );
			$entries[] = array(
				'path'         => $relative,
				'type'         => $directory ? 'directory' : 'file',
				'bytes'        => $directory ? 0 : (int) filesize( $resolved ),
				'modified_gmt' => gmdate( 'Y-m-d H:i:s', (int) filemtime( $resolved ) ),
			);
		}
		$offset = (int) ( $input['offset'] ?? 0 );
		$limit  = (int) ( $input['limit'] ?? 100 );
		return array(
			'entries' => array_slice( $entries, $offset, $limit ),
			'total'   => count( $entries ),
		);
	}

	public static function read( array $input ) {
		$path = self::resolve( $input['path'] );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! is_file( $path ) || ! self::permitted_file( $path ) ) {
			return new \WP_Error( 'unsupported_file', __( 'Only supported source and text files can be read.', 'site-agent' ) );
		}
		$data = file_get_contents( $path, false, null, 0, self::MAX_BYTES + 1 );
		if ( false === $data || strlen( $data ) > self::MAX_BYTES || false !== strpos( $data, "\0" ) || ! preg_match( '//u', $data ) ) {
			return new \WP_Error( 'file_limit', __( 'Files must contain UTF-8 text and be at most 256 KiB.', 'site-agent' ) );
		}
		return array(
			'path'         => $input['path'],
			'content'      => $data,
			'sha256'       => hash( 'sha256', $data ),
			'bytes'        => strlen( $data ),
			'modified_gmt' => gmdate( 'Y-m-d H:i:s', (int) filemtime( $path ) ),
		);
	}

	/** Locked compare-and-write prevents overwriting a changed file; fatal PHP changes are reverted. */
	public static function write( array $input ) {
		$path = self::resolve( $input['path'], true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! self::permitted_file( $path ) || strlen( $input['content'] ) > self::MAX_BYTES || false !== strpos( $input['content'], "\0" ) ) {
			return new \WP_Error( 'unsupported_file', __( 'Only supported UTF-8 source files up to 256 KiB can be written.', 'site-agent' ) );
		}
		if ( self::own_file( $path ) ) {
			return new \WP_Error( 'protected_plugin', __( 'Site Agent cannot edit its own files.', 'site-agent' ) );
		}
		$is_new = ! file_exists( $path );
		if ( $is_new && 'new' !== $input['expected_sha256'] ) {
			return new \WP_Error( 'file_conflict', __( 'Use expected_sha256="new" when creating a file.', 'site-agent' ) );
		}
		$checks = array(
			'lint'   => 'not_applicable',
			'health' => 'not_applicable',
		);
		if ( self::is_php( $path ) ) {
			$lint = self::validate_php( $input['content'], $input['path'] );
			if ( is_wp_error( $lint ) ) {
				return $lint;
			}
			$checks['lint'] = $lint;
		}
		$old = self::swap( $path, $is_new, $input['expected_sha256'], $input['content'] );
		if ( is_wp_error( $old ) ) {
			return $old;
		}
		if ( self::is_php( $path ) ) {
			$health = self::after_change( $input['path'], $path );
			if ( 'fatal' === $health['status'] ) {
				$restored = $is_new ? self::remove( $path ) : ! is_wp_error( self::swap( $path, false, hash( 'sha256', $input['content'] ), $old ) );
				self::invalidate( $path );
				return self::reverted( $health['error'] ?? '', $restored );
			}
			$checks['health'] = $health['status'];
		}
		return array(
			'path'   => $input['path'],
			'sha256' => hash( 'sha256', $input['content'] ),
			'bytes'  => strlen( $input['content'] ),
			'checks' => $checks,
		);
	}

	/** Create a directory and any missing parents beneath an allowed root. */
	public static function make_directory( array $input ) {
		$segments = explode( '/', (string) $input['path'] );
		$depths   = count( $segments );
		// The mu-plugins root itself may not exist yet; plugins/ and themes/ always do.
		if ( $depths < 2 && 'mu-plugins' !== $input['path'] ) {
			return new \WP_Error( 'invalid_path', __( 'Create directories inside plugins/, themes/ or mu-plugins/.', 'site-agent' ) );
		}
		$created = array();
		for ( $depth = 1; $depth <= $depths; $depth++ ) {
			$relative = implode( '/', array_slice( $segments, 0, $depth ) );
			$target   = self::resolve( $relative, true );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			if ( is_dir( $target ) ) {
				continue;
			}
			if ( file_exists( $target ) || ! mkdir( $target, defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755 ) ) {
				return new \WP_Error( 'mkdir_failed', __( 'The directory could not be created.', 'site-agent' ) );
			}
			$created[] = $relative;
		}
		return array(
			'path'    => $input['path'],
			'created' => $created,
		);
	}

	/** Delete a file whose hash still matches, or an empty directory. PHP deletions that break the site are undone. */
	public static function delete( array $input ) {
		$path = self::resolve( $input['path'] );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( count( explode( '/', $input['path'] ) ) < 2 || self::own_file( $path ) ) {
			return new \WP_Error( 'protected_path', __( 'Root directories and Site Agent files cannot be deleted.', 'site-agent' ) );
		}
		if ( is_dir( $path ) ) {
			if ( 'directory' !== $input['expected_sha256'] ) {
				return new \WP_Error( 'file_conflict', __( 'Use expected_sha256="directory" to delete an empty directory.', 'site-agent' ) );
			}
			if ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) || ! rmdir( $path ) ) {
				return new \WP_Error( 'delete_failed', __( 'Only empty directories can be deleted.', 'site-agent' ) );
			}
			return array(
				'path'    => $input['path'],
				'deleted' => true,
				'checks'  => array( 'health' => 'not_applicable' ),
			);
		}
		if ( ! is_file( $path ) || ! self::permitted_file( $path ) ) {
			return new \WP_Error( 'unsupported_file', __( 'Only supported source and text files can be deleted.', 'site-agent' ) );
		}
		$old = file_get_contents( $path, false, null, 0, self::MAX_BYTES + 1 );
		if ( false === $old || strlen( $old ) > self::MAX_BYTES || ! hash_equals( hash( 'sha256', $old ), (string) $input['expected_sha256'] ) ) {
			return new \WP_Error( 'file_conflict', __( 'The file changed. Read it again before deleting.', 'site-agent' ) );
		}
		if ( ! self::remove( $path ) ) {
			return new \WP_Error( 'delete_failed', __( 'The file could not be deleted.', 'site-agent' ) );
		}
		$health = self::is_php( $path ) ? self::after_change( $input['path'], $path ) : array( 'status' => 'not_applicable' );
		if ( 'fatal' === $health['status'] ) {
			$restored = ! is_wp_error( self::swap( $path, true, 'new', $old ) );
			self::invalidate( $path );
			return self::reverted( $health['error'] ?? '', $restored );
		}
		return array(
			'path'    => $input['path'],
			'deleted' => true,
			'checks'  => array( 'health' => $health['status'] ),
		);
	}

	/** Rename a file whose hash still matches. The destination must not exist. */
	public static function move( array $input ) {
		$from = self::resolve( $input['from'] );
		$to   = self::resolve( $input['to'], true );
		foreach ( array( $from, $to ) as $resolved ) {
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
		}
		if ( self::own_file( $from ) || self::own_file( $to ) ) {
			return new \WP_Error( 'protected_plugin', __( 'Site Agent cannot move its own files.', 'site-agent' ) );
		}
		if ( ! is_file( $from ) || ! self::permitted_file( $from ) || ! self::permitted_file( $to ) ) {
			return new \WP_Error( 'unsupported_file', __( 'Only supported source and text files can be moved, to a supported file name.', 'site-agent' ) );
		}
		if ( file_exists( $to ) ) {
			return new \WP_Error( 'file_exists', __( 'The destination already exists.', 'site-agent' ) );
		}
		$content = file_get_contents( $from, false, null, 0, self::MAX_BYTES + 1 );
		if ( false === $content || strlen( $content ) > self::MAX_BYTES || ! hash_equals( hash( 'sha256', $content ), (string) $input['expected_sha256'] ) ) {
			return new \WP_Error( 'file_conflict', __( 'The file changed. Read it again before moving.', 'site-agent' ) );
		}
		if ( ! rename( $from, $to ) ) {
			return new \WP_Error( 'move_failed', __( 'The file could not be moved.', 'site-agent' ) );
		}
		self::invalidate( $from );
		$php    = self::is_php( $from ) || self::is_php( $to );
		$health = $php ? self::after_change( $input['to'], $to ) : array( 'status' => 'not_applicable' );
		if ( 'fatal' === $health['status'] ) {
			$restored = rename( $to, $from );
			self::invalidate( $to );
			return self::reverted( $health['error'] ?? '', $restored );
		}
		return array(
			'from'   => $input['from'],
			'to'     => $input['to'],
			'sha256' => hash( 'sha256', $content ),
			'checks' => array( 'health' => $health['status'] ),
		);
	}

	/**
	 * Tokenize, then compile-check with a matching PHP CLI when one is available.
	 *
	 * @return string|\WP_Error Lint status: passed or unavailable.
	 */
	private static function validate_php( string $content, string $label ) {
		try {
			token_get_all( $content, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return new \WP_Error(
				'invalid_php',
				/* translators: 1: line number, 2: PHP parser message. */
				sprintf( __( 'PHP syntax error on line %1$d: %2$s. The file was not changed.', 'site-agent' ), $error->getLine(), $error->getMessage() )
			);
		}
		$lint = Health::lint( $content, $label );
		if ( is_wp_error( $lint ) ) {
			return $lint;
		}
		return true === $lint ? 'passed' : 'unavailable';
	}

	/**
	 * Clear opcode caches and load the site to detect a fatal error.
	 *
	 * @return array{status: string, error?: string}
	 */
	private static function after_change( string $relative, string $path ): array {
		self::invalidate( $path );
		return Health::check( $relative );
	}

	private static function invalidate( string $path ): void {
		if ( ! function_exists( 'wp_opcache_invalidate' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		wp_opcache_invalidate( $path, true );
	}

	private static function reverted( string $error, bool $restored ): \WP_Error {
		$message = $restored
			/* translators: %s: PHP fatal error. */
			? __( 'The change caused a fatal error and was reverted: %s', 'site-agent' )
			/* translators: %s: PHP fatal error. */
			: __( 'The change caused a fatal error and could not be reverted automatically. Restore the file by other means (SFTP or hosting file manager): %s', 'site-agent' );
		return new \WP_Error( $restored ? 'php_fatal_reverted' : 'php_fatal_not_reverted', sprintf( $message, $error ) );
	}

	private static function remove( string $path ): bool {
		wp_delete_file( $path );
		return ! file_exists( $path );
	}

	/**
	 * Compare-and-swap file contents under an exclusive lock.
	 *
	 * @return string|\WP_Error Previous contents ('' for a new file).
	 */
	private static function swap( string $path, bool $is_new, string $expected, string $content ) {
		$handle = fopen( $path, $is_new ? 'x+b' : 'r+b' );
		if ( false === $handle ) {
			return new \WP_Error( 'write_failed', __( 'The file could not be opened for writing.', 'site-agent' ) );
		}
		$written = false;
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return new \WP_Error( 'lock_failed', __( 'The file could not be locked.', 'site-agent' ) );
			}
			$old = stream_get_contents( $handle, self::MAX_BYTES + 1 );
			if ( false === $old || strlen( $old ) > self::MAX_BYTES || ( ! $is_new && ! hash_equals( hash( 'sha256', $old ), $expected ) ) ) {
				return new \WP_Error( 'file_conflict', __( 'The file changed. Read it again before saving.', 'site-agent' ) );
			}
			rewind( $handle );
			if ( ! self::write_all( $handle, $content ) || ! ftruncate( $handle, strlen( $content ) ) || ! fflush( $handle ) ) {
				rewind( $handle );
				self::write_all( $handle, $old );
				ftruncate( $handle, strlen( $old ) );
				fflush( $handle );
				return new \WP_Error( 'write_failed', __( 'The write failed; restoration of the previous content was attempted.', 'site-agent' ) );
			}
			$written = true;
			return $old;
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			if ( $is_new && ! $written ) {
				wp_delete_file( $path );
			}
		}
	}

	private static function write_all( $handle, string $content ): bool {
		$offset = 0;
		$length = strlen( $content );
		while ( $offset < $length ) {
			$count = fwrite( $handle, substr( $content, $offset ) );
			if ( false === $count || 0 === $count ) {
				return false;
			}
			$offset += $count;
		}
		return true;
	}
}
