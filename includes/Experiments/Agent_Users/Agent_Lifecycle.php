<?php
/**
 * Agent account lifecycle rules.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_Error;
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Keeps agents with their parents as accounts are removed and deleted.
 *
 * Agents follow their parent off a site and out of the network, their
 * content follows the parent's on the delete screens, and they only join
 * sites their parent belongs to. These rules run whether or not an agent is
 * known, since they only run when an account is added, removed, deleted, or
 * made a super admin.
 *
 * @since x.x.x
 */
final class Agent_Lifecycle {
	/**
	 * Hooks the lifecycle rules into WordPress.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'delete_user', array( $this, 'delete_agents_of_deleted_user' ), 10, 2 );
		add_filter( 'users_have_additional_content', array( $this, 'count_agent_content' ), 10, 2 );
		add_action( 'delete_user_form', array( $this, 'render_agents_deleted_with_parent' ), 10, 2 );
		add_filter( 'wp_dropdown_users_args', array( $this, 'exclude_agents_from_reassignment' ), 10, 2 );

		if ( ! is_multisite() ) {
			return;
		}

		add_filter( 'can_add_user_to_blog', array( $this, 'keep_agents_on_parent_sites' ), 10, 4 );
		add_filter( 'pre_update_site_option_site_admins', array( $this, 'strip_agents_from_super_admins' ) );
		add_action( 'remove_user_from_blog', array( $this, 'remove_agents_of_removed_user' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render_agents_removed_with_parent' ) );
		add_action( 'wpmu_delete_user', array( $this, 'delete_agents_of_deleted_network_user' ) );
		add_action( 'load-users.php', array( $this, 'reject_agents_as_network_heirs' ) );
	}

	/**
	 * Lets an agent join only sites its parent belongs to, and never as a site manager.
	 *
	 * An agent acts on behalf of its parent, so it belongs only where the
	 * parent does. A super admin parent has authority on every site, so their
	 * agents may join any site. This covers every path through
	 * `add_user_to_blog()`, such as adding an existing user to a site. Paths
	 * that write a role directly, such as WP-CLI, bypass it, and the per-site
	 * suspension in `Agent_Account::is_suspended()` covers those.
	 *
	 * @since x.x.x
	 *
	 * @param true|\WP_Error $can_add Whether the user can be added.
	 * @param int|string     $user_id User ID.
	 * @param string         $role    Role slug for the site.
	 * @param int|string     $blog_id Site ID.
	 * @return true|\WP_Error True, or an error for agents that cannot join the site.
	 */
	public function keep_agents_on_parent_sites( $can_add, $user_id, $role, $blog_id ) {
		if ( true !== $can_add || ! Agent_Account::is_agent( (int) $user_id ) ) {
			return $can_add;
		}

		// Core switches to the site before asking, so the role is that site's.
		if ( is_string( $role ) && Agent_Account::role_manages_site( $role ) ) {
			return new WP_Error( 'wpai_agent_role_not_allowed', __( 'Agents cannot have a role that manages the site, such as Administrator.', 'ai' ) );
		}

		$parent = Agent_Account::get_parent( (int) $user_id );
		if ( null !== $parent && ( is_super_admin( $parent->ID ) || Agent_Account::belongs_to_site( $parent->ID, (int) $blog_id ) ) ) {
			return $can_add;
		}

		return new WP_Error( 'wpai_agent_parent_not_member', __( 'An agent can only be added to sites its parent user belongs to.', 'ai' ) );
	}

	/**
	 * Keeps agent accounts out of the network's super admin list.
	 *
	 * Super admin is a network-wide status outside the site role system and
	 * bypasses most capability checks. Agent authority must remain defined by
	 * explicit roles, so agents cannot receive it.
	 *
	 * A value other than an array is malformed and left for core to handle,
	 * as `get_super_admins()` would. The `$super_admins` global in
	 * wp-config.php and direct database writes bypass this filter; both need
	 * server access, the same trust level as WP-CLI. Even then the parent
	 * ceiling holds, because `WP_User::has_cap()` denies super admins any
	 * capability mapped to `do_not_allow`.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $super_admins The super admin logins about to be saved.
	 * @return mixed The list without agent accounts.
	 */
	public function strip_agents_from_super_admins( $super_admins ) {
		if ( ! is_array( $super_admins ) ) {
			return $super_admins;
		}

		return array_values(
			array_filter(
				$super_admins,
				static function ( $login ): bool {
					if ( ! is_string( $login ) ) {
						return true;
					}

					$user = get_user_by( 'login', $login );

					return ! $user instanceof WP_User || ! Agent_Account::is_agent( $user );
				}
			)
		);
	}

