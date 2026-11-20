<?php
/**
 * Integration tests for the Personas experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Personas
 */

namespace WordPress\AI\Tests\Integration\Experiments\Personas;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Title_Generation\Title_Generation;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Personas\Personas;
use WordPress\AI\Experiments\Personas\REST_Personas_Controller;
use WordPress\AI\Services\Personas as Personas_Service;

/**
 * Personas experiment test case.
 *
 * @since x.x.x
 */
class PersonasTest extends WP_UnitTestCase {

	/**
	 * Experiment instance under test.
	 *
	 * @var \WordPress\AI\Experiments\Personas\Personas
	 */
	private Personas $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		Personas_Service::reset_cache();

		if ( post_type_exists( Personas_Service::POST_TYPE ) ) {
			unregister_post_type( Personas_Service::POST_TYPE );
		}

		$this->experiment = new Personas();
		$this->experiment->register_post_type();
		$this->experiment->register_post_meta();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		global $wp_rest_server;

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		wp_set_current_user( 0 );
		delete_option( Personas_Service::DEFAULT_OPTION );

		if ( post_type_exists( Personas_Service::POST_TYPE ) ) {
			unregister_post_type( Personas_Service::POST_TYPE );
		}

		unregister_post_meta( 'post', Personas_Service::META_KEY );
		unregister_post_meta( Personas_Service::POST_TYPE, Personas_Service::ROLE_META_KEY );
		unregister_post_meta( Personas_Service::POST_TYPE, Personas_Service::AUDIENCE_META_KEY );

		Personas_Service::reset_cache();

