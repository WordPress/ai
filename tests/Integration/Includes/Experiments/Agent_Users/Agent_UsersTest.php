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
use WordPress\AI\Experiments\Agent_Users\Agent_Users;
use WordPress\AI\Experiments\Agent_Users\New_User_Screen;
use WordPress\AI\Experiments\Agent_Users\Profile_Screen;
use WordPress\AI\Experiments\Agent_Users\Users_Screen;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WordPress\AI\Main;

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
	 * Agent account service.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Agent_Users\Agent_Account
	 */
	private Agent_Account $account;

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

		$this->experiment = $experiment;
		$this->account    = new Agent_Account();

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
		$result = $this->account->provision( $login, $role, $login . '@example.com' );
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
		$this->assertTrue( Agent_Account::current_user_can_provision() );
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
		$this->assertFalse( Agent_Account::current_user_can_provision() );

		$result = $this->account->provision( 'unsafe-agent', 'editor', 'unsafe-agent@example.com' );
		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_requires_network_activation', $result->get_error_code() );
		$this->assertFalse( username_exists( Agent_Account::apply_login_suffix( 'unsafe-agent' ) ), 'A direct provisioning call must not bypass the network-activation requirement.' );

		$GLOBALS['pagenow']     = 'user-new.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the core admin screen.
		$_REQUEST['wpai_agent'] = '1';
		ob_start();
		( new New_User_Screen( $this->account ) )->render_fields( 'add-new-user' );
		$this->assertSame( '', ob_get_clean(), 'The Add Agent fields should stay unavailable.' );

		ob_start();
		$this->experiment->render_network_activation_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'network-activated', $notice );

		$this->set_ai_plugin_network_active( true );
		$this->assertTrue( Agent_Account::current_user_can_provision(), 'Provisioning should resume after network activation.' );
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
		$this->assertFalse( metadata_exists( 'user', $agent->ID, 'wpai_agent_site_id' ), 'Provisioning should not create a private multisite boundary.' );

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
	 * Test that human accounts are not agents.
	 *
	 * @since x.x.x
	 */
	public function test_human_accounts_are_not_agents() {
		$this->assertFalse( Agent_Account::is_agent( $this->admin_id ) );
		$this->assertFalse( Agent_Account::is_agent( 0 ) );
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

		$agent = $this->account->provision( 'editor_helper', 'author', 'editor_helper@example.com', '', '', '', $parent_id );
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
			'role above parent' => array( 'editor', 'administrator', 'wpai_agent_role_exceeds_parent' ),
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

		$result = $this->account->provision( 'invalid_parent', $role, 'invalid_parent@example.com', '', '', '', $parent_id );

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
		$admin_agent = $this->provision_agent( 'provisioning_agent', 'administrator' );
		$editor_id   = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $admin_agent->ID );
		$result = $this->account->provision( 'grandchild', 'author', 'grandchild@example.com', '', '', '', $editor_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_cannot_provision_agents', $result->get_error_code() );
		$this->assertFalse( Agent_Account::current_user_can_provision() );

		foreach ( array( Agent_Account::META_KEY, Agent_Account::META_PARENT ) as $meta_key ) {
			$this->assertFalse(
				(bool) apply_filters( "auth_user_meta_{$meta_key}", true, $meta_key, $admin_agent->ID, $this->admin_id, 'edit_user_meta', array() ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking core's meta authorization hook.
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
		$agent       = $this->account->provision( 'owning_agent', 'author', 'owning_agent@example.com', '', '', '', $parent_id );
		$sibling     = $this->account->provision( 'sibling_agent', 'author', 'sibling_agent@example.com', '', '', '', $parent_id );
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
		$agent     = $this->account->provision( 'contributing_agent', 'contributor', 'contributing_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$pending   = $this->create_post( $agent->ID, 'pending' );
		$published = $this->create_post( $agent->ID, 'publish' );

		$this->assertTrue( user_can( $parent_id, 'edit_post', $pending ), 'A contributor parent edits their agent\'s pending posts, as their own.' );
		$this->assertFalse( user_can( $parent_id, 'edit_post', $published ), 'A contributor cannot edit published posts, even their agent\'s.' );
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
		$agent     = $this->account->provision( 'managed_agent', 'author', 'managed_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$this->assertTrue( user_can( $parent_id, 'edit_user', $agent->ID ), 'A parent should edit their agent.' );
		$this->assertTrue( user_can( $parent_id, 'create_app_password', $agent->ID ), 'A parent should manage their agent\'s credentials.' );
		$this->assertFalse( user_can( $parent_id, 'promote_user', $agent->ID ), 'Role changes should still require core permissions.' );
		$this->assertFalse( user_can( $parent_id, 'delete_user', $agent->ID ), 'Deleting the agent should still require core permissions.' );
		$this->assertFalse( user_can( $other_id, 'edit_user', $agent->ID ), 'Other users should not edit the agent.' );

		$admin_agent = $this->provision_agent( 'admin_child', 'administrator' );
		foreach ( array( 'edit_user', 'promote_user', 'remove_user', 'delete_user' ) as $capability ) {
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Checking each user management capability.
			$this->assertFalse( user_can( $admin_agent, $capability, $this->admin_id ), sprintf( 'An agent should never %s its own parent.', $capability ) );
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
		$agent     = $this->account->provision( 'credential_agent', 'author', 'credential_agent@example.com', '', '', '', $parent_id );
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
		$agent     = $this->account->provision( 'listed_agent', 'author', 'listed_agent@example.com', '', '', '', $parent_id );
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
		$agent     = $this->account->provision( 'bounded_agent', 'author', 'bounded_agent@example.com', '', '', '', $parent_id );
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
		$agent     = $this->account->provision( 'object_agent', 'editor', 'object_agent@example.com', '', '', '', $parent_id );
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
		$agent     = $this->account->provision( 'suspended_agent', 'author', 'suspended_agent@example.com', '', '', '', $parent_id );
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
		$agent     = $this->account->provision( 'network_bounded', 'editor', 'network_bounded@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		$author_site  = (int) self::factory()->blog->create();
		$foreign_site = (int) self::factory()->blog->create();
		add_user_to_blog( $author_site, $parent_id, 'author' );
		add_user_to_blog( $author_site, $agent->ID, 'editor' );
		add_user_to_blog( $foreign_site, $agent->ID, 'editor' );

		$this->assertTrue( user_can( $agent, 'edit_others_posts' ), 'The parent is an editor on the provisioning site.' );

		switch_to_blog( $author_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Simulating a request to another site of the test network.
		$author_site_others  = user_can( $agent->ID, 'edit_others_posts' );
		$author_site_publish = user_can( $agent->ID, 'publish_posts' );
		restore_current_blog();

		switch_to_blog( $foreign_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Simulating a request to another site of the test network.
		$foreign_site_edit = user_can( $agent->ID, 'edit_posts' );
		restore_current_blog();

		$this->assertFalse( $author_site_others, 'Where the parent is an author, the editor agent is limited to author capabilities.' );
		$this->assertTrue( $author_site_publish );
		$this->assertFalse( $foreign_site_edit, 'Where the parent is not a member, the agent has no authority.' );
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
	 * Tests that deleting a parent deletes their agents on single site.
	 *
	 * @since x.x.x
	 */
	public function test_deleting_parent_deletes_agents() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite removal and deletion are covered separately.' );
		}

		$parent_id   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$heir_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent       = $this->account->provision( 'doomed_agent', 'author', 'doomed_agent@example.com', '', '', '', $parent_id );
		$other_agent = $this->provision_agent( 'surviving_agent', 'author' );
		$this->assertInstanceOf( \WP_User::class, $agent );
		$post_id = $this->create_post( $agent->ID, 'publish' );

		wp_delete_user( $parent_id, $heir_id );

		$this->assertFalse( get_user_by( 'id', $agent->ID ), 'The parent\'s agent should be deleted with the parent.' );
		$this->assertSame( $heir_id, (int) get_post_field( 'post_author', $post_id ), 'Agent content should follow the parent\'s reassignment.' );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $other_agent->ID ), 'Other parents\' agents should remain.' );
	}

	/**
	 * Tests that agent content is deleted with the parent's when nothing is reassigned.
	 *
	 * @since x.x.x
	 */
	public function test_deleting_parent_without_reassignment_deletes_agent_content() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite removal and deletion are covered separately.' );
		}

		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->account->provision( 'contentful_agent', 'author', 'contentful_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );
		$post_id        = $this->create_post( $agent->ID, 'publish' );
		$parent_post_id = $this->create_post( $parent_id, 'publish' );

		wp_delete_user( $parent_id );

		$this->assertFalse( get_user_by( 'id', $agent->ID ) );
		$this->assertSame( 'trash', get_post_status( $parent_post_id ), 'Core trashes the parent\'s content.' );
		$this->assertSame( 'trash', get_post_status( $post_id ), 'Agent content should be handled like the parent\'s.' );
	}

	/**
	 * Tests that an agent chosen to receive the content is kept without a parent.
	 *
	 * @since x.x.x
	 */
	public function test_deleting_parent_keeps_heir_agent_detached() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite removal and deletion are covered separately.' );
		}

		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$heir      = $this->account->provision( 'heir_agent', 'author', 'heir_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $heir );
		$post_id = $this->create_post( $parent_id, 'publish' );

		wp_delete_user( $parent_id, $heir->ID );

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $heir->ID ), 'The heir should be kept.' );
		$this->assertSame( $heir->ID, (int) get_post_field( 'post_author', $post_id ) );
		$this->assertNull( Agent_Account::get_parent( $heir ), 'The heir should be detached from its deleted parent.' );
		$this->assertTrue( Agent_Account::is_suspended( $heir ) );
	}

	/**
	 * Tests that the delete screen offers reassignment for agent content.
	 *
	 * Core only offers the choice when the deleted users own content; a parent
	 * without content but with a content-owning agent must get it too.
	 *
	 * @since x.x.x
	 */
	public function test_delete_screen_accounts_for_agent_content() {
		$parent_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$childless  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$idle_agent = $this->account->provision( 'idle_agent', 'author', 'idle_agent@example.com', '', '', '', $childless );
		$agent      = $this->account->provision( 'writing_agent', 'author', 'writing_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $idle_agent );
		$this->assertInstanceOf( \WP_User::class, $agent );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking core hooks in an integration test.
		$this->assertFalse( apply_filters( 'users_have_additional_content', false, array( $parent_id ) ), 'No content yet.' );

		$this->create_post( $agent->ID, 'draft' );
		$this->assertTrue( apply_filters( 'users_have_additional_content', false, array( $parent_id ) ), 'The agent\'s content should trigger the reassignment choice.' );
		$this->assertFalse( apply_filters( 'users_have_additional_content', false, array( $childless ) ), 'Agents without content change nothing.' );

		ob_start();
		do_action( 'delete_user_form', wp_get_current_user(), array( $parent_id ) );
		$output = (string) ob_get_clean();
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->assertStringContainsString( 'Their agents will be deleted too:', $output );
		$this->assertStringContainsString( $agent->user_login, $output );
	}

	/**
	 * Tests that multisite removal and deletion follow the parent.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_multisite_parent_removal_and_deletion_follow_to_agents() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$site_id   = get_current_blog_id();
		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent     = $this->account->provision( 'network_agent', 'author', 'network_agent@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $agent );

		remove_user_from_blog( $parent_id, $site_id );
		$this->assertFalse( is_user_member_of_blog( $agent->ID, $site_id ), 'Removing the parent from a site should remove their agents from it.' );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $agent->ID ), 'The agent account should remain on the network.' );

		wpmu_delete_user( $parent_id );
		$this->assertFalse( get_user_by( 'id', $agent->ID ), 'Deleting the parent from the network should delete their agents.' );
	}

	/**
	 * Tests that removing the parent from one site keeps the heir's link elsewhere.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_multisite_site_removal_keeps_heir_linked_on_other_sites() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$parent_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$heir       = $this->account->provision( 'site_heir', 'author', 'site_heir@example.com', '', '', '', $parent_id );
		$other_site = (int) self::factory()->blog->create();
		$this->assertInstanceOf( \WP_User::class, $heir );
		add_user_to_blog( $other_site, $parent_id, 'editor' );
		add_user_to_blog( $other_site, $heir->ID, 'author' );
		$post_id = $this->create_post( $parent_id, 'publish' );

		remove_user_from_blog( $parent_id, get_current_blog_id(), $heir->ID );

		$this->assertSame( $heir->ID, (int) get_post_field( 'post_author', $post_id ), 'The heir receives the content on this site.' );
		$parent = Agent_Account::get_parent( $heir );
		$this->assertInstanceOf( \WP_User::class, $parent, 'Site removal is not account deletion; the link stays.' );
		$this->assertSame( $parent_id, $parent->ID );

		switch_to_blog( $other_site ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Simulating a request to another site of the test network.
		$can_publish = user_can( $heir->ID, 'publish_posts' );
		restore_current_blog();
		$this->assertTrue( $can_publish, 'The heir keeps its parent-bounded authority on other sites.' );
	}

	/**
	 * Tests that an agent inheriting its parent's content survives network deletion.
	 *
	 * Mirrors the network Users screen, which removes the parent from each site
	 * with a reassignment target before deleting the account.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_multisite_heir_agent_survives_parent_deletion() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$parent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$heir      = $this->account->provision( 'network_heir', 'author', 'network_heir@example.com', '', '', '', $parent_id );
		$this->assertInstanceOf( \WP_User::class, $heir );
		$post_id = $this->create_post( $parent_id, 'publish' );

		remove_user_from_blog( $parent_id, get_current_blog_id(), $heir->ID );
		wpmu_delete_user( $parent_id );

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $heir->ID ), 'The heir should be kept.' );
		$this->assertSame( $heir->ID, (int) get_post_field( 'post_author', $post_id ), 'The reassigned content should be kept.' );
		$this->assertNull( Agent_Account::get_parent( $heir ), 'The heir should be detached from its deleted parent.' );
	}

	/**
	 * Test that provisioning validates its input.
	 *
	 * @since x.x.x
	 */
	public function test_provision_validates_input() {
		$empty_login = $this->account->provision( '   ', 'editor', 'a@example.com' );
		$this->assertWPError( $empty_login );
		$this->assertSame( 'wpai_agent_empty_login', $empty_login->get_error_code() );

		$bad_role = $this->account->provision( 'test-agent', 'does-not-exist', 'a@example.com' );
		$this->assertWPError( $bad_role );
		$this->assertSame( 'wpai_agent_role_not_assignable', $bad_role->get_error_code() );

		$symbols_only = $this->account->provision( '!!!', 'editor', 'a@example.com' );
		$this->assertWPError( $symbols_only );
		$this->assertSame( 'wpai_agent_empty_login', $symbols_only->get_error_code() );

		$this->provision_agent( 'taken_agent' );
		$taken = $this->account->provision( 'taken', 'editor', 'a@example.com' );
		$this->assertWPError( $taken );
		$this->assertSame( 'wpai_agent_login_exists', $taken->get_error_code(), 'The suffixed login should be checked for collisions.' );

		$no_email = $this->account->provision( 'no-email-agent', 'editor', '' );
		$this->assertWPError( $no_email );
		$this->assertSame( 'wpai_agent_empty_email', $no_email->get_error_code() );

		$bad_email = $this->account->provision( 'bad-email-agent', 'editor', 'not-an-email' );
		$this->assertWPError( $bad_email );
		$this->assertSame( 'wpai_agent_invalid_email', $bad_email->get_error_code() );

		$taken_email = $this->account->provision( 'taken-email-agent', 'editor', get_userdata( $this->admin_id )->user_email );
		$this->assertWPError( $taken_email );
		$this->assertSame( 'wpai_agent_email_exists', $taken_email->get_error_code() );
	}

	/**
	 * Test that every agent username ends with the agent suffix.
	 *
	 * @since x.x.x
	 */
	public function test_provision_appends_login_suffix() {
		$this->assertSame( 'writer_agent', Agent_Account::apply_login_suffix( 'writer' ) );
		$this->assertSame( 'writer_agent', Agent_Account::apply_login_suffix( 'writer_agent' ) );

		$appended = $this->provision_agent( 'writer' );
		$this->assertSame( 'writer_agent', $appended->user_login, 'A missing suffix should be appended.' );
		$this->assertSame( 'writer_agent', $appended->display_name, 'The display name should use the final login.' );

		$kept = $this->provision_agent( 'reviewer_agent' );
		$this->assertSame( 'reviewer_agent', $kept->user_login, 'An existing suffix should not be doubled.' );

		$suffix_only = $this->account->provision( Agent_Account::LOGIN_SUFFIX, 'editor', 'suffix@example.com' );
		$this->assertWPError( $suffix_only );
		$this->assertSame( 'wpai_agent_empty_login', $suffix_only->get_error_code(), 'The suffix alone is not a name.' );

		$too_long = $this->account->provision( str_repeat( 'a', 58 ), 'editor', 'long@example.com' );
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

		$result = $this->account->provision( 'unauthorized-agent', 'subscriber', 'x@example.com' );

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

		$result = $this->account->provision( 'escalating-agent', 'subscriber', 'x@example.com' );

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

		$this->assertFalse( Agent_Account::current_user_can_provision() );
		$result = $this->account->provision( 'unmanageable-agent', 'subscriber', 'x@example.com' );

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

		$assignable_roles = $this->account->get_assignable_roles();
		$this->assertArrayHasKey( 'subscriber', $assignable_roles );
		$this->assertArrayNotHasKey( 'editor', $assignable_roles );
		$this->assertArrayNotHasKey( 'administrator', $assignable_roles );

		$rejected = $this->account->provision( 'escalating-agent', 'administrator', 'x@example.com' );
		$this->assertWPError( $rejected );
		$this->assertSame( 'wpai_agent_role_not_assignable', $rejected->get_error_code() );

		$allowed = $this->account->provision( 'read-only-agent', 'subscriber', 'x@example.com' );
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
			$assignable_roles = $this->account->get_assignable_roles();
			$result           = $this->account->provision( 'context-escalating-agent', 'wpai_agent_contextual_manager', 'x@example.com', '', '', '', $parent_id );
		} finally {
			remove_filter( 'map_meta_cap', $deny_only_to_provisioner, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Removing the test filter.
			remove_action( 'edit_user_created_user', $record_creation ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Removing the test action.
		}

		$this->assertArrayHasKey( 'wpai_agent_contextual_manager', $assignable_roles, 'The pre-creation comparison cannot see how the future agent will be filtered.' );
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

		$assignable = $this->account->get_assignable_roles();
		$this->assertArrayHasKey( 'editor', $assignable, 'A role is assignable when its only extra capability is inert for the agent.' );
		$this->assertArrayNotHasKey( 'administrator', $assignable );

		$agent = $this->account->provision( 'inert-cap-agent', 'editor', 'inert@example.com' );
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

		$named = $this->account->provision( 'named-agent', 'editor', 'owner@example.com', 'Content', 'Assistant', 'https://example.com/assistant' );
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
	 * Test that agents receive the capabilities of their assigned role.
	 *
	 * The `unfiltered_html` capability is the deliberate exception for
	 * non-administrator agents; dedicated tests cover it.
	 *
	 * @since x.x.x
	 */
	public function test_agents_receive_assigned_role_capabilities() {
		$admin_agent = $this->provision_agent( 'admin-agent', 'administrator' );

		// Compare against a plain administrator. The provisioning user is a
		// super admin on multisite, and that status bypasses capability checks.
		$human_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$human_admin    = get_userdata( $human_admin_id );
		$this->assertInstanceOf( \WP_User::class, $human_admin );

		$administrator_capabilities = array(
			'unfiltered_html',
			'create_users',
			'edit_users',
			'promote_users',
			'delete_users',
			'activate_plugins',
			'manage_options',
		);
		foreach ( $administrator_capabilities as $capability ) {
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Comparing representative capabilities from the assigned role.
			$this->assertSame( user_can( $human_admin, $capability ), user_can( $admin_agent, $capability ), sprintf( 'Administrator agents and humans should agree on %s.', $capability ) );
		}

		$this->assertTrue( user_can( $admin_agent, 'edit_user', $admin_agent->ID ), 'Core should allow the agent to edit itself when its role permits it.' );
		$this->assertTrue( user_can( $admin_agent, 'create_app_password', $admin_agent->ID ), 'Core should allow the agent to manage its own credentials.' );

		$editor_agent = $this->provision_agent( 'editor-agent', 'editor' );
		$editor_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$human_editor = get_userdata( $editor_id );
		$this->assertInstanceOf( \WP_User::class, $human_editor );

		foreach ( array( 'edit_others_posts', 'publish_posts', 'manage_options', 'create_users', 'activate_plugins' ) as $capability ) {
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Comparing representative capabilities from the assigned role.
			$this->assertSame( user_can( $human_editor, $capability ), user_can( $editor_agent, $capability ), sprintf( 'Editor agents and humans should agree on %s.', $capability ) );
		}

		$editor_agent->add_cap( 'wpai_agent_test_capability' );
		get_userdata( $this->admin_id )->add_cap( 'wpai_agent_test_capability' );
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Verifying a test-only custom capability.
		$this->assertTrue( user_can( $editor_agent, 'wpai_agent_test_capability' ), 'Agent identity should not remove explicitly granted capabilities the parent also has.' );
	}

	/**
	 * Test that non-administrator agents lose `unfiltered_html`.
	 *
	 * @since x.x.x
	 */
	public function test_non_admin_agents_lose_unfiltered_html() {
		$editor_agent = $this->provision_agent( 'filtered-agent', 'editor' );
		$admin_agent  = $this->provision_agent( 'filtered-admin-agent', 'administrator' );
		$editor_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$editor_agent->add_cap( 'unfiltered_html' );
		$this->assertFalse( user_can( $editor_agent, 'unfiltered_html' ), 'An Editor agent should not carry unfiltered_html.' );
		$this->assertFalse( user_can( $editor_agent, 'edit_css' ), 'The strip should cover meta capabilities that resolve to unfiltered_html.' );
		if ( ! is_multisite() ) {
			$this->assertTrue( user_can( $editor_id, 'unfiltered_html' ), 'A human Editor keeps the single-site role default.' );
			$this->assertTrue( user_can( $editor_id, 'edit_css' ), 'A human Editor keeps custom CSS access on single site.' );
		}

		$editor_agent->add_cap( 'manage_options' );
		$this->assertSame(
			user_can( $admin_id, 'unfiltered_html' ),
			user_can( $editor_agent, 'unfiltered_html' ),
			'Administrative agents should follow core regardless of their role name.'
		);

		$this->assertSame(
			user_can( $admin_id, 'unfiltered_html' ),
			user_can( $admin_agent, 'unfiltered_html' ),
			'Administrator agents should follow core, like any other Administrator.'
		);
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
	 * Test that account safeguards remain active when the experiment is disabled.
	 *
	 * @since x.x.x
	 */
	public function test_safeguards_remain_when_experiment_is_disabled() {
		$agent = $this->provision_agent( 'disabled-experiment-agent', 'administrator' );

		update_option( 'wpai_feature_agent-users_enabled', false );
		$this->remove_agent_account_safeguards();

		// This is the always-on bootstrap path, independent of feature loading.
		Main::get_instance()->register_agent_account_safeguards();

		$this->assertTrue( user_can( $agent, 'manage_options' ), 'The assigned role should remain authoritative.' );

		$reset = get_password_reset_key( $agent );
		$this->assertWPError( $reset );
		$this->assertSame( 'no_password_reset', $reset->get_error_code() );

		$blocked = wp_authenticate_username_password( null, $agent->user_login, 'any-password' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'wpai_agent_login_disabled', $blocked->get_error_code() );
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
	 * Test core's REST flow reveals an Application Password only on creation.
	 *
	 * @since x.x.x
	 */
	public function test_core_rest_flow_creates_application_password() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting a core test global.

		$agent = $this->provision_agent();

		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $agent->ID . '/application-passwords' );
		$request->set_param( 'name', 'Test MCP Client' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotEmpty( $data['password'] );
		$this->assertCount( 1, \WP_Application_Passwords::get_user_application_passwords( $agent->ID ) );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/users/' . $agent->ID . '/application-passwords/' . $data['uuid'] );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'password', $response->get_data() );

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * Test the Add User screen renders the agent fields only in agent mode.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_renders_agent_fields() {
		$screen = new New_User_Screen( $this->account );

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
		$this->assertStringContainsString( 'name="wpai_agent_parent" id="wpai_agent_parent" required', $output, 'Agent mode asks for a parent.' );
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

		$screen = new New_User_Screen( $this->account );
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
				( new New_User_Screen( $this->account ) )->handle_create();
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
			( new New_User_Screen( $this->account ) )->handle_create();
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
		( new New_User_Screen( $this->account ) )->handle_create();
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
		$screen    = new New_User_Screen( $this->account );
		$nonce     = wp_create_nonce( 'create-user' );

		$_POST['wpai_agent']              = '1';
		$_POST['user_login']              = 'parentless';
		$_POST['email']                   = 'parentless@example.com';
		$_POST['role']                    = 'author';
		$_POST['_wpnonce_create-user']    = $nonce;
		$_REQUEST['_wpnonce_create-user'] = $nonce;

		$screen->handle_create();
		$errors = new \WP_Error();
		$user   = new \stdClass();
		do_action_ref_array( 'user_profile_update_errors', array( &$errors, false, &$user ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking a core hook in an integration test.
		$this->assertSame( array( 'wpai_agent_parent_required' ), $errors->get_error_codes(), 'The form should not fall back to the provisioner.' );
		$this->assertFalse( get_user_by( 'login', 'parentless_agent' ) );

		// A failed submission for another reason re-renders with the chosen parent.
		remove_all_actions( 'user_profile_update_errors' );
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

		( new New_User_Screen( $this->account ) )->handle_create();

		$this->assertFalse( get_user_by( 'login', 'human' ) );
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
		$this->assertStringContainsString( '<p class="wpai-agent-account-type"', $output );
		$this->assertStringContainsString( 'Agent account.', $output );
		$this->assertStringNotContainsString( 'notice', $output, 'The note is plain text, not a notice.' );

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

		$this->assertSame( array( 'editor' => 'Editor (agent)' ), $screen->mark_agent_roles( $roles, $agent ) );
		$this->assertSame( $roles, $screen->mark_agent_roles( $roles, $human ) );
	}

	/**
	 * Test the account type filter narrows the Users list table query.
	 *
	 * @since x.x.x
	 */
	public function test_users_screen_account_type_filter() {
		$this->provision_agent();
		$screen = new Users_Screen();

		$this->assertSame( array(), $screen->filter_list_table( array() ), 'No filter by default.' );

		$_GET['wpai_account_type'] = 'agent';
		$this->assertSame( 'EXISTS', $screen->filter_list_table( array() )['meta_compare'] );

		$_GET['wpai_account_type'] = 'human';
		$this->assertSame( 'NOT EXISTS', $screen->filter_list_table( array() )['meta_compare'] );

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
	 * Test that the admin UI registers in admin context.
	 *
	 * The experiment framework already guarantees nothing registers while the
	 * experiment is disabled, so only the is_admin() branch needs coverage.
	 *
	 * @since x.x.x
	 */
	public function test_admin_ui_registered_in_admin_context() {
		set_current_screen( 'users' );
		$this->assertTrue( is_admin() );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$this->assertNotFalse( has_action( 'admin_action_createuser' ) );
		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'user_new_form' ) );
		$this->assertNotFalse( has_filter( 'show_password_fields' ) );
		$this->assertNotFalse( has_filter( 'get_role_list' ) );
		$this->assertNotFalse( has_action( 'manage_users_extra_tablenav' ) );
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
	 * Tests that an agent cannot become a super admin.
	 *
	 * @since x.x.x
	 *
	 * @group ms-required
	 */
	public function test_agent_cannot_become_super_admin() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$agent = $this->provision_agent();
		$human = get_user_by( 'id', $this->admin_id );
		$this->assertInstanceOf( \WP_User::class, $human );

		$this->assertSame(
			array( $human->user_login ),
			$this->account->strip_agents_from_super_admins( array( $human->user_login, $agent->user_login ) ),
			'Agent logins should be dropped from the super admin list.'
		);
		$this->assertSame( 'not-an-array', $this->account->strip_agents_from_super_admins( 'not-an-array' ), 'Non-array values should pass through.' );

		grant_super_admin( $agent->ID );

		$this->assertFalse( is_super_admin( $agent->ID ), 'An agent should never become a super admin.' );
		$this->assertNotContains( $agent->user_login, get_super_admins(), 'The agent login should not be stored in the super admin list.' );
		$this->assertTrue( is_super_admin( $this->admin_id ), 'A human should still be grantable.' );
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

	/**
	 * Removes only Agent Account callbacks from their always-on hooks.
	 *
	 * @since x.x.x
	 */
	private function remove_agent_account_safeguards(): void {
		global $wp_filter;

		$hooks = array(
			'wp_authenticate_user',
			'allow_password_reset',
			'wp_is_application_passwords_available_for_user',
			'map_meta_cap',
			'user_has_cap',
			'pre_update_site_option_site_admins',
		);

		foreach ( $hooks as $hook_name ) {
			if ( ! isset( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'] ?? null;
					if ( ! is_array( $function ) || ! isset( $function[0] ) || ! $function[0] instanceof Agent_Account ) {
						continue;
					}

					remove_filter( $hook_name, $function, $priority );
				}
			}
		}
	}
}
