<?php
/**
 * Integration tests for the Site Agent REST controller.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Site_Agent
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Site_Agent;

use ReflectionProperty;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Site_Agent\Create_Draft;
use WordPress\AI\Abilities\Site_Agent\Read_Site;
use WordPress\AI\Abilities\Site_Agent\Update_Post;
use WordPress\AI\Abilities\Site_Agent\Update_Setting;
use WordPress\AI\Experiments\Site_Agent\Conversation_Store;
use WordPress\AI\Experiments\Site_Agent\Proposal_Store;
use WordPress\AI\Experiments\Site_Agent\REST\Controller;
use WordPress\AI\Logging\Logging_Integration;

/**
 * Controller test case.
 *
 * @since x.x.x
 */
class ControllerTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Manager the logging integration held before the test disabled it.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private $original_shared_manager = null;

	public function setUp(): void {
		parent::setUp();

		$this->ensure_category();
		$this->register_abilities();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// These tests do not boot the request log table.
		$property = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$property->setAccessible( true );
		$this->original_shared_manager = Logging_Integration::get_log_manager();
		$property->setValue( null, null );

		update_option( 'wpai_feature_site-agent_enabled', true );
		update_option( 'wpai_feature_site-agent_field_allow_writes', true );
	}

	public function tearDown(): void {
		foreach ( array( Read_Site::ABILITY, Create_Draft::ABILITY, Update_Post::ABILITY, Update_Setting::ABILITY ) as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				continue;
			}

			wp_unregister_ability( $name );
		}

		delete_option( 'wpai_feature_site-agent_enabled' );
		delete_option( 'wpai_feature_site-agent_field_allow_writes' );

		$property = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$property->setAccessible( true );
		$property->setValue( null, $this->original_shared_manager );

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	public function test_lists_and_reads_conversations(): void {
		$conversation = ( new Conversation_Store() )->create( $this->admin_id, 'Controller test' );

		$list = ( new Controller() )->list_conversations( new WP_REST_Request() );

		$this->assertInstanceOf( WP_REST_Response::class, $list );
		$this->assertCount( 1, $list->get_data()['conversations'] );
		$this->assertSame( $conversation['id'], $list->get_data()['conversations'][0]['id'] );

		$request = new WP_REST_Request();
		$request->set_param( 'id', $conversation['id'] );

		$response = ( new Controller() )->get_conversation( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $conversation['id'], $response->get_data()['id'] );
		$this->assertSame( array(), $response->get_data()['transcript'] );
		$this->assertNull( $response->get_data()['proposal'] );
	}

	public function test_returns_a_pending_proposal_with_the_conversation(): void {
		$store        = new Conversation_Store();
		$conversation = $store->create( $this->admin_id, 'Proposal test' );
		$proposal     = ( new Proposal_Store() )->create(
			$this->admin_id,
			$conversation['id'],
			Create_Draft::ABILITY,
			array(
				'title'   => 'A new draft',
				'content' => '<p>Hello.</p>',
			)
		);

		$this->assertIsArray( $proposal );

		$conversation['pending_proposal_id'] = $proposal['id'];
		$store->save( $conversation );

		$request = new WP_REST_Request();
		$request->set_param( 'id', $conversation['id'] );

		$response = ( new Controller() )->get_conversation( $request );
		$data     = $response->get_data();

		$this->assertSame( $proposal['id'], $data['proposal']['id'] );
		$this->assertSame( Create_Draft::ABILITY, $data['proposal']['ability'] );
		$this->assertSame( 'A new draft', $data['proposal']['args']['title'] );
		$this->assertSame( 'Title', $data['proposal']['changes'][1]['label'] );
	}

	public function test_renames_and_archives_a_conversation(): void {
		$conversation = ( new Conversation_Store() )->create( $this->admin_id, 'Old title' );

		$rename = new WP_REST_Request();
		$rename->set_param( 'id', $conversation['id'] );
		$rename->set_param( 'title', 'New title' );

		$response = ( new Controller() )->rename_conversation( $rename );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'New title', $response->get_data()['title'] );

		$archive = new WP_REST_Request();
		$archive->set_param( 'id', $conversation['id'] );
		$archive->set_param( 'archived', true );

		$response = ( new Controller() )->archive_conversation( $archive );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['archived'] );
	}

	public function test_updates_proposal_arguments(): void {
		$store        = new Conversation_Store();
		$conversation = $store->create( $this->admin_id, 'Edit test' );
		$proposal     = ( new Proposal_Store() )->create(
			$this->admin_id,
			$conversation['id'],
			Create_Draft::ABILITY,
			array( 'title' => 'Original title' )
		);

		$this->assertIsArray( $proposal );

		$request = new WP_REST_Request();
		$request->set_param( 'proposal_id', $proposal['id'] );
		$request->set_param( 'conversation_id', $conversation['id'] );
		$request->set_param( 'args', array( 'title' => 'Edited title' ) );

		$response = ( new Controller() )->update_proposal( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'Edited title', $response->get_data()['args']['title'] );

		$stored = ( new Proposal_Store() )->get( $proposal['id'], $this->admin_id );

		$this->assertIsArray( $stored );
		$this->assertSame( 'Edited title', $stored['args']['title'] );
	}

	public function test_declines_and_deletes_a_conversation(): void {
		$store        = new Conversation_Store();
		$conversation = $store->create( $this->admin_id, 'Delete test' );
		$proposal     = ( new Proposal_Store() )->create(
			$this->admin_id,
			$conversation['id'],
			Create_Draft::ABILITY,
			array( 'title' => 'Declined draft' )
		);

		$this->assertIsArray( $proposal );

		$decline = new WP_REST_Request();
		$decline->set_param( 'proposal_id', $proposal['id'] );
		$decline->set_param( 'conversation_id', $conversation['id'] );

		$response = ( new Controller() )->decline( $decline );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['declined'] );
		$this->assertNotEmpty( $response->get_data()['transcript'] );

		$delete = new WP_REST_Request();
		$delete->set_param( 'id', $conversation['id'] );

		$response = ( new Controller() )->delete_conversation( $delete );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertNull( $store->get( $conversation['id'], $this->admin_id ) );
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
	 * Registers the experiment's abilities outside the normal init order.
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