		parent::tearDown();
	}

	/**
	 * Re-registers the post type after a filter changes its arguments.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Post_Type The registered post type object.
	 */
	private function reregister_post_type(): \WP_Post_Type {
		unregister_post_type( Personas_Service::POST_TYPE );
		$this->experiment->register_post_type();

		$post_type = get_post_type_object( Personas_Service::POST_TYPE );
		$this->assertInstanceOf( \WP_Post_Type::class, $post_type );

		return $post_type;
	}

	/**
	 * Returns the registered personas post type object.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Post_Type The post type object.
	 */
	private function post_type(): \WP_Post_Type {
		$post_type = get_post_type_object( Personas_Service::POST_TYPE );
		$this->assertInstanceOf( \WP_Post_Type::class, $post_type );

		return $post_type;
	}

	/**
	 * Boots a fresh REST server so the personas routes are registered.
	 *
	 * @since x.x.x
	 */
	private function boot_rest_server(): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * The experiment should describe itself as an editor experiment.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_metadata(): void {
		$this->assertSame( 'personas', Personas::get_id() );
		$this->assertSame( Experiment_Category::EDITOR, $this->experiment->get_category() );
		$this->assertNotEmpty( $this->experiment->get_label() );
		$this->assertNotEmpty( $this->experiment->get_description() );
	}

	/**
	 * Registering the experiment should wire its hooks.
	 *
	 * @since x.x.x
	 */
	public function test_register_wires_hooks(): void {
		unregister_post_type( Personas_Service::POST_TYPE );

		$this->experiment->register();

		$this->assertTrue( post_type_exists( Personas_Service::POST_TYPE ) );
		$this->assertNotFalse( has_action( 'enqueue_block_editor_assets', array( $this->experiment, 'enqueue_assets' ) ) );
		$this->assertNotFalse( has_action( 'wp_before_execute_ability', array( $this->experiment, 'capture_ability_context' ) ) );
		$this->assertNotFalse( has_action( 'wp_after_execute_ability', array( $this->experiment, 'release_ability_context' ) ) );
	}

	/**
	 * The post type should sit under Posts and use the persona REST controller.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_arguments(): void {
		$post_type = $this->post_type();

		$this->assertFalse( $post_type->public );
		$this->assertTrue( $post_type->show_ui );
		$this->assertSame( 'edit.php', $post_type->show_in_menu );
		$this->assertSame( REST_Personas_Controller::class, $post_type->rest_controller_class );
	}

	/**
	 * Authors should not be able to define personas.
	 *
	 * @since x.x.x
	 */
	public function test_authors_cannot_manage_personas(): void {
		$post_type = $this->post_type();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertFalse( current_user_can( $post_type->cap->create_posts ), 'An author should not be able to create personas' );
		$this->assertFalse( current_user_can( $post_type->cap->edit_posts ), 'An author should not reach the personas list table' );

		$persona_id = self::factory()->post->create( array( 'post_type' => Personas_Service::POST_TYPE ) );

		$this->assertFalse( current_user_can( 'edit_post', $persona_id ), 'An author should not be able to edit a persona' );
		$this->assertFalse( current_user_can( 'delete_post', $persona_id ), 'An author should not be able to delete a persona' );
		$this->assertFalse( current_user_can( 'read_post', $persona_id ), 'An author should not be able to read a persona' );
	}

	/**
	 * Editors and administrators should be able to define personas.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_roles_that_manage_personas
	 *
	 * @param string $role The role to check.
	 */
	public function test_editors_and_admins_can_manage_personas( string $role ): void {
		$post_type = $this->post_type();

		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$this->assertTrue( current_user_can( $post_type->cap->create_posts ), sprintf( 'A %s should be able to create personas', $role ) );

		$persona_id = self::factory()->post->create( array( 'post_type' => Personas_Service::POST_TYPE ) );

		$this->assertTrue( current_user_can( 'edit_post', $persona_id ), sprintf( 'A %s should be able to edit a persona', $role ) );
		$this->assertTrue( current_user_can( 'read_post', $persona_id ), sprintf( 'A %s should be able to read a persona', $role ) );
	}

	/**
	 * Data provider for roles that may manage personas.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}> Role names.
	 */
	public function data_roles_that_manage_personas(): array {
		return array(
			'editor'        => array( 'editor' ),
			'administrator' => array( 'administrator' ),
		);
	}

	/**
	 * The capability map should be overridable for sites with other needs.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_args_filter_can_change_the_capability(): void {
		add_filter(
			'wpai_persona_post_type_args',
			static function ( array $args ): array {
				$args['capabilities'] = array_fill_keys( array_keys( $args['capabilities'] ), 'manage_options' );

				return $args;
			}
		);

		$post_type = $this->reregister_post_type();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse( current_user_can( $post_type->cap->create_posts ), 'An editor should not create personas once the filter tightens the capability' );
	}

	/**
	 * Published personas must not be readable over REST by visitors.
	 *
	 * The core posts controller treats any published post as readable once its
	 * post type is in REST; the persona controller has to close that.
	 *
	 * @since x.x.x
	 */
	public function test_rest_hides_published_personas_from_visitors(): void {
		$persona_id = self::factory()->post->create(
			array(
				'post_type'    => Personas_Service::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => 'Secret house voice.',
			)
		);

		$this->boot_rest_server();
		wp_set_current_user( 0 );

		$single = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Personas_Service::POST_TYPE . '/' . $persona_id ) );
		$this->assertContains( $single->get_status(), array( 401, 403 ), 'A visitor should not read a single persona' );

		$collection = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Personas_Service::POST_TYPE ) );
		$this->assertSame( 200, $collection->get_status() );
		$this->assertSame( array(), $collection->get_data(), 'A visitor should see no personas in the collection' );
	}

	/**
	 * Subscribers and authors must not read personas over REST either.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_roles_that_cannot_read_personas
	 *
	 * @param string $role The role to check.
	 */
	public function test_rest_hides_personas_from_roles_below_editor( string $role ): void {
		$persona_id = self::factory()->post->create(
			array(
				'post_type'   => Personas_Service::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$this->boot_rest_server();
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$single = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Personas_Service::POST_TYPE . '/' . $persona_id ) );
		$this->assertContains( $single->get_status(), array( 401, 403 ), sprintf( 'A %s should not read a persona', $role ) );
	}

	/**
	 * Data provider for roles that may not read personas.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}> Role names.
	 */
	public function data_roles_that_cannot_read_personas(): array {
		return array(
			'subscriber' => array( 'subscriber' ),
			'author'     => array( 'author' ),
		);
	}

	/**
	 * Editors should read personas over REST, which the block editor relies on.
	 *
	 * @since x.x.x
	 */
	public function test_rest_lets_editors_read_personas(): void {
		$persona_id = self::factory()->post->create(
			array(
				'post_type'   => Personas_Service::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$this->boot_rest_server();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$single = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Personas_Service::POST_TYPE . '/' . $persona_id ) );
		$this->assertSame( 200, $single->get_status() );
	}

	/**
	 * Authors should be able to select a persona for their own post only.
	 *
	 * @since x.x.x
	 */
	public function test_per_post_meta_follows_edit_post(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$own       = self::factory()->post->create( array( 'post_author' => $author_id ) );
		$others    = self::factory()->post->create( array( 'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ) ) );

		wp_set_current_user( $author_id );

		$this->assertTrue( current_user_can( 'edit_post_meta', $own, Personas_Service::META_KEY ), 'An author may set the persona on their own post' );
		$this->assertFalse( current_user_can( 'edit_post_meta', $others, Personas_Service::META_KEY ), "An author may not set the persona on someone else's post" );
	}

	/**
	 * The per-post override should be sanitized to a persona ID.
	 *
	 * @since x.x.x
	 */
	public function test_per_post_meta_is_sanitized(): void {
		$post_id = self::factory()->post->create();

		update_post_meta( $post_id, Personas_Service::META_KEY, 'Journalistic <b>' );

		$this->assertSame( 'journalisticb', get_post_meta( $post_id, Personas_Service::META_KEY, true ) );
	}

	/**
	 * Personas should not get the per-post override meta key.
	 *
	 * @since x.x.x
	 */
	public function test_per_post_meta_is_not_registered_on_personas(): void {
		$this->assertArrayNotHasKey( Personas_Service::META_KEY, get_registered_meta_keys( 'post', Personas_Service::POST_TYPE ) );
	}

	/**
	 * Role and audience should be editable fields on personas, and nowhere else.
	 *
	 * @since x.x.x
	 */
	public function test_role_and_audience_meta_registered_on_personas(): void {
		$persona_keys = get_registered_meta_keys( 'post', Personas_Service::POST_TYPE );
		$post_keys    = get_registered_meta_keys( 'post', 'post' );

		foreach ( array( Personas_Service::ROLE_META_KEY, Personas_Service::AUDIENCE_META_KEY ) as $key ) {
			$this->assertArrayHasKey( $key, $persona_keys );
			$this->assertNotEmpty( $persona_keys[ $key ]['show_in_rest'] );
			$this->assertArrayNotHasKey( $key, $post_keys );
		}
	}

	/**
	 * Role and audience should round-trip through REST, as the editor saves them.
	 *
	 * Meta only appears in a post type's REST schema when the post type
	 * supports `custom-fields`, so registration alone is not enough.
	 *
	 * @since x.x.x
	 */
	public function test_role_and_audience_round_trip_over_rest(): void {
		$persona_id = self::factory()->post->create( array( 'post_type' => Personas_Service::POST_TYPE ) );

		$this->boot_rest_server();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Personas_Service::POST_TYPE . '/' . $persona_id );
		$request->set_body_params(
			array(
				'meta' => array(
					Personas_Service::ROLE_META_KEY     => 'A reporter.',
					Personas_Service::AUDIENCE_META_KEY => 'General readers.',
				),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'A reporter.', get_post_meta( $persona_id, Personas_Service::ROLE_META_KEY, true ) );
		$this->assertSame( 'General readers.', get_post_meta( $persona_id, Personas_Service::AUDIENCE_META_KEY, true ) );
	}

	/**
	 * Role and audience set on a persona post should reach the prompt.
	 *
	 * @since x.x.x
	 */
	public function test_role_and_audience_reach_the_prompt(): void {
		$persona_id = self::factory()->post->create(
			array(
				'post_type'    => Personas_Service::POST_TYPE,
				'post_name'    => 'support',
				'post_title'   => 'Support',
				'post_content' => 'Calm and specific.',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $persona_id, Personas_Service::ROLE_META_KEY, "A support specialist.\n<script>x</script>" );
		update_post_meta( $persona_id, Personas_Service::AUDIENCE_META_KEY, 'Customers mid-problem.' );
		update_option( Personas_Service::DEFAULT_OPTION, 'support' );
		Personas_Service::reset_cache();

		$formatted = Personas_Service::get_instance()->format_for_prompt();

		// The script element is dropped along with its contents, not just its tags.
		$this->assertStringContainsString( '<role>A support specialist.</role>', $formatted );
		$this->assertStringContainsString( '<audience>Customers mid-problem.</audience>', $formatted );
	}

	/**
	 * The settings field should offer "None" followed by every persona.
	 *
	 * @since x.x.x
	 */
	public function test_settings_field_lists_personas(): void {
		$fields = $this->experiment->get_settings_fields();

		$this->assertCount( 1, $fields );
		$this->assertSame( 'default_persona', $fields[0]['id'] );

		$values = wp_list_pluck( $fields[0]['elements'], 'value' );

		$this->assertSame( '', $values[0], 'The first option should be "None"' );
		$this->assertContains( 'professional', $values );
		$this->assertContains( 'journalistic', $values );
	}

	/**
	 * The default persona setting should be registered and sanitized.
	 *
	 * @since x.x.x
	 */
	public function test_default_persona_setting_is_registered(): void {
		$this->experiment->register_settings();

		$settings = get_registered_settings();

		$this->assertArrayHasKey( Personas_Service::DEFAULT_OPTION, $settings );

		update_option( Personas_Service::DEFAULT_OPTION, 'Technical!' );
		$this->assertSame( 'technical', get_option( Personas_Service::DEFAULT_OPTION ) );

		unregister_setting( 'ai_experiments', Personas_Service::DEFAULT_OPTION );
	}

	/**
	 * A post ID from ability input should resolve that post's persona.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_post_input_keys
	 *
	 * @param string $key The input key carrying the post ID.
	 */
	public function test_ability_input_resolves_the_post_persona( string $key ): void {
		update_option( Personas_Service::DEFAULT_OPTION, 'professional' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Personas_Service::META_KEY, 'technical' );

		$value = 'context' === $key ? (string) $post_id : $post_id;

		$this->experiment->capture_ability_context( 'ai/title-generation', array( $key => $value ) );
		$this->assertSame( 'technical', Personas_Service::get_instance()->get_active_persona_id() );

		$this->experiment->release_ability_context();
		$this->assertSame( 'professional', Personas_Service::get_instance()->get_active_persona_id() );
	}

	/**
	 * Data provider for the input keys abilities use to pass a post.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}> Input keys.
	 */
	public function data_post_input_keys(): array {
		return array(
			'post_id'         => array( 'post_id' ),
			'numeric context' => array( 'context' ),
		);
	}

	/**
	 * A comment reply should follow the persona of the post it replies on.
	 *
	 * @since x.x.x
	 */
	public function test_comment_input_resolves_the_comment_post_persona(): void {
		update_option( Personas_Service::DEFAULT_OPTION, 'professional' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Personas_Service::META_KEY, 'conversational' );
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		$this->experiment->capture_ability_context( 'ai/suggest-reply', array( 'comment_id' => $comment_id ) );

		$this->assertSame( 'conversational', Personas_Service::get_instance()->get_active_persona_id() );
	}

	/**
	 * Input without a usable post should fall back to the site default.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_inputs_without_a_post
	 *
	 * @param mixed $input Ability input.
	 */
	public function test_input_without_a_post_uses_the_default( $input ): void {
		update_option( Personas_Service::DEFAULT_OPTION, 'journalistic' );

		$this->experiment->capture_ability_context( 'ai/title-generation', $input );

		$this->assertNull( Personas_Service::get_context_post_id() );
		$this->assertSame( 'journalistic', Personas_Service::get_instance()->get_active_persona_id() );
	}

	/**
	 * Data provider for inputs that carry no post.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{mixed}> Inputs.
	 */
	public function data_inputs_without_a_post(): array {
		return array(
			'null input'      => array( null ),
			'string input'    => array( 'some text' ),
			'text context'    => array( array( 'context' => 'Post about cats' ) ),
			'zero post_id'    => array( array( 'post_id' => 0 ) ),
			'missing comment' => array( array( 'comment_id' => 999999 ) ),
			'unrelated keys'  => array( array( 'content' => 'Hello' ) ),
		);
	}

	/**
	 * The captured context should flow all the way into a system instruction.
	 *
	 * This exercises the path a real ability execution takes: the core action
	 * fires with the ability input, then the ability builds its instruction
	 * without being told which post it is working on.
	 *
	 * @since x.x.x
	 */
	public function test_captured_context_reaches_the_system_instruction(): void {
		update_option( Personas_Service::DEFAULT_OPTION, 'professional' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Personas_Service::META_KEY, 'journalistic' );

		add_action( 'wp_before_execute_ability', array( $this->experiment, 'capture_ability_context' ), 10, 2 );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'wp_before_execute_ability', 'ai/title-generation', array( 'context' => (string) $post_id ) );

		$ability = new Title_Generation(
			'ai/title-generation',
			array(
				'label'       => 'Title Generation',
				'description' => 'Generates title suggestions from content',
			)
		);

		$instruction = $ability->get_system_instruction();

		$this->assertStringContainsString( '<name>Journalistic</name>', $instruction );
		$this->assertStringNotContainsString( '<name>Professional</name>', $instruction );
	}

	/**
	 * The editor script should not load outside the post editor.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_skips_other_screens(): void {
		set_current_screen( 'options-general' );

		$this->experiment->enqueue_assets();

		$this->assertFalse( wp_script_is( 'ai_personas', 'enqueued' ) );
	}

	/**
	 * The editor script should load with the data both panels need.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_localizes_panel_data(): void {
		set_current_screen( 'post' );

		$this->experiment->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_personas', 'enqueued' ) );

		$data = (string) wp_scripts()->get_data( 'ai_personas', 'data' );

		foreach ( array( 'metaKey', 'personas', 'defaultPersona', 'manageUrl', 'personaPostType', 'roleMetaKey', 'audienceMetaKey' ) as $key ) {
			$this->assertStringContainsString( '"' . $key . '"', $data );
		}
	}
}
