<?php
/**
 * Integration tests for the Text to Speech REST controller.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Text_To_Speech\Job_Manager;
use WordPress\AI\Experiments\Text_To_Speech\REST_Controller;

/**
 * REST_Controller test case.
 *
 * @since x.x.x
 */
class REST_ControllerTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		add_action(
			'rest_api_init',
			static function () {
				( new REST_Controller() )->register_routes();
			}
		);

		do_action( 'rest_api_init', $wp_rest_server );

		add_filter( 'wpai_has_text_to_speech_support', '__return_true' );
		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function ( $pre, $text ) {
				return array( 'data' => base64_encode( '[' . $text . ']' ) );
			},
			10,
			2
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that POST starts a background job.
	 *
	 * @since x.x.x
	 */
	public function test_post_starts_job(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => 'Some content to read.' ) );

		$request  = new WP_REST_Request( 'POST', '/ai/v1/text-to-speech/' . $post_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()['status'] );
		$this->assertNotFalse( wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) ) );
	}

	/**
	 * Test that GET returns idle status for a fresh post.
	 *
	 * @since x.x.x
	 */
	public function test_get_returns_idle_status(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = self::factory()->post->create();

		$request  = new WP_REST_Request( 'GET', '/ai/v1/text-to-speech/' . $post_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'idle', $response->get_data()['status'] );
		$this->assertSame( 0, $response->get_data()['audio_id'] );
	}

	/**
	 * Test that a subscriber cannot trigger generation.
	 *
	 * @since x.x.x
	 */
	public function test_post_denied_for_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post_id = self::factory()->post->create();

		$request  = new WP_REST_Request( 'POST', '/ai/v1/text-to-speech/' . $post_id );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test that POST errors when no provider supports text to speech.
	 *
	 * @since x.x.x
	 */
	public function test_post_errors_without_tts_support(): void {
		remove_all_filters( 'wpai_has_text_to_speech_support' );
		add_filter( 'wpai_has_text_to_speech_support', '__return_false' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => 'Some content.' ) );

		$request  = new WP_REST_Request( 'POST', '/ai/v1/text-to-speech/' . $post_id );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unsupported', $response->get_data()['code'] );
	}

	/**
	 * Test that a missing post returns 404.
	 *
	 * @since x.x.x
	 */
	public function test_missing_post_returns_404(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/text-to-speech/999999' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}
}
