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
 * Most of this exercises the checks that run before a connection is attempted. The
 * redirect tests do open a connection, but only to a throwaway HTTP server this
 * test case starts on the loopback interface: nothing here reaches the internet.
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
	 * Loopback port the throwaway HTTP server binds to.
	 *
	 * `wp_http_validate_url()` only ever allows 80, 443 and 8080, so the test server
	 * has to sit on one of them for the opener to reach it at all.
	 *
	 * @var int
	 */
	private const SERVER_PORT = 8080;

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
	 * When several status lines arrive, the last one and its headers win.
	 *
	 * @since x.x.x
	 */
	public function test_only_the_final_status_line_is_reported(): void {
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
	 * The stream context never lets the wrapper follow a redirect.
	 *
	 * The context's header block carries the provider credential, and PHP replays it
	 * verbatim to every redirect target. `wp_http_validate_url()` only ever saw the
	 * first URL, so a followed redirect is an unvalidated host being handed an API
	 * key. The wrapper is therefore told not to follow at all.
	 *
	 * @since x.x.x
	 */
	public function test_stream_context_never_follows_a_redirect(): void {
		$method = new ReflectionMethod( Fopen_Stream_Opener::class, 'build_context_options' );
		$method->setAccessible( true );

		$options = $method->invoke(
			null,
			'POST',
			array( 'X-Api-Key' => array( 'secret-key-value' ) ),
			'{}',
			1.0
		);

		// The credential really is in the block that would have been replayed.
		$this->assertStringContainsString( 'secret-key-value', $options['http']['header'] );

		$this->assertSame( 0, $options['http']['follow_location'] );
		$this->assertLessThanOrEqual( 1, $options['http']['max_redirects'] );
	}

	/**
	 * A redirect is refused outright, and its target is never contacted.
	 *
	 * This runs against a throwaway HTTP server on the loopback interface: one route
	 * answers 302 towards another that records whatever headers reach it. The opener
	 * must report a transport error and leave that recording route untouched.
	 *
	 * @since x.x.x
	 */
	public function test_a_redirect_is_refused_and_its_target_never_receives_the_credential(): void {
		$server = $this->start_local_server();

		try {
			$thrown = null;

			try {
				$this->opener->open(
					'POST',
					'http://localhost:' . self::SERVER_PORT . '/redirect',
					array( 'X-Api-Key' => array( 'secret-key-value' ) ),
					'{}',
					5.0
				);
			} catch ( Streaming_Exception $e ) {
				$thrown = $e;
			}

			$this->assertInstanceOf( Streaming_Exception::class, $thrown, 'A redirect must be surfaced as a transport failure.' );
			$this->assertSame( Streaming_Exception::CODE_TRANSPORT, $thrown->getCode() );
			$this->assertFalse(
				file_exists( $server['log'] ),
				'The redirect target was contacted, so the request headers - the API key among them - were forwarded to a host wp_http_validate_url() never saw.'
			);
		} finally {
			$this->stop_local_server( $server );
		}
	}

	/**
	 * A response that is not a redirect is opened and its headers reach the server.
	 *
	 * This is the control for the redirect test above: it proves the throwaway server
	 * does record the credential when it is reached, so an empty recording there means
	 * the redirect was refused rather than that the harness never ran.
	 *
	 * @since x.x.x
	 */
	public function test_a_direct_response_is_opened_and_carries_the_credential(): void {
		$server = $this->start_local_server();

		try {
			$response = $this->opener->open(
				'POST',
				'http://localhost:' . self::SERVER_PORT . '/target',
				array( 'X-Api-Key' => array( 'secret-key-value' ) ),
				'{}',
				5.0
			);

			$this->assertSame( 200, $response['status'] );
			$this->assertStringContainsString( 'ok', $response['stream']->getContents() );
			$this->assertStringContainsString( 'secret-key-value', (string) file_get_contents( $server['log'] ) );
		} finally {
			$this->stop_local_server( $server );
		}
	}

	/**
	 * Starts a throwaway HTTP server that redirects one route to a recording route.
	 *
	 * @since x.x.x
	 *
	 * @return array{process: resource, dir: string, log: string} The running server.
	 */
	private function start_local_server(): array {
		$dir = sys_get_temp_dir() . '/wpai-stream-' . uniqid();

		if ( ! mkdir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a throwaway directory outside the WordPress install.
			$this->fail( 'Could not create a working directory for the test server.' );
		}

		$log = $dir . '/forwarded-headers.log';

		$router = <<<'ROUTER'
<?php
$log = __DIR__ . '/forwarded-headers.log';

if ( '/redirect' === parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) {
	header( 'Location: http://localhost:' . $_SERVER['SERVER_PORT'] . '/target', true, 302 );
	echo 'moved';
	return;
}

$received = array();

foreach ( $_SERVER as $key => $value ) {
	if ( 0 === strpos( $key, 'HTTP_' ) ) {
		$received[ $key ] = $value;
	}
}

file_put_contents( $log, json_encode( $received ) );

header( 'Content-Type: text/event-stream' );
echo 'ok';
ROUTER;

		file_put_contents( $dir . '/router.php', $router ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a throwaway file outside the WordPress install.

		$probe = @stream_socket_server( 'tcp://localhost:' . self::SERVER_PORT, $errno, $errstr ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $probe ) {
			$this->fail( 'Port ' . self::SERVER_PORT . ' is not free, so the redirect behaviour cannot be exercised: ' . $errstr );
		}

		fclose( $probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket, not a file.

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $dir . '/server.out', 'w' ),
			2 => array( 'file', $dir . '/server.out', 'a' ),
		);

		$pipes   = array();
		$command = escapeshellarg( PHP_BINARY ) . ' -S localhost:' . self::SERVER_PORT . ' -t ' . escapeshellarg( $dir ) . ' ' . escapeshellarg( $dir . '/router.php' );
		$process = proc_open( $command, $descriptors, $pipes, $dir );

		if ( ! is_resource( $process ) ) {
			$this->fail( 'Could not start the test HTTP server.' );
		}

		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$socket = @fsockopen( 'localhost', self::SERVER_PORT, $errno, $errstr, 0.2 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden, WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false !== $socket ) {
				fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket, not a file.

				return array(
					'process' => $process,
					'dir'     => $dir,
					'log'     => $log,
				);
			}

			usleep( 50000 );
		}

		proc_terminate( $process );
		proc_close( $process );

		$this->fail( 'The test HTTP server did not start.' );
	}

	/**
	 * Stops the throwaway HTTP server and removes its working directory.
	 *
	 * @since x.x.x
	 *
	 * @param array{process: resource, dir: string, log: string} $server The running server.
	 */
	private function stop_local_server( array $server ): void {
		proc_terminate( $server['process'] );
		proc_close( $server['process'] );

		foreach ( (array) glob( $server['dir'] . '/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- a throwaway file outside the WordPress install.
		}

		rmdir( $server['dir'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a throwaway directory outside the WordPress install.
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
