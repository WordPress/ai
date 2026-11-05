<?php
/**
 * Storage for AI Workspace conversations.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Post;
use WP_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Stores a workspace conversation, and the cancellation marker for its turn.
 *
 * Four properties are load bearing.
 *
 * **The medium is a private post type.** A chat is a `wpai_chat` post: its author
 * is the owner, its title is the name, `publish` means active and the registered
 * non-public `wpai_archived` status means archived. Trash is never used, because
 * `wp_scheduled_delete` empties it on its own and untrashing restores to `draft`.
 * Chats are kept until their owner deletes them; nothing expires them. The model
 * messages and one entry record per turn live in post meta as slashed JSON, with
 * `post_content` left empty, so neither kses nor unslashing can rewrite them.
 *
 * **A conversation belongs to one user.** The client sends the conversation ID,
 * so ownership is not implied by anything about the request. Every read matches
 * the post author as well as the ID, and the `map_meta_cap` filter grants reading,
 * editing and deleting a chat to its author alone, with no override for other
 * administrators. Without both, a conversation ID would be an enumerable read of
 * somebody else's private and draft post content.
 *
 * **A chat leaves with its owner.** Deleting or removing a user deletes their
 * chats before WordPress can reassign that user's content to someone else, which
 * would otherwise hand one person's transcripts to another.
 *
 * **Cancellation is stored separately.** It is written by a different HTTP
 * request than the one running the turn, so it lives in its own transient that
 * the loop re-reads between rounds. Its local cache entry is dropped before each
 * read, otherwise the loop would keep answering from the value it cached at the
 * start of the request and could never observe the cancellation.
 *
 * @since x.x.x
 */
final class Conversation_Store {

	/**
	 * The post type chats are stored as.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const POST_TYPE = 'wpai_chat';

	/**
	 * The post status of a chat that is in the default list.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const STATUS_ACTIVE = 'publish';

	/**
	 * The post status of an archived chat.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const STATUS_ARCHIVED = 'wpai_archived';

	/**
	 * Post meta key holding the model messages.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const MESSAGES_META = '_wpai_chat_messages';

	/**
	 * Post meta key holding the entry records, one per turn.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ENTRIES_META = '_wpai_chat_entries';

	/**
	 * Post meta key holding the rest of the conversation's state, such as its scope.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const STATE_META = '_wpai_chat_state';

	/**
	 * Length, in characters, of the name a chat is given from its first message.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const AUTO_NAME_LENGTH = 60;

	/**
	 * Longest name a chat may be given, in characters.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_NAME_LENGTH = 200;

	/**
	 * Transient name prefix for cancellation markers.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CANCEL_PREFIX = 'wpai_workspace_cancel_';

	/**
	 * How long a cancellation marker survives, in seconds.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const CANCEL_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Conversation keys that are stored in the post itself or in their own meta,
	 * and so are left out of the state meta.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const RESERVED_KEYS = array( 'id', 'user_id', 'name', 'archived', 'created', 'updated', 'messages', 'entries' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Registers the post type and the archived status.
	 *
	 * Called while the experiment is on. The ownership and user-removal hooks are
	 * registered separately, by {@see self::register_lifecycle_hooks()}, so they hold
	 * even while the experiment is off.
	 *
	 * @since x.x.x
	 */
	public static function register_post_type(): void {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			register_post_type( // phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- The constant holds the literal slug.
				self::POST_TYPE,
				array(
					'labels'              => array(
						'name'          => __( 'Workspace chats', 'ai' ),
						'singular_name' => __( 'Workspace chat', 'ai' ),
					),
					'public'              => false,
					'publicly_queryable'  => false,
					'show_ui'             => false,
					'show_in_menu'        => false,
					'show_in_nav_menus'   => false,
					'show_in_admin_bar'   => false,
					'show_in_rest'        => false,
					'exclude_from_search' => true,
					'has_archive'         => false,
					'rewrite'             => false,
					'query_var'           => false,
					'can_export'          => false,
					'delete_with_user'    => true,
					/*
					 * A dedicated capability type no role holds, with core's meta
					 * capability mapping off, so the only grant is the author check
					 * in {@see self::map_meta_cap()}.
					 */
					'capability_type'     => array( 'wpai_chat', 'wpai_chats' ),
					'map_meta_cap'        => false,
					'supports'            => array( 'title', 'author' ),
				)
			);
		}

