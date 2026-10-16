<?php
/**
 * Integration tests for the Revision_Change_Summary experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Revision_Change_Summary;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Experiments\Revision_Change_Summary\Revision_Change_Summary;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Revision_Change_Summary experiment test case.
 *
 * @since x.x.x
 */
class Revision_Change_SummaryTest extends WP_UnitTestCase {

	/**
	 * Feature registry.
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private $registry;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		update_option( 'wpai_feature_revision-change-summary_enabled', true );

		$experiments = new Experiments();
		$experiments->init();

		$this->registry = new Registry();
		$loader         = new Loader( $this->registry );
		$loader->init();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_feature_revision-change-summary_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_filter( 'wpai_default_feature_classes', array( Experiments::class, 'register_default_experiment_classes' ), 9 );

		foreach ( array( 'ai_revision_change_summary', 'ai_revision_change_summary_classic' ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}

		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * Tests that the experiment is listed in the default experiment classes.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_is_registered() {
		$this->assertInstanceOf( Revision_Change_Summary::class, $this->registry->get_feature( 'revision-change-summary' ) );
	}

	/**
	 * Tests that the editor script loads for post types with revisions.
	 *
	 * This is the positive case the skip tests below are checked against.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_on_post_editor() {
		$experiment = new Revision_Change_Summary();

		set_current_screen( 'post' );
		$experiment->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_revision_change_summary', 'enqueued' ) );
	}

	/**
	 * Tests that the editor script skips post types without revisions.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_skips_post_type_without_revisions() {
		register_post_type(
			'rcs_no_revisions',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);

		$experiment = new Revision_Change_Summary();

		set_current_screen( 'rcs_no_revisions' );
		get_current_screen()->base      = 'post';
		get_current_screen()->post_type = 'rcs_no_revisions';
		$experiment->enqueue_assets();

		unregister_post_type( 'rcs_no_revisions' );

		$this->assertFalse( wp_script_is( 'ai_revision_change_summary', 'enqueued' ) );
	}

	/**
	 * Tests that the editor script skips posts with revisions turned off.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_skips_post_with_revisions_turned_off() {
		$experiment = new Revision_Change_Summary();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the edited post.
		$GLOBALS['post'] = self::factory()->post->create_and_get();
		set_current_screen( 'post' );

		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		$experiment->enqueue_assets();
		remove_filter( 'wp_revisions_to_keep', '__return_zero' );

		$this->assertFalse( wp_script_is( 'ai_revision_change_summary', 'enqueued' ) );
	}

	/**
	 * Tests that the classic script skips posts with revisions turned off.
	 *
	 * Core still shows autosaves on the classic revisions screen in that case.
	 *
	 * @since x.x.x
	 */
	public function test_classic_assets_skip_post_with_revisions_turned_off() {
		$experiment = new Revision_Change_Summary();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the revisions screen post.
		$GLOBALS['post'] = self::factory()->post->create_and_get();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		$experiment->maybe_enqueue_classic_assets( 'revision.php' );
		remove_filter( 'wp_revisions_to_keep', '__return_zero' );

		$this->assertFalse( wp_script_is( 'ai_revision_change_summary_classic', 'enqueued' ) );
	}

	/**
	 * Tests that the classic script loads on the revisions screen.
	 *
	 * @since x.x.x
	 */
	public function test_classic_assets_load_on_revisions_screen() {
		$experiment = new Revision_Change_Summary();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the revisions screen post.
		$GLOBALS['post'] = self::factory()->post->create_and_get();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$experiment->maybe_enqueue_classic_assets( 'revision.php' );

		$this->assertTrue( wp_script_is( 'ai_revision_change_summary_classic', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'ai_revision_change_summary_classic', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( '"postId":"' . $GLOBALS['post']->ID . '"', $data );
	}

	/**
	 * Tests that the classic script skips users who can't edit the post.
	 *
	 * @since x.x.x
	 */
	public function test_classic_assets_skip_users_who_cannot_edit() {
		$experiment = new Revision_Change_Summary();

		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the revisions screen post.
		$GLOBALS['post'] = self::factory()->post->create_and_get( array( 'post_author' => $author_id ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$experiment->maybe_enqueue_classic_assets( 'revision.php' );

		$this->assertFalse( wp_script_is( 'ai_revision_change_summary_classic', 'enqueued' ) );
	}
}
