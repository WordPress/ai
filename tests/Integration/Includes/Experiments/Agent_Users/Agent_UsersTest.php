<?php
/**
 * Integration tests for the Agent_Users experiment.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Agent_Users
 */

namespace WordPress\AI\Tests\Integration\Experiments\Agent_Users;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Agent_Users\Agent_Account;
use WordPress\AI\Experiments\Agent_Users\Agent_Provisioner;
use WordPress\AI\Experiments\Agent_Users\Agent_Users;
use WordPress\AI\Experiments\Agent_Users\New_User_Screen;
use WordPress\AI\Experiments\Agent_Users\Profile_Screen;
use WordPress\AI\Experiments\Agent_Users\Users_Screen;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Agent_Users experiment test case.
 *
 * @since x.x.x
 */
class Agent_UsersTest extends WP_UnitTestCase {
	/**
	 * Experiment instance under test.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Agent_Users\Agent_Users
	 */
	private Agent_Users $experiment;

	/**
	 * Agent provisioning service.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Agent_Users\Agent_Provisioner
	 */
	private Agent_Provisioner $provisioner;

	/**
	 * Administrator performing the provisioning in tests.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Network-active plugins present before a multisite test.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private array $network_active_plugins = array();

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_agent-users_enabled', true );

		// Agent identity is network-wide, so every site must load the login and
		// password-reset safeguards before provisioning is available.
		if ( is_multisite() ) {
			$this->network_active_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
			$this->set_ai_plugin_network_active( true );
			update_site_option( 'add_new_users', 1 );
		}

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'agent-users' );
		$this->assertInstanceOf(
			Agent_Users::class,
			$experiment,
			'Agent Users experiment should be registered in the registry.'
		);

		$this->experiment  = $experiment;
		$this->provisioner = new Agent_Provisioner();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin_id );
		}
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting a core test global.

		$GLOBALS['pagenow'] = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring a core test global.
		unset( $GLOBALS['current_screen'] );

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_agent-users_enabled' );
		remove_all_filters( 'wpai_feature_agent-users_enabled' );
		remove_all_filters( 'wp_redirect' );
		remove_all_actions( 'user_profile_update_errors' );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_false', 5 );
		remove_filter( 'application_password_is_api_request', '__return_true' );
		remove_role( 'wpai_agent_create_only' );
		remove_role( 'wpai_agent_no_edit_manager' );
		remove_role( 'wpai_agent_limited_manager' );
		remove_role( 'wpai_agent_contextual_manager' );
		remove_role( 'wpai_agent_contextual_worker' );
		remove_role( 'wpai_agent_editor_manager' );
		if ( is_multisite() ) {
			revoke_super_admin( $this->admin_id );
			update_site_option( 'active_sitewide_plugins', $this->network_active_plugins );
		}
		parent::tearDown();
	}

	/**
	 * Changes whether the AI plugin appears network-active in a multisite test.
	 *
	 * The plugin code is loaded by the test bootstrap either way, which lets the
	 * inactive state model a per-site activation.
	 *
	 * @since x.x.x
	 *
	 * @param bool $active Whether the plugin should be network-active.
	 */
	private function set_ai_plugin_network_active( bool $active ): void {
		$plugins  = (array) get_site_option( 'active_sitewide_plugins', array() );
		$basename = plugin_basename( WPAI_PLUGIN_FILE );

		if ( $active ) {
			$plugins[ $basename ] = time();
		} else {
			unset( $plugins[ $basename ] );
		}

		update_site_option( 'active_sitewide_plugins', $plugins );
	}