	/**
	 * Deletes a parent's agents together with the parent.
	 *
	 * Agent content follows the parent's: it is reassigned to the same user, or
	 * otherwise treated exactly as core treats the parent's. The parent's own agents cannot
	 * receive it, since they are deleted too. On multisite, `wp_delete_user()`
	 * only removes the parent from the current site, so the agents are removed
	 * from that site the same way.
	 *
	 * @since x.x.x
	 *
	 * @param int|string $user_id  Deleted user ID.
	 * @param int|null   $reassign User ID receiving the deleted user's content, or null.
	 */
	public function delete_agents_of_deleted_user( $user_id, $reassign ): void {
		$user_id  = (int) $user_id;
		$reassign = null === $reassign ? null : (int) $reassign;

		self::reject_agent_as_heir( $user_id, (int) $reassign );

		foreach ( Agent_Account::get_agent_ids( $user_id ) as $agent_id ) {
			wp_delete_user( $agent_id, $reassign );
		}
	}

	/**
	 * Removes a parent's agents from a site the parent is removed from.
	 *
	 * The parent's own agents cannot receive the parent's content on that
	 * site, since they are removed too.
	 *
	 * @since x.x.x
	 *
	 * @param int|string      $user_id  Removed user ID.
	 * @param int|string      $blog_id  Site ID.
	 * @param int|string|null $reassign User ID receiving the removed user's content, or 0.
	 */
	public function remove_agents_of_removed_user( $user_id, $blog_id, $reassign = 0 ): void {
		$user_id  = (int) $user_id;
		$blog_id  = (int) $blog_id;
		$reassign = (int) $reassign;

		self::reject_agent_as_heir( $user_id, $reassign );

		foreach ( Agent_Account::get_agent_ids( $user_id ) as $agent_id ) {
			if ( ! Agent_Account::belongs_to_site( $agent_id, $blog_id ) ) {
				continue;
			}

			remove_user_from_blog( $agent_id, $blog_id, $reassign );
		}
	}

