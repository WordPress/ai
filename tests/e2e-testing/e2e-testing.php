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
// Register a REST endpoint for seeding backdated AI request log entries in E2E tests.
add_action( 'rest_api_init', 'ai_e2e_register_request_logs_endpoint' );

// Register a REST endpoint for overriding a feature's minimum content length in E2E tests.
add_action( 'rest_api_init', 'ai_e2e_register_min_content_length_endpoint' );
add_filter( 'wpai_min_content_length', 'ai_e2e_filter_min_content_length', 10, 2 );

// Mock the HTTP requests and provide known responses.
add_filter( 'pre_http_request', 'ai_e2e_test_request_mocking', 10, 3 );

// Register a sample setting flagged for the Abilities API, used by the core/settings-get E2E spec
// to verify the ability exposes settings registered by other active plugins.
add_action( 'init', 'ai_e2e_register_sample_setting' );

// Register a sample post type flagged for the Abilities API and seed a published post, used by
// the core/content-query E2E spec to verify the ability exposes content registered by other active plugins.
add_action( 'init', 'ai_e2e_register_sample_post_type', 5 );
add_action( 'init', 'ai_e2e_seed_sample_post', 20 );

// Abilities Explorer fixtures: two abilities in their own category, one with a custom provider
// label and one that always fails, and an extension column for the Explorer's fields filter.
add_action( 'wp_abilities_api_categories_init', 'ai_e2e_register_explorer_category' );
add_action( 'wp_abilities_api_init', 'ai_e2e_register_explorer_abilities' );
add_action( 'admin_enqueue_scripts', 'ai_e2e_enqueue_explorer_field' );

// Register the Abilities Explorer's assistant fixtures, switched on per spec through a REST
// endpoint so no other spec sees extra abilities or declaration-based admission.
add_action( 'rest_api_init', 'ai_e2e_register_explorer_fixtures_endpoint' );
add_action( 'wp_abilities_api_init', 'ai_e2e_register_explorer_fixtures' );
add_filter( 'wpai_workspace_tool_admission_enabled', 'ai_e2e_explorer_admission' );
add_filter( 'wpai_workspace_withheld_abilities', 'ai_e2e_explorer_withheld' );

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
			'permission_callback' => static function () {
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
			'permission_callback' => static function () {
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
 * @return \WP_REST_Response
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
 * Registers a REST endpoint for overriding a feature's minimum content length.
 *
 * POST /ai-e2e/v1/min-content-length — sets `length` for `feature`, or clears
 * the override when `length` is omitted.
 */
function ai_e2e_register_min_content_length_endpoint() {
	register_rest_route(
		'ai-e2e/v1',
		'/min-content-length',
		array(
			'methods'             => 'POST',
			'callback'            => 'ai_e2e_set_min_content_length',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'feature' => array(
					'type'     => 'string',
					'required' => true,
				),
				'length'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
		)
	);
}

/**
 * Stores or clears a feature's minimum content length override.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function ai_e2e_set_min_content_length( WP_REST_Request $request ) {
	$overrides = (array) get_option( 'ai_e2e_min_content_length', array() );
	$feature   = $request->get_param( 'feature' );

	if ( null === $request->get_param( 'length' ) ) {
		unset( $overrides[ $feature ] );
	} else {
		$overrides[ $feature ] = (int) $request->get_param( 'length' );
	}

	update_option( 'ai_e2e_min_content_length', $overrides );

	return new WP_REST_Response( $overrides );
}

/**
 * Applies any minimum content length override set by an E2E test.
 *
 * @param int    $content_length The minimum content length.
 * @param string $feature_id     The feature identifier.
 * @return int
 */
function ai_e2e_filter_min_content_length( $content_length, $feature_id ) {
	$overrides = (array) get_option( 'ai_e2e_min_content_length', array() );

	return $overrides[ $feature_id ] ?? $content_length;
}

/**
 * Registers a REST endpoint for seeding AI request log entries.
 *
 * POST /ai-e2e/v1/request-logs/seed — inserts a log entry dated `days_ago` days in the past.
 */
function ai_e2e_register_request_logs_endpoint() {
	register_rest_route(
		'ai-e2e/v1',
		'/request-logs/seed',
		array(
			'methods'             => 'POST',
			'callback'            => 'ai_e2e_seed_request_log',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'days_ago'  => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				),
				'operation' => array(
					'type'    => 'string',
					'default' => 'e2e:seeded',
				),
			),
		)
	);
}