	/**
	 * Creates a post with an author and status.
	 *
	 * @since x.x.x
	 *
	 * @param int    $author_id Post author.
	 * @param string $status    Post status.
	 * @return int Post ID.
	 */
	private function create_post( int $author_id, string $status ): int {
		return self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Provisions an agent and returns the user.
	 *
	 * @since x.x.x
	 *
	 * @param string $login Agent username.
	 * @param string $role  Role slug.
	 * @return \WP_User Provisioned agent.
	 */
	private function provision_agent( string $login = 'test_agent', string $role = 'editor' ): \WP_User {
		$result = $this->provisioner->provision( $login, $role, $login . '@example.com' );
		$this->assertInstanceOf( \WP_User::class, $result, 'Provisioning should succeed.' );

		return $result;
	}

	/**
	 * Test that the experiment metadata is registered correctly.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$this->assertSame( 'agent-users', $this->experiment->get_id() );
		$this->assertSame( 'Agent Users', $this->experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $this->experiment->get_category() );
		$this->assertSame( 'experimental', $this->experiment->get_stability() );
		$this->assertSame( 'none', $this->experiment->get_capability() );
	}

	/**
	 * Tests that the current installation can enforce network-wide safeguards.
	 *
	 * @since x.x.x
	 */
	public function test_network_safeguards_are_available() {
		$this->assertTrue( Agent_Account::can_enforce_network_safeguards() );
		$this->assertTrue( Agent_Provisioner::current_user_can_provision() );
	}

	/**
	 * Tests that per-site activation allows revoking agent credentials but not creating them.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_multisite_agent_credentials_cannot_be_created_without_network_activation() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->provisioner->provision( 'site_only_agent', 'author', 'site_only_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );
		$this->assertTrue( user_can( $parent_id, 'create_app_password', $agent->ID ), 'The parent creates credentials while the plugin is network-active.' );

		$this->set_ai_plugin_network_active( false );

		$this->assertFalse( user_can( $parent_id, 'create_app_password', $agent->ID ), 'A credential created now would be unbounded on sites without the plugin.' );
		$this->assertFalse( user_can( $this->admin_id, 'create_app_password', $agent->ID ), 'Not even a super admin may create one.' );
		$this->assertTrue( user_can( $parent_id, 'list_app_passwords', $agent->ID ) );
		$this->assertTrue( user_can( $parent_id, 'delete_app_passwords', $agent->ID ), 'Existing credentials can still be revoked.' );
		$this->assertTrue( user_can( $this->admin_id, 'create_app_password', $parent_id ), 'Humans are unaffected.' );
	}

	/**
	 * Tests that per-site activation cannot provision agents on multisite.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_multisite_provisioning_requires_network_activation() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->set_ai_plugin_network_active( false );

		$this->assertFalse( Agent_Account::can_enforce_network_safeguards() );
		$this->assertFalse( Agent_Provisioner::current_user_can_provision() );

		$result = $this->provisioner->provision( 'unsafe-agent', 'editor', 'unsafe-agent@example.com' );
		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_requires_network_activation', $result->get_error_code() );
		$this->assertFalse( username_exists( Agent_Provisioner::apply_login_suffix( 'unsafe-agent' ) ), 'A direct provisioning call must not bypass the network-activation requirement.' );

		$GLOBALS['pagenow']     = 'user-new.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin screen.
		$_REQUEST['wpai_agent'] = '1';
		ob_start();
		( new New_User_Screen( $this->provisioner ) )->render_fields( 'add-new-user' );
		$this->assertSame( '', ob_get_clean(), 'The Add Agent fields should stay unavailable.' );

		ob_start();
		$this->experiment->render_network_activation_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'network-activated', $notice );

		$GLOBALS['pagenow'] = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin screen.
		ob_start();
		$this->experiment->render_network_activation_notice();
		$this->assertSame( '', ob_get_clean(), 'Screens unrelated to users should not carry the notice.' );

		$this->set_ai_plugin_network_active( true );
		$this->assertTrue( Agent_Provisioner::current_user_can_provision(), 'Provisioning should resume after network activation.' );
	}

	/**
	 * Test that provisioning creates a flagged account with the expected shape.
	 *
	 * @since x.x.x
	 */
	public function test_provision_creates_flagged_account() {
		$agent = $this->provision_agent( 'content_editor_agent', 'editor' );

		$this->assertTrue( Agent_Account::is_agent( $agent ) );
		$this->assertTrue( Agent_Account::is_agent( $agent->ID ) );
		$this->assertSame( array( 'editor' ), $agent->roles );
		$this->assertSame( 'content_editor_agent', $agent->user_login );
		$this->assertSame( 'content_editor_agent', $agent->display_name );
		$this->assertSame( 'content_editor_agent@example.com', $agent->user_email );
		$this->assertSame( $this->admin_id, (int) get_user_meta( $agent->ID, Agent_Account::META_CREATED_BY, true ) );
		$this->assertSame( $this->admin_id, (int) get_user_meta( $agent->ID, Agent_Account::META_PARENT, true ), 'Programmatic provisioning defaults the parent to the provisioner.' );

		$this->assertCount(
			0,
			\WP_Application_Passwords::get_user_application_passwords( $agent->ID ),
			'Credentials should be created separately through core\'s one-time REST reveal flow.'
		);
	}

	/**
	 * Test that provisioning preserves core's post-creation contract.
	 *
	 * @since x.x.x
	 */
	public function test_provision_runs_core_created_user_action() {
		$created_user_id = 0;
		$notification    = '';
		$listener        = static function ( int $user_id, string $notify ) use ( &$created_user_id, &$notification ): void {
			$created_user_id = $user_id;
			$notification    = $notify;
		};

		add_action( 'edit_user_created_user', $listener, 20, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Listening to the core Add User action.
		$agent = $this->provision_agent( 'core-hook-agent' );
		remove_action( 'edit_user_created_user', $listener, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Removing the core Add User action listener.

		$this->assertSame( $agent->ID, $created_user_id );
		$this->assertSame( 'admin', $notification, 'Only the administrator should receive the new-account notification.' );
	}

	/**
	 * Test that the public helper identifies agent accounts.
	 *
	 * @since x.x.x
	 */
	public function test_global_helper_identifies_agent_accounts() {
		$agent = $this->provision_agent( 'helper-agent' );

		$this->assertTrue( \wpai_is_agent_user( $agent ) );
		$this->assertTrue( \wpai_is_agent_user( $agent->ID ) );
		$this->assertFalse( \wpai_is_agent_user( $this->admin_id ) );
		$this->assertFalse( \wpai_is_agent_user( 0 ) );
	}

	/**
	 * Tests that an agent can be provisioned for another parent user.
	 *
	 * @since x.x.x
	 */
	public function test_provision_accepts_explicit_parent() {
		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$agent = $this->provisioner->provision( 'editor_helper', 'author', 'editor_helper@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$parent = wpai_get_agent_parent( $agent );
		$this->assertInstanceOf( \WP_User::class, $parent );
		$this->assertSame( $parent_id, $parent->ID );
		$this->assertSame( $this->admin_id, (int) get_user_meta( $agent->ID, Agent_Account::META_CREATED_BY, true ), 'The provisioner should still be recorded.' );
		$this->assertSame( array( $agent->ID ), Agent_Account::get_agent_ids( $parent_id ) );
		$this->assertNull( wpai_get_agent_parent( $parent_id ), 'Humans have no parent.' );
	}

	/**
	 * Provides parents that provisioning must reject.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}> Parent kind, role, and expected error code.
	 */
	public function data_invalid_parents(): array {
		return array(
			'missing user'      => array( 'missing', 'author', 'wpai_agent_invalid_parent' ),
			'agent parent'      => array( 'agent', 'author', 'wpai_agent_invalid_parent' ),
			'ineligible parent' => array( 'subscriber', 'subscriber', 'wpai_agent_invalid_parent' ),
			'role above parent' => array( 'author', 'editor', 'wpai_agent_role_exceeds_parent' ),
		);
	}

	/**
	 * Tests that provisioning rejects parents that cannot have agents.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_invalid_parents
	 *
	 * @param string $kind Kind of parent to create.
	 * @param string $role Role requested for the agent.
	 * @param string $code Expected error code.
	 */
	public function test_provision_rejects_invalid_parents( string $kind, string $role, string $code ) {
		switch ( $kind ) {
			case 'missing':
				$parent_id = PHP_INT_MAX;
				break;
			case 'agent':
				$parent_id = $this->provision_agent()->ID;
				break;
			default:
				$parent_id = self::factory()->user->create( array( 'role' => $kind ) );
		}

		$result = $this->provisioner->provision( 'invalid_parent', $role, 'invalid_parent@example.com', '', '', '', $parent_id );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertFalse( username_exists( 'invalid_parent_agent' ) );
	}

	/**
	 * Tests who may have agents by default and that site owners can restrict it.
	 *
	 * @since x.x.x
	 */
	public function test_parent_capability_defaults_and_restrictions() {
		$subscriber_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$contributor_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$editor_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent          = $this->provision_agent();

		// phpcs:disable WordPress.WP.Capabilities.Undetermined -- The agent parent capability constant.
		$this->assertFalse( user_can( $subscriber_id, Agent_Account::PARENT_CAP ), 'Users who cannot edit posts should not have agents by default.' );
		$this->assertTrue( user_can( $contributor_id, Agent_Account::PARENT_CAP ), 'Users who can edit posts should have agents by default.' );
		$this->assertFalse( user_can( $agent, Agent_Account::PARENT_CAP ), 'Agents should never have agents.' );

		$editor_role = get_role( 'editor' );
		$this->assertInstanceOf( \WP_Role::class, $editor_role );
		$editor_role->add_cap( Agent_Account::PARENT_CAP, false );
		try {
			$denied = user_can( $editor_id, Agent_Account::PARENT_CAP );
		} finally {
			$editor_role->remove_cap( Agent_Account::PARENT_CAP );
		}
		$this->assertFalse( $denied, 'An explicit denial on the role should be respected.' );

		get_userdata( $subscriber_id )->add_cap( Agent_Account::PARENT_CAP );
		$this->assertTrue( user_can( $subscriber_id, Agent_Account::PARENT_CAP ), 'An explicit grant should be respected.' );
		// phpcs:enable WordPress.WP.Capabilities.Undetermined
	}

	/**
	 * Tests that agents cannot provision agents and that the links are protected meta.
	 *
	 * @since x.x.x
	 */
	public function test_agents_cannot_provision_agents_and_links_are_protected() {
		$agent     = $this->provision_agent( 'provisioning_agent', 'editor' );
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $agent->ID );
		$result = $this->provisioner->provision( 'grandchild', 'author', 'grandchild@example.com', '', '', '', $editor_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_cannot_provision_agents', $result->get_error_code() );
		$this->assertFalse( Agent_Provisioner::current_user_can_provision() );

		foreach ( array( Agent_Account::META_KEY, Agent_Account::META_PARENT ) as $meta_key ) {
			$this->assertFalse(
				(bool) apply_filters( "auth_user_meta_{$meta_key}", true, $meta_key, $agent->ID, $this->admin_id, 'edit_user_meta', array() ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking core's meta authorization hook.
				sprintf( '%s should not be writable through capability-checked meta APIs.', $meta_key )
			);
		}
	}

	/**
	 * Tests that an agent and its parent treat each other's posts as their own.
	 *
	 * @since x.x.x
	 */
	public function test_agent_and_parent_share_post_ownership() {
		$parent_id   = self::factory()->user->create( array( 'role' => 'author' ) );
		$agent       = $this->provisioner->provision( 'owning_agent', 'author', 'owning_agent@example.com', '', '', '', $parent_id );
		$sibling     = $this->provisioner->provision( 'sibling_agent', 'author', 'sibling_agent@example.com', '', '', '', $parent_id );
		$stranger_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->assertInstanceOf( \WP_User::class, $agent );
		$this->assertInstanceOf( \WP_User::class, $sibling );

		$agent_draft    = $this->create_post( $agent->ID, 'draft' );
		$agent_private  = $this->create_post( $agent->ID, 'private' );
		$parent_draft   = $this->create_post( $parent_id, 'draft' );
		$parent_publish = $this->create_post( $parent_id, 'publish' );
		$stranger_draft = $this->create_post( $stranger_id, 'draft' );
		$sibling_draft  = $this->create_post( $sibling->ID, 'draft' );

		$this->assertTrue( user_can( $parent_id, 'edit_post', $agent_draft ), 'The parent should edit their agent\'s drafts.' );
		$this->assertTrue( user_can( $parent_id, 'delete_post', $agent_draft ), 'The parent should delete their agent\'s drafts.' );
		$this->assertTrue( user_can( $parent_id, 'read_post', $agent_private ), 'The parent should read their agent\'s private posts.' );

		$this->assertTrue( user_can( $agent, 'edit_post', $agent_draft ), 'The agent should edit its own drafts.' );
		$this->assertTrue( user_can( $agent, 'edit_post', $parent_draft ), 'The agent should edit its parent\'s drafts.' );
		$this->assertTrue( user_can( $agent, 'edit_post', $parent_publish ), 'Own-post rules apply to the parent\'s published posts.' );

		$this->assertFalse( user_can( $agent, 'edit_post', $stranger_draft ), 'Other users\' posts stay out of reach.' );
		$this->assertFalse( user_can( $parent_id, 'edit_post', $stranger_draft ) );
		$this->assertFalse( user_can( $agent, 'edit_post', $sibling_draft ), 'Agents sharing a parent are not linked to each other.' );
		$this->assertFalse( user_can( $stranger_id, 'edit_post', $agent_draft ), 'Unrelated users gain nothing.' );
	}

	/**
	 * Tests that shared ownership keeps each account's own-post limits.
	 *
	 * @since x.x.x
	 */
	public function test_shared_ownership_keeps_own_post_limits() {
		$parent_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$agent     = $this->provisioner->provision( 'contributing_agent', 'contributor', 'contributing_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$pending   = $this->create_post( $agent->ID, 'pending' );
		$published = $this->create_post( $agent->ID, 'publish' );
		$trashed   = $this->create_post( $agent->ID, 'publish' );
		wp_trash_post( $trashed );

		$this->assertTrue( user_can( $parent_id, 'edit_post', $pending ), 'A contributor parent edits their agent\'s pending posts, as their own.' );
		$this->assertFalse( user_can( $parent_id, 'edit_post', $published ), 'A contributor cannot edit published posts, even their agent\'s.' );
		$this->assertFalse( user_can( $agent, 'delete_post', $trashed ), 'A contributor cannot delete its own trashed post that was published.' );
		$this->assertFalse( user_can( $parent_id, 'delete_post', $trashed ), 'Nor can a contributor parent delete their agent\'s.' );
		$this->assertFalse( user_can( $parent_id, 'edit_post', $trashed ) );
	}

	/**
	 * Tests that linking never changes access to anyone else's posts.
	 *
	 * Each parent must get exactly what a user with the same role and no
	 * agents gets for a third user's posts, in every status, and each agent
	 * exactly what both its own role and its parent's role allow. On a
	 * sibling agent's posts, an agent must never get more than its role.
	 *
	 * @since x.x.x
	 */
	public function test_linking_leaves_access_to_others_posts_unchanged() {
		$stranger_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$controls    = array();
		foreach ( array( 'contributor', 'author', 'editor' ) as $role ) {
			$controls[ $role ] = self::factory()->user->create( array( 'role' => $role ) );
		}

		$pairs = array(
			array( 'contributor', 'contributor' ),
			array( 'author', 'author' ),
			array( 'author', 'contributor' ),
			array( 'editor', 'editor' ),
			array( 'editor', 'author' ),
		);

		foreach ( $pairs as $index => [ $parent_role, $agent_role ] ) {
			$parent_id = self::factory()->user->create( array( 'role' => $parent_role ) );
			$agent     = $this->provisioner->provision( "matrix_{$index}", $agent_role, "matrix_{$index}@example.com", '', '', '', $parent_id );
			$sibling   = $this->provisioner->provision( "matrix_sibling_{$index}", $agent_role, "matrix_sibling_{$index}@example.com", '', '', '', $parent_id );
			$this->assertInstanceOf( \WP_User::class, $agent );
			$this->assertInstanceOf( \WP_User::class, $sibling );

			foreach ( $this->posts_in_every_status( $stranger_id, $agent->ID ) as $label => $post_id ) {
				foreach ( array( 'edit_post', 'delete_post', 'read_post' ) as $cap ) {
					$context = sprintf( '%s on a stranger\'s %s (parent %s, agent %s)', $cap, $label, $parent_role, $agent_role );

					// phpcs:disable WordPress.WP.Capabilities.Undetermined -- Comparing each post capability.
					$this->assertSame( user_can( $controls[ $parent_role ], $cap, $post_id ), user_can( $parent_id, $cap, $post_id ), "Parent: {$context}" );
					$this->assertSame(
						user_can( $controls[ $agent_role ], $cap, $post_id ) && user_can( $controls[ $parent_role ], $cap, $post_id ),
						user_can( $agent, $cap, $post_id ),
						"Agent: {$context}"
					);
					// phpcs:enable WordPress.WP.Capabilities.Undetermined
				}
			}

			foreach ( $this->posts_in_every_status( $sibling->ID, 0 ) as $label => $post_id ) {
				foreach ( array( 'edit_post', 'delete_post', 'read_post' ) as $cap ) {
					// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Comparing each post capability.
					if ( ! user_can( $agent, $cap, $post_id ) || user_can( $controls[ $agent_role ], $cap, $post_id ) ) {
						continue;
					}

					$this->fail( sprintf( 'Agent gained %s on its sibling\'s %s (parent %s, agent %s).', $cap, $label, $parent_role, $agent_role ) );
				}
			}
		}
	}

	/**
	 * Creates an author's posts and pages in every status that maps differently.
	 *
	 * @since x.x.x
	 *
	 * @param int $author_id         Author of the posts.
	 * @param int $revision_author_id Optional. Another user who saves a revision of the author's post.
	 * @return array<string, int> Post IDs keyed by a description.
	 */
	private function posts_in_every_status( int $author_id, int $revision_author_id ): array {
		$posts = array();
		foreach ( array( 'post', 'page' ) as $type ) {
			foreach ( array( 'draft', 'pending', 'publish', 'private', 'future' ) as $status ) {
				$posts[ "{$status} {$type}" ] = self::factory()->post->create(
					array(
						'post_type'   => $type,
						'post_author' => $author_id,
						'post_status' => $status,
						'post_date'   => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : '',
					)
				);
			}

			foreach ( array( 'publish', 'draft' ) as $status ) {
				$trashed = self::factory()->post->create(
					array(
						'post_type'   => $type,
						'post_author' => $author_id,
						'post_status' => $status,
					)
				);
				wp_trash_post( $trashed );
				$posts[ "trashed {$status} {$type}" ] = $trashed;
			}
		}

		if ( $revision_author_id > 0 ) {
			$posts['revision by the agent'] = self::factory()->post->create(
				array(
					'post_type'   => 'revision',
					'post_status' => 'inherit',
					'post_parent' => $posts['publish post'],
					'post_author' => $revision_author_id,
					'post_name'   => $posts['publish post'] . '-revision-v1',
				)
			);
		}

		return $posts;
	}

	/**
	 * Tests that shared ownership follows core's own-post rules for private posts.
	 *
	 * @since x.x.x
	 */
	public function test_shared_ownership_covers_private_posts() {
		$parent_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$agent     = $this->provisioner->provision( 'private_agent', 'author', 'private_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$agent_private  = $this->create_post( $agent->ID, 'private' );
		$parent_private = $this->create_post( $parent_id, 'private' );

		$this->assertTrue( user_can( $agent, 'edit_post', $agent_private ), 'An author agent edits its own private posts.' );
		$this->assertTrue( user_can( $agent, 'delete_post', $agent_private ), 'An author agent deletes its own private posts.' );
		$this->assertTrue( user_can( $parent_id, 'edit_post', $agent_private ), 'An author parent edits their agent\'s private posts, as their own.' );
		$this->assertTrue( user_can( $agent, 'edit_post', $parent_private ), 'An author agent edits its parent\'s private posts, as their own.' );
		$this->assertTrue( user_can( $agent, 'delete_post', $parent_private ) );
	}

	/**
	 * Tests that user administration capabilities never reach agents and do not limit their roles.
	 *
	 * @since x.x.x
	 */
	public function test_user_administration_never_reaches_agents_nor_limits_their_roles() {
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'helpdesk',
			'Helpdesk',
			array(
				'read'                 => true,
				'edit_posts'           => true,
				'edit_users'           => true,
				'manage_sites'         => true,
				'manage_network_users' => true,
			)
		);
		try {
			// The parent cannot administer users, but the agent never could either, so the role fits.
			$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			$bounded   = $this->provisioner->provision( 'helpdesk_agent', 'helpdesk', 'helpdesk_agent@example.com', '', '', '', $editor_id );

			if ( is_multisite() ) {
				// A super admin parent holds every capability, so only the agent rule stands in the way.
				$super_id = self::factory()->user->create( array( 'role' => 'editor' ) );
				grant_super_admin( $super_id );
				$network_agent = $this->provisioner->provision( 'network_helpdesk_agent', 'helpdesk', 'network_helpdesk_agent@example.com', '', '', '', $super_id );
				$this->assertInstanceOf( \WP_User::class, $network_agent );
				$this->assertFalse( user_can( $network_agent, 'manage_sites' ), 'An agent cannot add users to sites from the network screens.' );
				$this->assertFalse( user_can( $network_agent, 'manage_network_users' ), 'An agent cannot mark users as spam from the network screens.' );
				revoke_super_admin( $super_id );
			}
		} finally {
			remove_role( 'helpdesk' );
		}

		$this->assertInstanceOf( \WP_User::class, $bounded, 'Capabilities the agent can never use should not count against its parent.' );
		$this->assertFalse( user_can( $bounded, 'edit_users' ) );
	}

	/**
	 * Tests that parents manage their agents and agents cannot manage parents.
	 *
	 * Runs on multisite too, where the parent is deliberately not a network
	 * administrator.
	 *
	 * @since x.x.x
	 */
	public function test_parent_and_agent_user_management() {
		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->provisioner->provision( 'managed_agent', 'author', 'managed_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$this->assertTrue( user_can( $parent_id, 'edit_user', $agent->ID ), 'A parent should edit their agent.' );
		$this->assertTrue( user_can( $parent_id, 'create_app_password', $agent->ID ), 'A parent should manage their agent\'s credentials.' );
		$this->assertFalse( user_can( $parent_id, 'promote_user', $agent->ID ), 'Role changes should still require core permissions.' );
		$this->assertFalse( user_can( $parent_id, 'delete_user', $agent->ID ), 'Deleting the agent should still require core permissions.' );
		$this->assertFalse( user_can( $other_id, 'edit_user', $agent->ID ), 'Other users should not edit the agent.' );

		// Having agents changes nothing about managing anyone else.
		$other_agent = $this->provisioner->provision( 'other_managed_agent', 'author', 'other_managed_agent@example.com', '', '', '', $other_id );
		$this->assertInstanceOf( \WP_User::class, $other_agent );
		foreach ( array( $other_id, $other_agent->ID, $this->admin_id ) as $target_id ) {
			foreach ( array( 'edit_user', 'promote_user', 'remove_user', 'delete_user', 'create_app_password' ) as $capability ) {
				// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Checking each user management capability.
				$this->assertFalse( user_can( $parent_id, $capability, $target_id ), sprintf( 'A parent should not %s user %d.', $capability, $target_id ) );
				// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Checking each user management capability.
				$this->assertFalse( user_can( $agent, $capability, $target_id ), sprintf( 'An agent should not %s user %d.', $capability, $target_id ) );
			}
		}

		// Even a role that manages users gives an agent no say over other accounts.
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'user_manager',
			'User Manager',
			array(
				'read'          => true,
				'list_users'    => true,
				'edit_users'    => true,
				'promote_users' => true,
				'remove_users'  => true,
				'delete_users'  => true,
			)
		);
		try {
			$manager_agent   = $this->provision_agent( 'manager_child', 'user_manager' );
			$manager_control = self::factory()->user->create( array( 'role' => 'user_manager' ) );
			$stranger_id     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			if ( ! is_multisite() ) {
				$this->assertTrue( user_can( $manager_control, 'edit_user', $stranger_id ), 'A person with the same role edits other users.' );
			}
			foreach ( array( 'edit_user', 'promote_user', 'remove_user', 'delete_user' ) as $capability ) {
				foreach ( array( $this->admin_id, $stranger_id ) as $target_id ) {
					// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Checking each user management capability.
					$this->assertFalse( user_can( $manager_agent, $capability, $target_id ), sprintf( 'An agent should never %s user %d.', $capability, $target_id ) );
				}
			}
			$this->assertFalse( user_can( $manager_agent, 'promote_users' ), 'An agent cannot assign roles, so it cannot give one to a user it creates.' );
			$this->assertTrue( user_can( $manager_agent, 'edit_user', $manager_agent->ID ), 'An agent still edits its own profile.' );
			$this->assertTrue( user_can( $manager_agent, 'create_app_password', $manager_agent->ID ), 'An agent still manages its own credentials.' );
			$this->assertFalse( user_can( $manager_agent, 'promote_user', $manager_agent->ID ), 'An agent cannot change its own role.' );

			// The same holds over REST.
			wp_set_current_user( $manager_agent->ID );
			$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $stranger_id );
			$request->set_param( 'first_name', 'Renamed' );
			$response = rest_do_request( $request );
			wp_set_current_user( $this->admin_id );
			$this->assertSame( 403, $response->get_status() );
		} finally {
			remove_role( 'user_manager' );
		}
	}

	/**
	 * Tests that a non-administrator parent manages their agent's credentials over REST.
	 *
	 * @since x.x.x
	 */
	public function test_parent_manages_agent_application_passwords_over_rest() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting a core test global.

		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->provisioner->provision( 'credential_agent', 'author', 'credential_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		wp_set_current_user( $parent_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $agent->ID . '/application-passwords' );
		$request->set_param( 'name', 'Parent-issued credential' );
		$created = rest_do_request( $request );
		$this->assertSame( 201, $created->get_status(), 'The parent should create a credential for their agent.' );
		$this->assertArrayHasKey( 'password', $created->get_data(), 'The plaintext is revealed once to the parent.' );

		$uuid    = $created->get_data()['uuid'];
		$deleted = rest_do_request( new WP_REST_Request( 'DELETE', '/wp/v2/users/' . $agent->ID . '/application-passwords/' . $uuid ) );
		$this->assertSame( 200, $deleted->get_status(), 'The parent should revoke their agent\'s credential.' );
		$this->assertCount( 0, \WP_Application_Passwords::get_user_application_passwords( $agent->ID ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $agent->ID . '/application-passwords' );
		$request->set_param( 'name', 'Stranger credential' );
		$this->assertSame( 403, rest_do_request( $request )->get_status(), 'Other users should not manage the agent\'s credentials.' );
	}

	/**
	 * Tests that human profiles list their agents.
	 *
	 * @since x.x.x
	 */
	public function test_profile_lists_the_parents_agents() {
		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->provisioner->provision( 'listed_agent', 'author', 'listed_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );
		$screen = new Profile_Screen();

		wp_set_current_user( $parent_id );
		ob_start();
		$screen->render_agents_section( get_userdata( $parent_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<h2>Agents</h2>', $output );
		$this->assertStringContainsString( 'user-edit.php?user_id=' . $agent->ID, $output, 'The parent should reach their agent from their profile.' );

		ob_start();
		$screen->render_agents_section( get_userdata( $this->admin_id ) );
		$this->assertSame( '', ob_get_clean(), 'Users without agents get no section.' );
	}

	/**
	 * Tests that an agent never exceeds its parent's current capabilities.
	 *
	 * @since x.x.x
	 */
	public function test_agent_capabilities_are_bounded_by_parent() {
		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$parent    = get_userdata( $parent_id );
		$agent     = $this->provisioner->provision( 'bounded_agent', 'author', 'bounded_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$this->assertTrue( user_can( $agent, 'publish_posts' ), 'The agent role applies while the parent has the capability.' );
		$this->assertFalse( user_can( $agent, 'edit_others_posts' ), 'The agent role still limits the agent below its parent.' );

		$parent->set_role( 'contributor' );
		$this->assertFalse( user_can( $agent, 'publish_posts' ), 'Demoting the parent should narrow the agent immediately.' );
		$this->assertTrue( user_can( $agent, 'edit_posts' ), 'Capabilities both still hold should remain.' );

		$grant_to_agent = static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( $agent ): array {
			if ( $user->ID === $agent->ID ) {
				$allcaps['publish_posts'] = true;
			}

			return $allcaps;
		};
		add_filter( 'user_has_cap', $grant_to_agent, PHP_INT_MAX, 4 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Simulating a plugin granting capabilities late.
		try {
			$granted = user_can( $agent, 'publish_posts' );
		} finally {
			remove_filter( 'user_has_cap', $grant_to_agent, PHP_INT_MAX ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Removing the test filter.
		}
		$this->assertFalse( $granted, 'A capability granted to the agent by another filter should not lift it above its parent.' );
	}

	/**
	 * Tests that the parent is checked for the operation, including its object.
	 *
	 * @since x.x.x
	 */
	public function test_parent_is_checked_with_the_original_object() {
		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->provisioner->provision( 'object_agent', 'editor', 'object_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->admin_id ) );

		$this->assertTrue( user_can( $agent, 'edit_post', $post_id ), 'An editor agent of an editor edits others\' posts.' );

		$deny_parent_this_post = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $parent_id, $post_id ): array {
			if ( 'edit_post' === $cap && $parent_id === $user_id && (int) ( $args[0] ?? 0 ) === $post_id ) {
				return array( 'do_not_allow' );
			}

			return $caps;
		};
		add_filter( 'map_meta_cap', $deny_parent_this_post, 10, 4 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Simulating an object-specific rule for the parent.
		try {
			$can_edit = user_can( $agent, 'edit_post', $post_id );
		} finally {
			remove_filter( 'map_meta_cap', $deny_parent_this_post, 10 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Removing the test filter.
		}
		$this->assertFalse( $can_edit, 'An object-specific denial for the parent should bound the agent.' );
	}

	/**
	 * Tests that agents without an eligible parent are fully suspended.
	 *
	 * @since x.x.x
	 */
	public function test_agents_without_eligible_parent_are_suspended() {
		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$parent    = get_userdata( $parent_id );
		$agent     = $this->provisioner->provision( 'suspended_agent', 'author', 'suspended_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$created = \WP_Application_Passwords::create_new_application_password( $agent->ID, array( 'name' => 'Suspension test' ) );
		$this->assertIsArray( $created );
		$password = $created[0];

		$this->assertFalse( Agent_Account::is_suspended( $agent ) );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate_application_password( null, $agent->user_login, $password ) );

		$parent->add_cap( Agent_Account::PARENT_CAP, false );
		$this->assertTrue( Agent_Account::is_suspended( $agent ), 'An ineligible parent suspends their agents.' );
		foreach ( array( 'read', 'edit_posts' ) as $capability ) {
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Checking representative capabilities.
			$this->assertFalse( user_can( $agent, $capability ), sprintf( 'A suspended agent should not %s.', $capability ) );
		}
		foreach ( array( 'edit_user', 'create_app_password', 'delete_app_passwords' ) as $capability ) {
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Checking self-management capabilities core grants without requirements.
			$this->assertFalse( user_can( $agent, $capability, $agent->ID ), sprintf( 'A suspended agent should not %s on itself.', $capability ) );
		}
		$result = wp_authenticate_application_password( null, $agent->user_login, $password );
		$this->assertWPError( $result, 'A suspended agent\'s credentials should stop authenticating.' );
		$this->assertSame( 'wpai_agent_suspended', $result->get_error_code() );
		$this->assertTrue( user_can( $this->admin_id, 'edit_user', $agent->ID ), 'Administrators can still recover the account.' );

		$parent->remove_cap( Agent_Account::PARENT_CAP );
		$this->assertTrue( user_can( $agent, 'edit_posts' ), 'Restoring the parent should restore the agent.' );

		delete_user_meta( $agent->ID, Agent_Account::META_PARENT );
		$this->assertTrue( Agent_Account::is_suspended( $agent ), 'An agent without a parent is suspended.' );
		$this->assertFalse( user_can( $agent, 'read' ) );

		remove_filter( 'application_password_is_api_request', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * Tests that the parent's role on each site bounds the agent there.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_multisite_parent_roles_bound_the_agent_per_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->provisioner->provision( 'network_bounded', 'editor', 'network_bounded@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$author_site = (int) self::factory()->blog->create();
		add_user_to_blog( $author_site, $parent_id, 'author' );
		add_user_to_blog( $author_site, $agent->ID, 'editor' );

		$this->assertTrue( user_can( $agent, 'edit_others_posts' ), 'The parent is an editor on the provisioning site.' );

		switch_to_blog( $author_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Simulating a request to another site of the test network.
		$author_site_others  = user_can( $agent->ID, 'edit_others_posts' );
		$author_site_publish = user_can( $agent->ID, 'publish_posts' );
		restore_current_blog();

		$this->assertFalse( $author_site_others, 'Where the parent is an author, the editor agent is limited to author capabilities.' );
		$this->assertTrue( $author_site_publish );
	}

	/**
	 * Tests that an agent is suspended on sites its regular parent does not belong to.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_multisite_agent_is_suspended_where_parent_is_not_a_member() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->provisioner->provision( 'member_bound', 'author', 'member_bound@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		// Place the agent on a site of which its parent is not a member, writing the role directly as WP-CLI does.
		$foreign_site = (int) self::factory()->blog->create();
		switch_to_blog( $foreign_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Placing the agent on another site of the test network.
		( new \WP_User( $agent->ID ) )->set_role( 'author' );
		restore_current_blog();
		$this->assertTrue( is_user_member_of_blog( $agent->ID, $foreign_site ) );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$created = \WP_Application_Passwords::create_new_application_password( $agent->ID, array( 'name' => 'Membership test' ) );
		$this->assertIsArray( $created );

		$this->assertSame( '', Agent_Account::get_suspension_reason( $agent ), 'The agent is active where its parent is a member.' );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate_application_password( null, $agent->user_login, $created[0] ) );

		switch_to_blog( $foreign_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Simulating a request to another site of the test network.
		$reason = Agent_Account::get_suspension_reason( $agent->ID );
		$result = wp_authenticate_application_password( null, $agent->user_login, $created[0] );
		restore_current_blog();

		remove_filter( 'application_password_is_api_request', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );

		$this->assertSame( 'parent_not_member', $reason, 'The agent is suspended where its parent is not a member.' );
		$this->assertWPError( $result, 'The credential should not authenticate where the agent is suspended.' );
		$this->assertSame( 'wpai_agent_suspended', $result->get_error_code() );
		$this->assertStringContainsString( 'not a member', $result->get_error_message() );
	}

	/**
	 * Tests that a super admin who is not a site member can be the parent.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_multisite_non_member_super_admin_can_be_parent() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		remove_user_from_blog( $this->admin_id, get_current_blog_id() );
		$this->assertFalse( is_user_member_of_blog( $this->admin_id ) );

		$agent  = $this->provision_agent( 'super_child', 'editor' );
		$parent = Agent_Account::get_parent( $agent );
		$this->assertInstanceOf( \WP_User::class, $parent );
		$this->assertSame( $this->admin_id, $parent->ID );
		$this->assertTrue( user_can( $agent, 'edit_others_posts' ), 'The agent should keep its role capabilities under a super admin parent.' );
	}

	/**
	 * Test that provisioning validates its input.
	 *
	 * @since x.x.x
	 */
	public function test_provision_validates_input() {
		$empty_login = $this->provisioner->provision( '   ', 'editor', 'a@example.com' );
		$this->assertWPError( $empty_login );
		$this->assertSame( 'wpai_agent_empty_login', $empty_login->get_error_code() );

		$bad_role = $this->provisioner->provision( 'test-agent', 'does-not-exist', 'a@example.com' );
		$this->assertWPError( $bad_role );
		$this->assertSame( 'wpai_agent_role_not_assignable', $bad_role->get_error_code() );

		$symbols_only = $this->provisioner->provision( '!!!', 'editor', 'a@example.com' );
		$this->assertWPError( $symbols_only );
		$this->assertSame( 'wpai_agent_invalid_login', $symbols_only->get_error_code() );

		// Like core's Add User form, an illegal character is rejected rather than stripped into another name.
		$altered = $this->provisioner->provision( 'foo+bar', 'editor', 'foobar@example.com' );
		$this->assertWPError( $altered );
		$this->assertSame( 'wpai_agent_invalid_login', $altered->get_error_code() );
		$this->assertFalse( get_user_by( 'login', 'foobar_agent' ) );

		$this->provision_agent( 'taken_agent' );
		$taken = $this->provisioner->provision( 'taken', 'editor', 'a@example.com' );
		$this->assertWPError( $taken );
		$this->assertSame( 'wpai_agent_login_exists', $taken->get_error_code(), 'The suffixed login should be checked for collisions.' );

		$no_email = $this->provisioner->provision( 'no-email-agent', 'editor', '' );
		$this->assertWPError( $no_email );
		$this->assertSame( 'wpai_agent_empty_email', $no_email->get_error_code() );

		$bad_email = $this->provisioner->provision( 'bad-email-agent', 'editor', 'not-an-email' );
		$this->assertWPError( $bad_email );
		$this->assertSame( 'wpai_agent_invalid_email', $bad_email->get_error_code() );

		$taken_email = $this->provisioner->provision( 'taken-email-agent', 'editor', get_userdata( $this->admin_id )->user_email );
		$this->assertWPError( $taken_email );
		$this->assertSame( 'wpai_agent_email_exists', $taken_email->get_error_code() );
	}

	/**
	 * Test that every agent username ends with the agent suffix.
	 *
	 * @since x.x.x
	 */
	public function test_provision_appends_login_suffix() {
		$this->assertSame( 'writer_agent', Agent_Provisioner::apply_login_suffix( 'writer' ) );
		$this->assertSame( 'writer_agent', Agent_Provisioner::apply_login_suffix( 'writer_agent' ) );

		$appended = $this->provision_agent( 'writer' );
		$this->assertSame( 'writer_agent', $appended->user_login, 'A missing suffix should be appended.' );
		$this->assertSame( 'writer_agent', $appended->display_name, 'The display name should use the final login.' );

		$kept = $this->provision_agent( 'reviewer_agent' );
		$this->assertSame( 'reviewer_agent', $kept->user_login, 'An existing suffix should not be doubled.' );

		$suffix_only = $this->provisioner->provision( Agent_Provisioner::LOGIN_SUFFIX, 'editor', 'suffix@example.com' );
		$this->assertWPError( $suffix_only );
		$this->assertSame( 'wpai_agent_empty_login', $suffix_only->get_error_code(), 'The suffix alone is not a name.' );

		$too_long = $this->provisioner->provision( str_repeat( 'a', 58 ), 'editor', 'long@example.com' );
		$this->assertWPError( $too_long );
		$this->assertSame( 'wpai_agent_login_too_long', $too_long->get_error_code(), 'The suffix counts toward core\'s 60 character limit.' );
	}

	/**
	 * Test that provisioning rejects users who cannot create accounts.
	 *
	 * @since x.x.x
	 */
	public function test_provision_requires_user_creation_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$result = $this->provisioner->provision( 'unauthorized-agent', 'subscriber', 'x@example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_cannot_create_users', $result->get_error_code() );
	}

	/**
	 * Test that creating users alone does not authorize assigning an agent role.
	 *
	 * @since x.x.x
	 */
	public function test_provision_requires_role_assignment_capability() {
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'wpai_agent_create_only',
			'Agent Create Only',
			array(
				'read'         => true,
				'create_users' => true,
			)
		);

		$manager_id = self::factory()->user->create( array( 'role' => 'wpai_agent_create_only' ) );
		wp_set_current_user( $manager_id );

		$result = $this->provisioner->provision( 'escalating-agent', 'subscriber', 'x@example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_cannot_promote_users', $result->get_error_code() );
	}

	/**
	 * Test that a provisioner must be able to manage the resulting agent.
	 *
	 * @since x.x.x
	 */
	public function test_provision_requires_agent_management_capability() {
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'wpai_agent_no_edit_manager',
			'Agent Manager Without Edit Access',
			array(
				'read'          => true,
				'create_users'  => true,
				'promote_users' => true,
			)
		);

		$manager_id = self::factory()->user->create( array( 'role' => 'wpai_agent_no_edit_manager' ) );
		wp_set_current_user( $manager_id );

		$this->assertFalse( Agent_Provisioner::current_user_can_provision() );
		$result = $this->provisioner->provision( 'unmanageable-agent', 'subscriber', 'x@example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_cannot_manage_agents', $result->get_error_code() );
		$this->assertFalse( username_exists( 'unmanageable-agent' ) );
	}

	/**
	 * Test that an agent role cannot exceed its creator's effective capabilities.
	 *
	 * @since x.x.x
	 */
	public function test_provision_rejects_role_more_powerful_than_creator() {
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'wpai_agent_limited_manager',
			'Agent Limited Manager',
			array(
				'read'                 => true,
				'wpai_add_agents'      => true,
				'create_users'         => true,
				'promote_users'        => true,
				'edit_users'           => true,
				// Core requires this network-level capability to edit other users on multisite.
				'manage_network_users' => true,
			)
		);

		$manager_id = self::factory()->user->create( array( 'role' => 'wpai_agent_limited_manager' ) );
		wp_set_current_user( $manager_id );

		$assignable_roles = $this->provisioner->get_assignable_roles();
		$this->assertArrayHasKey( 'subscriber', $assignable_roles );
		$this->assertArrayNotHasKey( 'editor', $assignable_roles );
		$this->assertArrayNotHasKey( 'administrator', $assignable_roles );

		$rejected = $this->provisioner->provision( 'escalating-agent', 'editor', 'x@example.com' );
		$this->assertWPError( $rejected );
		$this->assertSame( 'wpai_agent_role_not_assignable', $rejected->get_error_code() );

		$allowed = $this->provisioner->provision( 'read-only-agent', 'subscriber', 'x@example.com' );
		$this->assertInstanceOf( \WP_User::class, $allowed );
		$this->assertSame( array( 'subscriber' ), $allowed->roles );
	}

	/**
	 * Test that the final agent cannot gain access denied to its provisioner.
	 *
	 * A capability filter may treat individual users differently, so the
	 * pre-creation role comparison alone cannot see how the future agent will
	 * be filtered. The comparison must be repeated against the real marked
	 * account and roll creation back when it fails.
	 *
	 * @since x.x.x
	 */
	public function test_provision_rechecks_effective_capabilities_for_created_agent() {
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'wpai_agent_contextual_manager',
			'Contextual Agent Manager',
			array(
				'read'                            => true,
				'create_users'                    => true,
				'promote_users'                   => true,
				'edit_users'                      => true,
				// Core requires this network-level capability to edit other users on multisite.
				'manage_network_users'            => true,
				'wpai_test_contextual_capability' => true,
				'wpai_add_agents'                 => true,
			)
		);
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'wpai_agent_contextual_worker',
			'Contextual Agent Worker',
			array(
				'read'                            => true,
				'wpai_test_contextual_capability' => true,
			)
		);

		// A parent other than the provisioner, holding the capability the
		// provisioner is denied, so only the provisioner check can catch it.
		$parent_id  = self::factory()->user->create( array( 'role' => 'wpai_agent_contextual_manager' ) );
		$manager_id = self::factory()->user->create( array( 'role' => 'wpai_agent_contextual_manager' ) );
		wp_set_current_user( $manager_id );

		$deny_only_to_provisioner = static function ( array $caps, string $cap, int $user_id ) use ( $manager_id ): array {
			if ( 'wpai_test_contextual_capability' === $cap && $manager_id === $user_id ) {
				return array( 'do_not_allow' );
			}

			return $caps;
		};
		$creation_action_fired    = false;
		$record_creation          = static function () use ( &$creation_action_fired ): void {
			$creation_action_fired = true;
		};

		add_filter( 'map_meta_cap', $deny_only_to_provisioner, 20, 3 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtering core capability mapping in a regression test.
		add_action( 'edit_user_created_user', $record_creation ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Observing the core creation action in a regression test.
		try {
			$assignable_roles = $this->provisioner->get_assignable_roles();
			$result           = $this->provisioner->provision( 'context-escalating-agent', 'wpai_agent_contextual_worker', 'x@example.com', '', '', '', $parent_id );
		} finally {
			remove_filter( 'map_meta_cap', $deny_only_to_provisioner, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Removing the test filter.
			remove_action( 'edit_user_created_user', $record_creation ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Removing the test action.
		}

		$this->assertArrayHasKey( 'wpai_agent_contextual_worker', $assignable_roles, 'The pre-creation comparison cannot see how the future agent will be filtered.' );
		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_role_not_assignable', $result->get_error_code() );
		$this->assertFalse( username_exists( 'context-escalating-agent' ), 'A failed post-creation check must roll the account back.' );
		$this->assertFalse( $creation_action_fired, 'Compatibility and notification hooks should run only after final authorization.' );
	}

	/**
	 * Test that inert `unfiltered_html` does not narrow assignable roles.
	 *
	 * The agent-side strip means a non-admin agent never holds
	 * `unfiltered_html`, so a provisioner without it can still assign a role
	 * that carries it.
	 *
	 * @since x.x.x
	 */
	public function test_assignable_roles_ignore_inert_unfiltered_html() {
		$editor_role = wp_roles()->get_role( 'editor' );
		$this->assertInstanceOf( \WP_Role::class, $editor_role );

		$caps = array_fill_keys( array_keys( array_filter( $editor_role->capabilities ) ), true );
		unset( $caps['unfiltered_html'] );
		$caps['create_users']  = true;
		$caps['promote_users'] = true;
		$caps['edit_users']    = true;
		// Core requires this network-level capability to edit other users on multisite.
		$caps['manage_network_users'] = true;

		add_role( 'wpai_agent_editor_manager', 'Editor-Level Agent Manager', $caps ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
		$manager_id = self::factory()->user->create( array( 'role' => 'wpai_agent_editor_manager' ) );
		wp_set_current_user( $manager_id );

		$assignable = $this->provisioner->get_assignable_roles();
		$this->assertArrayHasKey( 'editor', $assignable, 'A role is assignable when its only extra capability is inert for the agent.' );
		$this->assertArrayNotHasKey( 'administrator', $assignable );

		$agent = $this->provisioner->provision( 'inert-cap-agent', 'editor', 'inert@example.com' );
		$this->assertInstanceOf( \WP_User::class, $agent );
		$this->assertFalse( user_can( $agent, 'unfiltered_html' ), 'The provisioned agent must not gain what its provisioner lacks.' );
	}

	/**
	 * Test that the display name is derived from the names like core does.
	 *
	 * @since x.x.x
	 */
	public function test_provision_display_name() {
		$plain = $this->provision_agent( 'plain_agent' );
		$this->assertSame( 'plain_agent', $plain->display_name );

		$named = $this->provisioner->provision( 'named-agent', 'editor', 'owner@example.com', 'Content', 'Assistant', 'https://example.com/assistant' );
		$this->assertInstanceOf( \WP_User::class, $named );
		$this->assertSame( 'Content Assistant', $named->display_name );
		$this->assertSame( 'https://example.com/assistant', $named->user_url );
		$this->assertSame( 'owner@example.com', $named->user_email );
		$this->assertSame( 'Content', $named->first_name );
		$this->assertSame( 'Assistant', $named->last_name );
	}

	/**
	 * Test that interactive login is blocked for agents but not humans.
	 *
	 * @since x.x.x
	 */
	public function test_interactive_login_blocked_for_agents() {
		$agent = $this->provision_agent();

		$blocked = wp_authenticate_username_password( null, $agent->user_login, 'any-password' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'wpai_agent_login_disabled', $blocked->get_error_code() );

		$human_id = self::factory()->user->create(
			array(
				'role'      => 'editor',
				'user_pass' => 'human-password-1',
			)
		);
		$human    = get_user_by( 'id', $human_id );

		$allowed = wp_authenticate_username_password( null, $human->user_login, 'human-password-1' );
		$this->assertNotWPError( $allowed );
		$this->assertSame( $human_id, $allowed->ID );
	}

	/**
	 * Test that password resets are disabled for agents.
	 *
	 * @since x.x.x
	 */
	public function test_password_reset_disabled_for_agents() {
		$agent = $this->provision_agent();

		$reset = get_password_reset_key( $agent );
		$this->assertWPError( $reset );
		$this->assertSame( 'no_password_reset', $reset->get_error_code() );
	}

	/**
	 * Tests that an agent's password cannot be changed.
	 *
	 * @since x.x.x
	 */
	public function test_agent_password_cannot_be_changed() {
		$agent    = $this->provision_agent( 'locked_agent', 'author' );
		$original = get_userdata( $agent->ID )->user_pass;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking the core filter in an integration test.
		$this->assertFalse( apply_filters( 'send_password_change_email', true, array( 'ID' => $agent->ID ), array() ), 'No change, so no email.' );

		$result = wp_update_user(
			array(
				'ID'         => $agent->ID,
				'user_pass'  => 'known-password-1',
				'first_name' => 'Renamed',
			)
		);
		$this->assertSame( $agent->ID, $result );
		$updated = get_userdata( $agent->ID );
		$this->assertSame( $original, $updated->user_pass, 'Updates keep the agent password.' );
		$this->assertSame( 'Renamed', $updated->first_name, 'Other fields still update.' );

		wp_set_password( 'known-password-2', $agent->ID );
		$this->assertSame( $original, get_userdata( $agent->ID )->user_pass, 'Direct password writes are undone.' );

		$response = rest_do_request( new WP_REST_Request( 'POST', '/wp/v2/users/' . $agent->ID ) );
		$this->assertSame( 200, $response->get_status(), 'Requests without a password are unaffected.' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $agent->ID );
		$request->set_param( 'password', 'known-password-3' );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wpai_agent_password_not_allowed', $response->as_error()->get_error_code() );

		// An agent's own credential reaches its profile as `/users/me`.
		wp_set_current_user( $agent->ID );
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
		$request->set_param( 'password', 'known-password-4' );
		$response = rest_do_request( $request );
		wp_set_current_user( $this->admin_id );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wpai_agent_password_not_allowed', $response->as_error()->get_error_code() );
		$this->assertSame( $original, get_userdata( $agent->ID )->user_pass );

		$human_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_password( 'human-password-1', $human_id );
		$this->assertTrue( wp_check_password( 'human-password-1', get_userdata( $human_id )->user_pass, $human_id ), 'Humans are unaffected.' );
	}

	/**
	 * Test that agents receive the capabilities of their assigned role.
	 *
	 * The `unfiltered_html` capability is the deliberate exception; dedicated
	 * tests cover it.
	 *
	 * @since x.x.x
	 */
	public function test_agents_receive_assigned_role_capabilities() {
		$editor_agent = $this->provision_agent( 'editor-agent', 'editor' );
		$editor_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$human_editor = get_userdata( $editor_id );
		$this->assertInstanceOf( \WP_User::class, $human_editor );

		foreach ( array( 'edit_others_posts', 'publish_posts', 'manage_options', 'create_users', 'activate_plugins' ) as $capability ) {
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Comparing representative capabilities from the assigned role.
			$this->assertSame( user_can( $human_editor, $capability ), user_can( $editor_agent, $capability ), sprintf( 'Editor agents and humans should agree on %s.', $capability ) );
		}

		$this->assertTrue( user_can( $editor_agent, 'edit_user', $editor_agent->ID ), 'Core should allow the agent to edit itself.' );
		$this->assertTrue( user_can( $editor_agent, 'create_app_password', $editor_agent->ID ), 'Core should allow the agent to manage its own credentials.' );

		$editor_agent->add_cap( 'wpai_agent_test_capability' );
		get_userdata( $this->admin_id )->add_cap( 'wpai_agent_test_capability' );
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Verifying a test-only custom capability.
		$this->assertTrue( user_can( $editor_agent, 'wpai_agent_test_capability' ), 'Agent identity should not remove explicitly granted capabilities the parent also has.' );
	}

	/**
	 * Test that agents lose `unfiltered_html`.
	 *
	 * @since x.x.x
	 */
	public function test_agents_lose_unfiltered_html() {
		$editor_agent = $this->provision_agent( 'filtered-agent', 'editor' );
		$editor_id    = self::factory()->user->create( array( 'role' => 'editor' ) );

		$editor_agent->add_cap( 'unfiltered_html' );
		$this->assertFalse( user_can( $editor_agent, 'unfiltered_html' ), 'An Editor agent should not carry unfiltered_html.' );
		$this->assertFalse( user_can( $editor_agent, 'edit_css' ), 'The strip should cover meta capabilities that resolve to unfiltered_html.' );
		if ( is_multisite() ) {
			return;
		}

		$this->assertTrue( user_can( $editor_id, 'unfiltered_html' ), 'A human Editor keeps the single-site role default.' );
		$this->assertTrue( user_can( $editor_id, 'edit_css' ), 'A human Editor keeps custom CSS access on single site.' );
	}

	/**
	 * Tests that agents cannot be given a role that manages the site.
	 *
	 * @since x.x.x
	 */
	public function test_agents_cannot_hold_roles_that_manage_the_site() {
		$this->assertArrayNotHasKey( 'administrator', $this->provisioner->get_assignable_roles(), 'The Add Agent role list should not offer Administrator.' );

		$rejected = $this->provisioner->provision( 'chief_agent', 'administrator', 'chief_agent@example.com' );
		$this->assertWPError( $rejected );
		$this->assertSame( 'wpai_agent_role_not_allowed', $rejected->get_error_code() );
		$this->assertFalse( username_exists( 'chief_agent' ) );

		// Below `manage_options`, such as installing plugins, it is the parent's to allow.
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'plugin_installer',
			'Plugin Installer',
			array(
				'read'             => true,
				'install_plugins'  => true,
				'activate_plugins' => true,
			)
		);
		try {
			$this->assertArrayHasKey( 'plugin_installer', $this->provisioner->get_assignable_roles() );
			$installer = $this->provisioner->provision( 'installer_agent', 'plugin_installer', 'installer_agent@example.com' );
			$bounded   = $this->provisioner->provision( 'bounded_installer', 'plugin_installer', 'bounded_installer@example.com', '', '', '', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		} finally {
			remove_role( 'plugin_installer' );
		}
		$this->assertInstanceOf( \WP_User::class, $installer, 'A parent who can install plugins may give an agent a role that can.' );
		$this->assertSame( '', Agent_Account::get_suspension_reason( $installer ) );
		// On multisite, core already denies installing plugins to everyone but super admins.
		if ( ! is_multisite() ) {
			$this->assertWPError( $bounded, 'A parent who cannot install plugins cannot give an agent a role that can.' );
			$this->assertSame( 'wpai_agent_role_exceeds_parent', $bounded->get_error_code() );
		}

		$agent = $this->provision_agent( 'promotable_agent', 'editor' );

		// The profile screen lists and accepts only the editable roles.
		$GLOBALS['pagenow']  = 'user-edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin screen.
		$_REQUEST['user_id'] = (string) $agent->ID;
		$this->assertArrayNotHasKey( 'administrator', get_editable_roles(), 'The agent\'s profile should not offer Administrator.' );
		$this->assertArrayHasKey( 'editor', get_editable_roles() );
		$_REQUEST['user_id'] = (string) $this->admin_id;
		$this->assertArrayHasKey( 'administrator', get_editable_roles(), 'Human profiles are unaffected.' );
		unset( $_REQUEST['user_id'] );

		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $agent->ID );
		$request->set_param( 'roles', array( 'administrator' ) );
		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wpai_agent_role_not_allowed', $response->as_error()->get_error_code() );
		$this->assertSame( array( 'editor' ), get_userdata( $agent->ID )->roles );

		// WP-CLI and the bulk role change write the role directly; the agent is then suspended.
		get_userdata( $agent->ID )->set_role( 'administrator' );
		$this->assertSame( 'role_not_allowed', Agent_Account::get_suspension_reason( $agent->ID ) );
		$this->assertFalse( user_can( $agent->ID, 'read' ), 'A suspended agent should be denied everything.' );
		$this->assertFalse( user_can( $agent->ID, 'manage_options' ) );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$created = \WP_Application_Passwords::create_new_application_password( $agent->ID, array( 'name' => 'Promoted' ) );
		$this->assertIsArray( $created );
		$result = wp_authenticate_application_password( null, $agent->user_login, $created[0] );
		$this->assertWPError( $result, 'The credential should not authenticate while the agent manages the site.' );
		$this->assertSame( 'wpai_agent_suspended', $result->get_error_code() );

		get_userdata( $agent->ID )->set_role( 'editor' );
		$this->assertSame( '', Agent_Account::get_suspension_reason( $agent->ID ), 'Another role lifts the suspension.' );
	}

	/**
	 * Test that an agent's REST content is KSES-filtered.
	 *
	 * @since x.x.x
	 */
	public function test_non_admin_agent_content_is_kses_filtered() {
		$agent = $this->provision_agent( 'kses-agent', 'editor' );

		wp_set_current_user( $agent->ID );
		kses_init();

		try {
			$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
			$request->set_param( 'title', 'Agent post' );
			$request->set_param( 'content', '<p>Safe.</p><script>alert(1)</script>' );
			$request->set_param( 'status', 'draft' );
			$response = rest_do_request( $request );

			$this->assertSame( 201, $response->get_status() );
			$post_id = (int) ( $response->get_data()['id'] ?? 0 );
			$stored  = (string) get_post_field( 'post_content', $post_id );
			$this->assertStringContainsString( '<p>Safe.</p>', $stored );
			$this->assertStringNotContainsString( '<script>', $stored, 'Scripts from a non-administrator agent should be stripped by KSES.' );
		} finally {
			wp_set_current_user( $this->admin_id );
			kses_init();
		}
	}

	/**
	 * Test that Application Passwords stay available for agents.
	 *
	 * @since x.x.x
	 */
	public function test_application_passwords_stay_available_for_agents() {
		$agent = $this->provision_agent();
		$human = get_user_by( 'id', $this->admin_id );
		$this->assertInstanceOf( \WP_User::class, $human );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_false', 5 );

		$this->assertTrue( wp_is_application_passwords_available_for_user( $agent ) );
		$this->assertFalse( wp_is_application_passwords_available_for_user( $human ) );

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_false', 5 );
	}

	/**
	 * Test the Add User screen renders the agent fields only in agent mode.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_renders_agent_fields() {
		$screen = new New_User_Screen( $this->provisioner );

		$GLOBALS['pagenow'] = 'user-new.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin screen.

		ob_start();
		$screen->render_fields( 'add-new-user' );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Add Agent</a>', $output, 'Regular mode points to the agent flow.' );
		$this->assertStringNotContainsString( 'name="wpai_agent"', $output );

		$_REQUEST['wpai_agent'] = '1';
		ob_start();
		$screen->render_fields( 'add-new-user' );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'type="hidden" name="wpai_agent" value="1"', $output );
		$this->assertStringContainsString( 'Add User</a>', $output, 'Agent mode points back to the regular flow.' );
		$this->assertStringContainsString( 'Agent usernames end with _agent.', $output, 'Agent mode explains the username suffix.' );
		$this->assertMatchesRegularExpression( '/<select name="wpai_agent_parent" id="wpai_agent_parent"[^>]* required>/', $output, 'Agent mode asks for a parent.' );
		$this->assertStringNotContainsString( 'selected', $output, 'No parent is preselected.' );

		ob_start();
		$screen->render_fields( 'add-existing-user' );
		$this->assertSame( '', ob_get_clean(), 'Existing users cannot become agents.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		$screen->render_fields( 'add-new-user' );
		$this->assertSame( '', ob_get_clean(), 'Users who cannot provision should not see the option.' );
	}

	/**
	 * Test the "Add Agent" submenu opens the shared form in agent mode.
	 *
	 * @since x.x.x
	 */
	public function test_add_agent_submenu() {
		global $submenu;

		// Core's menu.php does not load in tests, so seed the Users submenu.
		$submenu['users.php'] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin menu.
			5  => array( 'All Users', 'list_users', 'users.php' ),
			10 => array( 'Add User', 'create_users', 'user-new.php' ),
			15 => array( 'Profile', 'read', 'profile.php' ),
		);

		$screen = new New_User_Screen( $this->provisioner );
		$screen->add_submenu();

		$slugs = array_values( array_column( $submenu['users.php'] ?? array(), 2 ) );
		$this->assertSame( 'user-new.php', $slugs[1] ?? null, 'Add User comes first.' );
		$this->assertSame( 'user-new.php?wpai_agent=1', $slugs[2] ?? null, 'Add Agent follows Add User.' );
		$this->assertSame( 'profile.php', $slugs[3] ?? null, 'Profile stays last.' );
		unset( $submenu['users.php'] );
		$this->assertSame( admin_url( 'user-new.php?wpai_agent=1' ), New_User_Screen::url() );

		$GLOBALS['pagenow'] = 'user-new.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin screen.
		$this->assertSame( 'user-new.php', $screen->highlight_submenu( 'user-new.php' ), 'Regular mode keeps the core highlight.' );
		$this->assertSame( 'Add User &lsaquo; Site', $screen->filter_admin_title( 'Add User &lsaquo; Site' ) );

		$_REQUEST['wpai_agent'] = '1';
		$this->assertSame( 'user-new.php?wpai_agent=1', $screen->highlight_submenu( 'user-new.php' ) );
		$this->assertSame( 'Add Agent &lsaquo; Site', $screen->filter_admin_title( 'Add User &lsaquo; Site' ) );
	}

	/**
	 * Test submitting the Add New User form with the agent option creates an agent.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_creates_agent_and_redirects_to_profile() {
		$parent_id                        = self::factory()->user->create( array( 'role' => 'editor' ) );
		$nonce                            = wp_create_nonce( 'create-user' );
		$_POST['wpai_agent']              = '1';
		$_POST['user_login']              = 'form';
		$_POST['email']                   = 'form_agent@example.com';
		$_POST['first_name']              = 'Form Agent';
		$_POST['role']                    = 'author';
		$_POST['wpai_agent_parent']       = (string) $parent_id;
		$_POST['_wpnonce_create-user']    = $nonce;
		$_REQUEST['_wpnonce_create-user'] = $nonce;

		$redirect = $this->capture_redirect(
			function () {
				( new New_User_Screen( $this->provisioner ) )->handle_create();
			}
		);

		$agent = get_user_by( 'login', 'form_agent' );
		$this->assertInstanceOf( \WP_User::class, $agent, 'The form submission should get the agent suffix.' );
		$this->assertTrue( Agent_Account::is_agent( $agent ) );
		$this->assertSame( array( 'author' ), $agent->roles );
		$this->assertSame( 'Form Agent', $agent->display_name );
		$this->assertSame( $parent_id, (int) get_user_meta( $agent->ID, Agent_Account::META_PARENT, true ), 'The submitted parent should be stored.' );
		$this->assertStringContainsString( 'user-edit.php?user_id=' . $agent->ID, $redirect );
		$this->assertStringContainsString( 'wpai_agent_created=1', $redirect );
		$this->assertStringEndsWith( '#application-passwords-section', $redirect );
	}

	/**
	 * Test that an unauthorized form submission returns HTTP 403.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_unauthorized_submission_returns_403() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$nonce                            = wp_create_nonce( 'create-user' );
		$_POST['wpai_agent']              = '1';
		$_POST['_wpnonce_create-user']    = $nonce;
		$_REQUEST['_wpnonce_create-user'] = $nonce;

		$die_args       = array();
		$handler_filter = static function () use ( &$die_args ): callable {
			return static function ( $message, $title, array $args ) use ( &$die_args ): void {
				$die_args = $args;
				throw new \RuntimeException( 'wp_die intercepted by the test.' );
			};
		};

		add_filter( 'wp_die_handler', $handler_filter ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Replacing core's die handler in a regression test.
		$did_die = false;
		try {
			( new New_User_Screen( $this->provisioner ) )->handle_create();
		} catch ( \RuntimeException $error ) {
			$did_die = true;
		} finally {
			remove_filter( 'wp_die_handler', $handler_filter ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Restoring core's die handler after the test.
		}

		$this->assertTrue( $did_die );
		$this->assertSame( 403, $die_args['response'] ?? null );
	}

	/**
	 * Test a failed agent submission is reported through core's form validation.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_reports_errors_on_the_form() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Core validates the form through edit_user() on single site only.' );
		}

		$nonce                            = wp_create_nonce( 'create-user' );
		$_POST['wpai_agent']              = '1';
		$_POST['user_login']              = '';
		$_POST['email']                   = 'agent@example.com';
		$_POST['role']                    = 'author';
		$_POST['wpai_agent_parent']       = (string) $this->admin_id;
		$_POST['_wpnonce_create-user']    = $nonce;
		$_REQUEST['_wpnonce_create-user'] = $nonce;

		$redirected = false;
		add_filter(
			'wp_redirect',
			static function ( string $location ) use ( &$redirected ): string {
				$redirected = true;
				return $location;
			}
		);
		( new New_User_Screen( $this->provisioner ) )->handle_create();
		$this->assertFalse( $redirected, 'Errors should not redirect, core re-renders the form.' );

		// Core's validation would complain about the hidden password; the agent error replaces it.
		$errors = new \WP_Error( 'pass', 'Please enter a password.' );
		$user   = new \stdClass();
		do_action_ref_array( 'user_profile_update_errors', array( &$errors, false, &$user ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking a core hook in an integration test.
		$this->assertSame( array( 'wpai_agent_empty_login' ), $errors->get_error_codes() );

		// When core already found something, it speaks alone.
		$errors = new \WP_Error( 'user_login', 'Core error.' );
		do_action_ref_array( 'user_profile_update_errors', array( &$errors, false, &$user ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking a core hook in an integration test.
		$this->assertSame( array( 'user_login' ), $errors->get_error_codes() );
		$this->assertFalse( get_user_by( 'email', 'agent@example.com' ) );
	}

	/**
	 * Tests that a failed agent submission on multisite stops core's signup with the agent error.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_new_user_screen_reports_errors_through_multisite_signup_validation() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$ineligible_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$nonce               = wp_create_nonce( 'create-user' );
		$_POST['wpai_agent'] = '1';
		// A valid agent username that core's signup rules for people reject.
		$_POST['user_login']              = 'Stray_1';
		$_POST['email']                   = 'stray_agent@example.com';
		$_POST['role']                    = 'author';
		$_POST['wpai_agent_parent']       = (string) $ineligible_id;
		$_POST['_wpnonce_create-user']    = $nonce;
		$_REQUEST['_wpnonce_create-user'] = $nonce;

		( new New_User_Screen( $this->provisioner ) )->handle_create();

		// Any signup validation error makes core re-render the form instead of signing up a user.
		$this->assertSame( array( 'wpai_agent_invalid_parent' ), $this->collect_create_user_errors( 'Stray_1', 'stray_agent@example.com' ), 'Only the actual reason should be shown.' );
		$this->assertFalse( get_user_by( 'email', 'stray_agent@example.com' ) );
	}

	/**
	 * Returns the errors core's create-user handler would show after a submission.
	 *
	 * Core validates the form with `edit_user()` on single site and with
	 * `wpmu_validate_user_signup()` on multisite.
	 *
	 * @since x.x.x
	 *
	 * @param string $login Submitted username.
	 * @param string $email Submitted email.
	 * @return array<int, string|int> Error codes.
	 */
	private function collect_create_user_errors( string $login, string $email ): array {
		if ( is_multisite() ) {
			return wpmu_validate_user_signup( $login, $email )['errors']->get_error_codes();
		}

		$errors = new \WP_Error();
		$user   = new \stdClass();
		do_action_ref_array( 'user_profile_update_errors', array( &$errors, false, &$user ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking a core hook in an integration test.

		return $errors->get_error_codes();
	}

	/**
	 * Tests that the parent selector offers super admins who are not members of the site.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_new_user_screen_offers_super_admins_who_are_not_site_members() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$network_admin_id = self::factory()->user->create( array( 'display_name' => 'Network Overseer' ) );
		grant_super_admin( $network_admin_id );
		remove_user_from_blog( $network_admin_id, get_current_blog_id() );
		$this->assertFalse( is_user_member_of_blog( $network_admin_id ) );

		$GLOBALS['pagenow']     = 'user-new.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin screen.
		$_REQUEST['wpai_agent'] = '1';
		ob_start();
		( new New_User_Screen( $this->provisioner ) )->render_fields( 'add-new-user' );
		$output = (string) ob_get_clean();
		revoke_super_admin( $network_admin_id );

		$this->assertStringContainsString( '<option value="' . $network_admin_id . '">Network Overseer', $output, 'Provisioning accepts any super admin as the parent, so the form should offer them.' );
	}

	/**
	 * Test the form requires a parent and keeps it selected after a failed submission.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_requires_and_keeps_the_parent() {
		$parent_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Chosen Parent',
			)
		);
		$screen    = new New_User_Screen( $this->provisioner );
		$nonce     = wp_create_nonce( 'create-user' );

		$_POST['wpai_agent']              = '1';
		$_POST['user_login']              = 'parentless';
		$_POST['email']                   = 'parentless@example.com';
		$_POST['role']                    = 'author';
		$_POST['_wpnonce_create-user']    = $nonce;
		$_REQUEST['_wpnonce_create-user'] = $nonce;

		$screen->handle_create();
		$this->assertSame( array( 'wpai_agent_parent_required' ), $this->collect_create_user_errors( 'parentless', 'parentless@example.com' ), 'The form should not fall back to the provisioner.' );
		$this->assertFalse( get_user_by( 'login', 'parentless_agent' ) );

		// A failed submission for another reason re-renders with the chosen parent.
		remove_all_actions( 'user_profile_update_errors' );
		remove_all_filters( 'wpmu_validate_user_signup' );
		$_POST['wpai_agent_parent'] = (string) $parent_id;
		$_POST['role']              = 'administrator';
		$screen->handle_create();
		$this->assertFalse( get_user_by( 'login', 'parentless_agent' ), 'A role above the parent should fail.' );

		$GLOBALS['pagenow']     = 'user-new.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin screen.
		$_REQUEST['wpai_agent'] = '1';
		ob_start();
		$screen->render_fields( 'add-new-user' );
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<option value="' . $parent_id . '" selected=\'selected\'>Chosen Parent/', $output, 'The submitted parent should stay selected.' );
		$this->assertStringNotContainsString( '<option value="' . $this->admin_id . '" selected', $output, 'The provisioner should not replace the submitted parent.' );
	}

	/**
	 * Test the form submission is ignored without the agent option.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_ignores_regular_submissions() {
		$_POST['user_login'] = 'human';

		( new New_User_Screen( $this->provisioner ) )->handle_create();

		$this->assertFalse( get_user_by( 'login', 'human' ) );
	}

	/**
	 * Tests the profile note for an agent without a parent and for one whose parent is not a site member.
	 *
	 * @since x.x.x
	 */
	public function test_profile_screen_explains_why_an_agent_is_suspended() {
		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->provisioner->provision( 'explaining_agent', 'author', 'explaining_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );
		$screen = new Profile_Screen();
		$note   = static function () use ( $screen ): string {
			ob_start();
			$screen->render_account_type();
			return (string) ob_get_clean();
		};

		set_current_screen( 'user-edit' );
		$_GET['user_id'] = (string) $agent->ID;

		if ( is_multisite() ) {
			// A role written directly, as WP-CLI does, on a site the parent does not belong to.
			$foreign_site = (int) self::factory()->blog->create();
			switch_to_blog( $foreign_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Rendering the agent's profile on another site of the test network.
			( new \WP_User( $agent->ID ) )->set_role( 'author' );
			$foreign = $note();
			restore_current_blog();
			$this->assertStringContainsString( 'who is not a member of this site, so it is suspended here', $foreign );
		}

		delete_user_meta( $agent->ID, Agent_Account::META_PARENT );
		$this->assertStringContainsString( 'It has no parent user, so it is suspended', $note() );
	}

	/**
	 * Tests the notice right after an agent is created, with and without Application Passwords.
	 *
	 * @since x.x.x
	 */
	public function test_profile_screen_points_to_the_first_credential() {
		$agent  = $this->provision_agent( 'fresh_agent', 'author' );
		$screen = new Profile_Screen();
		$notice = static function () use ( $screen ): string {
			ob_start();
			$screen->render_created_notice();
			return (string) ob_get_clean();
		};

		set_current_screen( 'user-edit' );
		$_GET['user_id'] = (string) $agent->ID;
		$this->assertSame( '', $notice(), 'The notice only follows creation.' );

		$_GET['wpai_agent_created'] = '1';
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$this->assertStringContainsString( 'Add an Application Password below so the agent can connect.', $notice() );

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$unavailable = $notice();
		remove_filter( 'wp_is_application_passwords_available', '__return_false' );
		$this->assertStringContainsString( 'Application Passwords are not available on this site', $unavailable, 'Without HTTPS the parent learns why the agent cannot connect yet.' );
		$this->assertStringContainsString( 'notice-warning', $unavailable );
	}

	/**
	 * Tests that the network profile names the sites where an agent is suspended.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_network_profile_names_sites_where_agent_is_suspended() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->provisioner->provision( 'roaming_agent', 'author', 'roaming_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$active_site  = (int) self::factory()->blog->create( array( 'title' => 'Shared Site' ) );
		$foreign_site = (int) self::factory()->blog->create( array( 'title' => 'Foreign Site' ) );
		add_user_to_blog( $active_site, $parent_id, 'editor' );
		add_user_to_blog( $active_site, $agent->ID, 'author' );
		// A role written directly, as WP-CLI does, on a site the parent does not belong to.
		switch_to_blog( $foreign_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Placing the agent on another site of the test network.
		( new \WP_User( $agent->ID ) )->set_role( 'author' );
		restore_current_blog();

		set_current_screen( 'user-edit-network' );
		$_GET['user_id'] = (string) $agent->ID;
		ob_start();
		( new Profile_Screen() )->render_account_type();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'It is suspended on Foreign Site,', $output );
		$this->assertStringNotContainsString( 'Shared Site', $output, 'The agent is active where its parent is a member.' );
		$this->assertStringNotContainsString( 'this site', $output, 'The network admin has no current site to describe.' );
	}

	/**
	 * Test the profile screen hides the password block and marks the account.
	 *
	 * @since x.x.x
	 */
	public function test_profile_screen_adapts_to_agents() {
		$agent  = $this->provision_agent();
		$human  = get_user_by( 'id', $this->admin_id );
		$screen = new Profile_Screen();

		$this->assertFalse( $screen->hide_password_fields( true, $agent ) );
		$this->assertTrue( $screen->hide_password_fields( true, $human ) );

		set_current_screen( 'user-edit' );

		$_GET['user_id'] = (string) $human->ID;
		ob_start();
		$screen->render_account_type();
		$screen->print_styles();
		$this->assertSame( '', ob_get_clean(), 'Human profiles keep every field and get no note.' );

		$_GET['user_id'] = (string) $agent->ID;
		ob_start();
		$screen->render_account_type();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '<p class="wpai-agent-account-type">', $output, 'The note should be visible without JavaScript.' );
		$this->assertStringContainsString( 'Agent account.', $output );
		$this->assertStringContainsString( 'It acts on behalf of <strong>' . $human->display_name . '</strong> and can only do', $output );
		$this->assertStringNotContainsString( 'notice', $output, 'The note is plain text, not a notice.' );

		// Super admins can always have agents, so demote the parent first on multisite.
		if ( is_multisite() ) {
			revoke_super_admin( $this->admin_id );
		}
		$human->add_cap( Agent_Account::PARENT_CAP, false );
		ob_start();
		$screen->render_account_type();
		$this->assertStringContainsString( 'who can no longer have agents, so it is suspended', (string) ob_get_clean() );
		$human->remove_cap( Agent_Account::PARENT_CAP );

		$GLOBALS['pagenow'] = 'user-edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin screen.
		$this->assertSame( 'Edit Agent &lsaquo; Site', $screen->filter_admin_title( 'Edit User &lsaquo; Site' ) );
		$_GET['user_id'] = (string) $human->ID;
		$this->assertSame( 'Edit User &lsaquo; Site', $screen->filter_admin_title( 'Edit User &lsaquo; Site' ) );

		$_GET['user_id'] = (string) $agent->ID;
		ob_start();
		$screen->print_styles();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '.user-admin-color-wrap', $output );
		$this->assertStringNotContainsString( '.user-email-wrap', $output, 'Email stays, it receives notifications.' );
		$this->assertStringNotContainsString( '.user-description-wrap', $output, 'Biographical info stays available to describe the agent.' );
		$this->assertStringNotContainsString( '.user-url-wrap', $output, 'Website stays, themes show it on the frontend.' );
		$this->assertStringNotContainsString( '.user-profile-picture', $output, 'Profile picture stays, avatars show on the frontend.' );
	}

	/**
	 * Test the Role column marks agent accounts.
	 *
	 * @since x.x.x
	 */
	public function test_users_screen_marks_agent_roles() {
		$agent  = $this->provision_agent();
		$human  = get_user_by( 'id', $this->admin_id );
		$screen = new Users_Screen();
		$roles  = array( 'editor' => 'Editor' );

		$this->assertInstanceOf( \WP_User::class, $human );

		$this->assertSame( array( 'editor' => 'Editor (agent of ' . $human->display_name . ')' ), $screen->mark_agent_roles( $roles, $agent ) );
		$this->assertSame( $roles, $screen->mark_agent_roles( $roles, $human ) );

		// Super admins can always have agents, so demote the parent first on multisite.
		if ( is_multisite() ) {
			revoke_super_admin( $this->admin_id );
		}
		$human->add_cap( Agent_Account::PARENT_CAP, false );
		$this->assertSame( array( 'editor' => 'Editor (agent of ' . $human->display_name . ', suspended)' ), $screen->mark_agent_roles( $roles, $agent ), 'An ineligible parent shows as suspended.' );
		$human->remove_cap( Agent_Account::PARENT_CAP );

		delete_user_meta( $agent->ID, Agent_Account::META_PARENT );
		$this->assertSame( array( 'editor' => 'Editor (agent without parent, suspended)' ), $screen->mark_agent_roles( $roles, $agent ) );
	}

	/**
	 * Test the account type filter narrows the Users list table query.
	 *
	 * @since x.x.x
	 */
	public function test_users_screen_account_type_filter() {
		$agent  = $this->provision_agent();
		$screen = new Users_Screen();
		$listed = static function () use ( $screen ): array {
			return array_map( 'intval', get_users( $screen->filter_list_table( array( 'fields' => 'ID' ) ) ) );
		};

		$this->assertSame( array(), $screen->filter_list_table( array() ), 'No filter by default.' );

		$_GET['wpai_account_type'] = 'agent';
		$this->assertContains( $agent->ID, $listed() );
		$this->assertNotContains( $this->admin_id, $listed(), 'Agents only leaves people out.' );

		$_GET['wpai_account_type'] = 'human';
		$this->assertContains( $this->admin_id, $listed() );
		$this->assertNotContains( $agent->ID, $listed(), 'Excluding agents leaves them out.' );

		$_GET['wpai_account_type'] = 'bogus';
		$this->assertSame( array(), $screen->filter_list_table( array() ), 'Unknown values are ignored.' );

		ob_start();
		$screen->render_filter( 'top' );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="wpai_account_type"', $output );
		$this->assertStringContainsString( 'Agents only', $output );

		ob_start();
		$screen->render_filter( 'bottom' );
		$this->assertSame( '', ob_get_clean(), 'A second select would overwrite the submitted value.' );
	}

	/**
	 * Test the Users screen swaps the reset link for an Application Passwords link.
	 *
	 * @since x.x.x
	 */
	public function test_users_screen_row_actions_for_agents() {
		$agent   = $this->provision_agent();
		$human   = get_user_by( 'id', $this->admin_id );
		$screen  = new Users_Screen();
		$actions = array(
			'edit'          => '<a>Edit</a>',
			'resetpassword' => '<a>Send password reset</a>',
		);

		$agent_actions = $screen->filter_row_actions( $actions, $agent );
		$this->assertArrayNotHasKey( 'resetpassword', $agent_actions );
		$this->assertArrayHasKey( 'wpai_application_passwords', $agent_actions );
		$this->assertStringContainsString( '#application-passwords-section', $agent_actions['wpai_application_passwords'] );

		$this->assertSame( $actions, $screen->filter_row_actions( $actions, $human ) );
	}

	/**
	 * Runs a callback and returns the URL it tried to redirect to.
	 *
	 * @since x.x.x
	 *
	 * @param callable $callback Callback expected to redirect.
	 * @return string Redirect URL.
	 */
	private function capture_redirect( callable $callback ): string {
		$filter = static function ( string $location ): void {
			throw new \RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Transporting the captured URL to the test.
		};
		add_filter( 'wp_redirect', $filter );

		try {
			$callback();
			$this->fail( 'Expected a redirect.' );
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter );
		}
	}

	/**
	 * Test that agents stay visible in user queries.
	 *
	 * @since x.x.x
	 */
	public function test_agents_stay_visible_in_user_queries() {
		$agent = $this->provision_agent();

		$ids = get_users( array( 'fields' => 'ID' ) );

		$this->assertContains( (string) $agent->ID, array_map( 'strval', $ids ) );
	}

	/**
	 * Tests that agents fall back to the agent avatar unless another one was set.
	 *
	 * @since x.x.x
	 */
	public function test_agents_get_the_agent_avatar() {
		$agent    = $this->provision_agent( 'avatar_agent', 'author' );
		$human_id = self::factory()->user->create();
		$post     = get_post( $this->create_post( $agent->ID, 'publish' ) );

		$fallback = 'd=' . rawurlencode( plugins_url( 'assets/images/agent-avatar.png', WPAI_PLUGIN_FILE ) );
		foreach ( array( $agent->ID, $agent, $agent->user_email, $post ) as $id_or_email ) {
			$url = (string) get_avatar_url( $id_or_email );
			$this->assertStringContainsString( 'gravatar.com/avatar/', $url, 'An agent\'s own Gravatar comes first.' );
			$this->assertStringContainsString( $fallback, $url, 'The flask is the Gravatar fallback.' );
		}
		$this->assertStringNotContainsString( 'agent-avatar', (string) get_avatar_url( $human_id ), 'Humans keep their Gravatar.' );

		$comment = get_comment( self::factory()->comment->create( array( 'user_id' => $agent->ID ) ) );
		$this->assertStringContainsString( $fallback, (string) get_avatar_url( $comment ) );
		$pingback = get_comment(
			self::factory()->comment->create(
				array(
					'user_id'      => $agent->ID,
					'comment_type' => 'pingback',
				)
			)
		);
		$this->assertFalse( get_avatar_url( $pingback ), 'Pingbacks get no avatar, as in core.' );

		$custom = static function ( array $args ): array {
			$args['url'] = 'https://example.com/custom.png';
			return $args;
		};
		add_filter( 'pre_get_avatar_data', $custom, 5 );
		$this->assertSame( 'https://example.com/custom.png', get_avatar_url( $agent->ID ), 'An avatar set earlier is kept.' );
		remove_filter( 'pre_get_avatar_data', $custom, 5 );
	}


	/**
	 * Test that the REST user response exposes the agent flag.
	 *
	 * @since x.x.x
	 */
	public function test_rest_user_response_exposes_agent_flag() {
		// Force a fresh REST server so `rest_api_init` fires with this
		// test's hooks attached, even when an earlier test booted one.
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting a core test global.

		$agent = $this->provision_agent();

		$request = new WP_REST_Request( 'GET', '/wp/v2/users/' . $agent->ID );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['wpai_is_agent'] );
		$this->assertSame( $this->admin_id, $response->get_data()['wpai_agent_parent'] );

		$request = new WP_REST_Request( 'GET', '/wp/v2/users/' . $this->admin_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['wpai_is_agent'] );
		$this->assertNull( $response->get_data()['wpai_agent_parent'] );

		// The parent is public attribution, like the author it describes.
		$request = new WP_REST_Request( 'GET', '/wp/v2/users/' . $agent->ID );
		$request->set_param( 'context', 'view' );
		$this->assertSame( $this->admin_id, rest_do_request( $request )->get_data()['wpai_agent_parent'] );
	}

	/**
	 * Tests that an agent can receive independent roles on multiple sites.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_agent_can_be_member_of_multiple_sites() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$first_site = get_current_blog_id();
		$agent      = $this->provision_agent();
		$other_site = (int) self::factory()->blog->create();

		$this->assertTrue( add_user_to_blog( $other_site, $agent->ID, 'author' ), 'Core should allow adding an agent to another site.' );
		$this->assertTrue( is_user_member_of_blog( $agent->ID, $first_site ) );
		$this->assertTrue( is_user_member_of_blog( $agent->ID, $other_site ) );

		switch_to_blog( $other_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Simulating a request to another site of the test network.
		$agent_on_other_site = new \WP_User( $agent->ID );
		$roles               = $agent_on_other_site->roles;
		$can_edit_posts      = user_can( $agent_on_other_site, 'edit_posts' );
		$can_manage_options  = user_can( $agent_on_other_site, 'manage_options' );
		restore_current_blog();

		$this->assertSame( array( 'author' ), $roles, 'The role on the second site should define authority there.' );
		$this->assertTrue( $can_edit_posts );
		$this->assertFalse( $can_manage_options );

		remove_user_from_blog( $agent->ID, $first_site );
		$this->assertFalse( is_user_member_of_blog( $agent->ID, $first_site ), 'Removing one membership should remove authority only on that site.' );
		$this->assertTrue( is_user_member_of_blog( $agent->ID, $other_site ), 'Membership on another site should remain intact.' );
	}

	/**
	 * Tests that a network-wide Application Password authenticates on member sites.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_application_password_authenticates_on_multiple_member_sites() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$agent      = $this->provision_agent();
		$other_site = (int) self::factory()->blog->create();
		$this->assertTrue( add_user_to_blog( $other_site, $agent->ID, 'editor' ) );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );

		$created = \WP_Application_Passwords::create_new_application_password( $agent->ID, array( 'name' => 'Multisite membership test' ) );
		$this->assertIsArray( $created );
		$password          = is_array( $created ) ? $created[0] : '';
		$first_site_result = wp_authenticate_application_password( null, $agent->user_login, $password );

		switch_to_blog( $other_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Simulating a request to another site of the test network.
		$other_site_result = wp_authenticate_application_password( null, $agent->user_login, $password );
		restore_current_blog();

		$this->assertInstanceOf( \WP_User::class, $first_site_result, 'The credential should authenticate on the provisioning site.' );
		$this->assertInstanceOf( \WP_User::class, $other_site_result, 'The same credential should authenticate on another member site.' );
		$this->assertSame( $agent->ID, $other_site_result->ID );

		remove_filter( 'application_password_is_api_request', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * Tests that core controls multisite agent management like any other user.
	 *
	 * Site administrators receive no agent-specific exception, while a network
	 * administrator may manage both human and agent accounts.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_core_controls_multisite_agent_management() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$agent         = $this->provision_agent();
		$human_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$site_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( user_can( $this->admin_id, 'edit_user', $agent->ID ), 'A network administrator should manage an agent.' );
		$this->assertTrue( user_can( $this->admin_id, 'edit_user', $human_id ), 'A network administrator should manage a human.' );
		$this->assertFalse( user_can( $site_admin_id, 'edit_user', $agent->ID ), 'A site administrator should receive no agent-specific management exception.' );
		$this->assertFalse( user_can( $site_admin_id, 'edit_user', $human_id ), 'Core should apply the same restriction to human accounts.' );
		$this->assertFalse( user_can( $site_admin_id, 'create_app_password', $agent->ID ), 'Application Password management should follow core user-edit permissions.' );
	}
}
