<?php
/**
 * Text to speech experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Text_To_Speech;

use WordPress\AI\Abilities\Speech\Generate_Speech;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Settings\Settings_Registration;

use function WordPress\AI\has_text_to_speech_support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text to speech experiment.
 *
 * @since x.x.x
 */
class Text_To_Speech extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'text-to-speech';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Text to Speech', 'ai' ),
			'description' => __( 'Generates an audio version of post content so visitors can listen instead of read. Requires an AI connector that includes support for text to speech models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
			'capability'  => 'text_to_speech_conversion',
			'usage'       => array(
				'trigger'  => 'on_demand',
				'runs'     => __( 'When you click Generate Audio or Regenerate Audio in the editor, then in the background until the audio is done', 'ai' ),
				'sends'    => __( 'The post title and saved post content as plain text, plus the chosen voice', 'ai' ),
				'requests' => __( 'About one request per 4,000 characters of title and content', 'ai' ),
				'details'  => __( 'Adds the audio to the Media Library and shows a player above the post for visitors', 'ai' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->register_post_meta();

		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( Job_Manager::CRON_HOOK, array( $this, 'process_chunk' ) );
		add_filter( 'the_content', array( $this, 'render_audio_player' ) );
		add_action( 'post_updated', array( $this, 'sync_audio_visibility' ), 10, 3 );
	}

	/**
	 * Registers the post meta used to track generated audio.
	 *
	 * @since x.x.x
	 */
	public function register_post_meta(): void {
		register_meta(
			'post',
			Job_Manager::META_DISPLAY,
			array(
				'type'         => 'boolean',
				'single'       => true,
				'default'      => true,
				'show_in_rest' => true,
			)
		);

		register_meta(
			'post',
			Job_Manager::META_AUDIO_ID,
			array(
				'type'    => 'integer',
				'single'  => true,
				'default' => 0,
			)
		);

		register_meta(
			'post',
			Job_Manager::META_STATUS,
			array(
				'type'    => 'string',
				'single'  => true,
				'default' => '',
			)
		);

		register_meta(
			'post',
			Job_Manager::META_ERROR,
			array(
				'type'    => 'string',
				'single'  => true,
				'default' => '',
			)
		);

		register_meta(
			'post',
			Job_Manager::META_UPDATED,
			array(
				'type'    => 'integer',
				'single'  => true,
				'default' => 0,
			)
		);
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/speech-generation',
			array(
				'label'         => __( 'Speech Generation', 'ai' ),
				'description'   => __( 'Generates speech audio from text or from a post&#8217;s content.', 'ai' ),
				'ability_class' => Generate_Speech::class,
			),
		);
	}

	/**
	 * Registers the REST routes used by the editor's background flow.
	 *
	 * @since x.x.x
	 */
	public function register_rest_routes(): void {
		( new REST_Controller() )->register_routes();
	}

	/**
	 * Processes the next audio chunk for a post. Cron callback.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id The post being converted.
	 */
	public function process_chunk( $post_id ): void {
		( new Job_Manager() )->process_chunk( (int) $post_id );
	}

	/**
	 * Keeps a post's audio hidden from visitors while the post has a password.
	 *
	 * @since x.x.x
	 *
	 * @param int      $post_id     The post ID.
	 * @param \WP_Post $post_after  The post after the update.
	 * @param \WP_Post $post_before The post before the update.
	 */
	public function sync_audio_visibility( $post_id, $post_after, $post_before ): void {
		if ( ! $post_after instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
			return;
		}

		// Only a password being added or removed changes who may see the audio.
		if ( ( '' === $post_after->post_password ) === ( '' === $post_before->post_password ) ) {
			return;
		}

		( new Job_Manager() )->sync_audio_visibility( (int) $post_id );
	}

	/**
	 * Enqueues and localizes needed assets.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->base || 'attachment' === $screen->post_type ) {
			return;
		}

		Asset_Loader::enqueue_script( 'text_to_speech', 'experiments/text-to-speech' );
		Asset_Loader::enqueue_style( 'text_to_speech', 'experiments/text-to-speech' );
		Asset_Loader::localize_script(
			'text_to_speech',
			'TextToSpeechData',
			array(
				'enabled'       => $this->is_enabled(),
				'hasTtsSupport' => has_text_to_speech_support(),
			)
		);
	}

	/**
	 * Enqueues the player stylesheet on singular front-end views.
	 *
	 * @since x.x.x
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! is_singular() || ! $this->get_player_audio_id( get_queried_object_id() ) ) {
			return;
		}

		Asset_Loader::enqueue_style( 'text_to_speech', 'experiments/text-to-speech' );
	}

	/**
	 * Returns the audio attachment the front-end player should play for a post.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id The post ID.
	 * @return int The audio attachment ID, or 0 when no player should render.
	 */
	private function get_player_audio_id( int $post_id ): int {
		if ( ! $post_id ) {
			return 0;
		}

		if ( post_password_required( $post_id ) ) {
			return 0;
		}

		// If a post isn't publicly viewable, only show it to users who can edit the post.
		if ( ! is_post_publicly_viewable( $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return 0;
		}

		if ( ! get_post_meta( $post_id, Job_Manager::META_DISPLAY, true ) ) {
			return 0;
		}

		$audio_id = absint( get_post_meta( $post_id, Job_Manager::META_AUDIO_ID, true ) );

		if ( ! $audio_id || ! wp_get_attachment_url( $audio_id ) ) {
			return 0;
		}

		return $audio_id;
	}

	/**
	 * Prepends the audio player to post content on singular front-end views.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $content The post content.
	 * @return mixed The content, with the player prepended when applicable.
	 */
	public function render_audio_player( $content ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}

		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = (int) get_the_ID();

		// Ensure we're not in a Query Loop or other nested content.
		if ( get_queried_object_id() !== $post_id ) {
			return $content;
		}

		$audio_id = $this->get_player_audio_id( $post_id );

		if ( ! $audio_id ) {
			return $content;
		}

		$audio_url = (string) wp_get_attachment_url( $audio_id );

		$player = sprintf(
			'<div class="wpai-tts-player"><span class="wpai-tts-player__label">%s</span><audio controls preload="metadata" src="%s"></audio></div>',
			esc_html__( 'Listen to this post', 'ai' ),
			esc_url( $audio_url )
		);

		/**
		 * Filters the audio player markup rendered above post content.
		 *
		 * @since x.x.x
		 *
		 * @param string $player   The player markup.
		 * @param int    $post_id  The post ID.
		 * @param int    $audio_id The audio attachment ID.
		 */
		$player = (string) apply_filters( 'wpai_tts_player_markup', $player, $post_id, $audio_id );

		return $player . $content;
	}

	/**
	 * Registers experiment-specific settings.
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'voice' ),
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
					),
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		$field = array(
			'id'      => 'voice',
			'label'   => __( 'Voice', 'ai' ),
			'type'    => 'text',
			'default' => '',
		);

		$resolver = new Voice_Resolver();
		$voices   = $resolver->get_supported_voices();

		if ( null !== $voices && array() !== $voices ) {
			$default_voice = $resolver->get_default_voice();

			$elements = array(
				array(
					'value' => '',
					'label' => '' !== $default_voice
						? sprintf(
							/* translators: %s: voice identifier used when none is chosen. */
							__( 'Use default (%s)', 'ai' ),
							$default_voice
						)
						: __( 'Use the provider default', 'ai' ),
				),
			);

			foreach ( $voices as $voice ) {
				$elements[] = array(
					'value' => $voice,
					'label' => $voice,
				);
			}

			// Keep a previously saved voice selectable, but say that it is no longer offered.
			$saved_voice = (string) get_option( static::get_field_option_name( 'voice' ), '' );

			if ( '' !== $saved_voice && ! in_array( $saved_voice, $voices, true ) ) {
				$elements[] = array(
					'value' => $saved_voice,
					'label' => sprintf(
						/* translators: %s: voice identifier saved previously. */
						__( '%s (not offered by the current provider)', 'ai' ),
						$saved_voice
					),
				);
			}

			$field['elements'] = $elements;
		} else {
			$field['description'] = __( 'If you want to use a specific voice, enter the voice identifier here. Leave blank to use the provider\'s default voice.', 'ai' );
		}

		return array( $field );
	}
}
