<?php
/**
 * Agent account capability rules.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Bounds what agents can do.
 *
 * An agent can never do more than its parent currently can, cannot manage
 * the site or change other users, and cannot write unfiltered HTML. Parents
 * manage their agents' profiles, and agent and parent share their posts.
 *
 * @since x.x.x
 */
final class Agent_Capabilities {
	/**
	 * Capabilities agents never use, because they change other users.
	 *
	 * See `keep_agents_out_of_other_users()`.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, string>
	 */
	public const USER_MANAGEMENT_CAPS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- A list of capabilities, same pattern as Experiments::EXPERIMENT_CLASSES.
		'edit_users',
		'promote_users',
		'remove_users',
		'delete_users',
		'manage_network_users',
		'manage_sites',
	);

	/**
	 * Hooks the rule deciding who may have agents into WordPress.
	 *
	 * Unlike the rules in `register()`, it is needed before the first agent
	 * exists, to choose that agent's parent.
	 *
	 * @since x.x.x
	 */
	public static function register_parent_capability(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_default_parent_capability' ), 10, 4 );
	}

	/**
	 * Hooks the capability rules into WordPress.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_filter( 'rest_request_before_callbacks', array( $this, 'reject_site_management_roles_over_rest' ), 10, 3 );
		add_filter( 'editable_roles', array( $this, 'keep_site_management_roles_from_agents' ) );
		add_filter( 'map_meta_cap', array( $this, 'strip_unfiltered_html_from_agents' ), 10, 3 );
		add_filter( 'map_meta_cap', array( $this, 'map_parent_user_management' ), 10, 4 );
		add_filter( 'map_meta_cap', array( $this, 'share_post_ownership' ), 10, 4 );
		add_filter( 'map_meta_cap', array( $this, 'keep_agents_out_of_other_users' ), PHP_INT_MAX - 1, 3 );
		// Runs last so no other mapping can lift an agent above its parent.
		add_filter( 'map_meta_cap', array( $this, 'limit_agents_to_parent' ), PHP_INT_MAX, 4 );
		add_filter( 'auth_user_meta_' . Agent_Account::META_KEY, '__return_false' );
		add_filter( 'auth_user_meta_' . Agent_Account::META_PARENT, '__return_false' );
	}

	/**
	 * Grants the parent capability by default and never to agents.
	 *
	 * Humans without an explicit grant or denial for `PARENT_CAP` may have
	 * agents when they can `edit_posts`, so site owners opt roles or users out
	 * (or in) with any role editor.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, bool> $allcaps Capabilities the user has.
	 * @param array<int, string>  $caps    Primitive capabilities being checked.
	 * @param array<int, mixed>   $args    Original `has_cap()` arguments.
	 * @param \WP_User            $user    The user being checked.
	 * @return array<string, bool> Filtered capabilities.
	 */
	public static function grant_default_parent_capability( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( ! in_array( Agent_Account::PARENT_CAP, $caps, true ) ) {
			return $allcaps;
		}

		if ( Agent_Account::is_agent( $user ) ) {
			$allcaps[ Agent_Account::PARENT_CAP ] = false;
		} elseif ( ! isset( $allcaps[ Agent_Account::PARENT_CAP ] ) ) {
			$allcaps[ Agent_Account::PARENT_CAP ] = ! empty( $allcaps['edit_posts'] );
		}

		return $allcaps;
	}

	/**
	 * Strips `unfiltered_html` from agents.
	 *
	 * Some roles agents may hold carry `unfiltered_html`, most notably Editor
	 * on single-site installations. For an agent that default is unsafe:
	 * model output stored with it becomes stored XSS. Removing the capability
	 * reinstates core's KSES filtering on content paths that use it.
	 *
	 * The check matches the resolved primitive instead of the requested
	 * capability name, so meta capabilities that core resolves to
	 * `unfiltered_html`, such as `edit_css`, are covered as well. When core has
	 * already denied the capability, on multisite or under
	 * `DISALLOW_UNFILTERED_HTML`, the result is the same.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function strip_unfiltered_html_from_agents( array $caps, string $cap, int $user_id ): array {
		if ( ! in_array( 'unfiltered_html', $caps, true ) || ! Agent_Account::is_agent( $user_id ) ) {
			return $caps;
		}

		return array( 'do_not_allow' );
	}

	/**
	 * Limits every agent to what its parent can currently do.
	 *
	 * The parent is checked for the same capability with the same arguments,
	 * such as the post being edited, so object-specific rules that apply to the
	 * parent also bound the agent. An agent's authority is therefore both its
	 * own role and its parent's current permissions, and demoting the parent
	 * narrows their agents immediately. Suspended agents, see `Agent_Account::is_suspended()`,
	 * are denied everything, including operations core allows without any
	 * capability, such as editing their own profile and Application Passwords.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @param array<int, mixed>  $args    Additional arguments, such as an object ID.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function limit_agents_to_parent( array $caps, string $cap, int $user_id, array $args ): array {
		if ( in_array( 'do_not_allow', $caps, true ) || ! Agent_Account::is_agent( $user_id ) ) {
			return $caps;
		}

		$parent = Agent_Account::get_parent( $user_id );
		if ( null === $parent || Agent_Account::is_suspended( $user_id ) ) {
			return array( 'do_not_allow' );
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Checking the parent for the capability the agent is checked for.
		if ( ! user_can( $parent, $cap, ...$args ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Keeps agents from changing any account but their own.
	 *
	 * Whatever their role, agents cannot edit, promote, remove, or delete
	 * other users, including assigning roles to accounts they create. Core
	 * maps those actions on another user to `edit_users`, `promote_users`,
	 * `remove_users`, and `delete_users`, so denying these covers every
	 * target. On multisite, the network screens also let `manage_network_users`
	 * mark users as spam and `manage_sites` add users to sites with any role,
	 * so those are denied too. Editing their own profile, including their own
	 * Application Passwords, maps to no capability and stays available;
	 * changing their own role needs `promote_users` and does not. It runs after
	 * every other mapping but the parent ceiling, so no later mapping to these
	 * capabilities escapes it.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function keep_agents_out_of_other_users( array $caps, string $cap, int $user_id ): array {
		if (
			array() === array_intersect( $caps, self::USER_MANAGEMENT_CAPS ) ||
			! Agent_Account::is_agent( $user_id )
		) {
			return $caps;
		}

		return array( 'do_not_allow' );
	}

	/**
	 * Maps user management between agents and their parents.
	 *
	 * Parents can edit their agents' profiles, which covers managing their
	 * Application Passwords, without holding `edit_users`. Changing an agent's
	 * role still requires core's `promote_user`. Agents can never edit, promote,
	 * remove, or delete their own parent, whatever their role.
	 *
	 * @todo Needs evaluation and discussion: on multisite, core only lets
	 *       users with `manage_network_users` edit other users, and every
	 *       other path to an agent follows that rule. Mapping the parent's access to
	 *       `PARENT_CAP` deliberately bypasses it so parents can manage their
	 *       own agents' credentials on every install. This differs from the
	 *       core permission model and should be decided with maintainers.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @param array<int, mixed>  $args    Additional arguments, starting with the target user ID.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function map_parent_user_management( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, array( 'edit_user', 'promote_user', 'remove_user', 'delete_user' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$target_id = (int) $args[0];

		$parent = Agent_Account::get_parent( $user_id );
		if ( null !== $parent && $parent->ID === $target_id ) {
			return array( 'do_not_allow' );
		}

		$target_parent = 'edit_user' === $cap ? Agent_Account::get_parent( $target_id ) : null;
		if ( null === $target_parent || $target_parent->ID !== $user_id ) {
			return $caps;
		}

		return array( Agent_Account::PARENT_CAP );
	}

	/**
	 * Lets an agent and its parent treat each other's posts as their own.
	 *
	 * The agent acts on behalf of its parent, so each may edit, delete, and
	 * read the other's posts under the same rules that apply to their own
	 * posts, for example `edit_published_posts` for published ones. Anything
	 * about "others' posts" or "private posts" is swapped for its own-post
	 * counterpart, and a trashed post that was published keeps requiring the
	 * published-post capability, exactly as core maps the author's own post.
	 * Every other requirement core resolved is kept. Siblings, agents sharing
	 * a parent, are not linked to each other.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @param array<int, mixed>  $args    Additional arguments, starting with the post ID.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function share_post_ownership( array $caps, string $cap, int $user_id, array $args ): array {
		// Post meta capabilities whose mapping depends on the post author.
		$author_dependent = array( 'edit_post', 'edit_page', 'delete_post', 'delete_page', 'read_post', 'read_page' );
		if ( ! in_array( $cap, $author_dependent, true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );
		// Core checks a revision against the post it belongs to.
		if ( $post instanceof WP_Post && 'revision' === $post->post_type ) {
			$post = get_post( $post->post_parent );
		}
		if ( ! $post instanceof WP_Post ) {
			return $caps;
		}

		$author_id = (int) $post->post_author;
		if ( $author_id === $user_id || ! self::are_linked( $user_id, $author_id ) ) {
			return $caps;
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type instanceof WP_Post_Type || ! $post_type->map_meta_cap ) {
			return $caps;
		}

		// Like core for the author, a trashed post that was published still counts as published.
		$was_published = 'trash' === $post->post_status &&
			in_array( get_post_meta( $post->ID, '_wp_trash_meta_status', true ), array( 'publish', 'future' ), true );

		$own_counterparts = array(
			$post_type->cap->edit_others_posts    => $was_published ? $post_type->cap->edit_published_posts : $post_type->cap->edit_posts,
			$post_type->cap->edit_private_posts   => $post_type->cap->edit_posts,
			$post_type->cap->delete_others_posts  => $was_published ? $post_type->cap->delete_published_posts : $post_type->cap->delete_posts,
			$post_type->cap->delete_private_posts => $post_type->cap->delete_posts,
			$post_type->cap->read_private_posts   => $post_type->cap->read,
		);

		$caps = array_map(
			static function ( string $required ) use ( $own_counterparts ): string {
				return $own_counterparts[ $required ] ?? $required;
			},
			$caps
		);

		return array_values( array_unique( $caps ) );
	}

	/**
	 * Refuses REST requests that give an agent a role managing the site.
	 *
	 * @since x.x.x
	 *
	 * @param mixed                $response Response to replace the handler's, or null.
	 * @param array<string, mixed> $handler  Route handler.
	 * @param \WP_REST_Request     $request  The request.
	 * @return mixed The response, or an error for such role changes.
	 */
	public function reject_site_management_roles_over_rest( $response, array $handler, \WP_REST_Request $request ) {
		if (
			null !== $response ||
			'GET' === $request->get_method() ||
			! is_array( $request->get_param( 'roles' ) ) ||
			1 !== preg_match( '#^/wp/v2/users/(\d+|me)$#', $request->get_route(), $matches )
		) {
			return $response;
		}

		$user_id = 'me' === $matches[1] ? get_current_user_id() : (int) $matches[1];
		if ( ! Agent_Account::is_agent( $user_id ) ) {
			return $response;
		}

		foreach ( $request->get_param( 'roles' ) as $role ) {
			if ( is_string( $role ) && Agent_Account::role_manages_site( $role ) ) {
				return new WP_Error(
					'wpai_agent_role_not_allowed',
					__( 'Agents cannot have a role that manages the site, such as Administrator.', 'ai' ),
					array( 'status' => 403 )
				);
			}
		}

		return $response;
	}

	/**
	 * Leaves roles that manage the site out of an agent's profile.
	 *
	 * Core uses the editable roles both for the role list on the user editing
	 * screen and to validate the submitted role, so an agent cannot be given
	 * such a role there. The bulk role change on the Users screen and WP-CLI
	 * have no per-user check; `Agent_Account::is_suspended()` covers agents given one anyway.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $roles Editable roles keyed by slug.
	 * @return mixed The roles, without those managing the site when editing an agent.
	 */
	public function keep_site_management_roles_from_agents( $roles ) {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which profile is shown or saved; core verifies the save.
		$user_id = isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0;
		if ( ! is_array( $roles ) || 'user-edit.php' !== $pagenow || ! Agent_Account::is_agent( $user_id ) ) {
			return $roles;
		}

		return array_filter(
			$roles,
			static function ( $role ): bool {
				return ! Agent_Account::role_manages_site( (string) $role );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Checks whether one user is the other's parent.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id  One user ID.
	 * @param int $other_id The other user ID.
	 * @return bool True when either is the other's agent.
	 */
	private static function are_linked( int $user_id, int $other_id ): bool {
		$parent = Agent_Account::get_parent( $user_id );
		if ( null !== $parent && $parent->ID === $other_id ) {
			return true;
		}

		$parent = Agent_Account::get_parent( $other_id );

		return null !== $parent && $parent->ID === $user_id;
	}
}
