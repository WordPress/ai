<?php
/**
 * Integration tests for the HTTP stream wrapper opener.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming;

use ReflectionMethod;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Fopen_Stream_Opener;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception;

/**
 * Fopen_Stream_Opener test case.
 *
 * Only the checks that run before a connection is attempted are exercised, so
 * nothing here touches the network.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Streaming\Fopen_Stream_Opener
 */
class Fopen_Stream_OpenerTest extends WP_UnitTestCase {

	/**
	 * The opener under test.
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Streaming\Fopen_Stream_Opener
	 */
	private Fopen_Stream_Opener $opener;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->opener = new Fopen_Stream_Opener();
	}

	/**
	 * Support detection answers without touching the network.
	 *
	 * @since x.x.x
	 */
	public function test_support_detection_reports_the_environment(): void {
		$this->assertSame(
			filter_var( ini_get( 'allow_url_fopen' ), FILTER_VALIDATE_BOOLEAN )
				&& in_array( 'https', stream_get_wrappers(), true ),
			Fopen_Stream_Opener::is_supported()
		);
	}

	/**
	 * A URL WordPress considers unsafe is refused before any connection is opened.
	 *
	 * This is the SSRF protection `wp_safe_remote_request()` would have applied
	 * on the buffered path; the streaming path has to apply it itself.
	 *
	 * @since x.x.x
	 */
	public function test_unsafe_url_is_refused(): void {
		if ( ! Fopen_Stream_Opener::is_supported() ) {
			$this->markTestSkipped( 'The HTTPS stream wrapper is unavailable in this environment.' );
		}

		$thrown = null;

		try {
			$this->opener->open( 'POST', 'http://127.0.0.1/internal', array(), null, 1.0 );
		} catch ( Streaming_Exception $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( Streaming_Exception::class, $thrown );
		$this->assertSame( Streaming_Exception::CODE_TRANSPORT, $thrown->getCode() );
		$this->assertStringContainsString( 'unsafe', $thrown->getMessage() );
	}

	/**
	 * A header carrying a line break is dropped rather than injected into the request.
	 *
	 * @since x.x.x
	 */
	public function test_header_injection_is_dropped(): void {
		$method = new ReflectionMethod( Fopen_Stream_Opener::class, 'format_headers' );
		$method->setAccessible( true );

		$formatted = $method->invoke(
			null,
			array(
				'Content-Type' => array( 'application/json' ),
				'X-Evil'       => array( "ok\r\nX-Injected: yes" ),
			)
		);

		$this->assertSame( 'Content-Type: application/json', $formatted );
	}

	/**
	 * Multi-valued headers are joined the way the HTTP API joins them.
	 *
	 * @since x.x.x
	 */
	public function test_multi_valued_headers_are_joined(): void {
		$method = new ReflectionMethod( Fopen_Stream_Opener::class, 'format_headers' );
		$method->setAccessible( true );

		$formatted = $method->invoke(
			null,
			array(
				'Accept'  => array( 'text/event-stream' ),
				'X-Thing' => array( 'a', 'b' ),
			)
		);

		$this->assertSame( "Accept: text/event-stream\r\nX-Thing: a, b", $formatted );
	}

	/**
	 * The status line and headers are read out of the wrapper's raw header lines.
	 *
	 * @since x.x.x
	 */
	public function test_wrapper_data_is_parsed(): void {
		$method = new ReflectionMethod( Fopen_Stream_Opener::class, 'parse_wrapper_data' );
		$method->setAccessible( true );

		$parsed = $method->invoke(
			null,
			array(
				'HTTP/1.1 200 OK',
				'Content-Type: text/event-stream',
				'X-Request-Id: abc',
			)
		);

		$this->assertSame( 200, $parsed[0] );
		$this->assertSame( array( 'text/event-stream' ), $parsed[1]['Content-Type'] );
	}

	/**
	 * After a redirect, the final hop's status and headers win.
	 *
	 * @since x.x.x
	 */
	public function test_only_the_final_hop_is_reported(): void {
		$method = new ReflectionMethod( Fopen_Stream_Opener::class, 'parse_wrapper_data' );
		$method->setAccessible( true );

		$parsed = $method->invoke(
			null,
			array(
				'HTTP/1.1 302 Found',
				'Location: https://example.org/next',
				'HTTP/1.1 429 Too Many Requests',
				'Retry-After: 5',
			)
		);

		$this->assertSame( 429, $parsed[0] );
		$this->assertArrayNotHasKey( 'Location', $parsed[1] );
		$this->assertSame( array( '5' ), $parsed[1]['Retry-After'] );
	}

	/**
	 * A response with no status line at all is reported rather than guessed at.
	 *
	 * @since x.x.x
	 */
	public function test_missing_status_line_throws(): void {
		$method = new ReflectionMethod( Fopen_Stream_Opener::class, 'parse_wrapper_data' );
		$method->setAccessible( true );

		$this->expectException( Streaming_Exception::class );

		$method->invoke( null, array( 'Content-Type: text/event-stream' ) );
	}
}
