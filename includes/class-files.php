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
	const EXTENSIONS = array( 'php', 'css', 'js', 'json', 'html', 'txt', 'md', 'svg', 'xml', 'yaml', 'yml', 'scss' );

	/** Resolve beneath wp-content/{plugins,themes}; never follow symlinks. */
	public static function resolve( string $path, bool $new_file = false ) {
		if ( strlen( $path ) > 1024 || ! preg_match( '#^(plugins|themes)(/[A-Za-z0-9_@+.-]+)*$#D', $path ) ) {
			return new \WP_Error( 'invalid_path', __( 'Use a path relative to wp-content, beginning with plugins/ or themes/.', 'site-agent' ) );
		}
		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( '.' === $segment || '..' === $segment || '.' === substr( $segment, 0, 1 ) || preg_match( '/(?:secret|credential|config|private[-_]key)/i', $segment ) ) {
				return new \WP_Error( 'protected_path', __( 'Hidden, configuration, credential, and traversal paths are blocked.', 'site-agent' ) );
			}
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
			$entries[] = array(
				'path' => $relative,
				'type' => is_dir( $resolved ) ? 'directory' : 'file',
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
			'path'    => $input['path'],
			'content' => $data,
			'sha256'  => hash( 'sha256', $data ),
		);
	}

	/** Locked compare-and-write prevents overwriting a changed file. */
	public static function write( array $input ) {
		$path = self::resolve( $input['path'], true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! self::permitted_file( $path ) || strlen( $input['content'] ) > self::MAX_BYTES || false !== strpos( $input['content'], "\0" ) ) {
			return new \WP_Error( 'unsupported_file', __( 'Only supported UTF-8 source files up to 256 KiB can be written.', 'site-agent' ) );
		}
		// Never let this tool rewrite its own authorization or bundled runtime.
		if ( strpos( $path . '/', realpath( SITE_AGENT_DIR ) . '/' ) === 0 ) {
			return new \WP_Error( 'protected_plugin', __( 'Site Agent cannot edit its own files.', 'site-agent' ) );
		}
		$is_new = ! file_exists( $path );
		if ( $is_new && 'new' !== $input['expected_sha256'] ) {
			return new \WP_Error( 'file_conflict', __( 'Use expected_sha256="new" when creating a file.', 'site-agent' ) );
		}
		$handle = fopen( $path, $is_new ? 'x+b' : 'r+b' );
		if ( false === $handle ) {
			return new \WP_Error( 'write_failed', __( 'The file could not be opened for writing.', 'site-agent' ) );
		}
		$result = null;
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return new \WP_Error( 'lock_failed', __( 'The file could not be locked.', 'site-agent' ) );
			}
			$old = stream_get_contents( $handle, self::MAX_BYTES + 1 );
			if ( false === $old || strlen( $old ) > self::MAX_BYTES || ( ! $is_new && ! hash_equals( hash( 'sha256', $old ), $input['expected_sha256'] ) ) ) {
				return new \WP_Error( 'file_conflict', __( 'The file changed. Read it again before saving.', 'site-agent' ) );
			}
			if ( 'php' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				try {
					token_get_all( $input['content'], TOKEN_PARSE );
				} catch ( \ParseError $error ) {
					return new \WP_Error( 'invalid_php', __( 'PHP syntax validation failed; the file was not changed.', 'site-agent' ) );
				}
			}
			rewind( $handle );
			$bytes = self::write_all( $handle, $input['content'] );
			if ( ! $bytes || ! ftruncate( $handle, strlen( $input['content'] ) ) || ! fflush( $handle ) ) {
				rewind( $handle );
				self::write_all( $handle, $old );
				ftruncate( $handle, strlen( $old ) );
				fflush( $handle );
				return new \WP_Error( 'write_failed', __( 'The write failed; restoration of the previous content was attempted.', 'site-agent' ) );
			}
			$result = array(
				'path'   => $input['path'],
				'sha256' => hash( 'sha256', $input['content'] ),
				'bytes'  => strlen( $input['content'] ),
			);
			return $result;
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			if ( $is_new && null === $result ) {
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
