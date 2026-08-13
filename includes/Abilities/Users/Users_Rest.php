<?php
/**
 * The REST-backed implementation of the `core/read-users` ability.
 *
 * @package WordPress\AI
 *
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Users;

use WP_User;
use WordPress\AI\Abilities\Rest\Rest_Backend;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Users_Rest
 *
 * Reads users through `GET /wp/v2/users` and `GET /wp/v2/users/<id>` instead of running
 * `WP_User_Query` and reading the user object directly.
 *
 * The ability field names match the REST field names one to one, so the mapping is mostly
 * about visibility. Two rules differ from REST and are applied here:
 *
 *   - REST returns the sensitive fields (username, email, names, locale, registration date)
 *     only in the `edit` context, which the ability grants to the current user and to users
 *     the caller can edit. The context is picked per user from that rule.
 *   - REST returns `roles` to anyone who can list users. The ability treats roles as
 *     sensitive, so they are dropped for users the caller cannot edit.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.3.0
 */
final class Users_Rest {

	/**
	 * The REST route for the users endpoint.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	private const ROUTE = '/wp/v2/users';

	/**
	 * Fields REST only returns in the `edit` context.
	 *
	 * @since 1.3.0
	 * @var list<string>
	 */
	private const SENSITIVE_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'username',
		'email',
		'first_name',
		'last_name',
		'nickname',
		'locale',
		'registered_date',
		'roles',
	);

	/**
	 * Reads a single user through the REST API.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_User     $user   The user to read.
	 * @param string[] $fields The requested field names.
	 * @return array<string, mixed>|\stdClass|\WP_Error The formatted user data, or a WP_Error on failure.
	 */
	public function get_user( WP_User $user, array $fields ) {
		$can_view_sensitive = $this->can_view_sensitive( $user );
		$context            = $can_view_sensitive && $this->wants_sensitive( $fields ) ? 'edit' : 'view';

		$response = Rest_Backend::get(
			self::ROUTE . '/' . (int) $user->ID,
			array(
				'context' => $context,
				'_fields' => $fields,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = Rest_Backend::data( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $this->format_user( $user, $fields, $data, $can_view_sensitive );
	}

	/**
	 * Reads a collection of users through the REST API.
	 *
	 * Takes the `WP_User_Query` arguments the ability prepared and maps them to the
	 * collection parameters of the users endpoint.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $query_args The prepared `WP_User_Query` arguments.
	 * @param string[]             $fields     The requested field names.
	 * @return array{users: list<array<string, mixed>|\stdClass>, total: int, total_pages: int}|\WP_Error The collection data, or a WP_Error on failure.
	 */
	public function query_users( array $query_args, array $fields ) {
		$per_page = max( 1, (int) ( $query_args['number'] ?? 10 ) );
		$offset   = max( 0, (int) ( $query_args['offset'] ?? 0 ) );

		$params = array(
			'context'  => 'view',
			'per_page' => $per_page,
			'page'     => (int) floor( $offset / $per_page ) + 1,
			'_fields'  => $this->collection_fields( $fields ),
		);

		if ( ! empty( $query_args['include'] ) ) {
			$params['include'] = $query_args['include'];
		}
		if ( ! empty( $query_args['role__in'] ) ) {
			$params['roles'] = $query_args['role__in'];
		}

		/*
		 * The ordering needs no mapping: both sides order by display name, ascending.
		 *
		 * `has_published_posts` does. The endpoint only accepts post types exposed to REST
		 * there, while the ability counts every publicly viewable post type, so the resolved
		 * list is carried over to the query the endpoint builds, for this request only.
		 */
		$carry_query_args = static function ( array $args ) use ( $query_args ): array {
			if ( ! empty( $query_args['has_published_posts'] ) ) {
				$args['has_published_posts'] = $query_args['has_published_posts'];
			}

			return $args;
		};
		add_filter( 'rest_user_query', $carry_query_args );

		try {
			$response = Rest_Backend::get( self::ROUTE, $params );
		} finally {
			remove_filter( 'rest_user_query', $carry_query_args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = Rest_Backend::data( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$users = array();
		foreach ( $data as $row ) {
			// A row the mapping cannot read is reported for the same reason a failed row
			// is below: the totals count it, so dropping it hides a user without saying so.
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				return Rest_Backend::unexpected_response_error();
			}

			$user = get_userdata( (int) $row['id'] );
			if ( ! $user instanceof WP_User ) {
				return Rest_Backend::unexpected_response_error();
			}

			/*
			 * The collection is read in the `view` context, which withholds the sensitive
			 * fields for every row. REST only serves them in the `edit` context, which
			 * drops rows the caller cannot edit and so cannot back this collection. Read
			 * the rows that may show sensitive fields individually instead.
			 */
			$formatted = $this->wants_sensitive( $fields ) && $this->can_view_sensitive( $user )
				? $this->get_user( $user, $fields )
				: $this->format_user( $user, $fields, $row, false );

			/*
			 * A row that fails is reported, not dropped. Dropping it would return a page
			 * that is short of rows while the totals still count them, so the caller
			 * cannot tell the missing users from users that do not exist.
			 */
			if ( is_wp_error( $formatted ) ) {
				return $formatted;
			}

			$users[] = $formatted;
		}

		return array(
			'users'       => $users,
			'total'       => Rest_Backend::pagination_header( $response, 'X-WP-Total' ),
			'total_pages' => Rest_Backend::pagination_header( $response, 'X-WP-TotalPages' ),
		);
	}

	/**
	 * Maps a REST user response to the ability output shape.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_User             $user               The user the response describes.
	 * @param string[]             $fields             The requested field names.
	 * @param array<string, mixed> $data               The REST response data.
	 * @param bool                 $can_view_sensitive Whether the caller may see the sensitive fields.
	 * @return array<string, mixed>|\stdClass The formatted user data, as an object when empty.
	 */
	private function format_user( WP_User $user, array $fields, array $data, bool $can_view_sensitive ) {
		$requested = array_flip( $fields );
		$result    = array();

		if ( isset( $requested['id'] ) ) {
			$result['id'] = (int) $user->ID;
		}
		foreach ( array( 'name', 'description', 'url', 'link', 'slug' ) as $field ) {
			if ( ! isset( $requested[ $field ], $data[ $field ] ) ) {
				continue;
			}

			$result[ $field ] = (string) $data[ $field ];
		}

		/*
		 * The option is read on every call. REST decides once per request whether avatar
		 * URLs are part of its schema, so a response can still carry them after the option
		 * was turned off. A size with no resolvable URL is reported as null.
		 */
		if ( isset( $requested['avatar_urls'] ) && get_option( 'show_avatars' ) && ! empty( $data['avatar_urls'] ) && is_array( $data['avatar_urls'] ) ) {
			$result['avatar_urls'] = array_map(
				static function ( $url ) {
					return is_string( $url ) ? $url : null;
				},
				$data['avatar_urls']
			);
		}

		if ( ! $can_view_sensitive ) {
			return array() === $result ? (object) $result : $result;
		}

		if ( isset( $requested['username'], $data['username'] ) ) {
			$result['username'] = (string) $data['username'];
		}
		if ( isset( $requested['email'] ) && array_key_exists( 'email', $data ) ) {
			$result['email'] = is_email( $data['email'] ) ? (string) $data['email'] : null;
		}
		foreach ( array( 'first_name', 'last_name', 'nickname', 'locale', 'registered_date' ) as $field ) {
			if ( ! isset( $requested[ $field ], $data[ $field ] ) ) {
				continue;
			}

			$result[ $field ] = (string) $data[ $field ];
		}
		if ( isset( $requested['roles'] ) && ! empty( $data['roles'] ) && is_array( $data['roles'] ) ) {
			$result['roles'] = array_values( array_unique( array_filter( $data['roles'], 'is_string' ) ) );
		}

		return array() === $result ? (object) $result : $result;
	}

	/**
	 * Checks whether the caller may see a user's sensitive fields.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_User $user The user object.
	 * @return bool True when the caller is the user or can edit them.
	 */
	private function can_view_sensitive( WP_User $user ): bool {
		return get_current_user_id() === (int) $user->ID || current_user_can( 'edit_user', $user->ID );
	}

	/**
	 * Checks whether any requested field is only served in the `edit` context.
	 *
	 * @since 1.3.0
	 *
	 * @param string[] $fields The requested field names.
	 * @return bool True when a sensitive field was requested.
	 */
	private function wants_sensitive( array $fields ): bool {
		return array() !== array_intersect( self::SENSITIVE_FIELDS, $fields );
	}

	/**
	 * Returns the fields to request for a collection row.
	 *
	 * The sensitive fields are never served in the `view` context, so they are dropped from
	 * the collection request. `id` is kept so each row can be resolved back to its user.
	 *
	 * @since 1.3.0
	 *
	 * @param string[] $fields The requested field names.
	 * @return string[] The field names to request.
	 */
	private function collection_fields( array $fields ): array {
		$collection_fields = array_values( array_diff( $fields, self::SENSITIVE_FIELDS ) );

		if ( ! in_array( 'id', $collection_fields, true ) ) {
			array_unshift( $collection_fields, 'id' );
		}

		return $collection_fields;
	}
}
