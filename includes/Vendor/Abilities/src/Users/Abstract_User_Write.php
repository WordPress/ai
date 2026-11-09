<?php
/**
 * Canonical WordPress abilities: shared base for the user write abilities.
 *
 * Ported from WordPress/ai PR #1105 (https://github.com/WordPress/ai/pull/1105) by @jorgefilipecosta.
 *
 * The helpers shared with this package's `Users_Query` port (the `core/users-query`
 * core-7.2 backport, PR #3) — the field set definitions, the field normalization, and
 * the user field projection — are extracted verbatim from that class as `protected`
 * copies: `Users_Query` is tracked by the coreify round-trip gate (a `core/*`
 * tier-core backport diffed against core trunk's class) and must stay byte-exact, so
 * it is shared by copy rather than by inheritance. `format_user()` gains the
 * `$edit_context` parameter the write path needs, ported verbatim from the PR's
 * write-side edition. The write logic itself (create, update, delete, the
 * roles/reassign rules) is a faithful port of the PR: upstream names the abilities
 * `core/user-*`; this package ships them under the experimental tier's `wp/`
 * prefix (see NAME-LIFECYCLE.md), with the `default` text domain and registration
 * owned by the package's Registrar.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Users;

use stdClass;
use WP_Error;
use WP_User;

use WordPress\Abilities\Abstract_Core_Ability;

/**
 * Shared base for the `wp/user-create`, `wp/user-update`, and `wp/user-delete`
 * abilities.
 *
 * A written user is answered through the same field projection as
 * `core/users-query`, in the edit context (write answers do not depend on edit
 * access, because the permission callbacks already require it).
 *
 * @package WordPress\Abilities
 */
abstract class Abstract_User_Write extends Abstract_Core_Ability {

	/**
	 * The ability category used for user abilities.
	 *
	 * @var string
	 */
	protected const CATEGORY = 'user';

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @var list<string>
	 */
	protected const DEFAULT_FIELDS = array(
		'id',
		'name',
		'description',
		'url',
		'link',
		'slug',
		'avatar_urls',
	);

	/**
	 * The canonical ability name, with its tier prefix.
	 *
	 * @return string
	 */
	abstract protected function ability_name(): string;

	/**
	 * Normalizes a mixed value into a list of user fields.
	 *
	 * A verbatim copy of this package's `Users_Query` port (the `core/users-query`
	 * core-7.2 backport, PR #3) — see the class docblock for why it is a copy.
	 *
	 * @param array<mixed> $input The ability input.
	 * @return list<string> The requested field names, with `id` always first.
	 */
	protected function normalize_fields( array $input ): array {
		$fields = isset( $input['fields'] ) ? $this->normalize_string_list( $input['fields'] ) : array();
		if ( array() === $fields ) {
			$fields = self::DEFAULT_FIELDS;
		}

		if ( ! in_array( 'id', $fields, true ) ) {
			array_unshift( $fields, 'id' );
		}

		return $fields;
	}