	/**
	 * Names the agents removed from the site along with their parents.
	 *
	 * Core's confirmation for removing users from a site has no hook of its
	 * own, so the warning uses the notices area above it.
	 *
	 * @since x.x.x
	 */
	public function render_agents_removed_with_parent(): void {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the confirmation screen's request to describe it; core verifies the removal.
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) );
		if ( '' === $action || '-1' === $action ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ?? '' ) );
		}
		if ( 'users.php' !== $pagenow || is_network_admin() || 'remove' !== $action ) {
			return;
		}

		$user_ids = isset( $_REQUEST['users'] ) ? wp_parse_id_list( wp_unslash( $_REQUEST['users'] ) ) : wp_parse_id_list( wp_unslash( $_REQUEST['user'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$names = array();
		foreach ( $user_ids as $user_id ) {
			foreach ( Agent_Account::get_agent_ids( $user_id ) as $agent_id ) {
				$agent = get_userdata( $agent_id );
				// Core already lists agents removed in their own right.
				if ( ! $agent instanceof WP_User || in_array( $agent_id, $user_ids, true ) || ! Agent_Account::belongs_to_site( $agent_id, get_current_blog_id() ) ) {
					continue;
				}

				$names[] = $agent->user_login;
			}
		}

		if ( array() === $names ) {
			return;
		}

		wp_admin_notice(
			esc_html(
				wp_sprintf(
					/* translators: %l: Comma-separated list of agent usernames. */
					__( 'Their agents will be removed from this site too: %l.', 'ai' ),
					$names
				)
			),
			array(
				'type'        => 'warning',
				'dismissible' => false,
			)
		);
	}

	/**
	 * Deletes a parent's agents together with the parent across the network.
	 *
	 * Core's network delete handler has already applied the delete screen's
	 * choices, for the parent's sites and for the agent's other sites, by
	 * removing each user from them, with or without a reassignment. The agent
	 * is deleted the same way the parent is, so its content ends up exactly
	 * where core leaves the parent's.
	 *
	 * @since x.x.x
	 *
	 * @param int|string $user_id Deleted user ID.
	 */
	public function delete_agents_of_deleted_network_user( $user_id ): void {
		$user_id = (int) $user_id;

		foreach ( Agent_Account::get_agent_ids( $user_id ) as $agent_id ) {
			wpmu_delete_user( $agent_id );
		}
	}

	/**
	 * Stops a parent's content from being given to the parent's own agent.
	 *
	 * The agent is deleted or removed along with its parent, so the content
	 * would be lost with it. The delete screens never offer the agent; this
	 * stops other callers before core changes anything.
	 *
	 * @since x.x.x
	 *
	 * @param int $parent_id Parent being deleted or removed.
	 * @param int $reassign  User ID receiving the parent's content, or 0.
	 */
	private static function reject_agent_as_heir( int $parent_id, int $reassign ): void {
		if ( $reassign <= 0 || ! in_array( $reassign, Agent_Account::get_agent_ids( $parent_id ), true ) ) {
			return;
		}

		wp_die(
			esc_html__( 'A user\'s content cannot be attributed to their own agent, because the agent is removed along with them. Choose another user.', 'ai' ),
			esc_html__( 'Choose another user', 'ai' ),
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}

	/**
	 * Stops a network deletion that would lose content chosen to be kept.
	 *
	 * The agents of the users being deleted are deleted with them, so content
	 * attributed to any of them would be deleted as well. WordPress 7.0's
	 * network delete screen still lists them, and core removes the users from
	 * their sites one by one, so `reject_agent_as_heir()` would only stop the
	 * request partway through. Where the agent was the only other member of a
	 * site, WordPress 7.1 offers to attribute the content with a list
	 * `exclude_agents_from_reassignment()` leaves empty, and core then deletes
	 * the content it was asked to keep. Checking every choice before core's
	 * handler runs makes the deletion all-or-nothing.
	 *
	 * @since x.x.x
	 */
	public function reject_agents_as_network_heirs(): void {
		if (
			! is_network_admin() ||
			! isset( $_REQUEST['action'], $_POST['_wpnonce'], $_POST['user'], $_POST['delete'] ) ||
			'dodelete' !== $_REQUEST['action'] ||
			! is_array( $_POST['user'] ) ||
			! is_array( $_POST['delete'] ) ||
			// Core's handler rejects the request itself when the nonce is wrong.
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ms-users-delete' )
		) {
			return;
		}

		$heirs_to_reject = array();
		foreach ( wp_parse_id_list( wp_unslash( $_POST['user'] ) ) as $user_id ) {
			$heirs_to_reject = array_merge( $heirs_to_reject, Agent_Account::get_agent_ids( $user_id ) );
		}

		$heirs = isset( $_POST['blog'] ) && is_array( $_POST['blog'] ) ? wp_unslash( $_POST['blog'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are cast to integers below.
		foreach ( wp_unslash( $_POST['delete'] ) as $site_id => $site_choices ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared against a fixed value below.
			if ( ! is_array( $site_choices ) ) {
				continue;
			}

			foreach ( $site_choices as $user_id => $choice ) {
				if ( 'reassign' !== $choice ) {
					continue;
				}

				$heir = (int) ( $heirs[ $user_id ][ $site_id ] ?? 0 );
				if ( $heir > 0 && ! in_array( $heir, $heirs_to_reject, true ) ) {
					continue;
				}

				wp_die(
					$heir > 0
						? esc_html__( 'Content cannot be attributed to an agent of a user being deleted, because the agent is deleted too. Choose another user.', 'ai' )
						: esc_html__( 'No user was chosen to receive the content, or nobody else on the site can. Choose another user, or choose to delete the content.', 'ai' ),
					esc_html__( 'Choose another user', 'ai' ),
					array(
						'response'  => 400,
						'back_link' => true,
					)
				);
			}
		}
	}

	/**
	 * Keeps a deleted user's agents out of the reassignment user lists.
	 *
	 * Core excludes the users being deleted from the "Attribute all content
	 * to" list on the Delete Users screen, and lists only the remaining site
	 * users on the network one. Their agents are deleted too, so they are
	 * left out the same way.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $query_args  `WP_User_Query` arguments.
	 * @param array<string, mixed> $parsed_args `wp_dropdown_users()` arguments.
	 * @return array<string, mixed> Filtered query arguments.
	 */
	public function exclude_agents_from_reassignment( array $query_args, array $parsed_args ): array {
		$name = is_string( $parsed_args['name'] ?? null ) ? $parsed_args['name'] : '';

		// WordPress 7.1 renders one `reassign_user[ID]` list per deleted user; 7.0 a single one.
		if ( 1 === preg_match( '/^reassign_user(\[\d+\])?$/', $name ) ) {
			$deleted_ids = wp_parse_id_list( $parsed_args['exclude'] ?? array() );
		} elseif ( 1 === preg_match( '/^blog\[(\d+)\]\[\d+\]$/', $name, $matches ) ) {
			$deleted_ids = array( (int) $matches[1] );
		} else {
			return $query_args;
		}

		$agent_ids = array();
		foreach ( $deleted_ids as $deleted_id ) {
			$agent_ids = array_merge( $agent_ids, Agent_Account::get_agent_ids( $deleted_id ) );
		}

		if ( array() === $agent_ids ) {
			return $query_args;
		}

		// `WP_User_Query` ignores `exclude` when `include` is set.
		if ( ! empty( $query_args['include'] ) ) {
			$remaining = array_values( array_diff( wp_parse_id_list( $query_args['include'] ), $agent_ids ) );
			// An empty `include` lists every user, so a list of only agents becomes a list of nobody.
			$query_args['include'] = array() === $remaining ? array( 0 ) : $remaining;
		} else {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Extends core's own exclusion on the delete screen.
			$query_args['exclude'] = array_merge( wp_parse_id_list( $query_args['exclude'] ?? array() ), $agent_ids );
		}

		return $query_args;
	}

	/**
	 * Reports agent content when their parents are deleted.
	 *
	 * Core only offers to reassign content when the deleted users own some.
	 * Their agents are deleted along with them, so their content counts too;
	 * otherwise it would be deleted without the choice ever being offered.
	 *
	 * @since x.x.x
	 *
	 * @param bool       $has_content Whether the users have additional content.
	 * @param array<int> $user_ids    IDs of the users being deleted.
	 * @return bool True when the users or their agents own content.
	 */
	public function count_agent_content( $has_content, $user_ids ): bool {
		if ( $has_content || ! is_array( $user_ids ) ) {
			return (bool) $has_content;
		}

		$agent_ids = array();
		foreach ( $user_ids as $user_id ) {
			$agent_ids = array_merge( $agent_ids, Agent_Account::get_agent_ids( (int) $user_id ) );
		}

		if ( array() === $agent_ids ) {
			return false;
		}

		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $agent_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Mirrors core's own content check on the delete screen; placeholders are generated above.
		$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author IN ( {$placeholders} ) LIMIT 1", $agent_ids ) );
		$link_id = null === $post_id ? $wpdb->get_var( $wpdb->prepare( "SELECT link_id FROM {$wpdb->links} WHERE link_owner IN ( {$placeholders} ) LIMIT 1", $agent_ids ) ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return null !== $post_id || null !== $link_id;
	}

	/**
	 * Names the agents deleted along with their parents on the delete screen.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User  $current_user The user deleting accounts.
	 * @param array<int> $user_ids     IDs of the users being deleted.
	 */
	public function render_agents_deleted_with_parent( $current_user, $user_ids ): void {
		$user_ids = array_map( 'intval', (array) $user_ids );
		$agents   = array();
		foreach ( $user_ids as $user_id ) {
			foreach ( Agent_Account::get_agent_ids( $user_id ) as $agent_id ) {
				$agent = get_user_by( 'id', $agent_id );
				// Core already asks about agents deleted in their own right.
				if ( ! ( $agent instanceof WP_User ) || in_array( $agent_id, $user_ids, true ) ) {
					continue;
				}

				$agents[ $agent_id ] = array(
					'agent'     => $agent,
					'parent_id' => $user_id,
				);
			}
		}

		if ( array() === $agents ) {
			return;
		}

		$names = array_map(
			static function ( array $entry ): string {
				return $entry['agent']->user_login;
			},
			$agents
		);

		$message = wp_sprintf(
			/* translators: %l: Comma-separated list of agent usernames. */
			__( 'Their agents will be deleted too: %l.', 'ai' ),
			array_values( $names )
		);
		if ( is_network_admin() ) {
			// The choice is made per site there.
			$message .= ' ' . __( 'On their parents\' sites, agent content follows the choice made for the parent on that site.', 'ai' );
		} elseif ( $this->count_agent_content( false, $user_ids ) ) {
			$message .= ' ' . __( 'Agent content follows the choice above.', 'ai' );
		}

		echo '<p class="wpai-agents-deleted-with-parent">' . esc_html( $message ) . '</p>';

		if ( ! is_network_admin() ) {
			return;
		}

		$this->render_agent_content_on_other_sites( $agents, array_merge( $user_ids, array_keys( $agents ) ) );
	}

	/**
	 * Offers the content choice for agent sites the deleted parent does not belong to.
	 *
	 * The network delete screen only asks about the parent's own sites, so
	 * agent content elsewhere could not be reassigned. The fields reuse core's
	 * `delete[site][user]` and `blog[user][site]` names, so core's own handler
	 * validates and applies the choice before it deletes the accounts, exactly
	 * as it applies the choices for the parent. Like core, a site without
	 * content or without another user to receive it gets no choice.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array{agent: \WP_User, parent_id: int}> $agents   Agents being deleted, keyed by ID.
	 * @param array<int, int>                                      $excluded Users being deleted, who cannot receive content.
	 */
	private function render_agent_content_on_other_sites( array $agents, array $excluded ): void {
		global $wpdb;

		$rendered = false;
		foreach ( $agents as $agent_id => $entry ) {
			// Like core's own list, archived and spam sites are included, so the content there can be reassigned too.
			foreach ( array_keys( get_blogs_of_user( $agent_id, true ) ) as $site_id ) {
				$site_id = (int) $site_id;
				if ( Agent_Account::belongs_to_site( $entry['parent_id'], $site_id ) ) {
					continue;
				}

				switch_to_blog( $site_id ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Mirrors core's per-site content check on the network delete screen.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mirrors core's own content check on the delete screen.
				$has_content = null !== $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d LIMIT 1", $agent_id ) )
					|| null !== $wpdb->get_var( $wpdb->prepare( "SELECT link_id FROM {$wpdb->links} WHERE link_owner = %d LIMIT 1", $agent_id ) );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$site_users = $has_content ? get_users(
					array(
						'blog_id' => $site_id,
						'fields'  => 'ID',
						'exclude' => $excluded,
					)
				) : array();
				$site_name  = get_bloginfo( 'name' );
				restore_current_blog();

				if ( array() === $site_users ) {
					continue;
				}

				if ( ! $rendered ) {
					echo '<h2>' . esc_html__( 'Agent content on other sites', 'ai' ) . '</h2>';
					echo '<p>' . esc_html__( 'These agents own content on sites their parent does not belong to. Choose what happens to it.', 'ai' ) . '</p>';
					$rendered = true;
				}

				$this->render_agent_site_choice( $entry['agent'], $site_id, $site_name, array_map( 'intval', $site_users ) );
			}
		}
	}

	/**
	 * Renders core's delete-or-reassign choice for one agent on one site.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User        $agent      Agent being deleted.
	 * @param int             $site_id    Site ID.
	 * @param string          $site_name  Site name.
	 * @param array<int, int> $site_users Users on the site who can receive the content.
	 */
	private function render_agent_site_choice( WP_User $agent, int $site_id, string $site_name, array $site_users ): void {
		$id_suffix = $site_id . '_' . $agent->ID;
		$name      = sprintf( 'delete[%d][%d]', $site_id, $agent->ID );

		echo '<fieldset class="wpai-agent-site-content"><legend>';
		printf(
			/* translators: 1: Link to the site, 2: Agent username. */
			esc_html__( 'Site: %1$s, agent: %2$s', 'ai' ),
			'<a href="' . esc_url( get_home_url( $site_id ) ) . '">' . esc_html( $site_name ) . '</a>',
			'<strong>' . esc_html( $agent->user_login ) . '</strong>'
		);
		echo '</legend><ul><li>';
		printf( '<input type="radio" id="delete_option_%1$s" name="%2$s" value="delete" required />', esc_attr( $id_suffix ), esc_attr( $name ) );
		printf( '<label for="delete_option_%1$s">%2$s</label>', esc_attr( $id_suffix ), esc_html__( 'Delete all content.', 'ai' ) );
		echo '</li><li>';
		printf( '<input type="radio" id="reassign_option_%1$s" name="%2$s" value="reassign" required />', esc_attr( $id_suffix ), esc_attr( $name ) );
		printf( '<label for="reassign_option_%1$s">%2$s</label> ', esc_attr( $id_suffix ), esc_html__( 'Attribute all content to another user.', 'ai' ) );
		printf( '<label for="reassign_user_%1$s" class="screen-reader-text">%2$s</label>', esc_attr( $id_suffix ), esc_html__( 'Select a user to attribute the content to.', 'ai' ) );
		/*
		 * Like core's own lists on this screen, the first user is preselected:
		 * a "Select a user" placeholder would post -1, which WordPress 7.0's
		 * handler passes on as the new author without checking it.
		 */
		wp_dropdown_users(
			array(
				'name'    => sprintf( 'blog[%d][%d]', $agent->ID, $site_id ),
				'include' => $site_users,
				'show'    => 'display_name_with_login',
				'id'      => 'reassign_user_' . $id_suffix,
				'blog_id' => $site_id,
			)
		);
		echo '</li></ul></fieldset>';
	}
}
