<?php
/**
 * Content classification experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Classification;

use WordPress\AI\Abilities\Content_Classification\Content_Classification as Content_Classification_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Embeddings\Sync\Backfill_Manager;
use WordPress\AI\Embeddings\Sync\Embedding_Sync;
use WordPress\AI\Embeddings\Sync\Embedding_Target;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Settings\Settings_Registration;

use function WordPress\AI\get_min_content_length;
use function WordPress\AI\register_embedding_consumer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content classification experiment.
 *
 * Provides AI-powered suggestions for post taxonomies
 * based on a comprehensive analysis of the post content.
 *
 * @since 0.7.0
 */
class Content_Classification extends Abstract_Feature {

	/**
	 * The default taxonomy strategy.
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	public const STRATEGY_EXISTING_ONLY = 'existing_only';

	/**
	 * The strategy that allows new term suggestions.
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	public const STRATEGY_ALLOW_NEW = 'allow_new';

	/**
	 * The default maximum number of suggestions.
	 *
	 * @since 0.7.0
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_SUGGESTIONS = 5;

	/**
	 * The minimum allowed number of suggestions.
	 *
	 * @since 0.7.0
	 *
	 * @var int
	 */
	public const MIN_SUGGESTIONS = 1;

	/**
	 * The maximum allowed number of suggestions.
	 *
	 * @since 0.7.0
	 *
	 * @var int
	 */
	public const MAX_SUGGESTIONS = 10;

