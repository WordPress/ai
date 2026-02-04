<?php
/**
 * Service Account Manager.
 *
 * Core logic for managing service accounts. This class is designed to be
 * portable to WordPress Core with minimal modifications.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Service_Account;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages service account functionality.
 *
 * This class handles the core logic for service accounts including role management,
 * capability filtering, user queries, and integration with WordPress user systems.
 *
 * @since 0.3.0
 */
class Service_Account_Manager {
	/**
	 * The service account role slug.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const ROLE = 'service';

	/**
	 * The service account meta key identifier.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const META_KEY = '_service_account';

	/**
	 * Singleton instance.
	 *
	 * @since 0.3.0
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether the manager has been initialized.
	 *
	 * @since 0.3.0
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Gets the singleton instance.
	 *
	 * @since 0.3.0
	 *
	 * @return self The manager instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton pattern.
	 *
	 * @since 0.3.0
	 */
	private function __construct() {}

	/**
	 * Initializes the service account manager.
	 *
	 * @since 0.3.0
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		// Register the service account role.
		$this->register_role();

		// Register service account user meta.
		$this->register_user_meta();

		// Filter user capabilities for service accounts.
		add_filter( 'user_has_cap', array( $this, 'filter_capabilities' ), 10, 4 );

		// Ensure service role assignments are flagged as service accounts.
		add_action( 'set_user_role', array( $this, 'maybe_mark_service_account_role' ), 10, 3 );
		add_action( 'add_user_role', array( $this, 'maybe_mark_service_account_role' ), 10, 2 );

		// Exclude service accounts from standard user queries.
		add_action( 'pre_get_users', array( $this, 'filter_user_queries' ) );

		// Filter user counts to exclude service accounts.
		add_filter( 'pre_count_users', array( $this, 'filter_user_counts' ), 10, 3 );

		// Add views to users list table.
		add_filter( 'views_users', array( $this, 'add_users_views' ) );

		// Handle the service accounts view.
		add_action( 'pre_get_users', array( $this, 'handle_users_view' ) );

		$this->initialized = true;

		/**
		 * Fires after the service account manager is initialized.
		 *
		 * @since 0.3.0
		 *
		 * @param self $manager The manager instance.
		 */
		do_action( 'service_account_manager_init', $this );
	}

	/**
	 * Registers user meta used by service accounts.
	 *
	 * @since 0.3.0
	 */
	private function register_user_meta(): void {
		register_meta(
			'user',
			'service_account_owner_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_owner_id' ),
				'auth_callback'     => array( $this, 'authorize_service_account_meta' ),
				'show_in_rest'      => false,
			)
		);

		register_meta(
			'user',
			'service_account_system',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( $this, 'authorize_service_account_meta' ),
				'show_in_rest'      => false,
			)
		);

		register_meta(
			'user',
			'service_account_reference',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( $this, 'authorize_service_account_meta' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Marks users as service accounts when the service role is assigned.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $user_id   User ID.
	 * @param string $role      Role assigned.
	 * @param array  $old_roles Previous roles.
	 */
	public function maybe_mark_service_account_role( int $user_id, string $role, array $old_roles = array() ): void {
		if ( self::ROLE !== $role ) {
			return;
		}

		if ( ! get_user_meta( $user_id, self::META_KEY, true ) ) {
			update_user_meta( $user_id, self::META_KEY, true );
		}
	}

	/**
	 * Builds a service account meta clause.
	 *
	 * @since 0.3.0
	 *
	 * @param string $compare Compare operator.
	 * @return array<string, string> Meta clause.
	 */
	private function get_service_account_meta_clause( string $compare = 'EXISTS' ): array {
		return array(
			'key'     => self::META_KEY,
			'compare' => $compare,
		);
	}

	/**
	 * Appends a meta query clause while preserving existing conditions.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $existing Existing meta query.
	 * @param array $clause   Meta clause to append.
	 * @return array Combined meta query.
	 */
	private function append_meta_query( $existing, array $clause ): array {
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			return array( $clause );
		}

		return array(
			'relation' => 'AND',
			$existing,
			$clause,
		);
	}

	/**
	 * Sanitizes the service account owner ID.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed  $value       Meta value.
	 * @param string $meta_key    Meta key.
	 * @param string $object_type Object type.
	 * @return int Sanitized user ID, or 0 if invalid.
	 */
	public function sanitize_owner_id( $value, string $meta_key = '', string $object_type = '' ): int {
		$owner_id = absint( $value );

		if ( 0 === $owner_id ) {
			return 0;
		}

		return get_user_by( 'id', $owner_id ) ? $owner_id : 0;
	}

	/**
	 * Authorizes access to service account meta.
	 *
	 * @since 0.3.0
	 *
	 * @param bool   $allowed   Whether access is allowed.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Object ID.
	 * @param int    $user_id   User ID.
	 * @param string $cap       Capability name.
	 * @param array  $caps      User capabilities.
	 * @return bool Whether access is allowed.
	 */
	public function authorize_service_account_meta( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ): bool {
		$target_id = (int) $object_id;

		if ( ! $this->is_service_account( $target_id ) ) {
			return false;
		}

		return current_user_can( 'edit_user', $target_id );
	}

	/**
	 * Gets the default capabilities for the service account role.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, bool> Default capabilities.
	 */
	public function get_default_role_capabilities(): array {
		$capabilities = array(
			'read'          => true,
			'edit_posts'    => true,
			'delete_posts'  => false,
			'publish_posts' => false,
		);

		/**
		 * Filters the default capabilities for the service account role.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string, bool> $capabilities Default role capabilities.
		 */
		return apply_filters( 'service_account_default_role_capabilities', $capabilities );
	}

	/**
	 * Gets the list of restricted capabilities for service accounts.
	 *
	 * These capabilities are always denied to service accounts regardless of role.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string> List of restricted capability names.
	 */
	public function get_restricted_capabilities(): array {
		$restricted = array(
			// Site administration.
			'manage_options',
			'export',
			'import',

			// Plugin management.
			'install_plugins',
			'activate_plugins',
			'edit_plugins',
			'delete_plugins',
			'update_plugins',

			// Theme management.
			'install_themes',
			'switch_themes',
			'edit_themes',
			'delete_themes',
			'update_themes',

			// User management.
			'list_users',
			'edit_users',
			'delete_users',
			'create_users',
			'promote_users',
			'remove_users',

			// File and core management.
			'edit_files',
			'update_core',
			'unfiltered_html',
			'unfiltered_upload',
		);

		/**
		 * Filters the list of restricted capabilities for service accounts.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string> $restricted List of capability names to restrict.
		 */
		return apply_filters( 'service_account_restricted_capabilities', $restricted );
	}

	/**
	 * Registers the service account role.
	 *
	 * @since 0.3.0
	 */
	public function register_role(): void {
		$existing_role = get_role( self::ROLE );

		if ( $existing_role ) {
			/**
			 * Fires when the service account role already exists.
			 *
			 * @since 0.3.0
			 *
			 * @param \WP_Role $role The existing service account role.
			 */
			do_action( 'service_account_role_exists', $existing_role );
			return;
		}

		$capabilities = $this->get_default_role_capabilities();

		add_role(
			self::ROLE,
			__( 'Service', 'ai' ),
			$capabilities
		);

		/**
		 * Fires after the service account role is registered.
		 *
		 * @since 0.3.0
		 *
		 * @param \WP_Role            $role         The newly created role.
		 * @param array<string, bool> $capabilities The capabilities assigned to the role.
		 */
		do_action( 'service_account_role_registered', get_role( self::ROLE ), $capabilities );
	}

	/**
	 * Filters capabilities for service accounts.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, bool> $allcaps All capabilities of the user.
	 * @param array<string>       $caps    Required primitive capabilities.
	 * @param array<mixed>        $args    Arguments accompanying the capability check.
	 * @param \WP_User            $user    The user object.
	 * @return array<string, bool> Filtered capabilities.
	 */
	public function filter_capabilities( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( ! $this->is_service_account( $user ) ) {
			return $allcaps;
		}

		// Service accounts always have read capability.
		$allcaps['read'] = true;

		// Restrict dangerous capabilities.
		$restricted_caps = $this->get_restricted_capabilities();

		foreach ( $restricted_caps as $cap ) {
			$allcaps[ $cap ] = false;
		}

		/**
		 * Filters the final capabilities for a service account.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string, bool> $allcaps         All capabilities of the service account.
		 * @param \WP_User            $user            The service account object.
		 * @param array<string>       $caps            Required primitive capabilities for current check.
		 * @param array<string>       $restricted_caps Capabilities that were restricted.
		 */
		return apply_filters( 'service_account_capabilities', $allcaps, $user, $caps, $restricted_caps );
	}

	/**
	 * Checks if a user is a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_User|int $user User object or ID.
	 * @return bool True if the user is a service account.
	 */
	public function is_service_account( $user ): bool {
		if ( is_int( $user ) ) {
			$user = get_user_by( 'id', $user );
		}

		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		// Check meta flag (persists even if role changes).
		$is_service_account = (bool) get_user_meta( $user->ID, self::META_KEY, true );

		// Fall back to role for legacy accounts missing meta.
		if ( ! $is_service_account && in_array( self::ROLE, $user->roles, true ) ) {
			$is_service_account = true;
		}

		/**
		 * Filters whether a user is considered a service account.
		 *
		 * @since 0.3.0
		 *
		 * @param bool     $is_service_account Whether the user is a service account.
		 * @param \WP_User $user               The user object.
		 */
		return apply_filters( 'is_service_account', $is_service_account, $user );
	}

	/**
	 * Filters user queries to exclude service accounts by default.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_User_Query $query The user query.
	 */
	public function filter_user_queries( \WP_User_Query $query ): void {
		// Allow explicit inclusion of service accounts.
		if ( $query->get( 'include_service_accounts' ) ) {
			return;
		}

		// Don't filter if explicitly querying for service accounts.
		$role = $query->get( 'role' );
		if ( self::ROLE === $role ) {
			return;
		}

		// Don't filter in the admin users list when viewing service accounts.
		if ( is_admin() && isset( $_GET['service_accounts'] ) && '1' === $_GET['service_accounts'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Include service accounts in the main users list table.
		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && 'users' === $screen->base ) {
				return;
			}
		}

		// Exclude service accounts from default queries.
		$role__not_in = $query->get( 'role__not_in' );
		if ( ! is_array( $role__not_in ) ) {
			$role__not_in = array();
		}
		$role__not_in[] = self::ROLE;
		$query->set( 'role__not_in', array_unique( $role__not_in ) );

		$meta_query = $this->append_meta_query(
			$query->get( 'meta_query' ),
			$this->get_service_account_meta_clause( 'NOT EXISTS' )
		);
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Filters user counts to exclude service accounts from the main count.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, int>|null $result  The count result, or null to calculate.
	 * @param string                  $strategy The counting strategy.
	 * @param int|null                $site_id  The site ID, or null for current site.
	 * @return array<string, int>|null Modified count or null.
	 */
	public function filter_user_counts( $result, string $strategy, ?int $site_id ) {
		// Only filter if we need to calculate.
		if ( null !== $result ) {
			return $result;
		}

		global $wpdb;

		$blog_id = $site_id ?? get_current_blog_id();
		$cap_key = is_multisite() ? $wpdb->get_blog_prefix( $blog_id ) . 'capabilities' : $wpdb->prefix . 'capabilities';

		// Get all counts including service accounts.
		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value, COUNT(*) AS count
				FROM {$wpdb->usermeta}
				WHERE meta_key = %s
				GROUP BY meta_value",
				$cap_key
			),
			ARRAY_A
		);

		// Get counts for service accounts based on meta flag.
		$service_result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT um_cap.meta_value, COUNT(*) AS count
				FROM {$wpdb->usermeta} um_cap
				INNER JOIN {$wpdb->usermeta} um_sa
					ON um_cap.user_id = um_sa.user_id
				WHERE um_cap.meta_key = %s
					AND um_sa.meta_key = %s
				GROUP BY um_cap.meta_value",
				$cap_key,
				self::META_KEY
			),
			ARRAY_A
		);

		$service_account_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id)
				FROM {$wpdb->usermeta}
				WHERE meta_key = %s",
				self::META_KEY
			)
		);

		$avail_roles            = array();
		$total_users            = 0;
		$include_service_in_all = false;

		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && 'users' === $screen->base ) {
				$include_service_in_all = true;
			}
		}

		if ( $result ) {
			foreach ( $result as $row ) {
				$role_data = maybe_unserialize( $row['meta_value'] );
				if ( ! is_array( $role_data ) ) {
					continue;
				}

				$count = (int) $row['count'];

				foreach ( array_keys( $role_data ) as $role_name ) {
					if ( ! isset( $avail_roles[ $role_name ] ) ) {
						$avail_roles[ $role_name ] = 0;
					}
					$avail_roles[ $role_name ] += $count;
					$total_users += $count;
				}
			}
		}

		if ( $service_result ) {
			foreach ( $service_result as $row ) {
				$role_data = maybe_unserialize( $row['meta_value'] );
				if ( ! is_array( $role_data ) ) {
					continue;
				}

				$count = (int) $row['count'];

				foreach ( array_keys( $role_data ) as $role_name ) {
					if ( isset( $avail_roles[ $role_name ] ) ) {
						$avail_roles[ $role_name ] = max( 0, $avail_roles[ $role_name ] - $count );
					}
					$total_users -= $count;
				}
			}
		}

		foreach ( $avail_roles as $role_name => $count ) {
			if ( $count <= 0 ) {
				unset( $avail_roles[ $role_name ] );
			}
		}

		if ( $total_users < 0 ) {
			$total_users = 0;
		}

		if ( $include_service_in_all ) {
			$total_users += $service_account_count;
		}

		// Store service account count for the views filter.
		$this->service_account_count = max( 0, $service_account_count );

		return array(
			'total_users' => $total_users,
			'avail_roles' => $avail_roles,
		);
	}

	/**
	 * Cached service account count from filter_user_counts.
	 *
	 * @since 0.3.0
	 * @var int
	 */
	private int $service_account_count = 0;

	/**
	 * Adds a "Service Accounts" view to the users list table.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, string> $views The current views.
	 * @return array<string, string> Modified views.
	 */
	public function add_users_views( array $views ): array {
		$count = $this->get_service_account_count();

		if ( 0 === $count ) {
			return $views;
		}

		$current = isset( $_GET['service_accounts'] ) && '1' === $_GET['service_accounts']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$class   = $current ? 'current' : '';

		$views['service_accounts'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
			esc_url( admin_url( 'users.php?service_accounts=1' ) ),
			esc_attr( $class ),
			esc_html__( 'Service Accounts', 'ai' ),
			number_format_i18n( $count )
		);

		return $views;
	}

	/**
	 * Handles the service accounts view in the users list.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_User_Query $query The user query.
	 */
	public function handle_users_view( \WP_User_Query $query ): void {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['service_accounts'] ) || '1' !== $_GET['service_accounts'] ) {
			return;
		}

		// Override the query to show only service accounts.
		$query->set(
			'meta_query',
			$this->append_meta_query(
				$query->get( 'meta_query' ),
				$this->get_service_account_meta_clause( 'EXISTS' )
			)
		);
		$query->set( 'role', '' );
		$query->set( 'role__not_in', array() );
		$query->set( 'include_service_accounts', true );
	}

	/**
	 * Gets the count of service accounts.
	 *
	 * @since 0.3.0
	 *
	 * @return int The number of service accounts.
	 */
	public function get_service_account_count(): int {
		// Use cached count if available.
		if ( $this->service_account_count > 0 ) {
			return $this->service_account_count;
		}

		$query = new \WP_User_Query(
			array(
				'fields'                   => 'ID',
				'number'                   => 1,
				'count_total'              => true,
				'include_service_accounts' => true,
				'meta_query'               => array(
					$this->get_service_account_meta_clause( 'EXISTS' ),
				),
			)
		);

		$this->service_account_count = (int) $query->get_total();

		return $this->service_account_count;
	}

	/**
	 * Gets all service accounts.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $args Optional. Query arguments.
	 * @return array<\WP_User> Array of service account objects.
	 */
	public function get_service_accounts( array $args = array() ): array {
		$defaults = array(
			'include_service_accounts' => true,
			'orderby'                  => 'registered',
			'order'                    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );
		$args['meta_query'] = $this->append_meta_query(
			$args['meta_query'] ?? array(),
			$this->get_service_account_meta_clause( 'EXISTS' )
		);

		return get_users( $args );
	}

	/**
	 * Creates a new service account.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $args {
	 *     User data arguments.
	 *
	 *     @type string $name        Required. Human-readable name for the service account (e.g., "Claude Code").
	 *     @type string $description Optional. Description of the service account's purpose.
	 * }
	 * @return \WP_User|\WP_Error The created user object or error.
	 */
	public function create_service_account( array $args ) {
		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_required_fields',
				__( 'Name is required.', 'ai' )
			);
		}

		$name        = sanitize_text_field( $args['name'] );
		$description = isset( $args['description'] ) ? sanitize_text_field( $args['description'] ) : '';

		// Generate username: service-{sanitized-name}-{short-uuid}.
		$sanitized_name = sanitize_title( $name );
		$short_uuid     = substr( wp_generate_uuid4(), 0, 8 );
		$username       = 'service-' . $sanitized_name . '-' . $short_uuid;

		// Ensure username fits within WP limits (60 chars).
		if ( strlen( $username ) > 60 ) {
			$username = substr( $username, 0, 50 ) . '-' . $short_uuid;
		}

		// Generate email: {username}@{site-domain}.
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';
		$email       = $username . '@' . $site_domain;

		$display_name = $name;

		// Generate a secure random password.
		$password = wp_generate_password( 32, true, true );

		/**
		 * Filters the service account data before creation.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string, mixed> $user_data User data for wp_insert_user.
		 * @param array<string, mixed> $args      Original arguments.
		 */
		$user_data = apply_filters(
			'service_account_pre_create',
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'role'         => self::ROLE,
				'description'  => $description,
				'display_name' => $display_name,
			),
			$args
		);

		$user_id = wp_insert_user( $user_data );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Mark as service account via meta.
		update_user_meta( $user_id, self::META_KEY, true );

		// Store creation timestamp.
		update_user_meta( $user_id, '_service_account_created', time() );

		$user = get_user_by( 'id', $user_id );

		/**
		 * Fires after a service account is created.
		 *
		 * @since 0.3.0
		 *
		 * @param \WP_User    $user         The created service account.
		 * @param string|null $app_password The application password if generated, null otherwise.
		 */
		do_action( 'service_account_created', $user, null );

		return $user;
	}

	/**
	 * Deletes a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param int      $user_id  The user ID to delete.
	 * @param int|null $reassign Optional. User ID to reassign content to.
	 * @return bool|\WP_Error True on success, error on failure.
	 */
	public function delete_service_account( int $user_id, ?int $reassign = null ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user || ! $this->is_service_account( $user ) ) {
			return new \WP_Error(
				'not_found',
				__( 'Service account not found.', 'ai' )
			);
		}

		/**
		 * Fires before a service account is deleted.
		 *
		 * @since 0.3.0
		 *
		 * @param \WP_User $user     The service account being deleted.
		 * @param int|null $reassign User ID content will be reassigned to.
		 */
		do_action( 'service_account_before_delete', $user, $reassign );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$result = wp_delete_user( $user_id, $reassign );

		if ( ! $result ) {
			return new \WP_Error(
				'delete_failed',
				__( 'Failed to delete service account.', 'ai' )
			);
		}

		/**
		 * Fires after a service account is deleted.
		 *
		 * @since 0.3.0
		 *
		 * @param int      $user_id  The deleted user ID.
		 * @param int|null $reassign User ID content was reassigned to.
		 */
		do_action( 'service_account_deleted', $user_id, $reassign );

		return true;
	}

	/**
	 * Gets a service account by ID.
	 *
	 * @since 0.3.0
	 *
	 * @param int $user_id The user ID.
	 * @return \WP_User|\WP_Error The user object or error.
	 */
	public function get_service_account( int $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user || ! $this->is_service_account( $user ) ) {
			return new \WP_Error(
				'not_found',
				__( 'Service account not found.', 'ai' )
			);
		}

		return $user;
	}

	/**
	 * Updates a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param int                  $user_id The user ID to update.
	 * @param array<string, mixed> $args    User data to update.
	 * @return \WP_User|\WP_Error The updated user or error.
	 */
	public function update_service_account( int $user_id, array $args ) {
		$user = $this->get_service_account( $user_id );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$user_data = array( 'ID' => $user_id );

		if ( isset( $args['description'] ) ) {
			$user_data['description'] = sanitize_text_field( $args['description'] );
		}

		if ( isset( $args['display_name'] ) ) {
			$user_data['display_name'] = sanitize_text_field( $args['display_name'] );
		}

		/**
		 * Filters the service account data before update.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string, mixed> $user_data User data for wp_update_user.
		 * @param \WP_User             $user      The current user object.
		 * @param array<string, mixed> $args      Original arguments.
		 */
		$user_data = apply_filters( 'service_account_pre_update', $user_data, $user, $args );

		$result = wp_update_user( $user_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated_user = get_user_by( 'id', $user_id );

		/**
		 * Fires after a service account is updated.
		 *
		 * @since 0.3.0
		 *
		 * @param \WP_User             $user The updated service account.
		 * @param array<string, mixed> $args The update arguments.
		 */
		do_action( 'service_account_updated', $updated_user, $args );

		return $updated_user;
	}

	/**
	 * Regenerates the application password for a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $user_id The user ID.
	 * @param string $name    Optional. Name for the new app password.
	 * @return array<string, mixed>|\WP_Error App password data or error.
	 */
	public function regenerate_app_password( int $user_id, string $name = '' ) {
		$user = $this->get_service_account( $user_id );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new \WP_Error(
				'not_supported',
				__( 'Application Passwords are not available.', 'ai' )
			);
		}

		if ( empty( $name ) ) {
			$name = sprintf(
				/* translators: %s: Date and time */
				__( 'Regenerated %s', 'ai' ),
				wp_date( 'Y-m-d H:i:s' )
			);
		}

		$app_password_data = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => $name )
		);

		if ( is_wp_error( $app_password_data ) ) {
			return $app_password_data;
		}

		/**
		 * Fires after an application password is regenerated for a service account.
		 *
		 * @since 0.3.0
		 *
		 * @param \WP_User $user     The service account.
		 * @param array    $app_data The application password data.
		 */
		do_action( 'service_account_app_password_regenerated', $user, $app_password_data );

		return array(
			'password' => $app_password_data[0],
			'uuid'     => $app_password_data[1]['uuid'],
			'name'     => $name,
		);
	}
}