		if ( null !== get_post_status_object( self::STATUS_ARCHIVED ) ) {
			return;
		}

		register_post_status(
			self::STATUS_ARCHIVED,
			array(
				'label'                     => _x( 'Archived', 'workspace chat status', 'ai' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}

	/**
	 * Hooks the ownership rule and user-removal cleanup.
	 *
	 * These run whether or not the experiment is on: a chat stored while it was on
	 * must not become readable by another administrator, or be reassigned with a
	 * deleted user's content, while it is off.
	 *
	 * @since x.x.x
	 */
	public static function register_lifecycle_hooks(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );

		// Both fire before WordPress reassigns the user's content.
		add_action( 'delete_user', array( self::class, 'delete_user_chats' ) );
		add_action( 'remove_user_from_blog', array( self::class, 'delete_user_chats' ) );
	}

	/**
	 * Grants reading, editing and deleting a chat to its author alone.
	 *
	 * @since x.x.x
	 *
	 * @param array<string> $caps    The primitive capabilities required.
	 * @param string        $cap     The capability being checked.
	 * @param int           $user_id The user ID.
	 * @param array<mixed>  $args    Arguments, the first of which is the post ID.
	 * @return array<string> The primitive capabilities required.
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		$meta_caps = array( 'read_post', 'edit_post', 'delete_post', 'read_wpai_chat', 'edit_wpai_chat', 'delete_wpai_chat' );

		if ( ! in_array( $cap, $meta_caps, true ) || ! isset( $args[0] ) ) {
			return $caps;
		}

		// Core accepts a post object as well as an ID for these capabilities.
		$post = $args[0] instanceof WP_Post ? $args[0] : ( is_numeric( $args[0] ) ? get_post( (int) $args[0] ) : null );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $caps;
		}

		return (int) $user_id > 0 && (int) $post->post_author === (int) $user_id ? array( 'exist' ) : array( 'do_not_allow' );
	}

	/**
	 * Deletes every chat a user owns.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id The user ID.
	 */
	public static function delete_user_chats( $user_id ): void {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		self::delete_chats( $user_id );
	}

	/**
	 * Deletes every chat on the current site, whoever owns it.
	 *
	 * @since x.x.x
	 */
	public static function delete_all_chats(): void {
		self::delete_chats( 0 );
	}

	/**
	 * Creates a new, empty conversation owned by a user.
	 *
	 * Nothing is stored until the conversation is first saved.
	 *
	 * @since x.x.x
	 *
	 * @param int    $user_id The owning user ID.
	 * @param string $scope   The conversation scope.
	 * @return array{id: string, user_id: int, scope: string, created: int, updated: int, messages: list<array<string, mixed>>, entries: list<array<string, mixed>>} The conversation.
	 */
	public function create( int $user_id, string $scope ): array {
		$now = time();

		return array(
			'id'       => wp_generate_uuid4(),
			'user_id'  => $user_id,
			'scope'    => $scope,
			'created'  => $now,
			'updated'  => $now,
			'messages' => array(),
			'entries'  => array(),
		);
	}

	/**
	 * Loads a conversation, but only for the user who owns it.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The requesting user ID.
	 * @return array<string, mixed>|null The conversation, or null when it is missing or owned by someone else.
	 *
	 * @phpstan-impure
	 */
	public function get( string $conversation_id, int $user_id ): ?array {
		$post = $this->find_post( $conversation_id, $user_id );

		if ( null === $post ) {
			return null;
		}

		$conversation = $this->read_json( $post->ID, self::STATE_META );

		return array_merge(
			$conversation,
			$this->summary_of( $post ),
			array(
				'user_id'  => (int) $post->post_author,
				'messages' => array_values( $this->read_json( $post->ID, self::MESSAGES_META ) ),
				'entries'  => array_values( $this->read_json( $post->ID, self::ENTRIES_META ) ),
			)
		);
	}

	/**
	 * Saves a conversation, creating its chat on the first save.
	 *
	 * The first save names the chat after the first message it records. A later
	 * save leaves the name and the archived status alone; those change only through
	 * {@see self::rename()} and {@see self::set_archived()}.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation to store.
	 * @return bool True when the conversation was written.
	 */
	public function save( array $conversation ): bool {
		$conversation_id = isset( $conversation['id'] ) && is_string( $conversation['id'] ) ? $conversation['id'] : '';
		$user_id         = isset( $conversation['user_id'] ) ? (int) $conversation['user_id'] : 0;

		if ( ! $this->is_valid_id( $conversation_id ) || $user_id <= 0 ) {
			return false;
		}

		$post = $this->find_post( $conversation_id, null );

		// An ID already taken by someone else's chat is never written over.
		if ( null !== $post && (int) $post->post_author !== $user_id ) {
			return false;
		}

		/*
		 * Encoded before anything is written, so a value JSON cannot hold leaves
		 * the stored chat as it was rather than replacing its transcript with
		 * nothing.
		 */
		$meta = array(
			self::MESSAGES_META => wp_json_encode( $this->list_of( $conversation, 'messages' ) ),
			self::ENTRIES_META  => wp_json_encode( $this->list_of( $conversation, 'entries' ) ),
			self::STATE_META    => wp_json_encode( array_diff_key( $conversation, array_flip( self::RESERVED_KEYS ) ) ),
		);

		if ( in_array( false, $meta, true ) ) {
			return false;
		}

		/*
		 * A conversation read back from a stored chat carries its name. If that
		 * chat is gone now, it was deleted while this copy was in use — by its
		 * owner, or with its owner — and writing it again would undo the deletion.
		 */
		if ( null === $post && array_key_exists( 'name', $conversation ) ) {
			return false;
		}

		if ( null === $post ) {
			$post_id = $this->write_post(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => self::STATUS_ACTIVE,
					'post_author'    => $user_id,
					'post_name'      => $conversation_id,
					'post_title'     => $this->initial_name( $conversation ),
					'post_content'   => '',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			);
		} else {
			// Touches the modified date, which orders the chat list.
			$post_id = $this->write_post( array( 'ID' => $post->ID ) );
		}

		if ( 0 === $post_id ) {
			return false;
		}

		foreach ( $meta as $key => $json ) {
			update_post_meta( $post_id, $key, wp_slash( (string) $json ) );
		}

		return true;
	}

	/**
	 * Deletes a conversation for good, with any cancellation marker it carries.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 */
	public function delete( string $conversation_id, int $user_id ): void {
		$post = $this->find_post( $conversation_id, $user_id );

		if ( null !== $post ) {
			wp_delete_post( $post->ID, true );
		}

		$this->clear_cancellation( $conversation_id, $user_id );
	}

	/**
	 * Lists a user's chats, most recently active first.
	 *
	 * @since x.x.x
	 *
	 * @param int  $user_id  The owning user ID.
	 * @param bool $archived Whether to list archived chats instead of active ones.
	 * @param int  $page     The one-based page.
	 * @param int  $per_page Chats per page.
	 * @return array{items: list<array{id: string, name: string, archived: bool, created: int, updated: int}>, total: int} The page of chat summaries and the total.
	 */
	public function list_for_user( int $user_id, bool $archived, int $page, int $per_page ): array {
		if ( $user_id <= 0 ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => $archived ? self::STATUS_ARCHIVED : self::STATUS_ACTIVE,
				'author'                 => $user_id,
				'orderby'                => array(
					'modified' => 'DESC',
					'ID'       => 'DESC',
				),
				'paged'                  => max( 1, $page ),
				'posts_per_page'         => max( 1, $per_page ),
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$items[] = $this->summary_of( $post );
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Renames a chat.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 * @param string $name            The new name, already sanitized and non-empty.
	 * @return bool True when the chat was renamed.
	 */
	public function rename( string $conversation_id, int $user_id, string $name ): bool {
		$post = $this->find_post( $conversation_id, $user_id );

		if ( null === $post || '' === $name ) {
			return false;
		}

		return 0 !== $this->write_post(
			array(
				'ID'         => $post->ID,
				'post_title' => $name,
			)
		);
	}

	/**
	 * Archives a chat, or restores an archived one to the active list.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 * @param bool   $archived        Whether the chat should be archived.
	 * @return bool True when the chat now has the requested status.
	 */
	public function set_archived( string $conversation_id, int $user_id, bool $archived ): bool {
		$post = $this->find_post( $conversation_id, $user_id );

		if ( null === $post ) {
			return false;
		}

		$status = $archived ? self::STATUS_ARCHIVED : self::STATUS_ACTIVE;

		if ( $status === $post->post_status ) {
			return true;
		}

		return 0 !== $this->write_post(
			array(
				'ID'          => $post->ID,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Builds the name a chat is first given: its first message, shortened.
	 *
	 * @since x.x.x
	 *
	 * @param string $message The message.
	 * @return string The name, or an empty string when the message holds no text.
	 */
	public static function name_from_message( string $message ): string {
		$text = trim( sanitize_text_field( $message ) );

		if ( '' === $text ) {
			return '';
		}

		if ( mb_strlen( $text ) <= self::AUTO_NAME_LENGTH ) {
			return $text;
		}

		// One character is left for the ellipsis, so the name stays within the length.
		return wp_html_excerpt( $text, self::AUTO_NAME_LENGTH - 1, '…' );
	}

	/**
	 * Marks a conversation's in-flight turn as cancelled.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 * @return bool True when the marker was written.
	 */
	public function cancel( string $conversation_id, int $user_id ): bool {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return false;
		}

		return set_transient( $this->cancel_name( $conversation_id, $user_id ), time(), self::CANCEL_TTL );
	}

	/**
	 * Reports whether a turn has been cancelled out of band.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 * @return bool True when a cancellation marker is present.
	 *
	 * @phpstan-impure
	 */
	public function is_cancelled( string $conversation_id, int $user_id ): bool {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return false;
		}

		$name = $this->cancel_name( $conversation_id, $user_id );

		/*
		 * The marker is written by a different request, so the value this request
		 * cached on its first read is stale by definition. Both cache locations a
		 * transient can occupy are dropped before reading: the `transient` group
		 * when an external object cache is in use, and the underlying option when
		 * the value lives in the options table.
		 */
		wp_cache_delete( $name, 'transient' );
		wp_cache_delete( '_transient_' . $name, 'options' );
		wp_cache_delete( '_transient_timeout_' . $name, 'options' );

		return false !== get_transient( $name );
	}

	/**
	 * Removes a cancellation marker.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 */
	public function clear_cancellation( string $conversation_id, int $user_id ): void {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return;
		}

		delete_transient( $this->cancel_name( $conversation_id, $user_id ) );
	}

	/**
	 * Builds the transient name holding a cancellation marker.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 * @return string The transient name.
	 */
	private function cancel_name( string $conversation_id, int $user_id ): string {
		return self::CANCEL_PREFIX . md5( $user_id . '|' . $conversation_id );
	}

	/**
	 * Finds a chat post by its conversation ID, optionally only for its owner.
	 *
	 * @since x.x.x
	 *
	 * @param string   $conversation_id The conversation ID.
	 * @param int|null $user_id         The owner to match, or null for any owner.
	 * @return \WP_Post|null The post, or null when there is none.
	 */
	private function find_post( string $conversation_id, ?int $user_id ): ?WP_Post {
		if ( ! $this->is_valid_id( $conversation_id ) || ( null !== $user_id && $user_id <= 0 ) ) {
			return null;
		}

		$args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => array( self::STATUS_ACTIVE, self::STATUS_ARCHIVED ),
			'name'                   => $conversation_id,
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);

		if ( null !== $user_id ) {
			$args['author'] = $user_id;
		}

		$posts = ( new WP_Query( $args ) )->posts;
		$post  = $posts[0] ?? null;

		if ( ! $post instanceof WP_Post || $conversation_id !== $post->post_name ) {
			return null;
		}

		// The query already matched the slug and the author; both are compared
		// again so a filter on the query cannot hand over a conversation either.
		if ( null !== $user_id && (int) $post->post_author !== $user_id ) {
			return null;
		}

		return $post;
	}

	/**
	 * Reports whether a conversation ID can name a chat.
	 *
	 * The ID is the post slug, so it must already be in the form a slug takes.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @return bool True when the ID is usable.
	 */
	private function is_valid_id( string $conversation_id ): bool {
		return '' !== $conversation_id && sanitize_title( $conversation_id ) === $conversation_id;
	}

	/**
	 * Builds the summary of a chat that the list and the conversation share.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The chat post.
	 * @return array{id: string, name: string, archived: bool, created: int, updated: int} The summary.
	 */
	private function summary_of( WP_Post $post ): array {
		return array(
			'id'       => $post->post_name,
			'name'     => $post->post_title,
			'archived' => self::STATUS_ARCHIVED === $post->post_status,
			'created'  => (int) strtotime( $post->post_date_gmt . ' UTC' ),
			'updated'  => (int) strtotime( $post->post_modified_gmt . ' UTC' ),
		);
	}

	/**
	 * Builds the name a new chat is given.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @return string The name.
	 */
	private function initial_name( array $conversation ): string {
		$entries = $this->list_of( $conversation, 'entries' );
		$first   = $entries[0] ?? null;
		$name    = is_array( $first ) && isset( $first['prompt'] ) && is_string( $first['prompt'] )
			? self::name_from_message( $first['prompt'] )
			: '';

		return '' === $name ? __( 'New chat', 'ai' ) : $name;
	}

	/**
	 * Inserts or updates a chat post.
	 *
	 * The name is plain text that has already been sanitized, so the title's kses
	 * filter is lifted for the write: for a user without `unfiltered_html` it would
	 * otherwise turn an ampersand into `&amp;` again on every save.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $postarr The post fields, unslashed.
	 * @return int The post ID, or 0 on failure.
	 */
	private function write_post( array $postarr ): int {
		$kses = has_filter( 'title_save_pre', 'wp_filter_kses' );

		if ( false !== $kses ) {
			remove_filter( 'title_save_pre', 'wp_filter_kses', $kses );
		}

		try {
			$post_id = isset( $postarr['ID'] )
				? wp_update_post( wp_slash( $postarr ), true )
				: wp_insert_post( wp_slash( $postarr ), true );
		} finally {
			if ( false !== $kses ) {
				add_filter( 'title_save_pre', 'wp_filter_kses', $kses );
			}
		}

		return is_wp_error( $post_id ) ? 0 : (int) $post_id;
	}

	/**
	 * Reads a list or map from a conversation, defaulting to an empty one.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @param string               $key          The key.
	 * @return array<mixed> The value.
	 */
	private function list_of( array $conversation, string $key ): array {
		return isset( $conversation[ $key ] ) && is_array( $conversation[ $key ] ) ? array_values( $conversation[ $key ] ) : array();
	}

	/**
	 * Reads a value {@see self::save()} wrote as slashed JSON.
	 *
	 * @since x.x.x
	 *
	 * @param int    $post_id The post ID.
	 * @param string $key     The meta key.
	 * @return array<mixed> The value, or an empty array when it is missing or unreadable.
	 */
	private function read_json( int $post_id, string $key ): array {
		$raw     = get_post_meta( $post_id, $key, true );
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Deletes chats, every one or one user's, in batches.
	 *
	 * The IDs are read straight from the posts table, not through WP_Query. These
	 * deletions run while the experiment is off (a user is deleted) and during
	 * uninstall, when the post type and its archived status are not registered,
	 * and WP_Query silently drops a status that is not registered: archived chats
	 * would be skipped, then reassigned with the rest of a deleted user's content.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id The owner whose chats to delete, or 0 for every chat.
	 */
	private static function delete_chats( int $user_id ): void {
		global $wpdb;

		do {
			$ids = 0 < $user_id
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read live, regardless of which statuses are registered; see above.
				? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d LIMIT 100", self::POST_TYPE, $user_id ) )
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read live, regardless of which statuses are registered; see above.
				: $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 100", self::POST_TYPE ) );

			$deleted = 0;

			foreach ( $ids as $id ) {
				if ( ! wp_delete_post( (int) $id, true ) ) {
					continue;
				}

				++$deleted;
			}

			// A batch where nothing could be deleted would be fetched again forever.
		} while ( $deleted > 0 );
	}
}