	/**
	 * The suggestion method that prompts a language model.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const METHOD_LLM = 'llm';

	/**
	 * The suggestion method that ranks existing terms by embedding similarity.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const METHOD_EMBEDDINGS = 'embeddings';

	/**
	 * The taxonomies whose terms are embedded in embedding mode.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	public const EMBEDDING_TAXONOMIES = array( 'category', 'post_tag' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'content-classification';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		$embeddings = self::METHOD_EMBEDDINGS === $this->get_method();

		return array(
			'label'       => __( 'Content Classification', 'ai' ),
			'description' => $embeddings
				? __( 'AI-powered suggestions for post tags and categories based on content analysis. Requires an AI connector that includes support for embedding models.', 'ai' )
				: __( 'AI-powered suggestions for post tags and categories based on content analysis. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
			'capability'  => $embeddings ? 'embedding_generation' : 'text_generation',
			'usage'       => array(
				'trigger'  => 'on_demand',
				'runs'     => __( 'When you click Suggest Tags or Suggest Categories', 'ai' ),
				'sends'    => $embeddings
					? __( 'Post title and content (to your embedding provider), plus your categories and tags, embedded in the background once and again when they change', 'ai' )
					: __( 'Post content, title, slug, author name, excerpt, content type, the terms already assigned, and up to 100 existing terms to choose from', 'ai' ),
				'requests' => $embeddings
					? __( 'One embedding request per click when the post has changed; background requests while term embeddings are generated', 'ai' )
					: __( 'One request per click', 'ai' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_model_capability(): string {
		return 'text_generation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_developer_settings_lock(): array {
		return array(
			'field'  => 'method',
			'equals' => self::METHOD_EMBEDDINGS,
			'note'   => __( 'Similarity matching uses the Embedding model setting above. This selection applies when Suggestion method is set to AI model.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		if ( ! $this->is_embedding_mode() ) {
			return;
		}

		$embedding_model = $this->get_embedding_model();

		register_embedding_consumer(
			static::get_id(),
			array(
				'provider' => $embedding_model['provider'],
				'model'    => $embedding_model['model'],
				'objects'  => array( 'term' => self::EMBEDDING_TAXONOMIES ),
			)
		);
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.7.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Content_Classification_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since 0.7.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load asset in new post and edit post screens only.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		// Load the assets only if the post type supports categories or tags and is not an attachment.
		// Also check if the user can manage categories.
		if (
			! $screen ||
			! current_user_can( 'manage_categories' ) ||
			in_array( $screen->post_type, array( 'attachment' ), true ) ||
			(
				! is_object_in_taxonomy( $screen->post_type, 'category' ) &&
				! is_object_in_taxonomy( $screen->post_type, 'post_tag' )
			)
		) {
			return;
		}

		$method            = $this->get_method();
		$embeddings_ready  = true;
		$embeddings_notice = '';

		if ( self::METHOD_EMBEDDINGS === $method ) {
			$readiness        = $this->get_embedding_readiness();
			$embeddings_ready = $readiness['ready'];

			if ( ! $embeddings_ready ) {
				if ( 'not_configured' !== $readiness['reason'] ) {
					$embeddings_notice = __( 'Suggestions will be available once term embeddings finish generating.', 'ai' );
				} elseif ( current_user_can( 'manage_options' ) ) {
					$embeddings_notice = __( 'Similarity matching needs an embedding model. Choose one in Settings > AI.', 'ai' );
				} else {
					// Users without manage_options cannot reach Settings > AI.
					$embeddings_notice = __( 'Similarity matching needs an embedding model. Ask an administrator to choose one.', 'ai' );
				}
			}
		}

		Asset_Loader::enqueue_script( 'content_classification', 'experiments/content-classification', array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( 'content_classification', 'experiments/content-classification' );
		Asset_Loader::localize_script(
			'content_classification',
			'ContentClassificationData',
			array(
				'enabled'          => $this->is_enabled(),
				'strategy'         => $this->get_strategy(),
				'maxSuggestions'   => $this->get_max_suggestions(),
				'minContentLength' => get_min_content_length( 'content-classification', 250 ),
				'method'           => $method,
				'embeddingsReady'  => $embeddings_ready,
				'embeddingsNotice' => $embeddings_notice,
				'settingsUrl'      => current_user_can( 'manage_options' ) ? admin_url( 'options-general.php?page=ai-wp-admin' ) : '',
			)
		);
	}

	/**
	 * Registers experiment-specific settings.
	 *
	 * @since 0.7.0
	 */
	public function register_settings(): void {
		// Registered here rather than in register(), which only runs while the feature is enabled, so a model change always prunes the old vectors.
		$model_change_hook = 'update_option_' . static::get_field_option_name( 'embedding_model' );

		if ( false === has_action( $model_change_hook, array( $this, 'handle_embedding_model_change' ) ) ) {
			add_action( $model_change_hook, array( $this, 'handle_embedding_model_change' ), 10, 2 );
		}

		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'method' ),
			array(
				'type'              => 'string',
				'default'           => self::METHOD_LLM,
				'sanitize_callback' => array( $this, 'sanitize_method' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( self::METHOD_LLM, self::METHOD_EMBEDDINGS ),
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'embedding_model' ),
			array(
				'type'              => 'object',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_embedding_model' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'provider' => array(
								'type'    => 'string',
								'default' => '',
							),
							'model'    => array(
								'type'    => 'string',
								'default' => '',
							),
						),
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'strategy' ),
			array(
				'type'              => 'string',
				'default'           => self::STRATEGY_EXISTING_ONLY,
				'sanitize_callback' => array( $this, 'sanitize_strategy' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( self::STRATEGY_EXISTING_ONLY, self::STRATEGY_ALLOW_NEW ),
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'max_suggestions' ),
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_MAX_SUGGESTIONS,
				'sanitize_callback' => array( $this, 'sanitize_max_suggestions' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => self::MIN_SUGGESTIONS,
						'maximum' => self::MAX_SUGGESTIONS,
					),
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		$embedding_only = array(
			'field'  => 'method',
			'equals' => self::METHOD_EMBEDDINGS,
		);

		return array(
			array(
				'id'       => 'method',
				'label'    => __( 'Suggestion method', 'ai' ),
				'type'     => 'text',
				'default'  => self::METHOD_LLM,
				'elements' => array(
					array(
						'value' => self::METHOD_LLM,
						'label' => __( 'AI model (sends content to a language model)', 'ai' ),
					),
					array(
						'value' => self::METHOD_EMBEDDINGS,
						'label' => __( 'Similarity matching (embeddings)', 'ai' ),
					),
				),
			),
			array(
				'id'          => 'embedding_model',
				'label'       => __( 'Embedding model', 'ai' ),
				'edit'        => 'embedding-model',
				'visibleWhen' => $embedding_only,
			),
			array(
				'id'          => 'embedding_sync',
				'label'       => __( 'Embeddings', 'ai' ),
				'edit'        => 'embedding-sync',
				'visibleWhen' => $embedding_only,
				'props'       => array( 'consumer' => static::get_id() ),
			),
			array(
				'id'           => 'strategy',
				'label'        => __( 'Taxonomy strategy', 'ai' ),
				'type'         => 'text',
				'default'      => self::STRATEGY_EXISTING_ONLY,
				'disabledWhen' => array(
					'field'  => 'method',
					'equals' => self::METHOD_EMBEDDINGS,
					'note'   => __( 'Similarity matching can only suggest existing terms.', 'ai' ),
				),
				'elements'     => array(
					array(
						'value' => self::STRATEGY_EXISTING_ONLY,
						'label' => __( 'Only suggest existing terms', 'ai' ),
					),
					array(
						'value' => self::STRATEGY_ALLOW_NEW,
						'label' => __( 'Suggest new terms based on context', 'ai' ),
					),
				),
			),
			array(
				'id'      => 'max_suggestions',
				'label'   => __( 'Maximum suggestions', 'ai' ),
				'type'    => 'integer',
				'default' => self::DEFAULT_MAX_SUGGESTIONS,
				'isValid' => array(
					'min' => self::MIN_SUGGESTIONS,
					'max' => self::MAX_SUGGESTIONS,
				),
			),
		);
	}

	/**
	 * Sanitizes the strategy setting.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string The sanitized strategy value.
	 */
	public function sanitize_strategy( $value ): string {
		$valid = array( self::STRATEGY_EXISTING_ONLY, self::STRATEGY_ALLOW_NEW );

		return in_array( $value, $valid, true ) ? $value : self::STRATEGY_EXISTING_ONLY;
	}

	/**
	 * Sanitizes the suggestion method setting.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string The sanitized method, `llm` when invalid.
	 */
	public function sanitize_method( $value ): string {
		return in_array( $value, array( self::METHOD_LLM, self::METHOD_EMBEDDINGS ), true ) ? $value : self::METHOD_LLM;
	}

	/**
	 * Sanitizes the embedding model setting.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The value to sanitize.
	 * @return array{provider: string, model: string} The trimmed provider and model IDs.
	 */
	public function sanitize_embedding_model( $value ): array {
		return self::normalize_embedding_model( $value );
	}

	/**
	 * Sanitizes the max suggestions setting.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $value The value to sanitize.
	 * @return int The sanitized max suggestions value.
	 */
	public function sanitize_max_suggestions( $value ): int {
		$value = absint( $value );

		return max( self::MIN_SUGGESTIONS, min( self::MAX_SUGGESTIONS, $value ) );
	}

	/**
	 * Gets the strategy to use for content classification.
	 *
	 * @since 0.7.0
	 *
	 * @return string The strategy to use.
	 */
	public function get_strategy(): string {
		// Similarity matching ranks existing terms, so it can never suggest new ones.
		if ( self::METHOD_EMBEDDINGS === $this->get_method() ) {
			return self::STRATEGY_EXISTING_ONLY;
		}

		$strategy = get_option( static::get_field_option_name( 'strategy' ), self::STRATEGY_EXISTING_ONLY );

		/**
		 * Filters the strategy to use for content classification.
		 *
		 * @since 0.7.0
		 *
		 * @param string $strategy The strategy to use.
		 * @return string The filtered strategy.
		 */
		$strategy = apply_filters( 'wpai_content_classification_strategy', $strategy );

		// Return the sanitized strategy value.
		return $this->sanitize_strategy( $strategy );
	}

	/**
	 * Gets the maximum number of suggestions to generate for content classification.
	 *
	 * @since 0.7.0
	 *
	 * @return int The maximum number of suggestions to generate.
	 */
	public function get_max_suggestions(): int {
		$max_suggestions = (int) get_option( static::get_field_option_name( 'max_suggestions' ), self::DEFAULT_MAX_SUGGESTIONS );

		/**
		 * Filters the maximum number of suggestions to generate for content classification.
		 *
		 * @since 0.7.0
		 *
		 * @param int $max_suggestions The maximum number of suggestions to generate.
		 * @return int The filtered max suggestions.
		 */
		return $this->sanitize_max_suggestions(
			apply_filters( 'wpai_content_classification_max_suggestions', $max_suggestions )
		);
	}

	/**
	 * Gets the saved suggestion method.
	 *
	 * @since x.x.x
	 *
	 * @return string Either `llm` (the default) or `embeddings`.
	 */
	public function get_method(): string {
		return $this->sanitize_method( get_option( static::get_field_option_name( 'method' ), self::METHOD_LLM ) );
	}

	/**
	 * Gets the saved embedding model.
	 *
	 * @since x.x.x
	 *
	 * @return array{provider: string, model: string} The provider and model IDs; empty strings when unset.
	 */
	public function get_embedding_model(): array {
		return self::normalize_embedding_model( get_option( static::get_field_option_name( 'embedding_model' ), array() ) );
	}

	/**
	 * Checks whether suggestions use embeddings.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the method is `embeddings` and an embedding model is chosen.
	 */
	public function is_embedding_mode(): bool {
		if ( self::METHOD_EMBEDDINGS !== $this->get_method() ) {
			return false;
		}

		$embedding_model = $this->get_embedding_model();

		return '' !== $embedding_model['provider'] && '' !== $embedding_model['model'];
	}

	/**
	 * Checks whether embedding suggestions can run.
	 *
	 * @since x.x.x
	 *
	 * @return array{ready: bool, reason: string} Readiness; `reason` is `''` when ready, else `not_configured`, `running` or `not_embedded`.
	 */
	public function get_embedding_readiness(): array {
		$status = $this->is_embedding_mode() ? Embedding_Sync::get_status( static::get_id() ) : null;

		if ( null === $status ) {
			return self::readiness( 'not_configured' );
		}

		if ( is_array( $status['backfill'] ) && Backfill_Manager::STATUS_RUNNING === ( $status['backfill']['status'] ?? null ) ) {
			return self::readiness( 'running' );
		}

		$any_indexed = false;

		foreach ( self::EMBEDDING_TAXONOMIES as $taxonomy ) {
			$coverage = $status['coverage']['term'][ $taxonomy ] ?? array(
				'indexed'   => 0,
				'indexable' => 0,
			);

			if ( $coverage['indexable'] < 1 ) {
				continue;
			}

			if ( $coverage['indexed'] < 1 ) {
				return self::readiness( 'not_embedded' );
			}

			$any_indexed = true;
		}

		return $any_indexed ? self::readiness( '' ) : self::readiness( 'not_embedded' );
	}

	/**
	 * Deletes the previous embedding model's vectors once a different model is saved.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $old_value The previous option value.
	 * @param mixed $value     The new option value.
	 */
	public function handle_embedding_model_change( $old_value, $value ): void {
		$old = self::normalize_embedding_model( $old_value );
		$new = self::normalize_embedding_model( $value );

		if ( '' === $old['provider'] || '' === $old['model'] || $old === $new ) {
			return;
		}

		$old_key  = Embedding_Target::key_for( $old['provider'], $old['model'] );
		$registry = Embedding_Sync::instance()->get_registry();

		foreach ( $registry->get_consumer_ids() as $consumer_id ) {
			if ( static::get_id() === $consumer_id ) {
				continue;
			}

			$consumer = $registry->get_consumer( $consumer_id );

			if ( null !== $consumer && Embedding_Target::key_for( $consumer['provider'], $consumer['model'] ) === $old_key ) {
				return;
			}
		}

		Embedding_Sync::prune_target( $old['provider'], $old['model'] );
	}

	/**
	 * Normalizes an embedding model value.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The raw value.
	 * @return array{provider: string, model: string} The trimmed provider and model IDs, in a fixed key order.
	 */
	private static function normalize_embedding_model( $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'provider' => is_string( $value['provider'] ?? null ) ? trim( $value['provider'] ) : '',
			'model'    => is_string( $value['model'] ?? null ) ? trim( $value['model'] ) : '',
		);
	}

	/**
	 * Builds a readiness result.
	 *
	 * @since x.x.x
	 *
	 * @param string $reason Why suggestions can't run, or `''` when they can.
	 * @return array{ready: bool, reason: string} The readiness result.
	 */
	private static function readiness( string $reason ): array {
		return array(
			'ready'  => '' === $reason,
			'reason' => $reason,
		);
	}
}
