<?php
/**
 * Plugin name: E2E Testing
 * Description: Support plugin for the E2E test suite. Mocks API requests and registers test fixtures, such as a setting and a post type flagged for the Abilities API.
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

// Register a REST endpoint for driving the sequenced response scenarios.
add_action( 'rest_api_init', 'ai_e2e_register_scenario_endpoint' );

// While a scenario is active, keep the workspace turn on its buffered path.
add_filter( 'wpai_workspace_stream_emitter', 'ai_e2e_suppress_provider_streaming', 99 );

// Mock the HTTP requests and provide known responses.
add_filter( 'pre_http_request', 'ai_e2e_test_request_mocking', 10, 3 );

// Register a sample setting flagged for the Abilities API, used by the core/read-settings E2E spec
// to verify the ability exposes settings registered by other active plugins.
add_action( 'init', 'ai_e2e_register_sample_setting' );

// Register a sample post type flagged for the Abilities API and seed a published post, used by
// the core/read-content E2E spec to verify the ability exposes content registered by other active plugins.
add_action( 'init', 'ai_e2e_register_sample_post_type', 5 );
add_action( 'init', 'ai_e2e_seed_sample_post', 20 );

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
 * Returns the provider IDs whose credentials this plugin can seed.
 *
 * @return array<string, string> Map of provider ID to its API key option name.
 */
function ai_e2e_credential_options() {
	return array(
		'openai'    => 'connectors_ai_openai_api_key',
		'google'    => 'connectors_ai_google_api_key',
		'anthropic' => 'connectors_ai_anthropic_api_key',
	);
}

/**
 * Seeds dummy provider keys so has_ai_credentials() returns true.
 *
 * With no `providers` parameter this seeds OpenAI and leaves the other providers
 * untouched, which is what the majority of specs expect. A spec that needs a
 * single named provider — the AI Workspace scenario specs need Anthropic, since
 * that is the only provider the mock has a tool-calling sequence for — passes
 * `providers`, and every provider not listed is cleared so the turn cannot be
 * answered by a provider the spec did not ask for.
 *
 * @param WP_REST_Request|null $request Optional. The request.
 * @return WP_REST_Response
 */
function ai_e2e_seed_credentials( $request = null ) {
	$options   = ai_e2e_credential_options();
	$requested = null;

	if ( $request instanceof WP_REST_Request ) {
		$param = $request->get_param( 'providers' );

		if ( is_array( $param ) ) {
			$requested = array_values( array_intersect( array_keys( $options ), $param ) );
		}
	}

	$exclusive = null !== $requested;
	$seed      = $exclusive ? $requested : array( 'openai' );

	foreach ( $options as $provider => $option_name ) {
		if ( in_array( $provider, $seed, true ) ) {
			update_option( $option_name, 'valid-api-key' );
			continue;
		}

		if ( $exclusive ) {
			delete_option( $option_name );
		}
	}

	return new WP_REST_Response(
		array(
			'seeded' => $seed,
		)
	);
}

/**
 * Removes the dummy provider keys so has_ai_credentials() returns false.
 *
 * @return WP_REST_Response
 */
function ai_e2e_clear_credentials() {
	foreach ( ai_e2e_credential_options() as $option_name ) {
		delete_option( $option_name );
	}

	return new WP_REST_Response( array( 'cleared' => true ) );
}

/**
 * Registers REST endpoints for driving sequenced mock responses.
 *
 * POST   /ai-e2e/v1/mock/scenario — activates a scenario and resets its call counters.
 * GET    /ai-e2e/v1/mock/scenario — reports the active scenario and how many calls it served.
 * DELETE /ai-e2e/v1/mock/scenario — deactivates whatever scenario is active.
 */
