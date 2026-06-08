<?php
/**
 * Integration tests for log data extraction.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\Log_Data_Extractor;

/**
 * Log_Data_Extractor test case.
 *
 * @since 1.0.0
 *
 * @covers \WordPress\AI\Logging\Log_Data_Extractor
 */
class Log_Data_ExtractorTest extends WP_UnitTestCase {

	/**
	 * Extractor instance under test.
	 *
	 * @var \WordPress\AI\Logging\Log_Data_Extractor
	 */
	private Log_Data_Extractor $extractor;

	/**
	 * Set up test case.
	 *
	 * @since 1.0.0
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->extractor = new Log_Data_Extractor();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	protected function tearDown(): void {
		remove_all_filters( 'wpai_request_log_providers' );
		remove_all_filters( 'wpai_request_log_tokens' );
		remove_all_filters( 'wpai_request_log_kind' );
		remove_all_filters( 'wpai_request_log_context' );
		parent::tearDown();
	}

	/**
	 * Tests that an OpenAI URL is detected via the connector slug.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_provider_openai(): void {
		$this->assertSame( 'openai', $this->extractor->detect_provider( 'https://api.openai.com/v1/chat/completions' ) );
	}

	/**
	 * Tests that an Anthropic URL is detected via the connector slug.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_provider_anthropic(): void {
		$this->assertSame( 'anthropic', $this->extractor->detect_provider( 'https://api.anthropic.com/v1/messages' ) );
	}

	/**
	 * Tests that the Google connector matches via the googleapis override.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_provider_google_uses_googleapis_override(): void {
		$this->assertSame( 'google', $this->extractor->detect_provider( 'https://generativelanguage.googleapis.com/v1/models/gemini-pro' ) );
	}

	/**
	 * Tests that unknown URLs return null.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_provider_returns_null_for_unknown(): void {
		$this->assertNull( $this->extractor->detect_provider( 'https://example.com/api/v1' ) );
	}

	/**
	 * Tests that URLs without a host return null.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_provider_returns_null_for_empty_host(): void {
		$this->assertNull( $this->extractor->detect_provider( '/relative/path' ) );
	}

	/**
	 * Tests that the wpai_request_log_providers filter can extend the map.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_provider_filter_can_add_patterns(): void {
		add_filter(
			'wpai_request_log_providers',
			static function ( $patterns ) {
				$patterns['custom'] = array( 'my-ai.example' );
				return $patterns;
			}
		);

		$extractor = new Log_Data_Extractor();

		$this->assertSame( 'custom', $extractor->detect_provider( 'https://my-ai.example/v1/run' ) );
	}

	/**
	 * Tests that a /v1/models endpoint is classified as metadata.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_request_kind_classifies_models_endpoint_as_metadata(): void {
		$this->assertSame(
			'metadata',
			$this->extractor->detect_request_kind( 'anthropic', '/v1/models', null )
		);
	}

	/**
	 * Tests that fal provider is always classified as image.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_request_kind_fal_is_image(): void {
		$this->assertSame( 'image', $this->extractor->detect_request_kind( 'fal', '/fal-ai/flux', null ) );
	}

	/**
	 * Tests that an embeddings path is classified correctly.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_request_kind_embeddings(): void {
		$this->assertSame( 'embeddings', $this->extractor->detect_request_kind( 'openai', '/v1/embeddings', null ) );
	}

	/**
	 * Tests that image generation paths are classified as image.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_request_kind_image_generation_path(): void {
		$this->assertSame( 'image', $this->extractor->detect_request_kind( 'openai', '/v1/images/generations', null ) );
	}

	/**
	 * Tests that audio paths are classified as audio.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_request_kind_audio(): void {
		$this->assertSame( 'audio', $this->extractor->detect_request_kind( 'openai', '/v1/audio/speech', null ) );
	}

	/**
	 * Tests that a generic chat path defaults to text.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_request_kind_defaults_to_text(): void {
		$this->assertSame( 'text', $this->extractor->detect_request_kind( 'openai', '/v1/chat/completions', null ) );
	}

	/**
	 * Tests that models with query parameters are classified as metadata.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_request_kind_models_with_query_params(): void {
		$this->assertSame( 'metadata', $this->extractor->detect_request_kind( 'openai', '/v1/models?limit=10', null ) );
	}

	/**
	 * Tests that a specific model path is classified as metadata.
	 *
	 * @since 1.0.0
	 */
	public function test_detect_request_kind_specific_model_path(): void {
		$this->assertSame( 'metadata', $this->extractor->detect_request_kind( 'openai', '/v1/models/gpt-4o', null ) );
	}

