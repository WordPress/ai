<?php
/**
 * Text to speech experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Text_To_Speech;

use WordPress\AI\Abilities\Speech\Generate_Speech;
use WordPress\AI\Abilities\Speech\Import_Base64_Audio;
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
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_assets' ) );
		add_action( Job_Manager::CRON_HOOK, array( $this, 'process_chunk' ) );
		add_filter( 'the_content', array( $this, 'render_audio_player' ) );
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

		wp_register_ability(
			'ai/speech-import',
			array(
				'label'         => __( 'Speech Import', 'ai' ),
				'description'   => __( 'Imports base64-encoded audio into the media library.', 'ai' ),
				'ability_class' => Import_Base64_Audio::class,
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
	 * Enqueues and localizes the block editor script.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->base || 'attachment' === $screen->post_type ) {
			return;
		}

		Asset_Loader::enqueue_script( 'text_to_speech', 'experiments/text-to-speech' );
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
	 * Enqueues the stylesheet for the editor iframe and the front end.
	 *
	 * @since x.x.x
	 */
	public function enqueue_block_assets(): void {
		Asset_Loader::enqueue_style( 'text_to_speech', 'experiments/text-to-speech' );
	}

	/**
	 * Prepends the audio player to post content on singular front-end views.
	 *
	 * @since x.x.x
	 *
	 * @param string $content The post content.
	 * @return string The content, with the player prepended when applicable.
	 */
	public function render_audio_player( string $content ): string {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return $content;
		}

		if ( ! get_post_meta( $post_id, Job_Manager::META_DISPLAY, true ) ) {
			return $content;
		}

		$audio_id = absint( get_post_meta( $post_id, Job_Manager::META_AUDIO_ID, true ) );

		if ( ! $audio_id ) {
			return $content;
		}

		$audio_url = wp_get_attachment_url( $audio_id );

		if ( ! $audio_url ) {
			return $content;
		}

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

		$voices = ( new Voice_Resolver() )->get_supported_voices();

		if ( is_array( $voices ) && array() !== $voices ) {
			$elements = array(
				array(
					'value' => '',
					'label' => __( 'Provider default (first available voice)', 'ai' ),
				),
			);

			$saved_voice = (string) get_option( static::get_field_option_name( 'voice' ), '' );

			if ( '' !== $saved_voice && ! in_array( $saved_voice, $voices, true ) ) {
				$voices[] = $saved_voice;
			}

			foreach ( $voices as $voice ) {
				$elements[] = array(
					'value' => $voice,
					'label' => $voice,
				);
			}

			$field['elements'] = $elements;
		}

		return array( $field );
	}
}
