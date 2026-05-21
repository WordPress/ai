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

// Register a REST endpoint for setting up/tearing down credentials in E2E tests.
add_action( 'rest_api_init', 'ai_e2e_register_credentials_endpoint' );

// Mock the HTTP requests and provide known responses.
add_filter( 'pre_http_request', 'ai_e2e_test_request_mocking', 10, 3 );

/**
 * Registers REST endpoints for seeding and clearing dummy AI provider credentials.
 *
 * POST /ai-e2e/v1/credentials/seed  — sets a dummy provider API key.
 * POST /ai-e2e/v1/credentials/clear — removes it.
 */
function ai_e2e_register_credentials_endpoint() {
	register_rest_route(
		'ai-e2e/v1',
		'/credentials/seed',
		array(
			'methods'             => 'POST',
			'callback'            => 'ai_e2e_seed_credentials',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	register_rest_route(
		'ai-e2e/v1',
		'/credentials/clear',
		array(
			'methods'             => 'POST',
			'callback'            => 'ai_e2e_clear_credentials',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}

/**
 * Seeds a dummy provider key so has_ai_credentials() returns true.
 *
 * @return WP_REST_Response
 */
function ai_e2e_seed_credentials() {
	update_option( 'connectors_ai_openai_api_key', 'valid-api-key' );
	return new WP_REST_Response( array( 'seeded' => true ) );
}

/**
 * Removes the dummy provider key so has_ai_credentials() returns false.
 *
 * @return WP_REST_Response
 */
function ai_e2e_clear_credentials() {
	delete_option( 'connectors_ai_openai_api_key' );
	return new WP_REST_Response( array( 'cleared' => true ) );
}

/**
 * Mock the HTTP requests and provide known responses.
 *
 * @param mixed  $preempt     Whether to preempt an HTTP request's return value.
 * @param array  $parsed_args HTTP request arguments.
 * @param string $url         The request URL.
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

	// Mock the Google models API response.
	if ( str_contains( $url, 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000' ) ) {
		// Handle invalid API key.
		if (
			isset( $parsed_args['headers']['X-Goog-Api-Key'] ) &&
			str_contains( $parsed_args['headers']['X-Goog-Api-Key'], 'invalid-api-key' )
		) {
			return $preempt;
		}

		$response = file_get_contents( __DIR__ . '/responses/Google/models.json' );
	}

	// Mock the Google Imagen API response.
	if ( str_contains( $url, 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict' ) ) {
		$response = file_get_contents( __DIR__ . '/responses/Google/imagen.json' );
	}

	// Mock the Google Gemini image API response.
	if ( str_contains( $url, 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image-preview:generateContent' ) ) {
		$response = file_get_contents( __DIR__ . '/responses/Google/gemini-image.json' );
	}

	// Mock the OpenAI responses API response.
	if ( str_contains( $url, 'https://api.openai.com/v1/responses' ) ) {
		$body = $parsed_args['body'] ?? '';

		// Route editorial-notes and editorial-updates requests to their own fixture.
		if ( is_string( $body ) && str_contains( $body, 'Category guidance by block type' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/editorial-notes-responses.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'You are an editorial assistant for WordPress. Your task is to update a single block' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/editorial-updates-responses.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'content taxonomy assistant' ) ) {
			// Route content-classification requests to their own fixture.
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/content-classification-responses.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'comment moderation assistant' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/comment-moderation-responses.json' );

			// Dynamically adjust response based on comment content for E2E variety.
			// We look for specific phrases from the E2E test to avoid matching the system prompt.
			if ( str_contains( $body, 'This is a positive comment' ) ) {
				$response = str_replace( 'negative', 'positive', $response );
				$response = str_replace( '0.95', '0.1', $response );
			} elseif ( str_contains( $body, 'This is a neutral comment' ) ) {
				$response = str_replace( 'negative', 'neutral', $response );
				$response = str_replace( '0.95', '0.5', $response );
			}
		} else {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/responses.json' );
		}
	}

	// Mock the OpenAI completions API response.
	if ( str_contains( $url, 'https://api.openai.com/v1/chat/completions' ) ) {
		$body = $parsed_args['body'] ?? '';

		// Route editorial-notes and editorial-updates requests to their own fixture.
		if ( is_string( $body ) && str_contains( $body, 'Category guidance by block type' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/editorial-notes-completions.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'You are an editorial assistant for WordPress. Your task is to update a single block' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/editorial-updates-completions.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'content taxonomy assistant' ) ) {
			// Route content-classification requests to their own fixture.
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/content-classification-completions.json' );
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
