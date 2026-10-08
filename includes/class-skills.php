<?php
/**
 * Page builder skills bundled with the plugin and served as read-only guidance.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading bundled plugin files from local disk.

/** Page builder skills bundled with the plugin, plus skills other plugins register, served as read-only guidance. */
final class Skills {
	/** Bundled skill names in listing order. bin/sync-skills.py checks the directories against this list. */
	const CATALOG    = array( 'gutenberg', 'generateblocks', 'elementor', 'bricks', 'divi' );
	const MAX_BYTES  = 65536;
	const EXTENSIONS = array( 'md', 'json', 'html' );
	const PATH       = '^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$';
	/** Names other plugins may register. */
	const NAME = '^[a-z0-9][a-z0-9-]{1,39}$';
	/** At most this many registered skills are listed. */
	const MAX_REGISTERED = 20;
	/**
	 * Plugins known to add skills through site_agent_skills, shown in Tools > Site Agent.
	 * Keyed by plugin file: name, product URL and what an agent can do with them.
	 */
	const PARTNERS = array(
		'gt-extensions-fluentcart/gt-extensions-fluentcart.php' => array(
			'name' => 'GT Extensions for FluentCart',
			'url'  => 'https://gauravtiwari.org/product/gt-extensions-for-fluentcart/',
			'does' => 'Draft, issue and email invoices; price and send quote proposals.',
		),
		'page-blocks-builder/page-blocks-builder.php' => array(
			'name' => 'GT Page Blocks Builder',
			'url'  => 'https://gauravtiwari.org/product/gt-page-blocks-builder/',
			'does' => 'Build draft pages from HTML, CSS and JavaScript sections; edit sections and the block library.',
		),
		'gt-link-manager/gt-link-manager.php'         => array(
			'name' => 'GT Link Manager',
			'url'  => 'https://wordpress.org/plugins/gt-link-manager/',
			'does' => 'Find, create and update short links and country rules; read link analytics.',
		),
	);

	/**
	 * Skills registered by other plugins through the site_agent_skills filter, keyed by name.
	 *
	 * A registration is `'name' => array( 'directory' => '/absolute/path/', 'plugin' => 'Label', 'version' => '1.0' )`.
	 * The directory must contain SKILL.md. Bundled names cannot be replaced, and only the same
	 * Markdown, JSON and HTML files as bundled skills are served.
	 *
	 * @return array<string, array{directory: string, plugin: string, version: string}>
	 */
	public static function registered(): array {
		$skills = apply_filters( 'site_agent_skills', array() );
		$clean  = array();
		foreach ( is_array( $skills ) ? $skills : array() as $name => $skill ) {
			if ( count( $clean ) >= self::MAX_REGISTERED ) {
				break;
			}
			if ( ! is_string( $name ) || ! preg_match( '/' . self::NAME . '/D', $name ) || in_array( $name, self::CATALOG, true ) || ! is_array( $skill ) || ! is_string( $skill['directory'] ?? null ) ) {
				continue;
			}
			$directory = realpath( $skill['directory'] );
			if ( false === $directory || ! is_dir( $directory ) || ! is_file( $directory . '/SKILL.md' ) ) {
				continue;
			}
			$clean[ $name ] = array(
				'directory' => trailingslashit( $directory ),
				'plugin'    => sanitize_text_field( (string) ( $skill['plugin'] ?? '' ) ),
				'version'   => sanitize_text_field( (string) ( $skill['version'] ?? '' ) ),
			);
		}
		return $clean;
	}

	/**
	 * Every skill name: bundled first, then registered.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_merge( self::CATALOG, array_keys( self::registered() ) );
	}

	public static function directory( string $skill ): string {
		$registered = self::registered();
		return isset( $registered[ $skill ] ) ? $registered[ $skill ]['directory'] : SITE_AGENT_DIR . 'skills/' . $skill . '/';
	}

	/**
	 * Builders active on this site, keyed by skill name.
	 *
	 * @return array<string, array{name: string, version: string, pro_version: string, abilities?: bool}>
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
				// Bricks 2.4+ registers its own abilities; Site Agent serves them with the Bricks tools group.
				'abilities'   => Bricks::available(),
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
		// Bricks renders its data unless the editor mode is the classic editor; layouts saved before
		// Bricks 2.0 or written programmatically often have no mode at all.
		$bricks_mode = get_post_meta( $post->ID, '_bricks_editor_mode', true );
		if ( 'bricks' === $bricks_mode || ( 'wordpress' !== $bricks_mode && self::has_bricks_data( $post->ID ) ) ) { // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Bricks stores the lowercase slug.
			return 'bricks';
		}
		// Divi 4 keeps shortcodes in post_content; Divi 5 uses divi/* blocks, sometimes without the flag.
		if ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) || false !== strpos( $post->post_content, '<!-- wp:divi/' ) ) {
			return 'divi';
		}
		// GenerateBlocks Pro global styles, conditions and other records keep their data in post meta.
		if ( 0 === strpos( $post->post_type, 'gblocks_' )
			|| false !== strpos( $post->post_content, '<!-- wp:generateblocks/' )
			|| false !== strpos( $post->post_content, '<!-- wp:generateblocks-pro/' ) ) {
			return 'generateblocks';
		}
		return has_blocks( $post->post_content ) ? 'gutenberg' : 'classic';
	}

	private static function has_bricks_data( int $post_id ): bool {
		foreach ( array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' ) as $key ) {
			if ( get_post_meta( $post_id, $key, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Servable files of a skill, relative to its directory.
	 *
	 * @return string[]
	 */
	public static function files( string $skill ): array {
		$base = self::directory( $skill );
		if ( ! in_array( $skill, self::names(), true ) || ! is_dir( $base ) ) {
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

	/**
	 * Known partner plugins with their state here: active (with the skills they registered), installed or missing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function partners(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed  = get_plugins();
		$registered = self::registered();
		$out        = array();
		foreach ( self::PARTNERS as $file => $partner ) {
			$skills = array();
			foreach ( $registered as $skill => $registration ) {
				if ( $partner['name'] === $registration['plugin'] ) {
					$skills[] = $skill;
				}
			}
			$state = isset( $installed[ $file ] ) ? ( is_plugin_active( $file ) ? 'active' : 'installed' ) : 'missing';
			$out[] = $partner + array(
				'file'   => $file,
				'state'  => $state,
				'skills' => $skills,
			);
		}
		return $out;
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
				'source'      => 'site-agent',
				'files'       => self::files( $skill ),
			);
		}
		foreach ( self::registered() as $skill => $registration ) {
			$skills[] = array(
				'name'        => $skill,
				'description' => self::description( $skill ),
				// A registering plugin is active by definition.
				'detected'    => true,
				'version'     => $registration['version'],
				'source'      => '' !== $registration['plugin'] ? $registration['plugin'] : 'plugin',
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
