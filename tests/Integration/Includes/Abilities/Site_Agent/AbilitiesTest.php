<?php
/**
 * Integration tests for the Site Agent abilities.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Site_Agent
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Site_Agent;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Site_Agent\Create_Draft;
use WordPress\AI\Abilities\Site_Agent\Read_Site;
use WordPress\AI\Abilities\Site_Agent\Update_Post;
use WordPress\AI\Abilities\Site_Agent\Update_Setting;

/**
 * Site Agent ability test case.
 *
 * @since x.x.x
 */
class AbilitiesTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private int $admin_id;

	/**
	 * @var int
	 */
	private int $subscriber_id;

	public function setUp(): void {
		parent::setUp();

		$this->ensure_category();
		$this->register_abilities();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->admin_id );

		update_option( 'wpai_feature_site-agent_enabled', true );
		update_option( 'wpai_feature_site-agent_field_allow_writes', true );
	}

	public function tearDown(): void {
		foreach ( array( Read_Site::ABILITY, Create_Draft::ABILITY, Update_Post::ABILITY, Update_Setting::ABILITY ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		delete_option( 'wpai_feature_site-agent_enabled' );
		delete_option( 'wpai_feature_site-agent_field_allow_writes' );

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	public function test_read_environment_returns_real_values(): void {
		$ability = new Read_Site();
		$result  = $ability->execute( array( 'section' => 'environment' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'environment', $result['section'] );
		$this->assertSame( get_bloginfo( 'name' ), $result['site_name'] );
		$this->assertNotEmpty( $result['wordpress'] );
		$this->assertSame( PHP_VERSION, $result['php'] );
		$this->assertArrayHasKey( 'active_plugins', $result );
		$this->assertArrayHasKey( 'updates', $result );
	}

	public function test_read_posts_returns_only_readable_items(): void {
		$draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Private draft',
				'post_author' => $this->admin_id,
			)
		);

		$ability = new Read_Site();
		$result  = $ability->execute(
			array(
				'section' => 'posts',
				'limit'   => 5,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'posts', $result['section'] );

		$ids = array_column( $result['items'], 'id' );
		$this->assertContains( $draft_id, $ids );

		$single = $ability->execute(
			array(
				'section' => 'posts',
				'id'      => $draft_id,
			)
		);

		$this->assertArrayHasKey( 'content', $single['items'][0] );
	}

	public function test_read_capabilities_are_section_specific(): void {
		$ability = new Read_Site();

		$this->assertTrue( $ability->check_permission( array( 'section' => 'environment' ) ) );
		$this->assertTrue( $ability->check_permission( array( 'section' => 'posts' ) ) );
		$this->assertTrue( $ability->check_permission( array( 'section' => 'settings' ) ) );
		$this->assertTrue( $ability->check_permission( array( 'section' => 'plugins' ) ) );

		wp_set_current_user( $this->subscriber_id );

		$this->assertWPError( $ability->check_permission( array( 'section' => 'environment' ) ) );
		$this->assertWPError( $ability->check_permission( array( 'section' => 'posts' ) ) );
		$this->assertWPError( $ability->check_permission( array( 'section' => 'settings' ) ) );
		$this->assertWPError( $ability->check_permission( array( 'section' => 'plugins' ) ) );
	}

	public function test_create_draft_creates_an_unpublished_post(): void {
		$ability = new Create_Draft();
		$result  = $ability->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Site Agent draft',
				'content'   => 'A short body.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );

		$post = get_post( $result['id'] );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Site Agent draft', $post->post_title );
		$this->assertSame( $this->admin_id, (int) $post->post_author );
	}

	public function test_create_draft_requires_the_write_gate(): void {
		update_option( 'wpai_feature_site-agent_field_allow_writes', false );

		$ability = new Create_Draft();

		$this->assertWPError( $ability->check_permission( array( 'post_type' => 'post' ) ) );
	}

	public function test_create_draft_requires_the_capability(): void {
		wp_set_current_user( $this->subscriber_id );

		$ability = new Create_Draft();

		$this->assertWPError( $ability->check_permission( array( 'post_type' => 'post' ) ) );
	}

	public function test_update_post_changes_only_allowed_fields(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Before',
				'post_author' => $this->admin_id,
			)
		);

		$ability = new Update_Post();
		$result  = $ability->execute(
			array(
				'id'    => $post_id,
				'title' => 'After',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'After', get_the_title( $post_id ) );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	public function test_update_post_cannot_publish(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);

		$ability = new Update_Post();
		$result  = $ability->execute(
			array(
				'id'     => $post_id,
				'status' => 'publish',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	public function test_update_post_requires_edit_capability(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);

		wp_set_current_user( $this->subscriber_id );

		$ability = new Update_Post();

		$this->assertWPError( $ability->check_permission( array( 'id' => $post_id ) ) );
	}

	public function test_read_coarse_check_accepts_a_section_capability(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$this->assertTrue( ( new Read_Site() )->check_permission( array() ) );
	}

	public function test_update_published_post_cannot_be_moved_to_draft(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		$ability = new Update_Post();
		$result  = $ability->execute(
			array(
				'id'     => $post_id,
				'status' => 'draft',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'site_agent_status_locked', $result->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	public function test_update_setting_changes_the_allowlisted_value(): void {
		$ability = new Update_Setting();
		$result  = $ability->execute(
			array(
				'name'  => 'blogdescription',
				'value' => 'Built with WordPress',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Built with WordPress', get_option( 'blogdescription' ) );
	}

	public function test_update_setting_accepts_an_unchanged_value(): void {
		update_option( 'blogdescription', 'Unchanged' );

		$ability = new Update_Setting();
		$result  = $ability->execute(
			array(
				'name'  => 'blogdescription',
				'value' => 'Unchanged',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Unchanged', $result['value'] );
	}

	public function test_update_setting_rejects_unlisted_settings(): void {
		$ability = new Update_Setting();
		$result  = $ability->execute(
			array(
				'name'  => 'siteurl',
				'value' => 'https://example.com',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'site_agent_invalid_setting', $result->get_error_code() );
	}

	public function test_update_setting_requires_the_write_gate(): void {
		update_option( 'wpai_feature_site-agent_field_allow_writes', false );

		$ability = new Update_Setting();

		$this->assertWPError( $ability->check_permission( array() ) );
	}

	/**
	 * Ensures the plugin's ability category exists.
	 */
	private function ensure_category(): void {
		if ( wp_has_ability_category( WPAI_DEFAULT_ABILITY_CATEGORY ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context.
		try {
			wp_register_ability_category(
				WPAI_DEFAULT_ABILITY_CATEGORY,
				array(
					'label'       => 'AI',
					'description' => 'AI abilities.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the experiment's abilities.
	 */
	private function register_abilities(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context.
		try {
			( new Read_Site() )->register();
			( new Create_Draft() )->register();
			( new Update_Post() )->register();
			( new Update_Setting() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}
}