/**
 * Inserts an AI request log entry with a backdated timestamp.
 *
 * Writes to the table directly because the log manager always stamps
 * entries with the current time.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function ai_e2e_seed_request_log( WP_REST_Request $request ) {
	global $wpdb;

	$days_ago = (int) $request->get_param( 'days_ago' );

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prefix . 'wpai_request_logs',
		array(
			'log_id'    => wp_generate_uuid4(),
			'timestamp' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ),
			'type'      => 'ai_client',
			'operation' => $request->get_param( 'operation' ),
			'status'    => 'success',
		),
		array( '%s', '%s', '%s', '%s', '%s' )
	);

	// Make the summary and filter dropdowns pick up the new entry.
	delete_transient( 'wpai_request_logs_filter_options' );
	foreach ( array( 'minute', 'hour', 'day', 'week', 'month', 'all' ) as $period ) {
		delete_transient( 'wpai_request_logs_summary_' . $period );
	}

	return new WP_REST_Response( array( 'seeded' => (bool) $wpdb->insert_id ) );
}

/**
 * Registers a sample setting exposed to the Abilities API.
 *
 * Used by the core/settings-get E2E spec to verify the ability exposes settings registered
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
 * Used by the core/content-query E2E spec to verify the ability exposes content registered
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
 * Runs once after the post type is registered; the core/content-query E2E spec fetches the
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
 * Registers the Abilities Explorer fixtures' category.
 *
 * The label carries an ampersand so the spec can check it renders as text.
 */
function ai_e2e_register_explorer_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		'ai-e2e-fixtures',
		array(
			'label'       => 'E2E Fixtures & Friends',
			'description' => 'Abilities registered by the E2E Testing plugin.',
		)
	);
}

/**
 * Registers the Abilities Explorer fixture abilities.
 *
 * `ai-e2e/acme-provider` has the custom provider label "Acme", for the provider
 * filter. `ai-e2e/failing-fixture` always returns an error, for the test
 * runner's error panel.
 */
function ai_e2e_register_explorer_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$permission = static function () {
		return current_user_can( 'manage_options' );
	};

	wp_register_ability(
		'ai-e2e/acme-provider',
		array(
			'label'               => 'Acme Provider Fixture',
			'description'         => 'An E2E fixture ability whose provider is the custom label "Acme".',
			'category'            => 'ai-e2e-fixtures',
			'execute_callback'    => static function () {
				return array( 'ok' => true );
			},
			'permission_callback' => $permission,
			'meta'                => array(
				'provider' => 'Acme',
			),
		)
	);

	wp_register_ability(
		'ai-e2e/failing-fixture',
		array(
			'label'               => 'Failing Fixture',
			'description'         => 'An E2E fixture ability that always returns an error.',
			'category'            => 'ai-e2e-fixtures',
			'execute_callback'    => static function () {
				return new WP_Error( 'ai_e2e_fixture_failed', 'The fixture failed on purpose.', array( 'reason' => 'e2e' ) );
			},
			'permission_callback' => $permission,
		)
	);
}

/**
 * The option that switches the Abilities Explorer fixtures on.
 */
const AI_E2E_EXPLORER_FIXTURES_OPTION = 'ai_e2e_explorer_fixtures';

/**
 * Registers the REST endpoint that switches the Abilities Explorer fixtures.
 *
 * POST /ai-e2e/v1/explorer-fixtures { enabled: bool } — switches the fixtures,
 * and always clears the assistant's owner exclusions and policy switch, so a
 * spec starts and ends with the surface options unset.
 */
