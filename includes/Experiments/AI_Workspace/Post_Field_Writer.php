<?php
/**
 * The `post-field` kind of AI Workspace proposal.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Error;
use WP_Post;
use WP_Post_Type;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Proposes and writes a new title, excerpt or content for an existing post.
 *
 * One item is one field of one post: `{post_id, field, value}`. The person sees
 * the field's current value next to the proposed one, may edit the proposed
 * one, and approves it. Published posts may be changed too, and the card says
 * that the change goes live at once.
 *
 * There is deliberately no `ai/*` write ability behind this kind. Every plugin
 * ability is offered over MCP by default, with no approval step there, so a
 * registered update-post ability (as #1214 had) would let any MCP client change
 * a live post unreviewed. Only #1214's validation rules are ported, here. Do not
 * "fix" MCP parity by registering a write ability; see {@see Proposal_Writer}.
 *
 * The rules, applied alike to the model's values and the person's edits:
 *
 * - **A title is plain text.** It is trimmed, and refused if it holds markup or
 *   runs past {@see self::TITLE_MAX_LENGTH}. Core's `title_save_pre` kses pass
 *   does not run for `unfiltered_html` users, who are the ones approving here,
 *   so this check is the only one.
 * - **Excerpt and content go through `wp_kses_post()` for every user**, for the
 *   reasons {@see Draft_Writer::normalize_item()} gives: the value is model output.
 * - **Content is not rewritten over markup kses would strip.** A post whose
 *   current content loses a tag, attribute, attribute value, comment or text
 *   under `wp_kses_post()`, such as one holding an iframe or embed script saved
 *   with `unfiltered_html`, would silently lose it, so a content item for it is
 *   refused. Its title and excerpt still change. Kses also re-serializes markup
 *   it keeps (`<img/>` becomes `<img />`), and that alone is not a refusal; see
 *   {@see self::kses_would_remove_markup()}.
 * - **The post must be one the person can edit**, of a post type exposed to
 *   abilities that supports the field, and not trashed, a revision or an
 *   autosave. Status, author and type never change.
 *
 * Each item carries a SHA-256 hash of the field's value when it was proposed
 * (the snapshot), and approval writes nothing when the field has changed since (`stale`), already
 * has the new value (`unchanged`), or another user holds the post lock (`locked`).
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Post_Field_Writer implements Proposal_Kind {

	use Proposal_Writes;

	/**
	 * The kind name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const KIND = 'post-field';

	/**
	 * Operation name recorded on every log row this class writes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const LOG_OPERATION = 'ai/update-post-fields';

	/**
	 * The longest title that may be proposed, in characters.
	 *
	 * The cap #1214 put on the values its write abilities accepted.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const TITLE_MAX_LENGTH = 500;

	/**
	 * The fields an item may change, mapped to their post column.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	public const FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'title'   => 'post_title',
		'excerpt' => 'post_excerpt',
		'content' => 'post_content',
	);

	/**
	 * The post type feature each field needs.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private const SUPPORTS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'title'   => 'title',
		'excerpt' => 'excerpt',
		'content' => 'editor',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function name(): string {
		return self::KIND;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function max_items(): int {
		return Proposal_Store::MAX_ITEMS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function ability(): string {
		return Propose_Post_Changes::ABILITY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Each item is checked against `edit_post` for its own post; a user who
	 * cannot edit posts at all is not offered the tool.
	 *
	 * @since x.x.x
	 */
	public function capability(): string {
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function instruction(): string {
		return __( 'To change the title, excerpt or content of an existing post, call the post changes tool with one item per field, each with the post ID, the field and its complete new value; the person sees the current value next to yours and approves it, and proposing it also ends your turn. A change to a published post goes live as soon as it is approved. Before changing a post\'s content, read the post with the read content bodies tool and "raw": true, then propose the complete block markup from its "content_raw", changed only where the person asked; never rebuild content from the plain-text body, which has lost its blocks.', 'ai' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function is_update(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function accepts_edits(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function log_operation(): string {
		return self::LOG_OPERATION;
	}

	/**
	 * Normalizes and validates one proposed field change.
	 *
	 * Error messages are written for the model to pass on, so the person hears
	 * why a change could not be proposed.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $item The raw item.
	 * @return array<string, string>|\WP_Error The normalized item, or an error.
	 */
	public function normalize_item( array $item ) {
		$post_id = isset( $item['post_id'] ) && is_numeric( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'workspace_post_change_not_found',
				__( 'That post was not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		$post_type_object = get_post_type_object( $post->post_type );

		if (
			! $post_type_object instanceof WP_Post_Type
			|| empty( $post_type_object->show_in_abilities )
			|| false !== wp_is_post_revision( $post )
			|| false !== wp_is_post_autosave( $post )
		) {
			return new WP_Error(
				'workspace_post_change_invalid_post',
				__( 'The assistant cannot change that item. Only posts of a type it may write to can be changed, not revisions or autosaves.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( 'trash' === $post->post_status ) {
			return new WP_Error(
				'workspace_post_change_trashed',
				__( 'That post is in the trash, so it cannot be changed. Restore it first.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'workspace_post_change_denied',
				__( 'You do not have permission to edit that post.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$field = isset( $item['field'] ) && is_string( $item['field'] ) ? sanitize_key( $item['field'] ) : '';

		if ( ! isset( self::FIELDS[ $field ] ) ) {
			return new WP_Error(
				'workspace_post_change_invalid_field',
				__( 'Only a post\'s title, excerpt or content can be changed.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! post_type_supports( $post->post_type, self::SUPPORTS[ $field ] ) ) {
			return new WP_Error(
				'workspace_post_change_unsupported_field',
				sprintf(
					/* translators: 1: a post field, such as excerpt. 2: a post type label, such as Pages. */
					__( 'Items of type %2$s have no %1$s, so it cannot be changed.', 'ai' ),
					$field,
					(string) $post_type_object->labels->name
				),
				array( 'status' => 400 )
			);
		}

		$value = $this->normalize_value( $field, isset( $item['value'] ) && is_string( $item['value'] ) ? $item['value'] : '' );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		if ( 'content' === $field && $this->kses_would_remove_markup( $post->post_content ) ) {
			return new WP_Error(
				'workspace_post_change_unsafe_content',
				__( 'This post\'s content holds markup, such as an embed or an iframe, that the assistant cannot rewrite without removing it, so its content cannot be changed here. Its title and excerpt can still be changed.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * New content is built from the markup the model read. If the post was
		 * saved after that read in this turn, the proposal would overwrite the
		 * newer content, and the snapshot taken below could not tell, since it is
		 * taken now. Outside a turn, or with no raw read this turn, there is
		 * nothing to compare.
		 */
		$read = 'content' === $field ? Turn_Context::read_hash( (int) $post->ID ) : '';

		if ( '' !== $read && ! hash_equals( $read, hash( 'sha256', (string) $post->post_content ) ) ) {
			return new WP_Error(
				'workspace_post_change_content_changed',
				__( 'This post\'s content changed after you read it. Read it again with the read content bodies tool and "raw": true, then propose the change from its new "content_raw".', 'ai' ),
				array( 'status' => 409 )
			);
		}

		/*
		 * An edited item keeps the snapshot it was proposed with. Only a hash is
		 * kept, since the value is only ever compared, never shown.
		 */
		$snapshot = isset( $item[ self::SNAPSHOT_FIELD ] ) && is_string( $item[ self::SNAPSHOT_FIELD ] )
			? $item[ self::SNAPSHOT_FIELD ]
			: hash( 'sha256', $this->current_value( $post, $field ) );

		return array(
			'post_id'            => (string) $post->ID,
			'field'              => $field,
			'value'              => $value,
			self::SNAPSHOT_FIELD => $snapshot,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The current value, title and status are read when the card loads, not
	 * stored, so the card shows the post as it is now. The snapshot is not shown.
	 *
	 * @since x.x.x
	 */
	public function present_item( array $item ): array {
		$post  = $this->item_post( $item );
		$field = $this->field( $item, 'field' );

		$status = $post instanceof WP_Post ? $post->post_status : '';

		return array(
			'post_id'   => $post instanceof WP_Post ? $post->ID : 0,
			'post_type' => $post instanceof WP_Post ? $post->post_type : '',
			'title'     => $post instanceof WP_Post ? $post->post_title : '',
			'status'    => $status,
			'live'      => 'publish' === $status,
			'field'     => $field,
			'current'   => $post instanceof WP_Post && isset( self::FIELDS[ $field ] ) ? $this->current_value( $post, $field ) : '',
			'value'     => $this->field( $item, 'value' ),
		);
	}

	/**
	 * Writes one field change.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item            The proposal item.
	 * @param string               $proposal_id     The proposal ID.
	 * @param string               $conversation_id The conversation ID.
	 * @return array<string, mixed> The item outcome.
	 */
	protected function write_item( array $item, string $proposal_id, string $conversation_id ): array {
		$post  = $this->item_post( $item );
		$field = $this->field( $item, 'field' );
		$value = $this->field( $item, 'value' );

		$started = microtime( true );

		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status || ! isset( self::FIELDS[ $field ] ) ) {
			$this->log( $item, $proposal_id, $conversation_id, 'error', 0, 'post_not_found', 'The post is gone or in the trash.' );

			return $this->outcome( $item, 'failed', 'post_not_found', __( 'That post no longer exists or is in the trash.', 'ai' ) );
		}

		/*
		 * Re-checked here, not only when the proposal was made: a capability can
		 * be revoked between the two requests, and this is the one that writes.
		 */
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			$this->log( $item, $proposal_id, $conversation_id, Turn_Runner::LOG_STATUS_DENIED, 0, 'cannot_edit_post', 'The user may no longer edit this post.' );

			return $this->outcome( $item, 'denied', 'cannot_edit_post', __( 'You no longer have permission to edit this post.', 'ai' ) );
		}

		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		if ( false !== wp_check_post_lock( $post->ID ) ) {
			$this->log( $item, $proposal_id, $conversation_id, 'skipped', 0, 'post_locked', 'Another user is editing the post.' );

			return $this->outcome( $item, 'locked', 'post_locked', __( 'Someone else is editing this post, so it was left as it is.', 'ai' ) );
		}

		$current = $this->current_value( $post, $field );

		if ( $current === $value ) {
			$this->log( $item, $proposal_id, $conversation_id, 'skipped', 0, 'unchanged', '' );

			return $this->outcome( $item, 'unchanged', '', '' );
		}

		/*
		 * Compared with this field's own value at propose time, not the post's
		 * modified date, so writing one field of a post does not make the next
		 * item, another field of the same post, look stale.
		 */
		if ( ! hash_equals( $this->field( $item, self::SNAPSHOT_FIELD ), hash( 'sha256', $current ) ) ) {
			$this->log( $item, $proposal_id, $conversation_id, 'skipped', 0, 'stale', 'The field changed after the proposal was made.' );

			return $this->outcome( $item, 'stale', 'stale', __( 'This field changed after the change was proposed, so it was left as it is.', 'ai' ) );
		}

		/*
		 * `wp_update_post()` unslashes what it is given, so the value is slashed
		 * first, or a backslash in it would be lost. Only the one column is passed,
		 * so status, author and type stay as they are.
		 */
		$kept = $this->keep_other_columns( $post, self::FIELDS[ $field ] );

		try {
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'                   => $post->ID,
						self::FIELDS[ $field ] => $value,
					)
				),
				true
			);
		} finally {
			foreach ( $kept as $hook => $callback ) {
				remove_filter( $hook, $callback, PHP_INT_MAX );
			}
		}

		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();

			$this->log( $item, $proposal_id, $conversation_id, 'error', $duration, $code, $result->get_error_message() );

			return $this->outcome( $item, 'failed', $code, $result->get_error_message() );
		}

		$this->log( $item, $proposal_id, $conversation_id, 'success', $duration, '', '' );

		return $this->outcome( $item, 'updated', '', '' );
	}

	/**
	 * Keeps every text column but the one being written exactly as stored.
	 *
	 * `wp_update_post()` saves the whole row, so each column goes back through
	 * its save filters, and for a user without `unfiltered_html` that includes
	 * kses. Changing only a post's title would then strip an iframe from its
	 * content, which is the silent loss the content rule refuses to cause. So
	 * the row's other columns are put back as stored, slashed as the row is at
	 * that point, after every save filter has run.
	 *
	 * The row is matched by ID: a `save_post` hook that saves another post
	 * during this write gets that post's own columns, not these.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post   The post being written.
	 * @param string   $column The column being written.
	 * @return array<string, \Closure> The added filters keyed by hook, for removal.
	 */
	private function keep_other_columns( WP_Post $post, string $column ): array {
		$stored = array();

		foreach ( array( 'post_title', 'post_excerpt', 'post_content', 'post_content_filtered' ) as $other ) {
			if ( $other === $column ) {
				continue;
			}

			$stored[ $other ] = wp_slash( (string) $post->$other );
		}

		$post_id = (int) $post->ID;

		$keep = static function ( $data, $postarr ) use ( $stored, $post_id ) {
			if ( ! is_array( $data ) || ! is_array( $postarr ) || ! isset( $postarr['ID'] ) || $post_id !== (int) $postarr['ID'] ) {
				return $data;
			}

			return array_merge( $data, $stored );
		};

		add_filter( 'wp_insert_post_data', $keep, PHP_INT_MAX, 2 );

		return array( 'wp_insert_post_data' => $keep );
	}

	/**
	 * Whether `wp_kses_post()` would remove anything from a post's content.
	 *
	 * A byte comparison is not enough: kses rebuilds every tag it keeps, so a
	 * block editor `<img .../>` comes back as `<img ... />`, `&` in an attribute
	 * as `&amp;`, and a style attribute with its spacing normalized. None of that
	 * removes anything, and refusing on it would refuse every image or separator
	 * post. So the two versions are compared as parsed markup instead: the same
	 * tags in the same order, each with the same attribute names and decoded
	 * values, and the same comments and decoded text between them. Anything kses
	 * strips — a tag, an `on*` attribute, a disallowed protocol, a CSS
	 * declaration — changes that sequence and the post is refused.
	 *
	 * Markup the parser cannot finish reading is treated as stripped.
	 *
	 * @since x.x.x
	 *
	 * @param string $content The stored content.
	 * @return bool True when kses would remove something.
	 */
	private function kses_would_remove_markup( string $content ): bool {
		$filtered = wp_kses_post( $content );

		if ( $filtered === $content ) {
			return false;
		}

		$original = $this->markup_tokens( $content );
		$kept     = $this->markup_tokens( $filtered );

		return null === $original || null === $kept || $original !== $kept;
	}

	/**
	 * Reads markup as a list of tokens that ignores how it is serialized.
	 *
	 * Each tag is its name, whether it closes, its attributes (names with their
	 * decoded values, sorted) and any raw text it holds, such as a textarea's.
	 * Adjacent text is joined and decoded; comments keep their text. Void-tag
	 * slashes, attribute quoting and order, and entity spelling do not appear.
	 *
	 * @since x.x.x
	 *
	 * @param string $html The markup.
	 * @return list<array<int, mixed>>|null The tokens, or null when the markup could not be read to its end.
	 */
	private function markup_tokens( string $html ): ?array {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$tokens    = array();
		$text      = null;

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( '#text' === $type ) {
				$text = ( $text ?? '' ) . $processor->get_modifiable_text();
				continue;
			}

			if ( null !== $text ) {
				$tokens[] = array( '#text', $text );
				$text     = null;
			}

			if ( '#tag' !== $type ) {
				$tokens[] = array( (string) $type, $processor->get_modifiable_text() );
				continue;
			}

			$attributes = array();

			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				$value = $processor->get_attribute( $name );

				// Kses rejoins the declarations it keeps without spacing around `;`.
				if ( 'style' === $name && is_string( $value ) ) {
					$value = implode(
						';',
						array_filter(
							array_map( 'trim', explode( ';', $value ) ),
							static function ( string $declaration ): bool {
								return '' !== $declaration;
							}
						)
					);
				}

				$attributes[ $name ] = $value;
			}

			ksort( $attributes );

			$tokens[] = array(
				( $processor->is_tag_closer() ? '/' : '' ) . (string) $processor->get_tag(),
				$attributes,
				$processor->get_modifiable_text(),
			);
		}

		if ( $processor->paused_at_incomplete_token() ) {
			return null;
		}

		if ( null !== $text ) {
			$tokens[] = array( '#text', $text );
		}

		return $tokens;
	}

	/**
	 * Sanitizes and validates a proposed value for a field.
	 *
	 * @since x.x.x
	 *
	 * @param string $field The field.
	 * @param string $value The raw value.
	 * @return string|\WP_Error The value to store, or an error.
	 */
	private function normalize_value( string $field, string $value ) {
		if ( 'title' !== $field ) {
			$value = wp_kses_post( $value );

			if ( 'content' === $field && '' === trim( $value ) ) {
				return new WP_Error(
					'workspace_post_change_empty',
					__( 'A post\'s new content cannot be empty.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			return $value;
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return new WP_Error(
				'workspace_post_change_empty',
				__( 'A post\'s new title cannot be empty.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( wp_strip_all_tags( $value ) !== $value ) {
			return new WP_Error(
				'workspace_post_change_title_markup',
				__( 'A title must be plain text, with no HTML tags.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( mb_strlen( $value ) > self::TITLE_MAX_LENGTH ) {
			return new WP_Error(
				'workspace_post_change_title_too_long',
				sprintf(
					/* translators: %d: the maximum title length in characters. */
					__( 'A title can be at most %d characters long.', 'ai' ),
					self::TITLE_MAX_LENGTH
				),
				array( 'status' => 400 )
			);
		}

		return $value;
	}

	/**
	 * Builds one item outcome.
	 *
	 * A written title or excerpt is reported in full. Content is reported by its
	 * length only, to keep the model's context small.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item          The proposal item.
	 * @param string               $outcome       The outcome name.
	 * @param string               $error_code    The error code, when there is one.
	 * @param string               $error_message The error message, when there is one.
	 * @return array<string, mixed> The outcome record.
	 */
	private function outcome( array $item, string $outcome, string $error_code, string $error_message ): array {
		$post  = $this->item_post( $item );
		$field = $this->field( $item, 'field' );
		$value = $this->field( $item, 'value' );

		$record = array(
			'key'           => $this->field( $item, 'key' ),
			'title'         => $post instanceof WP_Post ? $post->post_title : '',
			'post_type'     => $post instanceof WP_Post ? $post->post_type : '',
			'status'        => $post instanceof WP_Post ? $post->post_status : '',
			'outcome'       => $outcome,
			'post_id'       => $post instanceof WP_Post ? $post->ID : 0,
			'edit_link'     => $post instanceof WP_Post ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
			'error_code'    => $error_code,
			'error_message' => $error_message,
			'field'         => $field,
		);

		if ( 'content' === $field ) {
			$record['length'] = mb_strlen( $value );
		} else {
			$record['value'] = $value;
		}

		return $record;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function deselected_outcome( array $item ): array {
		return $this->outcome( $item, 'deselected', '', '' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function log_details( array $item ): array {
		$post = $this->item_post( $item );

		return array(
			'post_id'   => $post instanceof WP_Post ? $post->ID : 0,
			'post_type' => $post instanceof WP_Post ? $post->post_type : '',
			'status'    => $post instanceof WP_Post ? $post->post_status : '',
			'field'     => $this->field( $item, 'field' ),
		);
	}

	/**
	 * Returns a post's current raw value for a field.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post  The post.
	 * @param string   $field The field, one of {@see self::FIELDS}.
	 * @return string The value.
	 */
	private function current_value( WP_Post $post, string $field ): string {
		$column = self::FIELDS[ $field ];

		return (string) $post->$column;
	}

	/**
	 * Returns the post an item names, read fresh.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item The stored item.
	 * @return \WP_Post|null The post, or null.
	 */
	private function item_post( array $item ): ?WP_Post {
		$post_id = isset( $item['post_id'] ) && is_numeric( $item['post_id'] ) ? (int) $item['post_id'] : 0;

		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );

		return $post instanceof WP_Post ? $post : null;
	}
}
