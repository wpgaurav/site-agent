<?php
/**
 * Page builder skills bundled with the plugin and served as read-only guidance.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading bundled plugin files from local disk.

/** Page builder skills bundled with the plugin and served as read-only guidance. */
final class Skills {
	/** Skill names in listing order. bin/sync-skills.py checks the directories against this list. */
	const CATALOG    = array( 'gutenberg', 'generateblocks', 'elementor', 'bricks', 'divi' );
	const MAX_BYTES  = 65536;
	const EXTENSIONS = array( 'md', 'json', 'html' );
	const PATH       = '^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$';

	public static function directory( string $skill ): string {
		return SITE_AGENT_DIR . 'skills/' . $skill . '/';
	}

	/**
	 * Builders active on this site, keyed by skill name.
	 *
	 * @return array<string, array{name: string, version: string, pro_version: string}>
	 */
	public static function detected(): array {
		$template = get_template();
		$found    = array(
			'gutenberg' => array(
				'name'        => 'Gutenberg',
				'version'     => defined( 'GUTENBERG_VERSION' ) ? (string) GUTENBERG_VERSION : (string) get_bloginfo( 'version' ),
				'pro_version' => '',
			),
		);
		if ( defined( 'GENERATEBLOCKS_VERSION' ) ) {
			$found['generateblocks'] = array(
				'name'        => 'GenerateBlocks',
				'version'     => (string) GENERATEBLOCKS_VERSION,
				'pro_version' => defined( 'GENERATEBLOCKS_PRO_VERSION' ) ? (string) GENERATEBLOCKS_PRO_VERSION : '',
			);
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$found['elementor'] = array(
				'name'        => 'Elementor',
				'version'     => (string) ELEMENTOR_VERSION,
				'pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '',
			);
		}
		if ( 'bricks' === $template || defined( 'BRICKS_VERSION' ) ) {
			$found['bricks'] = array(
				'name'        => 'Bricks',
				'version'     => defined( 'BRICKS_VERSION' ) ? (string) BRICKS_VERSION : (string) wp_get_theme( 'bricks' )->get( 'Version' ),
				'pro_version' => '',
			);
		}
		$divi_theme = in_array( $template, array( 'Divi', 'Extra' ), true );
		if ( $divi_theme || defined( 'ET_BUILDER_PLUGIN_ACTIVE' ) ) {
			$found['divi'] = array(
				'name'        => $divi_theme ? $template : 'Divi Builder',
				// The product version; ET_BUILDER_VERSION is the parent theme's version.
				'version'     => defined( 'ET_BUILDER_PRODUCT_VERSION' ) ? (string) ET_BUILDER_PRODUCT_VERSION : (string) wp_get_theme( $template )->get( 'Version' ),
				'pro_version' => '',
			);
		}
		return $found;
	}

	/** The skill that matches where this post's layout is stored. */
	public static function post_builder( \WP_Post $post ): string {
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return 'elementor';
		}
		if ( 'bricks' === get_post_meta( $post->ID, '_bricks_editor_mode', true ) ) {
			return 'bricks';
		}
		// Divi 4 keeps shortcodes in post_content; Divi 5 uses divi/* blocks, sometimes without the flag.
		if ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) || false !== strpos( $post->post_content, '<!-- wp:divi/' ) ) {
			return 'divi';
		}
		if ( false !== strpos( $post->post_content, '<!-- wp:generateblocks/' ) ) {
			return 'generateblocks';
		}
		return has_blocks( $post->post_content ) ? 'gutenberg' : 'classic';
	}

	/**
	 * Servable files of a skill, relative to its directory.
	 *
	 * @return string[]
	 */
	public static function files( string $skill ): array {
		$base = self::directory( $skill );
		if ( ! in_array( $skill, self::CATALOG, true ) || ! is_dir( $base ) ) {
			return array();
		}
		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $base ) ) );
			if ( $file->isLink() || ! $file->isFile() || $file->getSize() > self::MAX_BYTES
				|| ! in_array( strtolower( $file->getExtension() ), self::EXTENSIONS, true )
				|| ! preg_match( '#' . self::PATH . '#', $relative ) ) {
				continue;
			}
			$files[] = $relative;
		}
		sort( $files );
		return $files;
	}

	/** The description line of a skill's front matter. */
	private static function description( string $skill ): string {
		$file = self::directory( $skill ) . 'SKILL.md';
		$text = is_readable( $file ) ? (string) file_get_contents( $file, false, null, 0, 4096 ) : '';
		return preg_match( '/^description: (.+)$/m', $text, $match ) ? trim( $match[1] ) : '';
	}

	public static function listing(): array {
		$detected = self::detected();
		$skills   = array();
		foreach ( self::CATALOG as $skill ) {
			$skills[] = array(
				'name'        => $skill,
				'description' => self::description( $skill ),
				'detected'    => isset( $detected[ $skill ] ),
				'version'     => $detected[ $skill ]['version'] ?? '',
				'files'       => self::files( $skill ),
			);
		}
		return array( 'skills' => $skills );
	}

	public static function read( array $input ) {
		$skill = $input['skill'];
		$path  = $input['path'] ?? 'SKILL.md';
		$files = self::files( $skill );
		// Only listed files are served, so traversal, symlinks and other types never resolve.
		if ( ! in_array( $path, $files, true ) ) {
			return new \WP_Error(
				'skill_file_unavailable',
				/* translators: 1: requested path, 2: skill name. */
				sprintf( __( '%1$s is not a file of the %2$s skill. Call list-skills for its files.', 'site-agent' ), $path, $skill )
			);
		}
		$result = array(
			'skill'   => $skill,
			'path'    => $path,
			'content' => (string) file_get_contents( self::directory( $skill ) . $path ),
		);
		if ( 'SKILL.md' === $path ) {
			$result['files'] = $files;
		}
		return $result;
	}
}