function ai_e2e_register_explorer_fixtures_endpoint() {
	register_rest_route(
		'ai-e2e/v1',
		'/explorer-fixtures',
		array(
			'methods'             => 'POST',
			'callback'            => 'ai_e2e_switch_explorer_fixtures',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}

/**
 * Switches the Abilities Explorer fixtures and resets the surface options.
 *
 * @param WP_REST_Request $request The request.
 * @return array The new state.
 */
function ai_e2e_switch_explorer_fixtures( $request ) {
	$enabled = (bool) $request->get_param( 'enabled' );

	if ( $enabled ) {
		update_option( AI_E2E_EXPLORER_FIXTURES_OPTION, '1' );
	} else {
		delete_option( AI_E2E_EXPLORER_FIXTURES_OPTION );
	}

	delete_option( 'wpai_workspace_tool_exclusions' );
	delete_option( 'wpai_workspace_tool_policy_disabled' );

	return array( 'enabled' => $enabled );
}

/**
 * Whether the Abilities Explorer fixtures are switched on.
 *
 * @return bool
 */
function ai_e2e_explorer_fixtures_enabled() {
	return (bool) get_option( AI_E2E_EXPLORER_FIXTURES_OPTION );
}

/**
 * Admits declared abilities to the assistant while the fixtures are on.
 *
 * @param bool $enabled Whether declared abilities may be admitted.
 * @return bool
 */
function ai_e2e_explorer_admission( $enabled ) {
	return ai_e2e_explorer_fixtures_enabled() ? true : $enabled;
}

/**
 * Withholds the withheld fixture from the assistant.
 *
 * @param array $withheld The withheld ability names.
 * @return array
 */
function ai_e2e_explorer_withheld( $withheld ) {
	if ( ai_e2e_explorer_fixtures_enabled() && is_array( $withheld ) ) {
		$withheld[] = 'ai-e2e/withheld-reader';
	}

	return $withheld;
}

/**
 * Registers the Abilities Explorer fixtures while they are switched on.
 *
 * - `ai-e2e/assistant-reader` declares itself fit for the assistant, so with
 *   admission on it is on the assistant and offers "Remove from assistant".
 * - `ai-e2e/withheld-reader` declares the same, but is withheld.
 */
function ai_e2e_register_explorer_fixtures() {
	if ( ! function_exists( 'wp_register_ability' ) || ! ai_e2e_explorer_fixtures_enabled() ) {
		return;
	}

	$declared = array(
		'annotations'  => array(
			'readonly'    => true,
			'destructive' => false,
			'open_world'  => false,
		),
		'ai-workspace' => array( 'public' => true ),
	);

	$permission = static function () {
		return current_user_can( 'manage_options' );
	};

	wp_register_ability(
		'ai-e2e/assistant-reader',
		array(
			'label'               => 'Assistant Reader Fixture',
			'description'         => 'An E2E fixture ability the assistant may call.',
			'category'            => 'ai-e2e-fixtures',
			'execute_callback'    => static function () {
				return array( 'read' => true );
			},
			'permission_callback' => $permission,
			'meta'                => $declared,
		)
	);

	wp_register_ability(
		'ai-e2e/withheld-reader',
		array(
			'label'               => 'Withheld Reader Fixture',
			'description'         => 'An E2E fixture ability that is withheld from the assistant.',
			'category'            => 'ai-e2e-fixtures',
			'execute_callback'    => static function () {
				return array( 'read' => true );
			},
			'permission_callback' => $permission,
			'meta'                => $declared,
		)
	);
}

/**
 * Adds the "Slug length" extension column when the Explorer is opened with `e2e_fields` set.
 *
 * @param string $hook_suffix The current admin page.
 */
function ai_e2e_enqueue_explorer_field( $hook_suffix ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture switch.
	if ( 'tools_page_ai-abilities-explorer' !== $hook_suffix || ! isset( $_GET['e2e_fields'] ) ) {
		return;
	}

	wp_enqueue_script(
		'ai-e2e-abilities-explorer-field',
		plugins_url( 'abilities-explorer-field.js', __FILE__ ),
		array( 'wp-hooks' ),
		'0.1.0',
		true
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
	// Respect a block/response already set by an earlier `pre_http_request`
	// filter (e.g. Connector Approval's Http_Guard), instead of unconditionally
	// overwriting it with a canned mock response.
	if ( false !== $preempt ) {
		return $preempt;
	}

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
		} elseif ( is_string( $body ) && str_contains( $body, 'explains what changed between two revisions' ) ) {
			// Route revision-change-summary requests to their own fixture.
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/revision-change-summary-responses.json' );
		} elseif ( is_string( $body ) && str_contains( $body, 'comment moderation assistant' ) ) {
			$response = file_get_contents( __DIR__ . '/responses/OpenAI/comment-moderation-responses.json' );

			// Dynamically adjust response based on comment content for E2E variety.
			// We look for specific phrases from the E2E test to avoid matching the system prompt.
			// Each replacement is scoped to its JSON key so it cannot collide with an
			// unrelated number elsewhere in the fixture, or with a value another
			// replacement in the same batch just wrote.
			$search = array(
				'\"toxicity_score\":0.95',
				'\"sentiment\":\"negative\"',
				'\"value_score\":0.15',
			);

			if ( str_contains( $body, 'This is a positive comment' ) ) {
				$response = str_replace(
					$search,
					array(
						'\"toxicity_score\":0.1',
						'\"sentiment\":\"positive\"',
						'\"value_score\":0.9',
					),
					$response
				);
			} elseif ( str_contains( $body, 'This is a neutral comment' ) ) {
				$response = str_replace(
					$search,
					array(
						'\"toxicity_score\":0.5',
						'\"sentiment\":\"neutral\"',
						'\"value_score\":0.5',
					),
					$response
				);
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
