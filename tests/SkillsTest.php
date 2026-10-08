<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\Abilities;
use SiteAgent\Audit;
use SiteAgent\Config;
use SiteAgent\Skills;

final class SkillsTest extends TestCase {
	private $posts = array();
	private $link;

	protected function setUp(): void {
		wp_set_current_user( 1 );
		update_option( Config::OPTION, array_merge( Config::defaults(), array( 'enabled' => true ) ), false );
		delete_option( Audit::OPTION );
	}

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		if ( $this->link && is_link( $this->link ) ) {
			unlink( $this->link );
		}
		update_option( Config::OPTION, Config::defaults(), false );
		wp_set_current_user( 0 );
	}

	private function post( array $data, array $meta = array() ): int {
		$id = wp_insert_post(
			wp_slash(
				$data + array(
					'post_status' => 'draft',
					'post_title'  => 'Skills fixture',
				)
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		$this->posts[] = $id;
		return $id;
	}

	private function assertMatchesOutputSchema( string $tool, $result ): void {
		$this->assertIsArray( $result, $tool );
		$this->assertTrue( rest_validate_value_from_schema( $result, Abilities::definitions()[ $tool ]['output'], 'output' ), $tool . ' output must match its declared schema.' );
	}

	public function test_plugins_can_register_skills_without_replacing_bundled_ones(): void {
		$dir = sys_get_temp_dir() . '/site-agent-registered-skill-' . wp_rand();
		mkdir( $dir . '/references', 0700, true );
		file_put_contents( $dir . '/SKILL.md', "---\nname: invoice\ndescription: Create invoices.\n---\n# Invoice\n" );
		file_put_contents( $dir . '/references/fields.md', '# Fields' );
		file_put_contents( $dir . '/scripts.py', 'print(1)' );
		$filter = static function ( $skills ) use ( $dir ) {
			$skills['invoice']    = array(
				'directory' => $dir,
				'plugin'    => 'GT Extensions',
				'version'   => '2.8.3',
			);
			$skills['gutenberg']  = array( 'directory' => $dir );
			$skills['Bad Name']   = array( 'directory' => $dir );
			$skills['no-skillmd'] = array( 'directory' => $dir . '/references' );
			return $skills;
		};
		add_filter( 'site_agent_skills', $filter );
		try {
			$this->assertSame( array_merge( Skills::CATALOG, array( 'invoice' ) ), Skills::names() );
			$this->assertSame( SITE_AGENT_DIR . 'skills/gutenberg/', Skills::directory( 'gutenberg' ), 'Bundled skills cannot be replaced.' );
			$listing = Abilities::execute( 'list-skills', array() );
			$this->assertMatchesOutputSchema( 'list-skills', $listing );
			$entry = end( $listing['skills'] );
			$this->assertSame( array( 'invoice', 'Create invoices.', true, '2.8.3', 'GT Extensions' ), array( $entry['name'], $entry['description'], $entry['detected'], $entry['version'], $entry['source'] ) );
			$this->assertSame( array( 'SKILL.md', 'references/fields.md' ), $entry['files'], 'Only Markdown, JSON and HTML files are served.' );
			$read = Skills::read(
				array(
					'skill' => 'invoice',
					'path'  => 'references/fields.md',
				)
			);
			$this->assertSame( '# Fields', $read['content'] );
			$this->assertTrue(
				is_wp_error(
					Skills::read(
						array(
							'skill' => 'invoice',
							'path'  => 'scripts.py',
						)
					)
				)
			);
			$this->assertTrue(
				is_wp_error(
					Skills::read(
						array(
							'skill' => 'invoice',
							'path'  => '../SKILL.md',
						)
					)
				)
			);
		} finally {
			remove_filter( 'site_agent_skills', $filter );
			array_map( 'unlink', array( $dir . '/SKILL.md', $dir . '/references/fields.md', $dir . '/scripts.py' ) );
			rmdir( $dir . '/references' );
			rmdir( $dir );
		}
		$this->assertSame( Skills::CATALOG, Skills::names() );
	}

	public function test_partner_plugins_report_their_state_and_skills(): void {
		$filter = static function ( $skills ) {
			$skills['gt-link-manager'] = array( 'directory' => SITE_AGENT_DIR . 'skills/gutenberg', 'plugin' => 'GT Link Manager', 'version' => '1.10.0' );
			return $skills;
		};
		add_filter( 'site_agent_skills', $filter );
		try {
			$partners = array_column( Skills::partners(), null, 'name' );
			$this->assertSame( array( 'GT Extensions for FluentCart', 'GT Page Blocks Builder', 'GT Link Manager' ), array_keys( $partners ) );
			$this->assertSame( array( 'gt-link-manager' ), $partners['GT Link Manager']['skills'] );
			foreach ( $partners as $partner ) {
				$this->assertContains( $partner['state'], array( 'active', 'installed', 'missing' ) );
				$this->assertStringStartsWith( 'https://', $partner['url'] );
			}
		} finally {
			remove_filter( 'site_agent_skills', $filter );
		}
	}

	public function test_skills_need_only_base_access(): void {
		$listing = Abilities::execute( 'list-skills', array() );
		$this->assertMatchesOutputSchema( 'list-skills', $listing );
		$this->assertSame( Skills::CATALOG, array_column( $listing['skills'], 'name' ) );
		foreach ( $listing['skills'] as $skill ) {
			$this->assertContains( 'SKILL.md', $skill['files'], $skill['name'] );
			$this->assertNotSame( '', $skill['description'], $skill['name'] );
		}
		$this->assertTrue( $listing['skills'][0]['detected'], 'Gutenberg is always available.' );
		update_option( Config::OPTION, Config::defaults(), false );
		$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'list-skills', array() ) );
		$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'get-skill', array( 'skill' => 'bricks' ) ) );
	}

	public function test_get_skill_reads_entry_points_and_references(): void {
		foreach ( Skills::CATALOG as $name ) {
			$skill = Abilities::execute( 'get-skill', array( 'skill' => $name ) );
			$this->assertMatchesOutputSchema( 'get-skill', $skill );
			$this->assertStringStartsWith( "---\nname: $name\n", $skill['content'] );
			$this->assertSame( Skills::files( $name ), $skill['files'] );
		}
		$reference = Abilities::execute(
			'get-skill',
			array(
				'skill' => 'bricks',
				'path'  => 'references/json-formats.md',
			)
		);
		$this->assertStringContainsString( '_bricks_page_content_2', $reference['content'] );
		$this->assertArrayNotHasKey( 'files', $reference );
		$rows = get_option( Audit::OPTION );
		$this->assertSame( 'skill bricks/references/json-formats.md', end( $rows )['target'] );
		$pattern = Abilities::execute(
			'get-skill',
			array(
				'skill' => 'bricks',
				'path'  => 'patterns/hero-centered.json',
			)
		);
		$this->assertIsArray( json_decode( $pattern['content'], true ) );
		$router = Abilities::execute(
			'get-skill',
			array(
				'skill' => 'generateblocks',
				'path'  => 'references/_index.md',
			)
		);
		$this->assertStringContainsString( 'authoring-contract.md', $router['content'] );
	}

	public function test_get_skill_serves_only_listed_files(): void {
		foreach ( array( '../../site-agent.php', 'references/../SKILL.md', '/etc/passwd', '.hidden.md', 'references\\x.md' ) as $path ) {
			$this->assertInstanceOf(
				WP_Error::class,
				Abilities::execute(
					'get-skill',
					array(
						'skill' => 'gutenberg',
						'path'  => $path,
					)
				),
				$path
			);
		}
		$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'get-skill', array( 'skill' => 'oxygen' ) ) );
		$this->assertInstanceOf(
			WP_Error::class,
			Abilities::execute(
				'get-skill',
				array(
					'skill' => 'gutenberg',
					'path'  => 'references/missing.md',
				)
			)
		);
		// A symlink inside a skill never resolves, even to an allowed type.
		$this->link = Skills::directory( 'gutenberg' ) . 'linked.md';
		symlink( ABSPATH . 'wp-config.php', $this->link );
		$this->assertNotContains( 'linked.md', Skills::files( 'gutenberg' ) );
		$this->assertInstanceOf(
			WP_Error::class,
			Abilities::execute(
				'get-skill',
				array(
					'skill' => 'gutenberg',
					'path'  => 'linked.md',
				)
			)
		);
	}

	public function test_content_reports_where_each_layout_is_stored(): void {
		$cases = array(
			array( 'elementor', $this->post( array( 'post_content' => '<p>Fallback</p>' ), array( '_elementor_edit_mode' => 'builder' ) ) ),
			array( 'bricks', $this->post( array( 'post_content' => '' ), array( '_bricks_editor_mode' => 'bricks' ) ) ),
			// Bricks renders its data unless the mode is "wordpress"; older or imported pages have no mode.
			array(
				'bricks',
				$this->post(
					array( 'post_content' => '' ),
					array(
						'_bricks_page_content_2' => array(
							array(
								'id'   => 'abc123',
								'name' => 'section',
							),
						),
					)
				),
			),
			array(
				'classic',
				$this->post(
					array( 'post_content' => '<p>Kept</p>' ),
					array(
						'_bricks_editor_mode'    => 'wordpress',
						'_bricks_page_content_2' => array(
							array(
								'id'   => 'abc123',
								'name' => 'section',
							),
						),
					)
				),
			),
			array( 'generateblocks', $this->post( array( 'post_content' => '<!-- wp:generateblocks-pro/accordion {"uniqueId":"a1b2c3d5"} --><div class="gb-accordion"></div><!-- /wp:generateblocks-pro/accordion -->' ) ) ),
			array( 'divi', $this->post( array( 'post_content' => '[et_pb_section][/et_pb_section]' ), array( '_et_pb_use_builder' => 'on' ) ) ),
			array( 'divi', $this->post( array( 'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->' ) ) ),
			array( 'generateblocks', $this->post( array( 'post_content' => '<!-- wp:generateblocks/text {"uniqueId":"a1b2c3d4","tagName":"p"} --><p class="gb-text">Hi</p><!-- /wp:generateblocks/text -->' ) ) ),
			array( 'gutenberg', $this->post( array( 'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) ) ),
			array( 'classic', $this->post( array( 'post_content' => '<p>Classic</p>' ) ) ),
		);
		foreach ( $cases as list( $builder, $id ) ) {
			$read = Abilities::execute( 'get-content', array( 'post_id' => $id ) );
			$this->assertSame( $builder, $read['builder'], $builder . ' post ' . $id );
		}
		// GenerateBlocks Pro keeps global styles and conditions in post meta of its own post types.
		$style = new WP_Post(
			(object) array(
				'ID'           => 0,
				'post_type'    => 'gblocks_styles',
				'post_content' => '',
			)
		);
		$this->assertSame( 'generateblocks', Skills::post_builder( $style ) );
		$context = Abilities::execute( 'site-context', array() );
		$this->assertSame( 'gutenberg', $context['builders'][0]['skill'] );
		$this->assertTrue( rest_validate_value_from_schema( $context, Abilities::definitions()['site-context']['output'], 'output' ) );
	}

	public function test_builder_layout_meta_stays_out_of_content_tools(): void {
		update_option(
			Config::OPTION,
			array_merge(
				Config::defaults(),
				array(
					'enabled'       => true,
					'content_write' => true,
				)
			),
			false
		);
		// Elementor registers its layout meta for REST, which would otherwise make it writable here.
		$args = array(
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'string',
			'auth_callback' => '__return_true',
		);
		register_post_meta( 'post', '_elementor_data', $args );
		register_post_meta( 'post', 'site_agent_plain_note', $args );
		$id = $this->post(
			array( 'post_content' => '<p>Fallback</p>' ),
			array(
				'_elementor_data'      => '[]',
				'_elementor_edit_mode' => 'builder',
			)
		);
		try {
			$read = Abilities::execute( 'get-content', array( 'post_id' => $id ) );
			$this->assertArrayNotHasKey( '_elementor_data', (array) $read['meta'] );
			$refused = Abilities::execute(
				'save-content',
				array(
					'post_id'                 => $id,
					'meta'                    => array( '_elementor_data' => '[{"id":"abc1234"}]' ),
					'expected_content_sha256' => $read['content_sha256'],
				)
			);
			$this->assertSame( 'builder_meta', $refused->get_error_code() );
			$this->assertSame( '[]', get_post_meta( $id, '_elementor_data', true ) );
			$saved = Abilities::execute(
				'save-content',
				array(
					'post_id'                 => $id,
					'meta'                    => array( 'site_agent_plain_note' => 'kept' ),
					'expected_content_sha256' => $read['content_sha256'],
				)
			);
			$this->assertSame( 'kept', $saved['meta']->site_agent_plain_note );
			// Developers can still opt a key in explicitly.
			$opt_in = static function ( $keys ) {
				$keys[] = '_elementor_data';
				return $keys;
			};
			add_filter( 'site_agent_post_meta_keys', $opt_in );
			$this->assertContains( '_elementor_data', SiteAgent\Content::meta_keys( 'post' ) );
			remove_filter( 'site_agent_post_meta_keys', $opt_in );
		} finally {
			unregister_post_meta( 'post', '_elementor_data' );
			unregister_post_meta( 'post', 'site_agent_plain_note' );
		}
	}

	public function test_generateblocks_escapes_survive_a_save_round_trip(): void {
		update_option(
			Config::OPTION,
			array_merge(
				Config::defaults(),
				array(
					'enabled'       => true,
					'content_write' => true,
				)
			),
			false
		);
		// The canary from the generateblocks skill: custom properties, clamp(), inline markup and an ampersand.
		$markup        = '<!-- wp:generateblocks/element {"uniqueId":"c4n4ry01","tagName":"div","styles":{"padding":"clamp(1rem, 2vw + 1rem, 3rem)","color":"var(\u002d\u002dcontrast)","\u0026:hover":{"color":"var(\u002d\u002daccent)"}},"css":".gb-element-c4n4ry01{color:var(\u002d\u002dcontrast)}"} -->' . "\n"
			. '<div class="gb-element-c4n4ry01"><!-- wp:generateblocks/text {"uniqueId":"c4n4ry02","tagName":"p","content":"Read \u003ca href=\u0022/x\u0022\u003emore\u003c/a\u003e \u0026amp; more"} -->' . "\n"
			. '<p class="gb-text">Read <a href="/x">more</a> &amp; more</p>' . "\n"
			. '<!-- /wp:generateblocks/text --></div>' . "\n"
			. '<!-- /wp:generateblocks/element -->';
		$saved         = Abilities::execute( 'save-content', array( 'content' => $markup ) );
		$this->posts[] = $saved['id'];
		$read          = Abilities::execute( 'get-content', array( 'post_id' => $saved['id'] ) );
		$this->assertSame( $markup, $read['content'] );
		$this->assertSame( 'generateblocks', $read['builder'] );
		$this->assertSame( $markup, serialize_blocks( parse_blocks( $markup ) ), 'The canary must already be in canonical WordPress serialization.' );
	}
}
