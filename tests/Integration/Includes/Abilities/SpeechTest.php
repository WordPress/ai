<?php
/**
 * Integration tests for the Speech ability classes.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use ReflectionClass;
use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Speech\Generate_Speech;
use WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech\Fake_Speech_Provider;

/**
 * Speech abilities test case.
 *
 * @since x.x.x
 */
class SpeechTest extends WP_UnitTestCase {

	/**
	 * The generation ability under test.
	 *
	 * @var Generate_Speech
	 */
	private $generate_ability;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->generate_ability = new Generate_Speech(
			'ai/speech-generation',
			array(
				'label'       => 'Speech Generation',
				'description' => 'Generates speech audio from text or from a post.',
			)
		);

		// Fake audio so no AI provider is required.
		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function ( $pre, $text ) {
				return array( 'data' => base64_encode( 'X:' . $text ) );
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
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Invokes a protected callback on an ability instance.
	 *
	 * @since x.x.x
	 *
	 * @param object $ability The ability instance.
	 * @param string $method  The method name.
	 * @param mixed  $input   The input argument.
	 * @return mixed The callback result.
	 */
	private function invoke( $ability, string $method, $input ) {
		$reflection = new ReflectionClass( $ability );
		$callback   = $reflection->getMethod( $method );
		$callback->setAccessible( true );

		return $callback->invoke( $ability, $input );
	}

	/**
	 * Test that speech is generated from direct text input.
	 *
	 * @since x.x.x
	 */
	public function test_generate_from_text(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->invoke( $this->generate_ability, 'execute_callback', array( 'text' => 'Hello world.' ) );

		$this->assertIsArray( $result );
		$this->assertSame( base64_encode( 'X:Hello world.' ), $result['audio']['data'] );
		$this->assertSame( 'audio/mpeg', $result['audio']['mime_type'] );
	}

	/**
	 * Test that speech is generated from a post's content.
	 *
	 * @since x.x.x
	 */
	public function test_generate_from_post_id(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = self::factory()->post->create( array( 'post_content' => 'Post content sentence.' ) );

		$result = $this->invoke( $this->generate_ability, 'execute_callback', array( 'post_id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( base64_encode( 'X:Post content sentence.' ), $result['audio']['data'] );
	}

	/**
	 * Test that multiple chunks are combined into one audio payload.
	 *
	 * @since x.x.x
	 */
	public function test_generate_combines_chunks(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// 25-char limit: each sentence fits alone (20 and 16 chars) but not
		// joined (37 chars), so the chunker produces exactly two chunks.
		add_filter(
			'wpai_tts_max_chunk_length',
			static function () {
				return 25;
			}
		);

		$result = $this->invoke(
			$this->generate_ability,
			'execute_callback',
			array( 'text' => 'First sentence here. Second one here.' )
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			base64_encode( 'X:First sentence here.' . 'X:Second one here.' ),
			$result['audio']['data']
		);
	}

	/**
	 * Test that generation without text or a post errors.
	 *
	 * @since x.x.x
	 */
	public function test_generate_requires_text_or_post(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->invoke( $this->generate_ability, 'execute_callback', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_content', $result->get_error_code() );
	}

	/**
	 * Test that multi-chunk generation is refused before any request when the
	 * provider cannot output MP3.
	 *
	 * @since x.x.x
	 */
	public function test_generate_multi_chunk_requires_mp3_provider(): void {
		remove_all_filters( 'wpai_tts_pre_generate_chunk' );
		Fake_Speech_Provider::install( array( 'audio/wav' ) );
		add_filter(
			'wpai_tts_max_chunk_length',
			static function () {
				return 20;
			}
		);

		try {
			$result = $this->invoke( $this->generate_ability, 'execute_callback', array( 'text' => 'First sentence here. Second sentence here.' ) );
		} finally {
			Fake_Speech_Provider::uninstall();
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tts_mp3_unsupported', $result->get_error_code() );
	}

	/**
	 * Test that generation permission is denied without upload_files.
	 *
	 * @since x.x.x
	 */
	public function test_generate_permission_denied_for_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->invoke( $this->generate_ability, 'permission_callback', array( 'text' => 'Hi.' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