function ai_e2e_register_scenario_endpoint() {
	register_rest_route(
		'ai-e2e/v1',
		'/mock/scenario',
		array(
			array(
				'methods'             => 'POST',
				'callback'            => 'ai_e2e_activate_scenario',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'scenario' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
			array(
				'methods'             => 'GET',
				'callback'            => 'ai_e2e_report_scenario',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => 'ai_e2e_deactivate_scenario',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
		)
	);
}

/**
 * Activates a scenario and resets its call counters.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function ai_e2e_activate_scenario( $request ) {
	$scenario = ai_e2e_sanitize_scenario( $request->get_param( 'scenario' ) );

	update_option( 'ai_e2e_mock_scenario', $scenario, false );
	update_option( 'ai_e2e_mock_calls', array(), false );

	return new WP_REST_Response( array( 'scenario' => $scenario ) );
}

/**
 * Reports the active scenario and the number of calls it has served.
 *
 * The call counts are what make a sequence verifiable from a spec: a turn that
 * really drove a tool call and then a final message consumed two entries, and a
 * turn that answered in one round consumed one.
 *
 * @return WP_REST_Response
 */
function ai_e2e_report_scenario() {
	$calls = get_option( 'ai_e2e_mock_calls', array() );

	return new WP_REST_Response(
		array(
			'scenario' => ai_e2e_active_scenario(),
			'calls'    => is_array( $calls ) ? $calls : array(),
		)
	);
}

/**
 * Deactivates the active scenario.
 *
 * @return WP_REST_Response
 */
function ai_e2e_deactivate_scenario() {
	delete_option( 'ai_e2e_mock_scenario' );
	delete_option( 'ai_e2e_mock_calls' );

	return new WP_REST_Response( array( 'scenario' => '' ) );
}

/**
 * Normalizes a scenario identifier to the characters a fixture filename may use.
 *
 * @param mixed $scenario The requested scenario.
 * @return string The sanitized scenario, or an empty string.
 */
function ai_e2e_sanitize_scenario( $scenario ) {
	if ( ! is_string( $scenario ) ) {
		return '';
	}

	return (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( $scenario ) );
}

/**
 * Returns the active scenario identifier.
 *
 * @return string The scenario, or an empty string when none is active.
 */
function ai_e2e_active_scenario() {
	return ai_e2e_sanitize_scenario( get_option( 'ai_e2e_mock_scenario', '' ) );
}

/**
 * Returns the next response body from the active scenario, if it has one.
 *
 * A scenario fixture lives at `responses/{Provider}/scenarios/{scenario}.json` and
 * holds a `sequence` array of complete provider response bodies. Each matching
 * request consumes the next entry, so one spec can drive a whole turn — request,
 * tool call, tool result, final message — from a single file. The last entry is
 * repeated once the sequence is exhausted, so a turn that runs one round more
 * than the fixture anticipated still terminates instead of falling through to the
 * substring-matched default and answering with something unrelated.
 *
 * The counter is stored rather than held in memory because a turn's rounds all
 * run inside one request but a spec reads the count back in a later one.
 *
 * @param string $provider The provider directory name, e.g. 'Anthropic'.
 * @return string|null The response body as JSON, or null when no scenario applies.
 */
function ai_e2e_scenario_response( $provider ) {
	$scenario = ai_e2e_active_scenario();

	if ( '' === $scenario ) {
		return null;
	}

	$path = __DIR__ . '/responses/' . $provider . '/scenarios/' . $scenario . '.json';

	if ( ! file_exists( $path ) ) {
		return null;
	}

	$decoded = json_decode( (string) file_get_contents( $path ), true );

	if ( ! is_array( $decoded ) || ! isset( $decoded['sequence'] ) || ! is_array( $decoded['sequence'] ) || array() === $decoded['sequence'] ) {
		return null;
	}

	$sequence = array_values( $decoded['sequence'] );
	$key      = $provider . '/' . $scenario;
	$counts   = get_option( 'ai_e2e_mock_calls', array() );

	if ( ! is_array( $counts ) ) {
		$counts = array();
	}

	$index = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;

	$counts[ $key ] = $index + 1;
	update_option( 'ai_e2e_mock_calls', $counts, false );

	$entry = $sequence[ min( $index, count( $sequence ) - 1 ) ];

	return (string) wp_json_encode( $entry );
}

/**
 * Keeps a scenario-driven turn on the buffered request path.
 *
 * The AI Workspace's streaming transport does not use `wp_safe_remote_request()`:
 * it opens its own connection through `Fopen_Stream_Opener`, which never reaches
 * the `pre_http_request` filter this plugin mocks. A streamed round would
 * therefore leave the machine for real, which a scenario spec must never do. The
 * emitter is filtered away for the duration of a scenario, so the turn answers
 * from the buffered — and mockable — path instead. Server-sent events from
 * WordPress to the browser are a separate seam and stay covered by the specs that
 * run without a scenario.
 *
 * @param callable|null $emitter The emitter another consumer supplied, if any.
 * @return callable|null The emitter, or null while a scenario is active.
 */
function ai_e2e_suppress_provider_streaming( $emitter ) {
	if ( '' === ai_e2e_active_scenario() ) {
		return $emitter;
	}

	return null;
}

/**
 * Registers a sample setting exposed to the Abilities API.
 *
 * Used by the core/read-settings E2E spec to verify the ability exposes settings registered
 * by other active plugins.
 */
function ai_e2e_register_sample_setting() {
	register_setting(
		'general',
		'ai_e2e_sample_setting',
		array(
			'type'              => 'string',
			'label'             => 'AI E2E Sample Setting',
			'description'       => 'A sample setting exposed to the Abilities API for end-to-end testing.',
			'show_in_abilities' => true,
			'default'           => 'sample-default',
		)
	);
}

/**
 * Registers a sample post type exposed to the Abilities API.
 *
 * Used by the core/read-content E2E spec to verify the ability exposes content registered
 * by other active plugins.
 */
function ai_e2e_register_sample_post_type() {
	register_post_type(
		'ai_e2e_sample',
		array(
			'label'             => 'AI E2E Sample',
			'public'            => true,
			'show_in_rest'      => true,
			'show_in_abilities' => true,
			'supports'          => array( 'title', 'editor', 'excerpt', 'author' ),
		)
	);
}

/**
 * Seeds a single published post of the sample post type.
 *
 * Runs once after the post type is registered; the core/read-content E2E spec fetches the
 * seeded post by slug to confirm content from another active plugin is exposed.
 */
function ai_e2e_seed_sample_post() {
	if ( get_page_by_path( 'ai-e2e-sample-content', OBJECT, 'ai_e2e_sample' ) ) {
		return;
	}

	wp_insert_post(
		array(
			'post_type'    => 'ai_e2e_sample',
			'post_name'    => 'ai-e2e-sample-content',
			'post_title'   => 'AI E2E Sample Content',
			'post_content' => 'Sample content body for end-to-end testing.',
			'post_status'  => 'publish',
		)
	);
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

	// Mock the Anthropic models API response.
	if ( str_contains( $url, 'https://api.anthropic.com/v1/models' ) ) {
		// Handle invalid API key.
		if (
			isset( $parsed_args['headers']['x-api-key'] ) &&
			str_contains( $parsed_args['headers']['x-api-key'], 'invalid-api-key' )
		) {
			return $preempt;
		}

		$response = file_get_contents( __DIR__ . '/responses/Anthropic/models.json' );
	}

	// Mock the Anthropic messages API response.
	if ( str_contains( $url, 'https://api.anthropic.com/v1/messages' ) ) {
		$sequenced = ai_e2e_scenario_response( 'Anthropic' );

		$response = null === $sequenced
			? file_get_contents( __DIR__ . '/responses/Anthropic/messages.json' )
			: $sequenced;
	}

	// Mock the OpenAI responses API response.
	if ( str_contains( $url, 'https://api.openai.com/v1/responses' ) ) {
		$body      = $parsed_args['body'] ?? '';
		$sequenced = ai_e2e_scenario_response( 'OpenAI' );

		if ( null !== $sequenced ) {
			return ai_e2e_mocked_response( $sequenced );
		}

		// Route editorial-notes and editorial-updates requests to their own fixture.
		if ( is_string( $body ) && str_contains( $body, 'Category guidance by block type' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/editorial-notes-responses.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'You are an editorial assistant for WordPress. Your task is to update a single block' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/editorial-updates-responses.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'content taxonomy assistant' ) ) {
			// Route content-classification requests to their own fixture.
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/content-classification-responses.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'inline ghost text suggestions' ) ) {
			// Route type-ahead text requests to their own fixture.
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/type-ahead-responses.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'permalink slug suggestions' ) ) {
			// Route slug-generation requests to their own fixture.
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/slug-generation-responses.json' );
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
		} elseif ( is_string( $body ) && str_contains( $body, 'permalink slug suggestions' ) ) {
			// Route slug-generation requests to their own fixture.
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/slug-generation-completions.json' );
		} else {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/completions.json' );
		}
	}

	// Mock the OpenAI images API response.
	if ( str_contains( $url, 'https://api.openai.com/v1/images/generations' ) ) {
		$response = file_get_contents( __DIR__ . '/responses/OpenAI/image.json' );
	}

	if ( ! empty( $response ) ) {
		return ai_e2e_mocked_response( $response );
	}

	// Return the original response if the URL is not a known request.
	return $preempt;
}

/**
 * Wraps a response body in the array shape `pre_http_request` expects.
 *
 * @param string $body The response body.
 * @return array The mocked HTTP response.
 */
function ai_e2e_mocked_response( $body ) {
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
		'body'        => $body,
	);
}
