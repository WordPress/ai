<?php
/**
 * The `template` kind of AI Workspace proposal.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Block_Template;
use WP_Block_Type_Registry;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Proposes and writes one new block template for the active theme.
 *
 * Approval creates a `wp_template` post for the active theme, which is the same
 * row the Site Editor writes when someone saves a template there. Three rules
 * bound it:
 *
 * - **It only creates.** A template someone already edited for this theme is
 *   never overwritten, so a slug that already has one is refused. Editing an
 *   existing template is follow-up work.
 * - **It may replace a template file the theme ships**, such as `404.html`. The
 *   new template takes over from the file, the card says so before approval, and
 *   the Site Editor's Reset puts the theme's own version back.
 * - **The markup is block markup.** It is filtered with `wp_kses_post()` when the
 *   proposal is stored, for the reasons {@see Draft_Writer::normalize_item()}
 *   gives, and then refused if it holds no blocks, any HTML outside them, or a
 *   block the site does not register. A
 *   template is shown to every visitor as soon as it is approved, so markup the
 *   block editor would not recognize is not let through.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Template_Writer extends Proposal_Writer {

	/**
	 * The kind name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const KIND = 'template';

	/**
	 * Operation name recorded on every log row this class writes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const LOG_OPERATION = 'ai/create-template';

	/**
	 * The capability a person needs to propose or create a template.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CAPABILITY = 'edit_theme_options';

	/**
	 * The post type a template is stored as.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const POST_TYPE = 'wp_template';

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
	 * One template per proposal, so the card has one thing to approve.
	 *
	 * @since x.x.x
	 */
	public function max_items(): int {
		return 1;
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
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function ability(): string {
		return Propose_Template::ABILITY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Templates belong to the theme, so only someone who may edit the theme is
	 * offered a way to propose one.
	 *
	 * @since x.x.x
	 */
	public function capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function instruction(): string {
		return __( 'To create a block template for the active theme, call the template proposal tool with its slug, title and complete block markup; the person approves it the same way, and proposing it also ends your turn.', 'ai' );
	}

	/**
	 * Returns the template slugs that may be proposed.
	 *
	 * The standard template hierarchy, as core lists it, less the per-format
	 * archives. A `page-{slug}` template for one page is allowed as well, by
	 * {@see self::is_allowed_slug()}.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The slugs.
	 */
	public static function standard_slugs(): array {
		$slugs = array();

		// Keys such as `404` come back as integers, so each is cast back to the slug.
		foreach ( array_keys( get_default_block_template_types() ) as $slug ) {
			$slug = (string) $slug;

			if ( 0 === strpos( $slug, 'taxonomy-post_format-' ) ) {
				continue;
			}

			$slugs[] = $slug;
		}

		return $slugs;
	}

	/**
	 * Normalizes and validates the proposed template.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $item The raw item.
	 * @return array<string, string>|\WP_Error The normalized item, or an error.
	 */
	public function normalize_item( array $item ) {
		$refused = $this->theme_refusal();

		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$slug = isset( $item['slug'] ) && is_string( $item['slug'] ) ? strtolower( trim( $item['slug'] ) ) : '';

		if ( ! $this->is_allowed_slug( $slug ) ) {
			return new WP_Error(
				'workspace_template_invalid_slug',
				sprintf(
					/* translators: %s: comma-separated list of template slugs. */
					__( 'A template slug must be one of %s, or page- followed by a page slug.', 'ai' ),
					implode( ', ', self::standard_slugs() )
				),
				array( 'status' => 400 )
			);
		}

		if ( $this->edited_template( $slug ) instanceof WP_Block_Template ) {
			return new WP_Error(
				'workspace_template_exists',
				__( 'This site already has an edited version of that template, and the assistant only creates new templates. Edit it in the Site Editor instead.', 'ai' ),
				array( 'status' => 409 )
			);
		}

		$title = isset( $item['title'] ) && is_string( $item['title'] ) ? trim( $item['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error(
				'workspace_proposal_missing_title',
				__( 'Each proposed item needs a title.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$content = isset( $item['content'] ) && is_string( $item['content'] ) ? wp_kses_post( $item['content'] ) : '';

		$blocks = parse_blocks( $content );

		if ( ! $this->is_block_markup( $blocks ) ) {
			return new WP_Error(
				'workspace_template_invalid_markup',
				__( 'A template must be block markup: at least one block, and no HTML outside the block comments.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$unknown = $this->unknown_block( $blocks );

		if ( '' !== $unknown ) {
			return new WP_Error(
				'workspace_template_unknown_block',
				sprintf(
					/* translators: %s: a block name, such as core/hero. */
					__( 'The template uses a block this site does not have: %s. Use only blocks registered on the site.', 'ai' ),
					$unknown
				),
				array( 'status' => 400 )
			);
		}

		return array(
			'slug'    => $slug,
			'title'   => $title,
			'content' => $content,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The theme and whether a file template is being replaced are read when the
	 * card loads, not stored, so the card reflects the site as it is now.
	 *
	 * @since x.x.x
	 */
	public function present_item( array $item ): array {
		$slug = $this->field( $item, 'slug' );

		return array(
			'post_type'               => self::POST_TYPE,
			'status'                  => 'publish',
			'slug'                    => $slug,
			'title'                   => $this->field( $item, 'title' ),
			'content'                 => $this->field( $item, 'content' ),
			'theme'                   => (string) wp_get_theme()->get( 'Name' ),
			'replaces_theme_template' => '' !== $slug && $this->theme_file_template( $slug ) instanceof WP_Block_Template,
		);
	}

	/**
	 * Writes the template.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item            The proposal item.
	 * @param string               $proposal_id     The proposal ID.
	 * @param string               $conversation_id The conversation ID.
	 * @return array<string, mixed> The item outcome.
	 */
	protected function write_item( array $item, string $proposal_id, string $conversation_id ): array {
		$key  = $this->field( $item, 'key' );
		$slug = $this->field( $item, 'slug' );

		$started = microtime( true );

		if ( ! wp_is_block_theme() ) {
			$this->log( $item, $proposal_id, $conversation_id, 'error', 0, 'classic_theme', 'The active theme is no longer a block theme.' );

			return $this->outcome( $item, 'failed', 0, 'classic_theme', __( 'The active theme no longer uses block templates.', 'ai' ) );
		}

		/*
		 * Re-checked here, not only when the proposal was made: a capability can
		 * be revoked between the two requests, and this is the one that writes.
		 */
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capability is the literal edit_theme_options, held in a constant.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			$this->log( $item, $proposal_id, $conversation_id, Turn_Runner::LOG_STATUS_DENIED, 0, 'cannot_edit_theme_options', 'The user may no longer edit templates.' );

			return $this->outcome( $item, 'denied', 0, 'cannot_edit_theme_options', __( 'You no longer have permission to create this item.', 'ai' ) );
		}

		$token    = $this->token( $proposal_id, $key );
		$existing = $this->existing_post_id( self::POST_TYPE, $token );

		if ( $existing > 0 ) {
			$this->log( $item, $proposal_id, $conversation_id, 'skipped', 0, 'already_written', '' );

			return $this->outcome( $item, 'duplicate', $existing, '', '' );
		}

		// Someone may have edited this template in the Site Editor since it was proposed.
		if ( $this->edited_template( $slug ) instanceof WP_Block_Template ) {
			$this->log( $item, $proposal_id, $conversation_id, 'error', 0, 'template_exists', 'An edited template with this slug already exists.' );

			return $this->outcome( $item, 'failed', 0, 'template_exists', __( 'This site already has an edited version of that template, so it was left as it is.', 'ai' ) );
		}

		/*
		 * `wp_insert_post()` unslashes what it is given. Block markup carries
		 * backslashes in its attribute JSON (`\u002d`), so the values are slashed
		 * first, or the template written would differ from the one approved.
		 */
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_title'   => $this->field( $item, 'title' ),
					'post_content' => $this->field( $item, 'content' ),
					'post_excerpt' => '',
					'post_author'  => get_current_user_id(),
					'meta_input'   => array( self::IDEMPOTENCY_META => $token ),
				)
			),
			true
		);

		if ( ! is_wp_error( $post_id ) ) {
			// The theme term is what makes the row this theme's template.
			$assigned = wp_set_post_terms( (int) $post_id, array( get_stylesheet() ), 'wp_theme' );

			if ( ! is_array( $assigned ) ) {
				wp_delete_post( (int) $post_id, true );

				$post_id = is_wp_error( $assigned )
					? $assigned
					: new WP_Error( 'template_theme_not_assigned', __( 'The template could not be assigned to the active theme.', 'ai' ) );
			}
		}

		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $post_id ) ) {
			$code = (string) $post_id->get_error_code();

			$this->log( $item, $proposal_id, $conversation_id, 'error', $duration, $code, $post_id->get_error_message() );

			return $this->outcome( $item, 'failed', 0, $code, $post_id->get_error_message() );
		}

		$this->log( $item, $proposal_id, $conversation_id, 'success', $duration, '', '' );

		return $this->outcome( $item, 'created', (int) $post_id, '', '' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function item_post_type( array $item ): string {
		unset( $item );

		return self::POST_TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function item_status( array $item ): string {
		unset( $item );

		return 'publish';
	}

	/**
	 * Returns why a template cannot be proposed for this site and user, if it cannot.
	 *
	 * The message is written for the model to pass on, so the person hears why
	 * rather than seeing a tool fail.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error|null The refusal, or null when a template may be proposed.
	 */
	public function theme_refusal(): ?WP_Error {
		if ( ! wp_is_block_theme() ) {
			return new WP_Error(
				'workspace_template_classic_theme',
				__( 'The active theme is a classic theme, which does not use block templates, so a template cannot be created. Changing how a classic theme lays out a page needs its PHP template files edited, or a switch to a block theme.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capability is the literal edit_theme_options, held in a constant.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error(
				'workspace_template_denied',
				__( 'You cannot edit the theme\'s templates, so a template cannot be proposed.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		return null;
	}

	/**
	 * Reports whether a slug may be proposed.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The slug.
	 * @return bool Whether it is allowed.
	 */
	private function is_allowed_slug( string $slug ): bool {
		if ( in_array( $slug, self::standard_slugs(), true ) ) {
			return true;
		}

		return 1 === preg_match( '/^page-[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug );
	}

	/**
	 * Reports whether markup is only blocks.
	 *
	 * The parser returns anything outside the block comments as a block with no
	 * name. Whitespace between blocks is fine; anything else is HTML the block
	 * editor would show as a classic block, or would not show at all.
	 *
	 * @since x.x.x
	 *
	 * @param array<int|string, array<string, mixed>> $blocks The parsed top-level blocks.
	 * @return bool Whether it holds at least one block and nothing outside them.
	 */
	private function is_block_markup( array $blocks ): bool {
		$named = 0;

		foreach ( $blocks as $block ) {
			if ( null !== $block['blockName'] ) {
				++$named;
				continue;
			}

			if ( '' !== trim( (string) $block['innerHTML'] ) ) {
				return false;
			}
		}

		return $named > 0;
	}

	/**
	 * Returns the first block name the site does not register, at any depth.
	 *
	 * A block the site does not have renders nothing, or its saved HTML raw, and
	 * the Site Editor shows it as unsupported, so a template naming one would go
	 * live broken. A model inventing a plausible name such as `core/hero` is the
	 * likely way that happens.
	 *
	 * @since x.x.x
	 *
	 * @param array<int|string, array<string, mixed>> $blocks The parsed blocks.
	 * @return string The unregistered block name, or an empty string when every block is registered.
	 */
	private function unknown_block( array $blocks ): string {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';

			if ( '' !== $name && ! $registry->is_registered( $name ) ) {
				return $name;
			}

			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $this->unknown_block( $block['innerBlocks'] ) : '';

			if ( '' !== $inner ) {
				return $inner;
			}
		}

		return '';
	}

	/**
	 * Returns the template someone has already edited for this theme, if there is one.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The template slug.
	 * @return \WP_Block_Template|null The edited template, or null.
	 */
	private function edited_template( string $slug ): ?WP_Block_Template {
		$template = get_block_template( get_stylesheet() . '//' . $slug, self::POST_TYPE );

		return $template instanceof WP_Block_Template && 'custom' === $template->source ? $template : null;
	}

	/**
	 * Returns the template file the theme ships for a slug, if it ships one.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The template slug.
	 * @return \WP_Block_Template|null The file template, or null.
	 */
	private function theme_file_template( string $slug ): ?WP_Block_Template {
		$template = get_block_file_template( get_stylesheet() . '//' . $slug, self::POST_TYPE );

		return $template instanceof WP_Block_Template ? $template : null;
	}
}