	/**
	 * Tests that request data extracts model discovery requests as metadata.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_request_data_marks_model_discovery_as_metadata(): void {
		$log_data = $this->extractor->extract_request_data(
			'https://api.anthropic.com/v1/models',
			'GET',
			null
		);

		$this->assertSame( 'anthropic:models', $log_data['operation'] );
		$this->assertSame( 'metadata', $log_data['context']['request_kind'] );
	}

	/**
	 * Tests that the model is extracted from the request body.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_request_data_extracts_model_from_body(): void {
		$body     = wp_json_encode(
			array(
				'model'    => 'gpt-4o',
				'messages' => array(),
			)
		);
		$log_data = $this->extractor->extract_request_data(
			'https://api.openai.com/v1/chat/completions',
			'POST',
			$body
		);

		$this->assertSame( 'gpt-4o', $log_data['model'] );
		$this->assertSame( 'openai', $log_data['provider'] );
		$this->assertSame( 'ai_client', $log_data['type'] );
	}

	/**
	 * Tests that the operation includes the provider prefix.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_request_data_builds_operation_string(): void {
		$log_data = $this->extractor->extract_request_data(
			'https://api.openai.com/v1/chat/completions',
			'POST',
			null
		);

		$this->assertSame( 'openai:completions', $log_data['operation'] );
	}

	/**
	 * Tests that input preview is included in context when a body is present.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_request_data_includes_input_preview(): void {
		$body     = wp_json_encode(
			array(
				'model'    => 'gpt-4o',
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'Hello world',
					),
				),
			)
		);
		$log_data = $this->extractor->extract_request_data(
			'https://api.openai.com/v1/chat/completions',
			'POST',
			$body
		);

		$this->assertArrayHasKey( 'input_preview', $log_data['context'] );
		$this->assertStringContainsString( 'Hello world', $log_data['context']['input_preview'] );
	}

	/**
	 * Tests that null response body returns log data unchanged.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_response_data_returns_unchanged_for_null_body(): void {
		$log_data = array(
			'type'    => 'ai_client',
			'context' => array(),
		);
		$result   = $this->extractor->extract_response_data( null, $log_data );

		$this->assertSame( $log_data, $result );
	}

	/**
	 * Tests that non-JSON response body returns log data unchanged.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_response_data_returns_unchanged_for_invalid_json(): void {
		$log_data = array(
			'type'    => 'ai_client',
			'context' => array(),
		);
		$result   = $this->extractor->extract_response_data( 'not json', $log_data );

		$this->assertSame( $log_data, $result );
	}

	/**
	 * Tests that model is extracted from the response when not set.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_response_data_fills_model_from_response(): void {
		$log_data = array(
			'type'    => 'ai_client',
			'model'   => '',
			'context' => array(),
		);
		$body     = wp_json_encode( array( 'model' => 'gpt-4o-2024-08-06' ) );
		$result   = $this->extractor->extract_response_data( $body, $log_data );

		$this->assertSame( 'gpt-4o-2024-08-06', $result['model'] );
	}

	/**
	 * Tests that token usage is extracted from an OpenAI-format response.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_response_data_extracts_openai_tokens(): void {
		$log_data = array(
			'type'    => 'ai_client',
			'context' => array(),
		);
		$body     = wp_json_encode(
			array(
				'usage' => array(
					'prompt_tokens'     => 100,
					'completion_tokens' => 50,
				),
			)
		);

		$result = $this->extractor->extract_response_data( $body, $log_data );

		$this->assertSame( 100, $result['tokens_input'] );
		$this->assertSame( 50, $result['tokens_output'] );
	}

	/**
	 * Tests OpenAI token usage format with prompt_tokens / completion_tokens.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_token_usage_openai_format(): void {
		$tokens = $this->extractor->extract_token_usage(
			array(
				'usage' => array(
					'prompt_tokens'     => 150,
					'completion_tokens' => 75,
				),
			)
		);

		$this->assertSame( 150, $tokens['input'] );
		$this->assertSame( 75, $tokens['output'] );
	}

	/**
	 * Tests Anthropic token usage format with input_tokens / output_tokens.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_token_usage_anthropic_format(): void {
		$tokens = $this->extractor->extract_token_usage(
			array(
				'usage' => array(
					'input_tokens'  => 200,
					'output_tokens' => 100,
				),
			)
		);

		$this->assertSame( 200, $tokens['input'] );
		$this->assertSame( 100, $tokens['output'] );
	}

	/**
	 * Tests Google token usage format with usageMetadata.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_token_usage_google_format(): void {
		$tokens = $this->extractor->extract_token_usage(
			array(
				'usageMetadata' => array(
					'promptTokenCount'     => 300,
					'candidatesTokenCount' => 150,
					'thoughtsTokenCount'   => 20,
				),
			)
		);

		$this->assertSame( 300, $tokens['input'] );
		$this->assertSame( 170, $tokens['output'] );
	}

	/**
	 * Tests that missing usage data returns nulls.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_token_usage_returns_null_when_no_usage(): void {
		$tokens = $this->extractor->extract_token_usage( array( 'id' => 'chatcmpl-abc' ) );

		$this->assertNull( $tokens['input'] );
		$this->assertNull( $tokens['output'] );
	}

	/**
	 * Tests input preview extraction from OpenAI/Anthropic messages format.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_input_preview_messages_format(): void {
		$preview = $this->extractor->extract_input_preview(
			array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => 'You are helpful.',
					),
					array(
						'role'    => 'user',
						'content' => 'Write a poem.',
					),
				),
			)
		);

		$this->assertNotNull( $preview );
		$this->assertStringContainsString( '[system]', $preview );
		$this->assertStringContainsString( '[user]', $preview );
		$this->assertStringContainsString( 'Write a poem.', $preview );
	}

	/**
	 * Tests input preview extraction from a prompt field.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_input_preview_prompt_field(): void {
		$preview = $this->extractor->extract_input_preview(
			array( 'prompt' => 'Generate a title for this content.' )
		);

		$this->assertNotNull( $preview );
		$this->assertStringContainsString( 'Generate a title', $preview );
	}

	/**
	 * Tests that input preview returns null when no recognized fields exist.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_input_preview_returns_null_when_empty(): void {
		$this->assertNull( $this->extractor->extract_input_preview( array( 'model' => 'gpt-4o' ) ) );
	}

	/**
	 * Tests that messages with empty content are skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_input_preview_skips_empty_content(): void {
		$preview = $this->extractor->extract_input_preview(
			array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => '',
					),
					array(
						'role'    => 'user',
						'content' => 'Hello',
					),
				),
			)
		);

		$this->assertNotNull( $preview );
		$this->assertStringNotContainsString( '[system]', $preview );
		$this->assertStringContainsString( '[user] Hello', $preview );
	}

	/**
	 * Tests output preview extraction from OpenAI choices format.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_output_preview_openai_choices(): void {
		$preview = $this->extractor->extract_output_preview(
			array(
				'choices' => array(
					array(
						'message' => array( 'content' => 'Here is the answer.' ),
					),
				),
			)
		);

		$this->assertNotNull( $preview );
		$this->assertStringContainsString( 'Here is the answer.', $preview );
	}

	/**
	 * Tests output preview extraction from Anthropic content format.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_output_preview_anthropic_content(): void {
		$preview = $this->extractor->extract_output_preview(
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'The response text.',
					),
				),
			)
		);

		$this->assertNotNull( $preview );
		$this->assertStringContainsString( 'The response text.', $preview );
	}

	/**
	 * Tests output preview extraction from Google candidates format.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_output_preview_google_candidates(): void {
		$preview = $this->extractor->extract_output_preview(
			array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array( array( 'text' => 'Google response.' ) ),
						),
					),
				),
			)
		);

		$this->assertNotNull( $preview );
	}

	/**
	 * Tests output preview extraction from direct output field.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_output_preview_direct_output(): void {
		$preview = $this->extractor->extract_output_preview(
			array( 'output' => 'Direct output text.' )
		);

		$this->assertNotNull( $preview );
		$this->assertStringContainsString( 'Direct output text.', $preview );
	}

	/**
	 * Tests that output preview returns null when no recognized format exists.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_output_preview_returns_null_when_empty(): void {
		$this->assertNull( $this->extractor->extract_output_preview( array( 'id' => 'chatcmpl-abc' ) ) );
	}

	/**
	 * Tests media metadata extraction from OpenAI DALL-E URL format.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_media_metadata_dalle_url_format(): void {
		$context = $this->extractor->extract_media_metadata(
			array(
				'data' => array(
					array( 'url' => 'https://example.com/image1.png' ),
					array( 'url' => 'https://example.com/image2.png' ),
				),
			)
		);

		$this->assertSame( 'image', $context['media_type'] );
		$this->assertSame( 2, $context['media_count'] );
		$this->assertCount( 2, $context['image_urls'] );
		$this->assertStringContainsString( '2 image(s)', $context['output_preview'] );
	}

	/**
	 * Tests media metadata extraction from base64 format.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_media_metadata_base64_format(): void {
		$context = $this->extractor->extract_media_metadata(
			array(
				'data' => array(
					array( 'b64_json' => 'iVBORw0KGgoAAAANS...' ),
				),
			)
		);

		$this->assertSame( 'image', $context['media_type'] );
		$this->assertSame( 1, $context['media_count'] );
		$this->assertArrayHasKey( 'image_metadata', $context );
		$this->assertSame( 'base64', $context['image_metadata'][0]['format'] );
	}

	/**
	 * Tests media metadata extraction from alternative images array format.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_media_metadata_images_array(): void {
		$context = $this->extractor->extract_media_metadata(
			array(
				'images' => array(
					array( 'url' => 'https://example.com/img.png' ),
				),
			)
		);

		$this->assertSame( 'image', $context['media_type'] );
		$this->assertSame( 1, $context['media_count'] );
	}

	/**
	 * Tests that media metadata returns empty array when no media is present.
	 *
	 * @since 1.0.0
	 */
	public function test_extract_media_metadata_returns_empty_when_no_media(): void {
		$this->assertEmpty( $this->extractor->extract_media_metadata( array( 'id' => 'chatcmpl-abc' ) ) );
	}

