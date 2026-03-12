<?php
/**
 * Plugin name: E2E Test Request Mocking
 * Description: This plugin is used to mock the API requests when running E2E tests.
 * Version: 0.1.0
 * Author: WordPress.org Contributors
 * Author URI: https://make.wordpress.org/ai/
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Mock the HTTP requests and provide known responses.
add_filter( 'pre_http_request', 'ai_e2e_test_request_mocking', 10, 3 );

/**
 * Mock the HTTP requests and provide known responses.
 *
 * @param mixed $preempt Whether to preempt an HTTP request's return value.
 * @param array $parsed_args HTTP request arguments.
 * @param string $url The request URL.
 * @return array|bool The response.
 */
function ai_e2e_test_request_mocking( $preempt, $parsed_args, $url ) {
	$response = '';

	// Mock the OpenAI models API response.
	if ( str_contains( $url, 'https://api.openai.com/v1/models' ) ) {
		// Handle invalid API key.
		if (
			isset( $parsed_args['headers']['Authorization'] ) &&
			str_contains( $parsed_args['headers']['Authorization'], 'invalid-api-key' )
		) {
			return $preempt;
		}

		$response = file_get_contents( __DIR__ . '/responses/OpenAI/models.json' );
	}

	// Mock the OpenAI responses API response.
	if ( str_contains( $url, 'https://api.openai.com/v1/responses' ) ) {
		$body = $parsed_args['body'] ?? '';

		// Route review-notes and refine-notes requests to their own fixture.
		if ( is_string( $body ) && str_contains( $body, 'Category guidance by block type' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/review-notes-responses.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'You are an editorial assistant for WordPress. Your task is to update a single block' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/refine-notes-responses.json' );
		} else {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/responses.json' );
		}
	}

	// Mock the OpenAI completions API response.
	if ( str_contains( $url, 'https://api.openai.com/v1/chat/completions' ) ) {
		$body = $parsed_args['body'] ?? '';

		// Route review-notes and refine-notes requests to their own fixture.
		if ( is_string( $body ) && str_contains( $body, 'Category guidance by block type' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/review-notes-completions.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'You are an editorial assistant for WordPress. Your task is to update a single block' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/refine-notes-completions.json' );
		} else {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/completions.json' );
		}
	}

	// Mock the OpenAI images API response.
	if ( str_contains( $url, 'https://api.openai.com/v1/images/generations' ) ) {
		$response = file_get_contents( __DIR__ . '/responses/OpenAI/image.json' );
	}

	if ( ! empty( $response ) ) {
		return array(
			'headers'     => array(),
			'cookies'     => array(),
			'filename'    => null,
			'response'    => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'status_code' => 200,
			'success'     => 1,
			'body'        => $response,
		);
	}

	// Return the original response if the URL is not a known request.
	return $preempt;
}