	/**
	 * Normalizes a mixed value into a list of strings.
	 *
	 * A verbatim copy of this package's `Users_Query` port (see the class docblock).
	 *
	 * @param mixed $value Raw value.
	 * @return list<string> Unique non-empty strings.
	 */
	protected function normalize_string_list( $value ): array {
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_list( $value ), 'is_string' ) );
	}

	/**
	 * Returns the user field definitions, keyed by field name in output order.
	 *
	 * A verbatim copy of this package's `Users_Query` port (see the class docblock).
	 *
	 * @return array<string, array<string, mixed>> Field definitions.
	 */
	protected function get_user_properties(): array {
		return array(
			'id'              => array(
				'type'        => 'integer',
				'description' => __( 'The user ID.' ),
			),
			'name'            => array(
				'type'        => 'string',
				'description' => __( 'The display name for the user.' ),
			),
			'description'     => array(
				'type'        => 'string',
				'description' => __( 'Description of the user. Present when the current user can view it.' ),
			),
			'url'             => array(
				/*
				 * Unlike the REST users controller, `url` declares no `uri` format. It is
				 * empty for users without a website, and clients that check formats,
				 * such as the abilities JS client when it re-validates the output, would
				 * reject the empty string and fail the whole call.
				 */
				'type'        => 'string',
				'description' => __( 'URL of the user. Present when the current user can view it.' ),
			),
			'link'            => array(
				'type'        => 'string',
				'format'      => 'uri',
				'description' => __( 'Author archive URL for the user.' ),
			),
			'slug'            => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the user.' ),
			),
			'avatar_urls'     => array(
				'type'                 => 'object',
				'description'          => __( 'Avatar URLs for the user, keyed by image size in pixels. A size is null when no avatar URL can be resolved for it. Present when the show_avatars option is enabled.' ),
				'additionalProperties' => array(
					'type'   => array( 'string', 'null' ),
					'format' => 'uri',
				),
			),
			'username'        => array(
				'type'        => 'string',
				'description' => __( 'Login name for the user. Present when the current user can view it.' ),
			),
			'email'           => array(
				'type'        => array( 'string', 'null' ),
				'format'      => 'email',
				'description' => __( 'The email address for the user. Null when the user has no stored address, or when the stored address is not a valid email. Present when the current user can view it.' ),
			),
			'first_name'      => array(
				'type'        => 'string',
				'description' => __( 'First name for the user. Present when the current user can view it.' ),
			),
			'last_name'       => array(
				'type'        => 'string',
				'description' => __( 'Last name for the user. Present when the current user can view it.' ),
			),
			'nickname'        => array(
				'type'        => 'string',
				'description' => __( 'The nickname for the user. Present when the current user can view it.' ),
			),
			'locale'          => array(
				'type'        => 'string',
				'description' => __( 'Locale for the user. Present when the current user can view it.' ),
			),
			'registered_date' => array(
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'description' => __( 'Registration date for the user. Null when the stored date is not a valid date. Present when the current user can view it.' ),
			),
			'roles'           => array(
				'type'        => 'array',
				'description' => __( 'Roles assigned to the user. Present when the current user can view them.' ),
				/*
				 * Output roles are not pinned to an enum. The schema is a
				 * registration-time snapshot, but a role can be registered after
				 * registration and still be held by a returned user; a snapshot enum
				 * would reject that legitimate value during output validation and
				 * fail the whole call. This also matches the REST users controller,
				 * whose `roles` output items are plain strings.
				 */
				'items'       => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Whether the given user's record is viewed by its own account.
	 *
	 * @param WP_User $user The user.
	 * @return bool Whether the record is viewed by its own account.
	 */
	protected function is_current_user( WP_User $user ): bool {
		return get_current_user_id() === $user->ID;
	}

	/**
	 * Projects a user into the answer, through the `core/users-query` field
	 * definitions.
	 *
	 * A copy of this package's `Users_Query` port with the `$edit_context`
	 * parameter the write path needs, ported verbatim from WordPress/ai PR
	 * #1105's write-side edition (see the class docblock).
	 *
	 * @param WP_User              $user         The user.
	 * @param list<string>         $fields       The requested field names.
	 * @param bool                 $edit_context Whether the answer is a write's edit-context answer.
	 * @return array<string, mixed> The user fields.
	 */
	protected function format_user( WP_User $user, array $fields, bool $edit_context = false ): array {
		$fields_requested = static function ( string $field ) use ( $fields ): bool {
			return in_array( $field, $fields, true );
		};

		$can_view_sensitive = $edit_context || $this->is_current_user( $user ) || current_user_can( 'edit_user', $user->ID );

		$data = array();

		if ( $fields_requested( 'id' ) ) {
			$data['id'] = $user->ID;
		}
		if ( $fields_requested( 'name' ) ) {
			$data['name'] = (string) $user->display_name;
		}
		if ( $fields_requested( 'description' ) ) {
			$data['description'] = (string) $user->description;
		}
		if ( $fields_requested( 'url' ) ) {
			$data['url'] = (string) $user->user_url;
		}
		if ( $fields_requested( 'link' ) ) {
			$data['link'] = (string) get_author_posts_url( $user->ID, $user->user_nicename );
		}
		if ( $fields_requested( 'slug' ) ) {
			$data['slug'] = (string) $user->user_nicename;
		}
		/*
		 * The schemas always declare avatar_urls; availability is enforced here,
		 * since the option can change after the schemas are registered.
		 */
		if ( $fields_requested( 'avatar_urls' ) && get_option( 'show_avatars' ) ) {
			$data['avatar_urls'] = array_map(
				static function ( $url ) {
					return is_string( $url ) ? $url : null;
				},
				rest_get_avatar_urls( $user )
			);
		}

		if ( $can_view_sensitive ) {
			if ( $fields_requested( 'username' ) ) {
				$data['username'] = (string) $user->user_login;
			}
			if ( $fields_requested( 'email' ) ) {
				$data['email'] = is_email( $user->user_email ) ? (string) $user->user_email : null;
			}
			if ( $fields_requested( 'first_name' ) ) {
				$data['first_name'] = (string) $user->first_name;
			}
			if ( $fields_requested( 'last_name' ) ) {
				$data['last_name'] = (string) $user->last_name;
			}
			if ( $fields_requested( 'nickname' ) ) {
				$data['nickname'] = (string) $user->nickname;
			}
			if ( $fields_requested( 'locale' ) ) {
				$data['locale'] = (string) get_user_locale( $user );
			}
			if ( $fields_requested( 'registered_date' ) ) {
				/*
				 * The zero date, the column default, formats with a negative year that the
				 * `date-time` format rejects, so it is reported as null, like an unusable email.
				 */
				$registered_timestamp    = strtotime( $user->user_registered );
				$registered_date         = false !== $registered_timestamp ? gmdate( 'c', $registered_timestamp ) : '';
				$data['registered_date'] = rest_parse_date( $registered_date ) ? $registered_date : null;
			}
		}

		/*
		 * Roles reveal a user's privilege level, so they are gated like the other
		 * sensitive fields: visible for the current user or a user the caller can
		 * edit. A WRITE answers in the edit context, where `list_users` alone is
		 * enough, as the source PR's write path pins.
		 */
		// @phpstan-ignore booleanAnd.leftAlwaysFalse (phpstan narrows the write call sites; the line stays verbatim for upstream parity.)
		if ( $fields_requested( 'roles' ) && ( $can_view_sensitive || ( $edit_context && current_user_can( 'list_users' ) ) ) ) {
			$data['roles'] = array_values( $user->roles );
		}

		return $data;
	}

	/**
	 * Builds the error for a user lookup that does not resolve.
	 *
	 * A user who does not exist, or does not belong to the current site, is reported
	 * like a missing one, so the error does not reveal whether such an account exists.
	 *
	 * @return WP_Error The not-found error.
	 */
	protected function not_found_error(): WP_Error {
		return new WP_Error(
			'users_not_found',
			__( 'The requested user was not found.' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Gets the user, if the ID is valid.
	 *
	 * A user who does not exist, or does not belong to the current site, gets the error
	 * `core/users-query` gives for a user it cannot read.
	 *
	 * The ID is read with parse_filter_int(), as in the users query, so a malformed ID
	 * or one beyond the integer range cannot be coerced onto another user.
	 * WP_Ability::check_permissions() does not validate the input, so the permission
	 * callbacks can receive one.
	 *
	 * @param mixed $id Supplied ID.
	 * @return \WP_User|\WP_Error The user if the ID is valid, WP_Error otherwise.
	 */
	protected function get_user( $id ) {
		$error = $this->not_found_error();

		$user_id = $this->parse_filter_int( $id, 1 );
		if ( null === $user_id ) {
			return $error;
		}

		$user = get_userdata( $user_id );
		if ( empty( $user ) || ! $user->exists() ) {
			return $error;
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) {
			return $error;
		}

		return $user;
	}

	/**
	 * Checks for a valid value for the reassign parameter when deleting users.
	 *
	 * The value can be a user ID, 0 to leave the posts without an author, or false, 'false',
	 * or '' to delete them. The ID is read with parse_filter_int(), so a value such as -3 or
	 * 2.9 is refused rather than coerced onto another user.
	 *
	 * @param mixed $value The value passed to the reassign parameter.
	 * @return int|false|\WP_Error The user ID, false to delete the user's posts and links, or a WP_Error.
	 */
	protected function check_reassign( $value ) {
		if ( false === $value || '' === $value || 'false' === $value ) {
			return false;
		}

		$user_id = $this->parse_filter_int( $value, 0 );
		if ( null !== $user_id ) {
			return $user_id;
		}

		return new WP_Error(
			'users_invalid_param',
			__( 'Invalid user parameter(s).' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Checks a username.
	 *
	 * Performs a couple of checks like edit_user() in wp-admin/includes/user.php.
	 *
	 * @param mixed $value The username submitted in the input.
	 * @return string|\WP_Error The sanitized username, if valid, otherwise an error.
	 */
	protected function check_username( $value ) {
		$username = (string) $value;

		if ( ! validate_username( $username ) ) {
			return new WP_Error(
				'users_user_invalid_username',
				__( 'This username is invalid because it uses illegal characters. Please enter a valid username.' ),
				array( 'status' => 400 )
			);
		}

		/** This filter is documented in wp-includes/user.php */
		$illegal_logins = (array) apply_filters( 'illegal_user_logins', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		if ( in_array( strtolower( $username ), array_map( 'strtolower', $illegal_logins ), true ) ) {
			return new WP_Error(
				'users_user_invalid_username',
				__( 'Sorry, that username is not allowed.' ),
				array( 'status' => 400 )
			);
		}

		return $username;
	}

	/**
	 * Checks a user password.
	 *
	 * Performs a couple of checks like edit_user() in wp-admin/includes/user.php.
	 *
	 * @param mixed $value The password submitted in the input.
	 * @return string|\WP_Error The sanitized password, if valid, otherwise an error.
	 */
	protected function check_user_password(
		// phpcs:ignore PHPCompatibility.Attributes.NewAttributes.PHPNativeAttributeFound -- PHP 7.4 reads the attribute as a comment.
		#[\SensitiveParameter]
		$value
	) {
		$password = (string) $value;

		if ( empty( $password ) ) {
			return new WP_Error(
				'users_user_invalid_password',
				__( 'Passwords cannot be empty.' ),
				array( 'status' => 400 )
			);
		}

		if ( str_contains( $password, '\\' ) ) {
			return new WP_Error(
				'users_user_invalid_password',
				sprintf(
					/* translators: %s: The '\' character. */
					__( 'Passwords cannot contain the "%s" character.' ),
					'\\'
				),
				array( 'status' => 400 )
			);
		}

		return $password;
	}

	/**
	 * Sanitizes the input of a write ability.
	 *
	 * Every value is checked before anything is written, and every value that fails is
	 * reported with its reason.
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array<mixed>|\WP_Error The sanitized input, or a WP_Error naming the invalid parameters.
	 */
	protected function sanitize_params( array $input ) {
		$sanitize_callbacks = array(
			'username'   => array( $this, 'check_username' ),
			'name'       => 'sanitize_text_field',
			'first_name' => 'sanitize_text_field',
			'last_name'  => 'sanitize_text_field',
			'email'      => 'sanitize_text_field',
			'url'        => 'sanitize_url',
			'nickname'   => 'sanitize_text_field',
			'slug'       => 'sanitize_title',
			'roles'      => 'rest_sanitize_array',
			'password'   => array( $this, 'check_user_password' ),
			'reassign'   => array( $this, 'check_reassign' ),
		);

		$invalid_params  = array();
		$invalid_details = array();

		foreach ( $input as $key => $value ) {
			if ( ! isset( $sanitize_callbacks[ $key ] ) ) {
				continue;
			}

			$sanitized_value = call_user_func( $sanitize_callbacks[ $key ], $value );

			if ( is_wp_error( $sanitized_value ) ) {
				$invalid_params[ $key ]  = implode( ' ', $sanitized_value->get_error_messages() );
				$invalid_details[ $key ] = rest_convert_error_to_response( $sanitized_value )->get_data();
			} else {
				$input[ $key ] = $sanitized_value;
			}
		}

		if ( $invalid_params ) {
			return new WP_Error(
				'users_invalid_param',
				/* translators: %s: List of invalid parameters. */
				sprintf( __( 'Invalid parameter(s): %s' ), implode( ', ', array_keys( $invalid_params ) ) ),
				array(
					'status'  => 400,
					'params'  => $invalid_params,
					'details' => $invalid_details,
				)
			);
		}

		return $input;
	}

	/**
	 * Prepares a single user for creation or update.
	 *
	 * @param array<mixed> $input The sanitized ability input.
	 * @return \stdClass User object.
	 */
	protected function prepare_item_for_database( array $input ): stdClass {
		$prepared_user = new stdClass();

		// Required arguments.
		if ( isset( $input['email'] ) ) {
			$prepared_user->user_email = $input['email'];
		}

		if ( isset( $input['username'] ) ) {
			$prepared_user->user_login = $input['username'];
		}

		if ( isset( $input['password'] ) ) {
			$prepared_user->user_pass = $input['password'];
		}

		// Optional arguments.
		if ( isset( $input['id'] ) ) {
			$prepared_user->ID = absint( $input['id'] );
		}

		if ( isset( $input['name'] ) ) {
			$prepared_user->display_name = $input['name'];
		}

		if ( isset( $input['first_name'] ) ) {
			$prepared_user->first_name = $input['first_name'];
		}

		if ( isset( $input['last_name'] ) ) {
			$prepared_user->last_name = $input['last_name'];
		}

		if ( isset( $input['nickname'] ) ) {
			$prepared_user->nickname = $input['nickname'];
		}

		/*
		 * A slug that sanitizes to nothing is skipped. wp_insert_user() would otherwise replace the
		 * slug with one built from the username, which would reveal the username.
		 */
		if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
			$prepared_user->user_nicename = $input['slug'];
		}

		if ( isset( $input['description'] ) ) {
			$prepared_user->description = $input['description'];
		}

		if ( isset( $input['url'] ) ) {
			$prepared_user->user_url = $input['url'];
		}

		if ( isset( $input['locale'] ) ) {
			$prepared_user->locale = $input['locale'];
		}

		// Setting roles will be handled outside of this function.
		if ( isset( $input['roles'] ) ) {
			$prepared_user->role = false;
		}

		return $prepared_user;
	}

	/**
	 * Determines if the current user is allowed to make the desired roles change.
	 *
	 * @param int|null     $user_id User ID, or null when creating a user.
	 * @param list<string> $roles   New user roles. An empty list removes every role.
	 * @return true|\WP_Error True if the current user is allowed to make the role change,
	 *                        otherwise a WP_Error object.
	 */
	protected function check_role_update( ?int $user_id, array $roles ) {
		global $wp_roles;

		// Without a role, users cannot edit users either, so they cannot remove their own roles.
		if ( array() === $roles
			&& ! ( is_multisite() && current_user_can( 'manage_sites' ) )
			&& get_current_user_id() === $user_id
		) {
			return new WP_Error(
				'users_user_invalid_role',
				__( 'Sorry, you are not allowed to remove your own roles.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		foreach ( $roles as $role ) {
			if ( ! isset( $wp_roles->role_objects[ $role ] ) ) {
				return new WP_Error(
					'users_user_invalid_role',
					/* translators: %s: Role key. */
					sprintf( __( 'The role %s does not exist.' ), $role ),
					array( 'status' => 400 )
				);
			}

			$potential_role = $wp_roles->role_objects[ $role ];

			/*
			 * Don't let anyone with 'edit_users' (admins) edit their own role to something without it.
			 * Multisite super admins can freely edit their blog roles -- they possess all caps.
			 */
			if ( ! ( is_multisite()
				&& current_user_can( 'manage_sites' ) )
				&& get_current_user_id() === $user_id
				&& ! $potential_role->has_cap( 'edit_users' )
			) {
				return new WP_Error(
					'users_user_invalid_role',
					__( 'Sorry, you are not allowed to give users that role.' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			// Include user admin functions to get access to get_editable_roles().
			require_once ABSPATH . 'wp-admin/includes/user.php';

			// The new role must be editable by the logged-in user.
			$editable_roles = get_editable_roles();

			if ( empty( $editable_roles[ $role ] ) ) {
				return new WP_Error(
					'users_user_invalid_role',
					__( 'Sorry, you are not allowed to give users that role.' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Permission callback for the `wp/user-create` ability.
	 *
	 * The rest of the input is checked during execution.
	 *
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_create_permission(): bool {
		return is_user_logged_in() && current_user_can( 'create_users' );
	}

	/**
	 * Permission callback for the `wp/user-update` ability.
	 *
	 * The current user must be able to edit the user, or to promote the user when only the
	 * roles are given, an empty list included. A roles change still needs the promote
	 * capability, which is checked during execution so the caller learns that the roles
	 * were refused.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_update_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = $this->get_user( $input['id'] ?? 0 );
		if ( is_wp_error( $user ) ) {
			return false;
		}

		// An empty list is a roles change too: it removes every role.
		if ( isset( $input['roles'] ) && current_user_can( 'promote_user', $user->ID ) ) {
			$request_params = array_keys( $input );
			sort( $request_params );
			/*
			 * If only 'id' and 'roles' are specified (we are only trying to
			 * edit roles), then only the 'promote_user' cap is required.
			 */
			if ( array( 'id', 'roles' ) === $request_params ) {
				return true;
			}
		}

		return current_user_can( 'edit_user', $user->ID );
	}

	/**
	 * Permission callback for the `wp/user-delete` ability.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_delete_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = $this->get_user( $input['id'] ?? 0 );
		if ( is_wp_error( $user ) ) {
			return false;
		}

		return current_user_can( 'delete_user', $user->ID );
	}

	/**
	 * Executes the `wp/user-create` ability.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The created user, or a WP_Error.
	 */
	public function execute_user_create( $input = array() ) {
		$input = $this->sanitize_params( rest_sanitize_object( $input ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		if ( ! empty( $input['roles'] ) ) {
			// As in wp-admin, giving a new user roles takes the capability to promote users.
			if ( ! current_user_can( 'promote_users' ) ) {
				return new WP_Error(
					'users_cannot_edit_roles',
					__( 'Sorry, you are not allowed to give users roles.' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			$check_permission = $this->check_role_update( null, $input['roles'] );

			if ( is_wp_error( $check_permission ) ) {
				return $check_permission;
			}
		}

		$user = $this->prepare_item_for_database( $input );

		// As in wp-admin, a password is generated when none is given; the user sets their own from the email sent below.
		if ( ! isset( $user->user_pass ) ) {
			$user->user_pass = wp_generate_password( 24 );
		}

		if ( is_multisite() ) {
			$ret = wpmu_validate_user_signup( $user->user_login, $user->user_email );

			// @phpstan-ignore function.alreadyNarrowedType (defensive: the shape types errors as WP_Error; the guard before has_errors() is upstream-verbatim.)
			if ( is_wp_error( $ret['errors'] ) && $ret['errors']->has_errors() ) {
				$error = new WP_Error(
					'users_invalid_param',
					__( 'Invalid user parameter(s).' ),
					array( 'status' => 400 )
				);

				foreach ( $ret['errors']->errors as $code => $messages ) {
					foreach ( $messages as $message ) {
						$error->add( $code, $message );
					}

					$error_data = $error->get_error_data( $code );

					if ( ! $error_data ) {
						continue;
					}

					$error->add_data( $error_data, $code );
				}
				return $error;
			}

			$user_id = wpmu_create_user( $user->user_login, $user->user_pass, $user->user_email );

			if ( ! $user_id ) {
				return new WP_Error(
					'users_user_create',
					__( 'Error creating new user.' ),
					array( 'status' => 500 )
				);
			}

			$user->ID = $user_id;
			$result   = wp_update_user( wp_slash( (array) $user ) );

			if ( ! is_wp_error( $result ) ) {
				$result = add_user_to_blog( get_current_blog_id(), $user_id, '' );
			}

			if ( is_wp_error( $result ) ) {
				// Don't leave behind the network user that wpmu_create_user() created.
				require_once ABSPATH . 'wp-admin/includes/ms.php';
				wpmu_delete_user( $user_id );

				return $result;
			}
		} else {
			$user_id = wp_insert_user( wp_slash( (array) $user ) );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			// The database can refuse the row, e.g. an email too long for its column, and then the ID is 0.
			if ( ! $user_id ) {
				return new WP_Error(
					'users_user_create',
					__( 'Error creating new user.' ),
					array( 'status' => 500 )
				);
			}
		}

		$user = new WP_User( $user_id );

		if ( ! empty( $input['roles'] ) ) {
			array_map( array( $user, 'add_role' ), $input['roles'] );
		}

		if ( ! isset( $input['password'] ) ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		return $this->format_user( $user, $this->normalize_fields( $input ), true );
	}

	/**
	 * Executes the `wp/user-update` ability.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The updated user, or a WP_Error.
	 */
	public function execute_user_update( $input = array() ) {
		$input = $this->sanitize_params( rest_sanitize_object( $input ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$user = $this->get_user( $input['id'] ?? 0 );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		/*
		 * The current roles, in their order, are no change, so a user read with core/users-query
		 * can be sent back. The order counts, as the first role is the user's primary role.
		 */
		if ( isset( $input['roles'] ) && array_values( $input['roles'] ) === array_values( $user->roles ) ) {
			unset( $input['roles'] );
		}

		if ( isset( $input['roles'] ) && ! current_user_can( 'promote_user', $user->ID ) ) {
			return new WP_Error(
				'users_cannot_edit_roles',
				__( 'Sorry, you are not allowed to edit roles of this user.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$id = $user->ID;

		$owner_id = false;
		if ( is_string( $input['email'] ?? null ) ) {
			$owner_id = email_exists( $input['email'] );
		}

		if ( $owner_id && $owner_id !== $id ) {
			return new WP_Error(
				'users_user_invalid_email',
				__( 'Invalid email address.' ),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $input['slug'] ) && $input['slug'] !== $user->user_nicename && get_user_by( 'slug', $input['slug'] ) ) {
			return new WP_Error(
				'users_user_invalid_slug',
				__( 'Invalid slug.' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $input['roles'] ) ) {
			$check_permission = $this->check_role_update( $id, $input['roles'] );

			if ( is_wp_error( $check_permission ) ) {
				return $check_permission;
			}
		}

		$user = $this->prepare_item_for_database( $input );

		// Ensure we're operating on the same user we already checked.
		$user->ID = $id;

		$user_id = wp_update_user( wp_slash( (array) $user ) );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = new WP_User( $user_id );

		if ( ! empty( $input['roles'] ) ) {
			array_map( array( $user, 'add_role' ), $input['roles'] );
		}

		return $this->format_user( $user, $this->normalize_fields( $input ), true );
	}

	/**
	 * Executes the `wp/user-delete` ability.
	 *
	 * Users cannot be trashed, so the user is deleted permanently and returned as it was just
	 * before the deletion.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The deleted user, or a WP_Error.
	 */
	public function execute_user_delete( $input = array() ) {
		$input = $this->sanitize_params( rest_sanitize_object( $input ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		// We don't support delete requests in multisite.
		if ( is_multisite() ) {
			return new WP_Error(
				'users_cannot_delete',
				__( 'The user cannot be deleted.' ),
				array( 'status' => 501 )
			);
		}

		$user = $this->get_user( $input['id'] ?? 0 );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		// As in wp-admin, users cannot delete their own account.
		if ( get_current_user_id() === $user->ID ) {
			return new WP_Error(
				'users_cannot_delete',
				__( 'Sorry, you are not allowed to delete your own account.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$id = $user->ID;

		// check_reassign() has already read the reassign input as false or a user ID.
		$reassign = false === $input['reassign'] ? null : (int) $input['reassign'];

		if ( ! empty( $reassign ) ) {
			if ( $reassign === $id || ! get_userdata( $reassign ) ) {
				return new WP_Error(
					'users_user_invalid_reassign',
					__( 'Invalid user ID for reassignment.' ),
					array( 'status' => 400 )
				);
			}
		}

		$previous = $this->format_user( $user, $this->normalize_fields( $input ), true );

		// Include user admin functions to get access to wp_delete_user().
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$result = wp_delete_user( $id, $reassign );

		if ( ! $result ) {
			return new WP_Error(
				'users_cannot_delete',
				__( 'The user cannot be deleted.' ),
				array( 'status' => 500 )
			);
		}

		return $previous;
	}

	/**
	 * Returns the input properties shared by the create and update abilities, keyed by field name.
	 *
	 * `email` and `name` are capped at the length of their columns in the users table.
	 * wp_insert_user() checks the length of the other columns, but not of these two, and
	 * wp_update_user() reports success even when the database refuses a value too long for one.
	 *
	 * As in the query schema, the `roles` enum lists the roles registered when the ability is.
	 * A role removed since then is refused when the user is written.
	 *
	 * @return array<string, mixed> Write property definitions.
	 */
	protected function get_user_write_properties(): array {
		return array(
			'name'        => array(
				'type'        => 'string',
				'maxLength'   => 250,
				'description' => __( 'Display name for the user.' ),
			),
			'first_name'  => array(
				'type'        => 'string',
				'description' => __( 'First name for the user.' ),
			),
			'last_name'   => array(
				'type'        => 'string',
				'description' => __( 'Last name for the user.' ),
			),
			'email'       => array(
				'type'        => 'string',
				'format'      => 'email',
				'maxLength'   => 100,
				'description' => __( 'The email address for the user.' ),
			),
			'url'         => array(
				'type'        => 'string',
				'description' => __( 'URL of the user.' ),
			),
			'description' => array(
				'type'        => 'string',
				'description' => __( 'Description of the user.' ),
			),
			'locale'      => array(
				'type'        => 'string',
				'enum'        => array_merge( array( '', 'en_US' ), get_available_languages() ),
				'description' => __( 'Locale for the user. An empty string uses the site locale.' ),
			),
			'nickname'    => array(
				'type'        => 'string',
				'description' => __( 'The nickname for the user.' ),
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the user.' ),
			),
			'roles'       => array(
				'type'        => 'array',
				'uniqueItems' => true,
				'items'       => array(
					'type' => 'string',
					'enum' => array_keys( wp_roles()->roles ),
				),
				'description' => __( 'Roles assigned to the user. An empty list leaves the user without a role. Giving a user roles, or changing them, requires permission to promote users.' ),
			),
			'password'    => array(
				'type'        => 'string',
				'description' => __( 'Password for the user. It is never returned. When a user is created without one, a password is generated and the user is emailed a link to set their own.' ),
			),
		);
	}

	/**
	 * Builds the input schema for the `wp/user-create` ability.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_user_create_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'username', 'email' ),
			'additionalProperties' => false,
			'properties'           => array_merge(
				array(
					'username' => array(
						'type'        => 'string',
						'description' => __( 'Login name for the user. It cannot be changed later.' ),
					),
				),
				$this->get_user_write_properties(),
				array( 'fields' => $this->get_fields_input_schema() )
			),
		);
	}

	/**
	 * Builds the input schema for the `wp/user-update` ability.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_user_update_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array_merge(
				array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the user to update.' ),
					),
				),
				$this->get_user_write_properties(),
				array( 'fields' => $this->get_fields_input_schema() )
			),
		);
	}

	/**
	 * Builds the input schema for the `wp/user-delete` ability.
	 *
	 * `reassign` also takes a string so the query-string forms of an ID and of false are read
	 * like the ID and false themselves.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_user_delete_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'reassign' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The ID of the user to delete.' ),
				),
				'reassign' => array(
					// Strings come first, so input coercion keeps them as sent: 'FALSE' is not false.
					'type'        => array( 'string', 'integer', 'boolean' ),
					'description' => __( "The ID of the user to reassign the deleted user's posts and links to, or false to delete them, which moves posts and pages to the trash." ),
				),
				'fields'   => $this->get_fields_input_schema(),
			),
		);
	}

	/**
	 * Builds the schema of the `fields` input shared by the write abilities.
	 *
	 * @return array<string, mixed> The `fields` JSON Schema.
	 */
	protected function get_fields_input_schema(): array {
		return array(
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_user_properties() ),
			),
			'description' => __( 'Limit the returned user to these fields. If omitted or empty, a lean set of common read fields is returned. The `id` field is always included, and fields the current user cannot view are omitted rather than causing an error.' ),
		);
	}

	/**
	 * Builds the output schema of a single user, shared by the write abilities.
	 *
	 * Only `id` is required, because it is always returned. The other fields are optional
	 * because the `fields` input lets the caller request any subset.
	 *
	 * @return array<string, mixed> The user JSON Schema.
	 */
	protected function get_user_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => $this->get_user_properties(),
		);
	}
}