	/**
	 * Tests that strings are returned trimmed.
	 *
	 * @since 1.0.0
	 */
	public function test_stringify_content_string(): void {
		$this->assertSame( 'hello', $this->extractor->stringify_content( '  hello  ' ) );
	}

	/**
	 * Tests that array chunks with text keys are concatenated.
	 *
	 * @since 1.0.0
	 */
	public function test_stringify_content_array_with_text_chunks(): void {
		$result = $this->extractor->stringify_content(
			array(
				array( 'text' => 'Part one.' ),
				array( 'text' => 'Part two.' ),
			)
		);

		$this->assertStringContainsString( 'Part one.', $result );
		$this->assertStringContainsString( 'Part two.', $result );
	}

	/**
	 * Tests that base64 image arrays return a placeholder.
	 *
	 * @since 1.0.0
	 */
	public function test_stringify_content_base64_image(): void {
		$this->assertSame(
			'[base64 image]',
			$this->extractor->stringify_content( array( 'b64_json' => 'data...' ) )
		);
	}

	/**
	 * Tests that scalar values are cast to string.
	 *
	 * @since 1.0.0
	 */
	public function test_stringify_content_scalar(): void {
		$this->assertSame( '42', $this->extractor->stringify_content( 42 ) );
	}

	/**
	 * Tests that null/non-scalar returns empty string.
	 *
	 * @since 1.0.0
	 */
	public function test_stringify_content_null_returns_empty(): void {
		$this->assertSame( '', $this->extractor->stringify_content( null ) );
	}

	/**
	 * Tests that strings within the limit are returned unchanged.
	 *
	 * @since 1.0.0
	 */
	public function test_truncate_string_within_limit(): void {
		$this->assertSame( 'short', $this->extractor->truncate_string( 'short', 100 ) );
	}

	/**
	 * Tests that strings exceeding the limit are truncated with ellipsis.
	 *
	 * @since 1.0.0
	 */
	public function test_truncate_string_exceeds_limit(): void {
		$result = $this->extractor->truncate_string( 'abcdef', 3 );

		$this->assertSame( 3, mb_strlen( $result ) - 1 ); // +1 for the ellipsis character.
		$this->assertStringContainsString( 'abc', $result );
	}

	/**
	 * Tests that empty strings are returned as-is.
	 *
	 * @since 1.0.0
	 */
	public function test_truncate_string_empty(): void {
		$this->assertSame( '', $this->extractor->truncate_string( '' ) );
	}
}
