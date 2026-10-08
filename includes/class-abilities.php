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
				'description' => __( 'Inspect WordPress, PHP, active plugins, theme, page builders, post types, taxonomies and the tools this connection can use. Start here.', 'site-agent' ),
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
				'description' => __( 'List the bundled page builder skills (Gutenberg, GenerateBlocks, Elementor, Bricks, Divi) and skills that active plugins add, such as store or invoicing workflows, with whether each applies here and its files. Read the matching skill before creating or editing builder layouts or before running a plugin\'s workflow.', 'site-agent' ),
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
				'description' => __( 'Read a skill from list-skills: a bundled page builder skill or one an active plugin adds. Start with its SKILL.md, which explains the workflow and which reference files to read next.', 'site-agent' ),
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
		$instructions = 'WordPress developer access through explicitly enabled tools.';
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
