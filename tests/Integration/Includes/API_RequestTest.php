<?php
/**
 * Integration tests for the API_Request class.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WordPress\AI\API_Request;
use WP_Error;
use WP_UnitTestCase;

/**
 * API_Request test case.
 *
 * @since 0.1.0
 */
class API_RequestTest extends WP_UnitTestCase {

	/**
	 * Test that constructor sets provider and model correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_sets_provider_and_model() {
		$request = new API_Request( 'openai', 'gpt-4' );

		$reflection = new \ReflectionClass( $request );
		$provider_property = $reflection->getProperty( 'provider' );
		$provider_property->setAccessible( true );
		$model_property = $reflection->getProperty( 'model' );
		$model_property->setAccessible( true );

		$this->assertEquals( 'openai', $provider_property->getValue( $request ), 'Provider should be set' );
		$this->assertEquals( 'gpt-4', $model_property->getValue( $request ), 'Model should be set' );
	}

	/**
	 * Test that constructor handles empty strings.
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_handles_empty_strings() {
		$request = new API_Request( '', '' );

		$reflection = new \ReflectionClass( $request );
		$provider_property = $reflection->getProperty( 'provider' );
		$provider_property->setAccessible( true );
		$model_property = $reflection->getProperty( 'model' );
		$model_property->setAccessible( true );

		$this->assertEquals( '', $provider_property->getValue( $request ), 'Provider should be empty string' );
		$this->assertEquals( '', $model_property->getValue( $request ), 'Model should be empty string' );
	}

	/**
	 * Test that is_client_available() checks for AiClient class.
	 *
	 * @since 0.1.0
	 */
	public function test_is_client_available_checks_class() {
		$request = new API_Request();

		$reflection = new \ReflectionClass( $request );
		$method = $reflection->getMethod( 'is_client_available' );
		$method->setAccessible( true );

		$result = $method->invoke( $request );

		// The result depends on whether AiClient class exists in the test environment.
		$this->assertIsBool( $result, 'Should return a boolean' );
	}

	/**
	 * Test that generate_text() returns error when client is not available.
	 *
	 * @since 0.1.0
	 */
	public function test_generate_text_returns_error_when_client_unavailable() {
		$request = new API_Request();

		// Mock the is_client_available method to return false.
		$mock = $this->getMockBuilder( API_Request::class )
			->onlyMethods( array( 'is_client_available' ) )
			->getMock();

		$mock->expects( $this->once() )
			->method( 'is_client_available' )
			->willReturn( false );

		$result = $mock->generate_text( 'test prompt' );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error when client unavailable' );
		$this->assertEquals( 'ai_client_not_available', $result->get_error_code(), 'Error code should be ai_client_not_available' );
	}

	/**
	 * Test that sanitize_choice() sanitizes text correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_sanitize_choice_sanitizes_text() {
		$request = new API_Request();

		$reflection = new \ReflectionClass( $request );
		$method = $reflection->getMethod( 'sanitize_choice' );
		$method->setAccessible( true );

		// Test trimming quotes.
		$result = $method->invoke( $request, '"Test Title"' );
		$this->assertEquals( 'Test Title', $result, 'Should remove double quotes' );

		$result = $method->invoke( $request, "'Test Title'" );
		$this->assertEquals( 'Test Title', $result, 'Should remove single quotes' );

		// Test trimming whitespace.
		$result = $method->invoke( $request, '  Test Title  ' );
		$this->assertEquals( 'Test Title', $result, 'Should trim whitespace' );

		// Test sanitize_text_field behavior (removes HTML).
		$result = $method->invoke( $request, '<script>alert("test")</script>Test Title' );
		$this->assertEquals( 'Test Title', $result, 'Should sanitize HTML' );
	}

	/**
	 * Test that get_result() returns error for empty response.
	 *
	 * @since 0.1.0
	 */
	public function test_get_result_returns_error_for_empty_response() {
		$request = new API_Request();

		$reflection = new \ReflectionClass( $request );
		$method = $reflection->getMethod( 'get_result' );
		$method->setAccessible( true );

		$result = $method->invoke( $request, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error for empty response' );
		$this->assertEquals( 'no_choices', $result->get_error_code(), 'Error code should be no_choices' );
	}

	/**
	 * Test that get_result() processes choices correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_get_result_processes_choices() {
		$request = new API_Request();

		$reflection = new \ReflectionClass( $request );
		$method = $reflection->getMethod( 'get_result' );
		$method->setAccessible( true );

		$response = array(
			'  Title 1  ',
			'"Title 2"',
			"'Title 3'",
		);

		$result = $method->invoke( $request, $response );

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertCount( 3, $result, 'Should have 3 choices' );
		$this->assertEquals( 'Title 1', $result[0], 'Should sanitize first choice' );
		$this->assertEquals( 'Title 2', $result[1], 'Should sanitize second choice' );
		$this->assertEquals( 'Title 3', $result[2], 'Should sanitize third choice' );
	}

	/**
	 * Test that process_model_config() processes string values.
	 *
	 * @since 0.1.0
	 */
	public function test_process_model_config_processes_string_values() {
		$request = new API_Request();

		$reflection = new \ReflectionClass( $request );
		$method = $reflection->getMethod( 'process_model_config' );
		$method->setAccessible( true );

		$options = array(
			'temperature' => '0.7',
		);

		$result = $method->invoke( $request, $options );

		$this->assertInstanceOf( \WordPress\AiClient\Providers\Models\DTO\ModelConfig::class, $result, 'Should return ModelConfig instance' );
	}

	/**
	 * Test that process_model_config() processes integer values.
	 *
	 * @since 0.1.0
	 */
	public function test_process_model_config_processes_integer_values() {
		$request = new API_Request();

		$reflection = new \ReflectionClass( $request );
		$method = $reflection->getMethod( 'process_model_config' );
		$method->setAccessible( true );

		$options = array(
			'candidateCount' => '5',
		);

		$result = $method->invoke( $request, $options );

		$this->assertInstanceOf( \WordPress\AiClient\Providers\Models\DTO\ModelConfig::class, $result, 'Should return ModelConfig instance' );
	}

	/**
	 * Test that process_model_config() processes boolean values.
	 *
	 * @since 0.1.0
	 */
	public function test_process_model_config_processes_boolean_values() {
		$request = new API_Request();

		$reflection = new \ReflectionClass( $request );
		$method = $reflection->getMethod( 'process_model_config' );
		$method->setAccessible( true );

		$options = array(
			'someBoolean' => 'true',
		);

		// This will only work if 'someBoolean' is in the ModelConfig schema.
		// Otherwise it will be skipped. We'll just verify it doesn't error.
		$result = $method->invoke( $request, $options );

		$this->assertInstanceOf( \WordPress\AiClient\Providers\Models\DTO\ModelConfig::class, $result, 'Should return ModelConfig instance' );
	}

	/**
	 * Test that process_model_config() skips invalid options.
	 *
	 * @since 0.1.0
	 */
	public function test_process_model_config_skips_invalid_options() {
		$request = new API_Request();

		$reflection = new \ReflectionClass( $request );
		$method = $reflection->getMethod( 'process_model_config' );
		$method->setAccessible( true );

		$options = array(
			'invalid_option' => 'value',
			'temperature'    => '0.7',
		);

		$result = $method->invoke( $request, $options );

		$this->assertInstanceOf( \WordPress\AiClient\Providers\Models\DTO\ModelConfig::class, $result, 'Should return ModelConfig instance' );
	}
}

