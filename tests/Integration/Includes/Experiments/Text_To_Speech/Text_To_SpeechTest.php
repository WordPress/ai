<?php
/**
 * Integration tests for the Text_To_Speech experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Text_To_Speech\Job_Manager;
use WordPress\AI\Experiments\Text_To_Speech\Text_To_Speech;

/**
 * Text_To_Speech experiment test case.
 *
 * @since x.x.x
 */
class Text_To_SpeechTest extends WP_UnitTestCase {

	/**
	 * The experiment under test.
	 *
	 * @var Text_To_Speech
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Text_To_Speech();
	}

	/**
	 * Test the experiment ID and metadata.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_metadata(): void {
		$this->assertSame( 'text-to-speech', Text_To_Speech::get_id() );
		$this->assertSame( 'experimental', $this->experiment->get_stability() );
		$this->assertSame( 'speech_generation', $this->experiment->get_capability() );
		$this->assertNotEmpty( $this->experiment->get_label() );
		$this->assertNotEmpty( $this->experiment->get_description() );
	}

	/**
	 * Test that the experiment is registered in the default experiment list.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_is_registered_as_default(): void {
		$classes = apply_filters( 'wpai_default_feature_classes', array() );

		$this->assertArrayHasKey( 'text-to-speech', $classes );
		$this->assertSame( Text_To_Speech::class, $classes['text-to-speech'] );
	}

	/**
	 * Test that register() registers the post meta keys.
	 *
	 * @since x.x.x
	 */
	public function test_register_registers_post_meta(): void {
		$this->experiment->register();

		$this->assertTrue( registered_meta_key_exists( 'post', Job_Manager::META_DISPLAY ) );
		$this->assertTrue( registered_meta_key_exists( 'post', Job_Manager::META_AUDIO_ID ) );
		$this->assertTrue( registered_meta_key_exists( 'post', Job_Manager::META_STATUS ) );
		$this->assertTrue( registered_meta_key_exists( 'post', Job_Manager::META_ERROR ) );
		$this->assertTrue( registered_meta_key_exists( 'post', Job_Manager::META_UPDATED ) );
	}

	/**
	 * Test that the display toggle defaults to true.
	 *
	 * @since x.x.x
	 */
	public function test_display_meta_defaults_to_true(): void {
		$this->experiment->register();

		$post_id = self::factory()->post->create();

		$this->assertTrue( (bool) get_post_meta( $post_id, Job_Manager::META_DISPLAY, true ) );
	}

	/**
	 * Test that register() wires the cron hook, REST routes, abilities, and
	 * the content filter.
	 *
	 * @since x.x.x
	 */
	public function test_register_wires_hooks(): void {
		$this->experiment->register();

		$this->assertNotFalse( has_action( Job_Manager::CRON_HOOK, array( $this->experiment, 'process_chunk' ) ) );
		$this->assertNotFalse( has_filter( 'the_content', array( $this->experiment, 'render_audio_player' ) ) );
		$this->assertNotFalse( has_action( 'wp_abilities_api_init', array( $this->experiment, 'register_abilities' ) ) );
		$this->assertNotFalse( has_action( 'rest_api_init', array( $this->experiment, 'register_rest_routes' ) ) );
	}

	/**
	 * Test that the voice settings field is exposed.
	 *
	 * @since x.x.x
	 */
	public function test_settings_fields_include_voice(): void {
		$fields = $this->experiment->get_settings_fields();

		$this->assertCount( 1, $fields );
		$this->assertSame( 'voice', $fields[0]['id'] );
		$this->assertSame( 'text', $fields[0]['type'] );
	}
}
