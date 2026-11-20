<?php
/**
 * Official Abilities API and MCP server wiring.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Official Abilities API and MCP server wiring. */
final class Abilities {
	public static function register_category(): void {
		wp_register_ability_category(
			'site-agent',
			array(
				'label'       => __( 'Site Agent', 'site-agent' ),
				'description' => __( 'Authenticated WordPress content and developer tools.', 'site-agent' ),
			)
		);
	}

	/** Build a JSON Schema property with a description. */
	private static function field( string $type, string $description, array $extra = array() ): array {
		return array(
			'type'        => $type,
			'description' => $description,
		) + $extra;
	}

	private static function string( string $description, int $max = 1024, array $extra = array() ): array {
		return self::field( 'string', $description, array( 'maxLength' => $max ) + $extra );
	}

	private static function object( array $properties, array $required = array() ): array {
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => $required,
		);
	}

	private static function list_of( array $items ): array {
		return array(
			'type'  => 'array',
			'items' => $items,
		);
	}

	private static function paging( int $max = 100, int $fallback = 20 ): array {
		return array(
			'limit'  => self::field(
				'integer',
				/* translators: 1: maximum page size, 2: default page size. */
				sprintf( __( 'Results per page, 1 to %1$d. Default %2$d.', 'site-agent' ), $max, $fallback ),
				array(
					'minimum' => 1,
					'maximum' => $max,
				)
			),
			'page'   => self::field( 'integer', __( 'Page number, starting at 1.', 'site-agent' ), array( 'minimum' => 1 ) ),
			'search' => self::string( __( 'Optional search text.', 'site-agent' ), 200 ),
		);
	}

	private static function post_output(): array {
		$string = array( 'type' => 'string' );
		$int    = array( 'type' => 'integer' );
		return self::object(
			array(
				'id'             => $int,
				'type'           => $string,
				'title'          => $string,
				'slug'           => $string,
				'status'         => $string,
				'content'        => $string,
				'excerpt'        => $string,
				'author'         => $int,
				'date_gmt'       => $string,
				'modified_gmt'   => $string,
				'link'           => $string,
				'featured_media' => $int,
				'terms'          => array(
					'type'                 => 'object',
					'additionalProperties' => self::list_of( $int ),
				),
				'meta'           => array( 'type' => 'object' ),
				'autosave'       => array( 'type' => array( 'object', 'null' ) ),
				'builder'        => $string,
				'content_sha256' => $string,
			),
			array( 'id', 'status', 'content', 'content_sha256' )
		);
	}

	private static function media_output(): array {
		$string = array( 'type' => 'string' );
		$int    = array( 'type' => 'integer' );
		return self::object(
			array(
				'id'        => $int,
				'title'     => $string,
				'url'       => $string,
				'mime_type' => $string,
				'alt'       => $string,
				'caption'   => $string,
				'width'     => $int,
				'height'    => $int,
				'parent'    => $int,
			),
			array( 'id', 'url' )
		);
	}

	private static function checks_output(): array {
		return self::object(
			array(
				'lint'   => array( 'type' => 'string' ),
				'health' => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Tool definitions keyed by tool name.
	 *
	 * Each has label, description, group (the settings switch, '' for always-available reads),
	 * callback, input (properties), required, output (schema) and readonly, plus optional
	 * destructive and idempotent annotations.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$post_id   = self::field( 'integer', __( 'Post ID.', 'site-agent' ), array( 'minimum' => 1 ) );
		$post_type = self::string( __( 'REST-enabled post type such as post or page. Default post.', 'site-agent' ), 64 );
		$path      = self::string( __( 'Path relative to wp-content, for example plugins/my-plugin/my-plugin.php or themes/my-theme/style.css.', 'site-agent' ) );
		$sha       = self::field( 'string', __( 'SHA-256 from read-file, proving you edit the current version.', 'site-agent' ), array( 'pattern' => '^[a-f0-9]{64}$' ) );
		$media_id  = self::field( 'integer', __( 'Attachment ID.', 'site-agent' ), array( 'minimum' => 1 ) );
		$media     = array(
			'title'       => self::string( __( 'Media title.', 'site-agent' ), 2000 ),
			'alt'         => self::string( __( 'Alternative text describing the image for screen readers.', 'site-agent' ), 2000 ),
			'caption'     => self::string( __( 'Caption shown with the media.', 'site-agent' ), 10000 ),
			'description' => self::string( __( 'Attachment page description.', 'site-agent' ), 10000 ),
		);
		$timeout   = Developer::cli_timeout();
		return array(
			'site-context'       => array(
				'label'       => __( 'Site context', 'site-agent' ),
				'description' => __( 'Inspect WordPress, PHP, plugins, theme, page builders, plugin workflow skills, post types, taxonomies, the tools this connection can use and how it authenticated. Start here. Read the matching integration skill before plugin work. If connection has a warning, pass it on to the user.', 'site-agent' ),
				'group'       => '',
				'callback'    => array( Content::class, 'context' ),
				'input'       => array(),
				'required'    => array(),
				'output'      => self::object(
					array(
						'site_url'      => array( 'type' => 'string' ),
						'wordpress'     => array( 'type' => 'string' ),
						'php'           => array( 'type' => 'string' ),
						'environment'   => array( 'type' => 'string' ),
						'plugins'       => self::list_of( array( 'type' => 'object' ) ),
						'post_types'    => self::list_of( array( 'type' => 'string' ) ),
						'taxonomies'    => self::list_of( array( 'type' => 'string' ) ),
						'enabled_tools' => self::list_of( array( 'type' => 'string' ) ),
						'builders'      => self::list_of( array( 'type' => 'object' ) ),
						'integrations'  => self::list_of(
							self::object(
								array(
									'skill'   => array( 'type' => 'string' ),
									'name'    => array( 'type' => 'string' ),
									'file'    => array( 'type' => 'string' ),
									'state'   => array(
										'type' => 'string',
										'enum' => array( 'active', 'installed', 'missing' ),
									),
									'version' => array( 'type' => 'string' ),
									'skills'  => self::list_of( array( 'type' => 'string' ) ),
								),
								array( 'skill', 'name', 'file', 'state', 'version', 'skills' )
							)
						),
						'connection'    => self::object(
							array(
								'method'  => array( 'type' => 'string' ),
								'client'  => array( 'type' => 'string' ),
								'warning' => array( 'type' => 'string' ),
							),
							array( 'method' )
						),
					),
					array( 'site_url', 'wordpress', 'enabled_tools' )
				),
				'readonly'    => true,
			),
			'list-content'       => array(
				'label'       => __( 'List content', 'site-agent' ),
				'description' => __( 'Search and paginate posts in a REST-enabled post type, most recently modified first.', 'site-agent' ),
				'group'       => '',
				'callback'    => array( Content::class, 'listing' ),
				'input'       => self::paging() + array(
					'post_type' => $post_type,
					'status'    => self::field(
						'string',
						__( 'Only this status. Default: every status except trash.', 'site-agent' ),
						array( 'enum' => Content::STATUSES )
					),
					'orderby'   => self::field(
						'string',
						__( 'Sort field. Default modified.', 'site-agent' ),
						array( 'enum' => array( 'modified', 'date', 'title', 'id' ) )
					),
					'order'     => self::field(
						'string',
						__( 'Sort direction. Default desc (asc for title).', 'site-agent' ),
						array( 'enum' => array( 'asc', 'desc' ) )
					),
				),
				'required'    => array(),
				'output'      => self::object(
					array(
						'posts' => self::list_of( array( 'type' => 'object' ) ),
						'total' => array( 'type' => 'integer' ),
						'pages' => array( 'type' => 'integer' ),
					),
					array( 'posts', 'total' )
				),
				'readonly'    => true,
			),
			'get-content'        => array(
				'label'       => __( 'Read content', 'site-agent' ),
				'description' => __( 'Read a post by ID, URL or slug: raw Gutenberg markup, terms, featured image, SEO meta, the builder that stores its layout, your autosave if any, and the content hash needed for updates. Pass autosave_content to read the text of your staged autosave.', 'site-agent' ),
				'group'       => '',
				'callback'    => array( Content::class, 'read' ),
				'input'       => array(
					'post_id'          => $post_id,
					'url'              => self::string( __( 'Permalink of the post, as an alternative to post_id.', 'site-agent' ), 2048 ),
					'slug'             => self::string( __( 'Post slug, as an alternative to post_id. Combine with post_type for pages or custom types.', 'site-agent' ), 200 ),
					'post_type'        => $post_type,
					'autosave_content' => self::field( 'boolean', __( 'Also return the title, content and excerpt of your autosave. Edits to live posts are staged there, so read it back after such a save.', 'site-agent' ) ),
				),
				'required'    => array(),
				'output'      => self::post_output(),
				'readonly'    => true,
			),
			'save-content'       => array(
				'label'       => __( 'Save content', 'site-agent' ),
				'description' => __( 'Create a draft or update a post through WordPress. Updates need the current content hash from get-content. Edits to a published, private or scheduled post are saved as your autosave for review unless you pass status (for example publish) to change the live post. Publishing or scheduling always requires status.', 'site-agent' ),
				'group'       => 'content_write',
				'callback'    => array( Content::class, 'save' ),
				'input'       => array(
					'post_id'                 => self::field( 'integer', __( 'Post to update. Omit to create a post.', 'site-agent' ), array( 'minimum' => 1 ) ),
					'post_type'               => self::string( __( 'Post type for a new post. Default post.', 'site-agent' ), 64 ),
					'title'                   => self::string( __( 'Plain-text title.', 'site-agent' ), 2000 ),
					'content'                 => self::string( __( 'Complete post content, usually serialized Gutenberg block markup. Replaces the existing content.', 'site-agent' ), 1000000 ),
					'excerpt'                 => self::string( __( 'Manual excerpt.', 'site-agent' ), 100000 ),
					'status'                  => self::field(
						'string',
						__( 'New status. Omit to keep drafts as drafts and stage edits to live posts. future needs a future date_gmt.', 'site-agent' ),
						array( 'enum' => Content::STATUSES )
					),
					'slug'                    => self::string( __( 'URL slug. WordPress makes it unique.', 'site-agent' ), 200 ),
					'date_gmt'                => self::string( __( 'Publish date in UTC, ISO 8601, for example 2026-10-05T09:00:00Z. A future date with status publish or future schedules the post.', 'site-agent' ), 40 ),
					'terms'                   => array(
						'type'                 => 'object',
						'description'          => __( 'Replace terms per taxonomy, for example {"category": [3], "post_tag": ["seo", 12]}. Integers are term IDs; strings are names, created when missing. Use list-terms to find IDs.', 'site-agent' ),
						'additionalProperties' => array(
							'type'     => 'array',
							'maxItems' => 100,
							'items'    => array(
								'type'      => array( 'integer', 'string' ),
								'maxLength' => 200,
							),
						),
					),
					'featured_media'          => self::field( 'integer', __( 'Image attachment ID for the featured image, or 0 to remove it.', 'site-agent' ), array( 'minimum' => 0 ) ),
					'meta'                    => array(
						'type'                 => 'object',
						'description'          => __( 'Post meta to set, for example SEO title and description fields. Only keys listed in get-content meta support are accepted; an empty string deletes the value.', 'site-agent' ),
						'additionalProperties' => array(
							'type'      => array( 'string', 'number', 'integer', 'boolean' ),
							'maxLength' => 10000,
						),
					),
					'expected_content_sha256' => self::field( 'string', __( 'content_sha256 from get-content. Required for updates.', 'site-agent' ), array( 'pattern' => '^[a-f0-9]{64}$' ) ),
				),
				'required'    => array(),
				'output'      => self::post_output(),
				'readonly'    => false,
			),
			'list-terms'         => array(
				'label'       => __( 'List terms', 'site-agent' ),
				'description' => __( 'Find categories, tags or other taxonomy terms and their IDs.', 'site-agent' ),
				'group'       => '',
				'callback'    => array( Content::class, 'terms' ),
				'input'       => self::paging( 100, 50 ) + array(
					'taxonomy' => self::string( __( 'REST-enabled taxonomy such as category or post_tag. Default category.', 'site-agent' ), 64 ),
				),
				'required'    => array(),
				'output'      => self::object(
					array(
						'terms' => self::list_of( array( 'type' => 'object' ) ),
						'total' => array( 'type' => 'integer' ),
					),
					array( 'terms', 'total' )
				),
				'readonly'    => true,
			),
			'list-media'         => array(
				'label'       => __( 'List media', 'site-agent' ),
				'description' => __( 'Find existing media library items with their URLs, alt text and dimensions. Prefer existing media over new uploads.', 'site-agent' ),
				'group'       => '',
				'callback'    => array( Media::class, 'listing' ),
				'input'       => self::paging() + array(
					'mime_type' => self::string( __( 'Filter by MIME type or family, for example image or image/png.', 'site-agent' ), 100, array( 'pattern' => '^[a-z]+(/[a-z0-9.+-]+)?$' ) ),
				),
				'required'    => array(),
				'output'      => self::object(
					array(
						'media' => self::list_of( self::media_output() ),
						'total' => array( 'type' => 'integer' ),
					),
					array( 'media', 'total' )
				),
				'readonly'    => true,
			),
			'list-skills'        => array(
				'label'       => __( 'List skills', 'site-agent' ),
				'description' => __( 'List bundled builder and plugin skills, plus skills active plugins add. Includes Core Forms, Core Commerce, MailerHive, Core Support, FluentCart, GT Extensions for FluentCart, Stackless and Page Blocks Builder (PBB), with installed versions, whether each is active and its files. Read the matching skill before layout or plugin work.', 'site-agent' ),
				'group'       => '',
				'callback'    => array( Skills::class, 'listing' ),
				'input'       => array(),
				'required'    => array(),
				'output'      => self::object(
					array(
						'skills' => self::list_of(
							self::object(
								array(
									'name'        => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'detected'    => array( 'type' => 'boolean' ),
									'version'     => array( 'type' => 'string' ),
									'source'      => array( 'type' => 'string' ),
									'files'       => self::list_of( array( 'type' => 'string' ) ),
								),
								array( 'name', 'detected', 'files' )
							)
						),
					),
					array( 'skills' )
				),
				'readonly'    => true,
			),
			'get-skill'          => array(
				'label'       => __( 'Read skill', 'site-agent' ),
				'description' => __( 'Read a skill from list-skills: a bundled builder or plugin skill, or one an active plugin adds. Start with its SKILL.md, which explains the workflow and which reference files to read next.', 'site-agent' ),
				'group'       => '',
				'callback'    => array( Skills::class, 'read' ),
				'input'       => array(
					'skill' => self::field( 'string', __( 'Skill name from list-skills.', 'site-agent' ), array( 'enum' => Skills::names() ) ),
					'path'  => self::string( __( 'File within the skill, for example references/elements.md. Default SKILL.md.', 'site-agent' ), 200, array( 'pattern' => Skills::PATH ) ),
				),
				'required'    => array( 'skill' ),
				'output'      => self::object(
					array(
						'skill'   => array( 'type' => 'string' ),
						'path'    => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'files'   => self::list_of( array( 'type' => 'string' ) ),
					),
					array( 'skill', 'path', 'content' )
				),
				'readonly'    => true,
			),
			'bricks-abilities'   => array(
				'label'       => __( 'List Bricks abilities', 'site-agent' ),
				'description' => __( 'List Bricks Builder\'s own abilities on this site: name, label, one-line summary, readonly and destructive hints, and the tool name of those also served directly (bricks-*). Pass ability_name for the full description and the input and output schemas. Use this where Bricks guidance names mcp-adapter-discover-abilities or mcp-adapter-get-ability-info.', 'site-agent' ),
				'group'       => 'bricks',
				'callback'    => array( Bricks::class, 'listing' ),
				'input'       => array(
					'ability_name' => self::string( __( 'A Bricks ability, for example bricks/get-page-elements, to return in full with its schemas.', 'site-agent' ), 100, array( 'pattern' => Bricks::NAME ) ),
					'search'       => self::string( __( 'Only list abilities whose name, label or description contains this text.', 'site-agent' ), 100 ),
				),
				'required'    => array(),
				'output'      => self::object(
					array(
						'version'   => array( 'type' => 'string' ),
						'abilities' => self::list_of(
							self::object(
								array(
									'name'          => array( 'type' => 'string' ),
									'label'         => array( 'type' => 'string' ),
									'description'   => array( 'type' => 'string' ),
									'direct_tool'   => array( 'type' => 'string' ),
									'readonly'      => array( 'type' => 'boolean' ),
									'destructive'   => array( 'type' => 'boolean' ),
									'input_schema'  => array( 'type' => 'object' ),
									'output_schema' => array( 'type' => 'object' ),
								),
								array( 'name', 'description' )
							)
						),
					),
					array( 'abilities' )
				),
				'readonly'    => true,
			),
			'run-bricks-ability' => array(
				'label'       => __( 'Run Bricks ability', 'site-agent' ),
				'description' => __( 'Run one of Bricks Builder\'s own abilities with its parameters. Use this where Bricks guidance names mcp-adapter-execute-ability; ability_name and parameters work the same way. Bricks checks its own permissions and validates the parameters against the ability\'s input schema. Abilities that are not readonly change the site: follow Bricks\' readback and revision guidance.', 'site-agent' ),
				'group'       => 'bricks',
				'callback'    => array( Bricks::class, 'run' ),
				'input'       => array(
					'ability_name' => self::string( __( 'Bricks ability name from bricks-abilities, for example bricks/get-page-elements.', 'site-agent' ), 100, array( 'pattern' => Bricks::NAME ) ),
					'parameters'   => self::field( 'object', __( 'The ability\'s input, matching its input schema. Omit for abilities that take no input.', 'site-agent' ) ),
				),
				'required'    => array( 'ability_name' ),
				'output'      => self::object(
					array(
						'ability_name' => array( 'type' => 'string' ),
						'result'       => array( 'type' => array( 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' ) ),
					),
					array( 'ability_name' )
				),
				'readonly'    => false,
			),
			'upload-media'       => array(
				'label'       => __( 'Upload media', 'site-agent' ),
				'description' => __( 'Import a file into the media library from a public URL or base64 data, with alt text. WordPress checks the file type. Use the returned ID as featured_media or the URL in content.', 'site-agent' ),
				'group'       => 'content_write',
				'callback'    => array( Media::class, 'upload' ),
				'input'       => $media + array(
					'url'         => self::string( __( 'Public http(s) URL to import. Private and loopback addresses are refused.', 'site-agent' ), 2048 ),
					'data_base64' => self::string( __( 'File contents as base64, up to 10 MiB. Requires filename.', 'site-agent' ), 14000000 ),
					'filename'    => self::string( __( 'File name with extension, for example hero.png. Required with data_base64.', 'site-agent' ), 200 ),
					'post_id'     => self::field( 'integer', __( 'Optional post to attach the media to.', 'site-agent' ), array( 'minimum' => 1 ) ),
				),
				'required'    => array(),
				'output'      => self::media_output(),
				'readonly'    => false,
				'destructive' => false,
			),
			'update-media'       => array(
				'label'       => __( 'Update media', 'site-agent' ),
				'description' => __( 'Set the title, alt text, caption or description of an existing media item.', 'site-agent' ),
				'group'       => 'content_write',
				'callback'    => array( Media::class, 'update' ),
				'input'       => array( 'id' => $media_id ) + $media,
				'required'    => array( 'id' ),
				'output'      => self::media_output(),
				'readonly'    => false,
				'idempotent'  => true,
			),
			'list-files'         => array(
				'label'       => __( 'List source files', 'site-agent' ),
				'description' => __( 'Browse plugin, theme and must-use plugin directories. Hidden files, credential files and symlinks are hidden.', 'site-agent' ),
				'group'       => 'file_read',
				'callback'    => array( Files::class, 'listing' ),
				'input'       => array(
					'path'   => self::string( __( 'Directory relative to wp-content, for example plugins or themes/my-theme.', 'site-agent' ) ),
					'offset' => self::field( 'integer', __( 'Entries to skip. Default 0.', 'site-agent' ), array( 'minimum' => 0 ) ),
					'limit'  => self::field(
						'integer',
						__( 'Entries to return, 1 to 200. Default 100.', 'site-agent' ),
						array(
							'minimum' => 1,
							'maximum' => 200,
						)
					),
				),
				'required'    => array( 'path' ),
				'output'      => self::object(
					array(
						'entries' => self::list_of( array( 'type' => 'object' ) ),
						'total'   => array( 'type' => 'integer' ),
					),
					array( 'entries', 'total' )
				),
				'readonly'    => true,
			),
			'read-file'          => array(
				'label'       => __( 'Read source file', 'site-agent' ),
				'description' => __( 'Read a UTF-8 plugin or theme source file up to 256 KiB and obtain the SHA-256 hash needed to change it.', 'site-agent' ),
				'group'       => 'file_read',
				'callback'    => array( Files::class, 'read' ),
				'input'       => array( 'path' => $path ),
				'required'    => array( 'path' ),
				'output'      => self::object(
					array(
						'path'    => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'sha256'  => array( 'type' => 'string' ),
						'bytes'   => array( 'type' => 'integer' ),
					),
					array( 'path', 'content', 'sha256' )
				),
				'readonly'    => true,
			),
			'write-file'         => array(
				'label'       => __( 'Write source file', 'site-agent' ),
				'description' => __( 'Create or replace a plugin or theme source file. Supply its current hash, or new for a new file. PHP is syntax- and compile-checked first; after saving, the site is loaded and a change that causes a fatal error is reverted. This changes executable code.', 'site-agent' ),
				'group'       => 'file_write',
				'callback'    => array( Files::class, 'write' ),
				'input'       => array(
					'path'            => $path,
					'content'         => self::string( __( 'Complete new file contents.', 'site-agent' ), Files::MAX_BYTES ),
					'expected_sha256' => self::field( 'string', __( 'sha256 from read-file, or new to create a file that must not exist yet.', 'site-agent' ), array( 'pattern' => '^(new|[a-f0-9]{64})$' ) ),
				),
				'required'    => array( 'path', 'content', 'expected_sha256' ),
				'output'      => self::object(
					array(
						'path'   => array( 'type' => 'string' ),
						'sha256' => array( 'type' => 'string' ),
						'bytes'  => array( 'type' => 'integer' ),
						'checks' => self::checks_output(),
					),
					array( 'path', 'sha256' )
				),
				'readonly'    => false,
			),
			'create-directory'   => array(
				'label'       => __( 'Create directory', 'site-agent' ),
				'description' => __( 'Create a directory, and any missing parents, inside plugins/, themes/ or mu-plugins/.', 'site-agent' ),
				'group'       => 'file_write',
				'callback'    => array( Files::class, 'make_directory' ),
				'input'       => array( 'path' => self::string( __( 'Directory relative to wp-content, for example plugins/my-plugin/includes.', 'site-agent' ) ) ),
				'required'    => array( 'path' ),
				'output'      => self::object(
					array(
						'path'    => array( 'type' => 'string' ),
						'created' => self::list_of( array( 'type' => 'string' ) ),
					),
					array( 'path', 'created' )
				),
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'delete-file'        => array(
				'label'       => __( 'Delete source file', 'site-agent' ),
				'description' => __( 'Delete a plugin or theme file whose hash still matches, or an empty directory. Deleting PHP that breaks the site is undone.', 'site-agent' ),
				'group'       => 'file_write',
				'callback'    => array( Files::class, 'delete' ),
				'input'       => array(
					'path'            => $path,
					'expected_sha256' => self::field( 'string', __( 'sha256 from read-file, or directory to delete an empty directory.', 'site-agent' ), array( 'pattern' => '^(directory|[a-f0-9]{64})$' ) ),
				),
				'required'    => array( 'path', 'expected_sha256' ),
				'output'      => self::object(
					array(
						'path'    => array( 'type' => 'string' ),
						'deleted' => array( 'type' => 'boolean' ),
						'checks'  => self::checks_output(),
					),
					array( 'path', 'deleted' )
				),
				'readonly'    => false,
			),
			'move-file'          => array(
				'label'       => __( 'Move source file', 'site-agent' ),
				'description' => __( 'Rename or move a plugin or theme file whose hash still matches. The destination must not exist. A PHP move that breaks the site is undone.', 'site-agent' ),
				'group'       => 'file_write',
				'callback'    => array( Files::class, 'move' ),
				'input'       => array(
					'from'            => self::string( __( 'Current path relative to wp-content.', 'site-agent' ) ),
					'to'              => self::string( __( 'New path relative to wp-content. Its directory must exist.', 'site-agent' ) ),
					'expected_sha256' => $sha,
				),
				'required'    => array( 'from', 'to', 'expected_sha256' ),
				'output'      => self::object(
					array(
						'from'   => array( 'type' => 'string' ),
						'to'     => array( 'type' => 'string' ),
						'checks' => self::checks_output(),
					),
					array( 'from', 'to' )
				),
				'readonly'    => false,
			),
			'execute-php'        => array(
				'label'       => __( 'Execute PHP', 'site-agent' ),
				'description' => __( 'Run PHP statements in the loaded WordPress request with full server privileges; not sandboxed. No PHP tags and no exit or die. Echo output and a JSON-serializable return value are captured; errors include their message and line.', 'site-agent' ),
				'group'       => 'php_execute',
				'callback'    => array( Developer::class, 'php' ),
				'input'       => array( 'code' => self::string( __( 'PHP statements without <?php, for example: return get_option( "blogname" );', 'site-agent' ), 65536 ) ),
				'required'    => array( 'code' ),
				'output'      => self::object(
					array(
						'output'    => array( 'type' => 'string' ),
						'truncated' => array( 'type' => 'boolean' ),
					),
					array( 'output', 'truncated' )
				),
				'readonly'    => false,
			),
			'run-wp-cli'         => array(
				'label'       => __( 'Run WP-CLI', 'site-agent' ),
				/* translators: %d: time limit in seconds. */
				'description' => sprintf( __( 'Run WP-CLI on this installation as the authenticated user, with arguments as an array and no shell. Full developer access; foreground commands have a %d-second limit.', 'site-agent' ), $timeout ),
				'group'       => 'cli_execute',
				'callback'    => array( Developer::class, 'cli' ),
				'input'       => array(
					'arguments' => array(
						'type'        => 'array',
						'description' => __( 'Command words and flags without the leading wp, for example ["plugin", "list", "--format=json"]. --path, --url, --user, --ssh, --http, --require, --exec, --allow-root and @aliases are set by Site Agent.', 'site-agent' ),
						'minItems'    => 1,
						'maxItems'    => 40,
						'items'       => array(
							'type'      => 'string',
							'maxLength' => 4096,
						),
					),
				),
				'required'    => array( 'arguments' ),
				'output'      => self::object(
					array(
						'stdout'    => array( 'type' => 'string' ),
						'stderr'    => array( 'type' => 'string' ),
						'exit_code' => array( 'type' => 'integer' ),
						'timed_out' => array( 'type' => 'boolean' ),
						'truncated' => array( 'type' => 'boolean' ),
					),
					array( 'stdout', 'stderr', 'exit_code' )
				),
				'readonly'    => false,
			),
		) + self::core_forms();
	}

	/**
	 * Core Forms tools, in the core_forms group. They call Core Forms' own REST API as the current user.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function core_forms(): array {
		$form_id     = self::field( 'integer', __( 'Core Forms form ID from site-agent-core-forms-list-forms.', 'site-agent' ), array( 'minimum' => 1 ) );
		$fingerprint = self::field( 'string', __( 'fingerprint from the latest core-forms-get-form or save result. A stale value is refused with cf_edit_conflict: read the form again and reconcile.', 'site-agent' ), array( 'pattern' => '^[a-f0-9]{64}$' ) );
		$scalar      = array( 'type' => array( 'string', 'number', 'integer', 'boolean' ) );
		$attributes  = static function ( string $description ) use ( $scalar ): array {
			return array(
				'type'                 => 'object',
				'description'          => $description,
				'additionalProperties' => $scalar,
			);
		};
		$columns     = self::field(
			'integer',
			__( 'Container columns, 1 to 6.', 'site-agent' ),
			array(
				'minimum' => 1,
				'maximum' => 6,
			)
		);
		$field       = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'name', 'type' ),
			'properties'           => array(
				'name'               => self::string( __( 'Unique field name: the submitted key and the [name] data variable in actions. Letters, numbers, _ and -. Multiple-file and multi-select fields get [] automatically.', 'site-agent' ), 100, array( 'pattern' => '^[A-Za-z0-9_-]+(\[\])?$' ) ),
				'type'               => self::string( __( 'A field type from core-forms-overview field_types, for example text, email, tel, textarea, select, radio, checkbox, file, consent, rating, heading, paragraph, container, fieldset or submit (named _submit, so it is not stored as an answer).', 'site-agent' ), 40 ),
				'label'              => self::string( __( 'Label. For heading and paragraph fields, the text shown.', 'site-agent' ), 2000 ),
				'placeholder'        => self::string( __( 'Placeholder. For select, the empty first option.', 'site-agent' ), 500 ),
				'default_value'      => self::string( __( 'Initial value. For paragraph and html fields, the content.', 'site-agent' ), 20000 ),
				'default_values'     => self::list_of( array( 'type' => 'string' ) ) + array( 'description' => __( 'Initially selected values of a multiple-choice field.', 'site-agent' ) ),
				'checked'            => self::field( 'boolean', __( 'Initial state of a single checkbox or consent field.', 'site-agent' ) ),
				'required'           => self::field( 'boolean', __( 'Whether an answer is required.', 'site-agent' ) ),
				'description'        => self::string( __( 'Help text shown under the field.', 'site-agent' ), 2000 ),
				'css_class'          => self::string( __( 'Classes on the field\'s control (for choice groups and containers, on the group).', 'site-agent' ), 500 ),
				'options'            => self::list_of(
					self::object(
						array(
							'value'    => array( 'type' => 'string' ),
							'label'    => array( 'type' => 'string' ),
							'group'    => array( 'type' => 'string' ),
							'disabled' => array( 'type' => 'boolean' ),
						),
						array( 'value' )
					)
				) + array( 'description' => __( 'Choices for select, radio and checkbox-group fields. group makes select optgroups.', 'site-agent' ) ),
				'validation'         => self::field( 'object', __( 'Rules such as {"min": 1, "max": 5, "pattern": "^[0-9]+$"}. A rating uses min and max for its scale.', 'site-agent' ) ),
				'conditions'         => self::list_of( array( 'type' => 'object' ) ) + array( 'description' => __( 'Show or hide the field from another answer, as the builder stores it: [{"action": "show", "rule": "topic=support"}]. Rules: field=value, field:a|b|c (any of), field=* (any answer).', 'site-agent' ) ),
				'attributes'         => $attributes( __( 'Extra control attributes, for example {"autocomplete": "email"}. For file fields: {"accept": ".pdf,image/*", "multiple": true, "data-max-files": 3}. A heading uses {"level": 2}.', 'site-agent' ) ),
				'wrapper_attributes' => $attributes( __( 'Attributes on the field\'s wrapper element, for example {"class": "col-span-2"}. Only id, class, style, title, role, tabindex, dir, lang, hidden, data-* and aria-* are kept.', 'site-agent' ) ),
				'group'              => self::string( __( 'Step name in a multi-step or conversational layout.', 'site-agent' ), 200 ),
				'parent'             => self::string( __( 'Name of the container or fieldset this field sits in.', 'site-agent' ), 100 ),
				'cols_desktop'       => $columns,
				'cols_tablet'        => $columns,
				'cols_mobile'        => $columns,
				'gap'                => self::string( __( 'Container gap, for example 1rem.', 'site-agent' ), 20 ),
				'position'           => self::field( 'integer', __( 'Order. Defaults to the position in the list.', 'site-agent' ), array( 'minimum' => 0 ) ),
			),
		);
		$form_output = self::object(
			array(
				'form_id'     => array( 'type' => 'integer' ),
				'fingerprint' => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'shortcode'   => array( 'type' => 'string' ),
				'fields'      => self::list_of( array( 'type' => 'object' ) ),
				'actions'     => self::list_of( array( 'type' => 'object' ) ),
				'settings'    => array( 'type' => 'object' ),
				'messages'    => array( 'type' => 'object' ),
				'publishing'  => array( 'type' => 'object' ),
				'saved'       => self::list_of( array( 'type' => 'string' ) ),
			),
			array( 'form_id', 'fingerprint' )
		);
		$submission  = array(
			'status'    => self::field(
				'string',
				__( 'Entry status.', 'site-agent' ),
				array( 'enum' => array( 'new', 'read', 'replied', 'archived' ) )
			),
			'spam'      => self::field( 'boolean', __( 'true for entries stored as spam, false for the rest.', 'site-agent' ) ),
			'search'    => self::string( __( 'Search the submitted answers.', 'site-agent' ), 200 ),
			'date_from' => self::string( __( 'Submitted on or after this date, YYYY-MM-DD.', 'site-agent' ), 40 ),
			'date_to'   => self::string( __( 'Submitted on or before this date, YYYY-MM-DD.', 'site-agent' ), 40 ),
		);
		$entry       = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
		return array(
			'core-forms-overview'          => array(
				'label'       => __( 'Core Forms overview', 'site-agent' ),
				'description' => __( 'Core Forms version and modules, every action type with the default settings its editor starts from, field types, default form settings and messages, global settings with secrets shown as [stored], and which settings wp-config.php constants supply. Read the core-forms skill and call this first.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'overview' ),
				'input'       => array(),
				'required'    => array(),
				'output'      => self::object(
					array(
						'version'      => array( 'type' => 'string' ),
						'modules'      => self::list_of( array( 'type' => 'object' ) ),
						'action_types' => self::list_of( array( 'type' => 'object' ) ),
						'field_types'  => self::list_of( array( 'type' => 'string' ) ),
						'constants'    => self::list_of( array( 'type' => 'object' ) ),
					),
					array( 'version' )
				),
				'readonly'    => true,
			),
			'core-forms-list-forms'        => array(
				'label'       => __( 'List Core Forms forms', 'site-agent' ),
				'description' => __( 'Forms you can manage, by title: ID, slug, status, shortcode, whether a field schema is stored, action types, whether draft publishing is on and the entry count.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'list_forms' ),
				'input'       => self::paging( 100, 50 ),
				'required'    => array(),
				'output'      => self::object(
					array(
						'forms' => self::list_of( array( 'type' => 'object' ) ),
						'total' => array( 'type' => 'integer' ),
						'pages' => array( 'type' => 'integer' ),
					),
					array( 'forms', 'total' )
				),
				'readonly'    => true,
			),
			'core-forms-get-form'          => array(
				'label'       => __( 'Read Core Forms form', 'site-agent' ),
				'description' => __( 'Read a form\'s draft definition: fingerprint (needed for every change), fields (the stored builder schema, or parsed from markup when none is stored), layout, markup, settings, the ordered actions with their index and fingerprint, messages, draft publishing state, schema drift and entry counts. Secrets appear as [stored].', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'get_form' ),
				'input'       => array( 'form_id' => $form_id ),
				'required'    => array( 'form_id' ),
				'output'      => $form_output,
				'readonly'    => true,
			),
			'core-forms-save-form'         => array(
				'label'       => __( 'Save Core Forms form', 'site-agent' ),
				'description' => __( 'Create a form (omit form_id) or change one (form_id and expected_fingerprint). Prefer fields: the complete field list, rendered into markup by Core Forms\' own renderer and saved with its schema, as the visual builder does; a submit button is added to single-page forms that have none. Or send markup for code-view HTML. Title, slug, settings (merged; field_wrapper_class adds classes to every field wrapper) and messages can change in the same call. Actions change with core-forms-save-actions. With draft publishing on, saves change the draft until core-forms-publish-form.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'save' ),
				'input'       => array(
					'form_id'              => self::field( 'integer', __( 'Form to change. Omit to create a form.', 'site-agent' ), array( 'minimum' => 1 ) ),
					'expected_fingerprint' => $fingerprint,
					'title'                => self::string( __( 'Form title.', 'site-agent' ), 200 ),
					'slug'                 => self::string( __( 'Form slug, used in [cf_form slug="..."]. Core Forms keeps slugs unique.', 'site-agent' ), 200 ),
					'fields'               => self::list_of( $field ) + array(
						'description' => __( 'The complete field list in order. Replaces the current fields and markup.', 'site-agent' ),
						'maxItems'    => 300,
					),
					'layout'               => self::object(
						array(
							'type' => self::field(
								'string',
								__( 'single (default), multi-step (fields grouped into steps by their group) or conversational (one question at a time).', 'site-agent' ),
								array( 'enum' => array( 'single', 'multi-step', 'conversational' ) )
							),
						)
					) + array( 'description' => __( 'Layout with fields. Omit to keep the current layout.', 'site-agent' ) ),
					'markup'               => self::string( __( 'Complete form HTML without the form tag, as in the code view. Replaces the markup; Core Forms re-checks choice options in a stored schema and reports drift.', 'site-agent' ), 1000000 ),
					'schema_from_markup'   => self::field( 'boolean', __( 'With markup: also rebuild the stored field schema from it. Use after adding, removing or renaming fields in code; builder-only layout data is replaced.', 'site-agent' ) ),
					'settings'             => self::field( 'object', __( 'Form settings to change, merged into the current ones. Keys and defaults are in core-forms-overview form_settings, for example save_submissions, redirect_url, hide_after_success, submit_button_text, form_class and field_wrapper_class.', 'site-agent' ) ),
					'messages'             => array(
						'type'                 => 'object',
						'description'          => __( 'Messages to change, keyed as in core-forms-overview messages, for example {"success": "Thanks, we will reply within a day."}.', 'site-agent' ),
						'additionalProperties' => array(
							'type'      => 'string',
							'maxLength' => 5000,
						),
					),
				),
				'required'    => array(),
				'output'      => $form_output,
				'readonly'    => false,
			),
			'core-forms-duplicate-form'    => array(
				'label'       => __( 'Duplicate Core Forms form', 'site-agent' ),
				'description' => __( 'Copy a form with Core Forms\' own duplicate: fields, markup, settings, actions and messages. The copy has a new slug and no entries or published snapshot.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'duplicate' ),
				'input'       => array( 'form_id' => $form_id ),
				'required'    => array( 'form_id' ),
				'output'      => $form_output,
				'readonly'    => false,
				'destructive' => false,
			),
			'core-forms-trash-form'        => array(
				'label'       => __( 'Trash Core Forms form', 'site-agent' ),
				'description' => __( 'Move a form to the WordPress trash. Its shortcode stops rendering and its entries are kept; it can be restored from the trash. Confirm with the user first.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'trash' ),
				'input'       => array(
					'form_id'              => $form_id,
					'expected_fingerprint' => $fingerprint,
				),
				'required'    => array( 'form_id', 'expected_fingerprint' ),
				'output'      => self::object(
					array(
						'form_id' => array( 'type' => 'integer' ),
						'status'  => array( 'type' => 'string' ),
					),
					array( 'form_id', 'status' )
				),
				'readonly'    => false,
			),
			'core-forms-publish-form'      => array(
				'label'       => __( 'Publish Core Forms draft', 'site-agent' ),
				'description' => __( 'Draft publishing keeps visitors on the published form while saves change a draft. start turns it on (snapshotting the current form as live), publish makes the saved draft live, discard returns the draft to the published form. Publishing changes the live form: confirm first.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'publish' ),
				'input'       => array(
					'form_id'              => $form_id,
					'action'               => self::field( 'string', __( 'start, publish or discard.', 'site-agent' ), array( 'enum' => array( 'start', 'publish', 'discard' ) ) ),
					'expected_fingerprint' => $fingerprint,
				),
				'required'    => array( 'form_id', 'action', 'expected_fingerprint' ),
				'output'      => $form_output,
				'readonly'    => false,
			),
			'core-forms-save-actions'      => array(
				'label'       => __( 'Save Core Forms actions', 'site-agent' ),
				'description' => __( 'Replace a form\'s ordered action list (email, autoresponder, webhook and integrations): add, edit, remove or reorder. Keep an unchanged action with {"keep_index": i, "keep_fingerprint": "..."} from core-forms-get-form. Send a new or edited action as its settings object with type (keys from core-forms-overview action_types defaults); leave [stored] values as they are to keep stored secrets. Mapped field names must exist in the form. Data variables such as [email] and [all:label] fill in answers.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'save_actions' ),
				'input'       => array(
					'form_id'              => $form_id,
					'expected_fingerprint' => $fingerprint,
					'actions'              => array(
						'type'        => 'array',
						'description' => __( 'The complete action list in run order. An empty list removes every action.', 'site-agent' ),
						'maxItems'    => 50,
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'keep_index'       => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
								'keep_fingerprint' => array(
									'type'    => 'string',
									'pattern' => '^[a-f0-9]{64}$',
								),
								'type'             => array(
									'type'      => 'string',
									'maxLength' => 64,
								),
							),
						),
					),
				),
				'required'    => array( 'form_id', 'expected_fingerprint', 'actions' ),
				'output'      => $form_output,
				'readonly'    => false,
			),
			'core-forms-test-action'       => array(
				'label'       => __( 'Test Core Forms action', 'site-agent' ),
				'description' => __( 'Run one action for real with sample answers through Core Forms\' action test: it sends the email, calls the webhook or contacts the integration. Pass index for a saved action, or action for unsaved settings. Ask the user before sending to a real recipient. Use core-forms-rehearse to check without sending.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'test_action' ),
				'input'       => array(
					'form_id'   => $form_id,
					'index'     => self::field( 'integer', __( 'index of a saved action from core-forms-get-form.', 'site-agent' ), array( 'minimum' => 0 ) ),
					'action'    => self::field( 'object', __( 'Unsaved action settings with type. [stored] values come from the saved action with the same _cf_action_id.', 'site-agent' ) ),
					'test_data' => array(
						'type'                 => 'object',
						'description'          => __( 'Sample answers keyed by field name. Default: Core Forms\' samples for the form\'s fields.', 'site-agent' ),
						'additionalProperties' => true,
					),
				),
				'required'    => array( 'form_id' ),
				'output'      => self::object(
					array(
						'success' => array( 'type' => 'boolean' ),
						'status'  => array( 'type' => array( 'string', 'integer', 'null' ) ),
						'message' => array( 'type' => array( 'string', 'null' ) ),
					),
					array( 'success' )
				),
				'readonly'    => false,
				'destructive' => false,
			),
			'core-forms-rehearse'          => array(
				'label'       => __( 'Rehearse Core Forms submission', 'site-agent' ),
				'description' => __( 'Check sample answers against the saved draft without storing, sending, subscribing or charging anything: validation result, calculated values, each action\'s would_run or would_skip with its destination, and the planned payment.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'rehearse' ),
				'input'       => array(
					'form_id'     => $form_id,
					'data'        => array(
						'type'                 => 'object',
						'description'          => __( 'Answers keyed by field name, for example {"name": "Ada", "email": "ada@example.com"}.', 'site-agent' ),
						'additionalProperties' => true,
					),
					'revision_id' => self::field( 'integer', __( 'Rehearse a saved revision instead of the draft.', 'site-agent' ), array( 'minimum' => 1 ) ),
				),
				'required'    => array( 'form_id' ),
				'output'      => self::object(
					array(
						'valid'   => array( 'type' => 'boolean' ),
						'error'   => array( 'type' => 'string' ),
						'actions' => self::list_of( array( 'type' => 'object' ) ),
					),
					array( 'valid' )
				),
				'readonly'    => true,
			),
			'core-forms-list-submissions'  => array(
				'label'       => __( 'List Core Forms entries', 'site-agent' ),
				'description' => __( 'Entries (submissions) for one form or all forms you can access, newest first: answers, status, spam flag, IP address, user agent, referring URL and time. Contains personal data; share only what the user needs.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'list_submissions' ),
				'input'       => array(
					'form_id' => self::field( 'integer', __( 'Only this form\'s entries.', 'site-agent' ), array( 'minimum' => 1 ) ),
					'limit'   => self::field(
						'integer',
						__( 'Entries per page, 1 to 100. Default 20.', 'site-agent' ),
						array(
							'minimum' => 1,
							'maximum' => 100,
						)
					),
					'page'    => self::field( 'integer', __( 'Page number, starting at 1.', 'site-agent' ), array( 'minimum' => 1 ) ),
					'order'   => self::field( 'string', __( 'desc (default, newest first) or asc.', 'site-agent' ), array( 'enum' => array( 'asc', 'desc' ) ) ),
				) + $submission,
				'required'    => array(),
				'output'      => self::object(
					array(
						'submissions' => self::list_of( $entry ),
						'total'       => array( 'type' => 'integer' ),
						'pages'       => array( 'type' => 'integer' ),
					),
					array( 'submissions', 'total' )
				),
				'readonly'    => true,
			),
			'core-forms-get-submission'    => array(
				'label'       => __( 'Read Core Forms entry', 'site-agent' ),
				'description' => __( 'One entry with its answers, status, spam flag, IP address, user agent, referring URL and time.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'get_submission' ),
				'input'       => array( 'submission_id' => self::field( 'integer', __( 'Entry ID.', 'site-agent' ), array( 'minimum' => 1 ) ) ),
				'required'    => array( 'submission_id' ),
				'output'      => $entry,
				'readonly'    => true,
			),
			'core-forms-update-submission' => array(
				'label'       => __( 'Update Core Forms entry', 'site-agent' ),
				'description' => __( 'Triage an entry: set its status (new, read, replied, archived) or mark it as spam or not spam. Answers are not changed.', 'site-agent' ),
				'group'       => 'core_forms',
				'callback'    => array( Core_Forms::class, 'update_submission' ),
				'input'       => array(
					'submission_id' => self::field( 'integer', __( 'Entry ID.', 'site-agent' ), array( 'minimum' => 1 ) ),
					'status'        => $submission['status'],
					'spam'          => self::field( 'boolean', __( 'true to mark as spam, false to mark as not spam.', 'site-agent' ) ),
				),
				'required'    => array( 'submission_id' ),
				'output'      => $entry,
				'readonly'    => false,
				'idempotent'  => true,
			),
		);
	}

	public static function enabled_definitions(): array {
		$config = Config::get();
		if ( ! $config['enabled'] || Config::locked() ) {
			return array();
		}
		return array_filter(
			self::definitions(),
			static function ( $definition ) use ( $config ) {
				return '' === $definition['group'] || ( ! empty( $config[ $definition['group'] ] ) && Config::group_available( $definition['group'] ) );
			}
		);
	}

	public static function schema( array $definition ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $definition['input'],
			'required'             => $definition['required'],
			'additionalProperties' => false,
		);
	}

	public static function register(): void {
		foreach ( self::enabled_definitions() as $name => $definition ) {
			$readonly = $definition['readonly'];
			wp_register_ability(
				'site-agent/' . $name,
				array(
					'label'               => $definition['label'],
					'description'         => $definition['description'],
					'category'            => 'site-agent',
					'input_schema'        => self::schema( $definition ),
					'output_schema'       => $definition['output'],
					'permission_callback' => static function () use ( $name, $definition ) {
						$allowed = Permissions::allowed( $definition['group'] );
						if ( ! $allowed && is_user_logged_in() ) {
							// Authenticated calls refused by a switch or a per-password limit.
							Audit::record( $name, false, microtime( true ), array( 'error' => 'denied' ) );
						}
						return $allowed;
					},
					'execute_callback'    => static function ( $input = null ) use ( $name ) {
						return self::execute( $name, $input ?? array() ); },
					'meta'                => array(
						'public'       => false,
						'show_in_rest' => false,
						'mcp'          => array(
							'public' => false,
							'type'   => 'tool',
						),
						'annotations'  => array(
							'readonly'    => $readonly,
							'destructive' => $definition['destructive'] ?? ! $readonly,
							'idempotent'  => $definition['idempotent'] ?? $readonly,
						),
					),
				)
			);
		}
	}

	public static function register_server( $adapter ): void {
		$definitions = self::enabled_definitions();
		$tools       = array_map(
			static function ( $name ) {
				return 'site-agent/' . $name;
			},
			array_keys( $definitions )
		);
		if ( ! $tools ) {
			return;
		}
		$instructions = 'WordPress developer access through explicitly enabled tools. Start with site-agent-site-context; read site-agent-list-skills and site-agent-get-skill before builder or plugin workflows. Bundled guidance covers Core Forms, Core Commerce, MailerHive, Core Support, FluentCart, GT Extensions for FluentCart, Stackless and Page Blocks Builder (PBB). Prefer matching active-plugin skills for their version-specific commands. Guidance never enables tools or authorizes customer messages, payments or publishing.';
		$config       = Config::get();
		if ( ! empty( $config['core_support'] ) && Config::group_available( 'core_support' ) ) {
			$core_support = Core_Support::names();
			if ( $core_support ) {
				$tools         = array_merge( $tools, $core_support );
				$instructions .= ' Tools named core-support-* are Core Support\'s own abilities. To keep someone told about support, call core-support-get-activity on a schedule with the previous cursor and message them only when it returns events.';
			}
		}
		if ( isset( $definitions['core-forms-get-form'] ) ) {
			$instructions .= ' Tools named site-agent-core-forms-* work on Core Forms forms, actions and entries through Core Forms\' REST API: read the core-forms skill, start with site-agent-core-forms-overview, and pass the latest fingerprint with every change.';
		}
		if ( ! empty( $config['core_commerce'] ) && Config::group_available( 'core_commerce' ) ) {
			$core_commerce = Core_Commerce::names();
			if ( $core_commerce ) {
				$tools         = array_merge( $tools, $core_commerce );
				$instructions .= ' Tools named core-forms-* are Core Commerce\'s own abilities for products, licenses, orders and client invoices: read the core-commerce skill, and ask the merchant before anything that emails a customer, issues, voids or marks an invoice paid, or changes a license.';
			}
		}
		if ( isset( $definitions['run-bricks-ability'] ) ) {
			// Bricks' fast-path abilities keep their own tool names, so Bricks' guidance applies as written.
			$tools         = array_merge( $tools, Bricks::direct() );
			$instructions .= ' Tools named bricks-* are Bricks Builder\'s own abilities. Where Bricks guidance names mcp-adapter-discover-abilities, mcp-adapter-get-ability-info or mcp-adapter-execute-ability, use site-agent-bricks-abilities and site-agent-run-bricks-ability with the same ability_name and parameters.';
		}
		$adapter->create_server(
			'site-agent',
			'site-agent/v1',
			'mcp',
			'Site Agent',
			$instructions,
			SITE_AGENT_VERSION,
			array( Vendor\WP\MCP\Transport\HttpTransport::class ),
			Vendor\WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler::class,
			Vendor\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$tools,
			array(),
			array(),
			array( Permissions::class, 'transport' )
		);
	}

	/**
	 * Hide tools from tools/list when a per-password limit or switch blocks them for this caller.
	 *
	 * @param mixed $tools  Tool records.
	 * @param mixed $server MCP server.
	 * @return mixed
	 */
	public static function visible_tools( $tools, $server ) {
		if ( ! is_array( $tools ) || ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) || 'site-agent' !== $server->get_server_id() ) {
			return $tools;
		}
		$definitions = self::definitions();
		return array_values(
			array_filter(
				$tools,
				static function ( $tool ) use ( $definitions ) {
					$name = is_object( $tool ) && method_exists( $tool, 'get' ) ? (string) $tool->get( 'name' ) : '';
					if ( 0 === strpos( $name, 'bricks-' ) ) {
						return Permissions::allowed( 'bricks' );
					}
					if ( 0 === strpos( $name, 'core-support-' ) ) {
						return Permissions::allowed( 'core_support' );
					}
					if ( 0 === strpos( $name, 'core-forms-' ) ) {
						return Permissions::allowed( 'core_commerce' );
					}
					$key = 0 === strpos( $name, 'site-agent-' ) ? substr( $name, strlen( 'site-agent-' ) ) : '';
					return ! isset( $definitions[ $key ] ) || Permissions::allowed( $definitions[ $key ]['group'] );
				}
			)
		);
	}

	/** Recheck authorization and schemas even for direct PHP callers. */
	public static function execute( string $name, $input ) {
		$start       = microtime( true );
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $name ] ) || ! Permissions::allowed( $definitions[ $name ]['group'] ) ) {
			Audit::record( $name, false, $start, array( 'error' => 'denied' ) );
			return new \WP_Error( 'site_agent_forbidden', __( 'This tool is disabled or you do not have permission to use it.', 'site-agent' ) );
		}
		$definition = $definitions[ $name ];
		if ( ! is_array( $input ) ) {
			Audit::record( $name, false, $start, array( 'error' => 'invalid_input' ) );
			return new \WP_Error( 'invalid_input', __( 'Tool arguments must be an object.', 'site-agent' ) );
		}
		$details    = array( 'target' => Audit::target( $input ) );
		$validation = rest_validate_value_from_schema( $input, self::schema( $definition ), 'arguments' );
		if ( is_wp_error( $validation ) ) {
			Audit::record( $name, false, $start, $details + array( 'error' => $validation->get_error_code() ) );
			return $validation;
		}
		try {
			$result = call_user_func( $definition['callback'], $input );
		} catch ( \Throwable $error ) {
			$result = new \WP_Error(
				'site_agent_tool_failed',
				/* translators: %s: exception class, message and location. */
				sprintf( __( 'The tool raised %s. Check the site before retrying a write.', 'site-agent' ), Errors::describe( $error ) )
			);
		}
		$failed_exit = is_array( $result ) && isset( $result['exit_code'] ) && 0 !== $result['exit_code'];
		$success     = ! is_wp_error( $result ) && ! $failed_exit;
		$error       = is_wp_error( $result ) ? $result->get_error_code() : ( $failed_exit ? 'exit_code' : '' );
		Audit::record( $name, $success, $start, $details + array( 'error' => (string) $error ) );
		return $result;
	}
}
