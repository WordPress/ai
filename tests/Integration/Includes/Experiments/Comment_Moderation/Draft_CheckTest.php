<?php
/**
 * Integration tests for draft comment checks.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Comment_Moderation
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Comment_Moderation;

use WP_AI_Client_Prompt_Builder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation;

/**
 * Exercises the draft check endpoint and form with a deterministic AI response.
 *
 * @since x.x.x
 */
class Draft_CheckTest extends WP_UnitTestCase {

	/**
	 * REST route exercised by the test.
	 *
	 * @since x.x.x
	 */
	private const ROUTE = '/ai/v1/comment-draft-check';

	/**
	 * Transient holding the current request budget.
	 *
	 * @since x.x.x
	 */
	private const BUDGET_TRANSIENT = 'wpai_comment_draft_budget';

	/**
	 * Experiment under test.
	 *
	 * @var \WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation
	 */
	private Comment_Moderation $experiment;

	/**
	 * Public post created for the test.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Prepares a public post with comments open and an anonymous visitor.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Comment_Moderation();
		$this->experiment->register_settings();
		$this->post_id = self::factory()->post->create(
			array(
				'post_status'    => 'publish',
				'comment_status' => 'open',
			)
		);

		wp_set_current_user( 0 );
		update_option( 'comment_registration', false );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		delete_transient( self::BUDGET_TRANSIENT );
	}

	/**
	 * Restores WordPress state after exercising the REST route.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		unset( $GLOBALS['wp_rest_server'] );

		delete_transient( self::BUDGET_TRANSIENT );
		parent::tearDown();
	}

	/**
	 * Checks that draft analysis is unavailable with default settings.
	 *
	 * @since x.x.x
	 */
	public function test_draft_checks_are_off_by_default(): void {
		$this->register_experiment( false );
		$this->assertSame( 404, $this->check_draft()->get_status() );
		$this->assertStringNotContainsString( 'data-wpai-comment-draft-check=', $this->render_form() );
	}

	/**
	 * Checks that eligible readers receive the browser integration.
	 *
	 * @since x.x.x
	 */
	public function test_enabled_form_includes_draft_check_marker(): void {
		$this->register_experiment();
		$this->assertStringContainsString( 'data-wpai-comment-draft-check=', $this->render_form() );
	}

	/**
	 * Checks that guest moderation and comment availability apply to the form and endpoint.
	 *
	 * @since x.x.x
	 * @dataProvider ineligible_comment_forms
	 *
	 * @param string $restriction Restriction to apply.
	 */
	public function test_ineligible_form_cannot_request_draft_analysis( string $restriction ): void {
		$this->register_experiment();
		$this->stub_provider( '', 0 );

		if ( 'guest_moderation_off' === $restriction ) {
			update_option( Comment_Moderation::get_field_option_name( 'moderate_guests' ), false );
		} elseif ( 'login_required' === $restriction ) {
			update_option( 'comment_registration', true );
		} elseif ( 'private_post' === $restriction ) {
			wp_update_post(
				array(
					'ID'          => $this->post_id,
					'post_status' => 'private',
				)
			);
		} else {
			wp_update_post(
				array(
					'ID'             => $this->post_id,
					'comment_status' => 'closed',
				)
			);
		}

		$this->assertSame( 401, $this->check_draft()->get_status() );
		$this->assertStringNotContainsString( 'data-wpai-comment-draft-check=', $this->render_form() );
	}

	/**
	 * Supplies form restrictions that must also apply to the REST route.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}> Form restrictions.
	 */
	public static function ineligible_comment_forms(): array {
		return array(
			'guest moderation disabled' => array( 'guest_moderation_off' ),
			'login required'            => array( 'login_required' ),
			'comments closed'           => array( 'comments_closed' ),
			'private post'              => array( 'private_post' ),
		);
	}

	/**
	 * Checks that the route returns a verdict for drafts and leaves comments unsaved.
	 *
	 * @since x.x.x
	 * @dataProvider draft_verdicts
	 *
	 * @param float  $score     Provider toxicity score.
	 * @param string $sentiment Provider sentiment.
	 * @param bool   $blocked   Expected draft verdict.
	 */
	public function test_applies_the_configured_threshold_to_negative_drafts( float $score, string $sentiment, bool $blocked ): void {
		$this->register_experiment();
		update_option( Comment_Moderation::get_field_option_name( 'draft_toxicity_threshold' ), 80 );
		$this->stub_provider(
			wp_json_encode(
				array(
					'toxicity_score' => $score,
					'sentiment'      => $sentiment,
				)
			),
			1
		);

		$response = $this->check_draft();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'blocked' => $blocked ), $response->get_data() );
		$this->assertSame(
			0,
			get_comments(
				array(
					'post_id' => $this->post_id,
					'count'   => true,
				)
			)
		);
	}

	/**
	 * Supplies scores and sentiments around the configured cutoff.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{float, string, bool}> Draft verdict cases.
	 */
	public static function draft_verdicts(): array {
		return array(
			'negative at the cutoff'    => array( 0.8, 'negative', true ),
			'negative below the cutoff' => array( 0.79, 'negative', false ),
			'neutral above the cutoff'  => array( 0.9, 'neutral', false ),
		);
	}

	/**
	 * Checks that an invalid draft never reaches the provider.
	 *
	 * @since x.x.x
	 */
	public function test_blank_draft_is_rejected_before_generation(): void {
		$this->register_experiment();
		$this->stub_provider( '', 0 );

		$response = $this->check_draft( '   ' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_comment_draft', $response->get_data()['code'] );
	}

	/**
	 * Checks that a disconnected provider leaves the normal comment form available.
	 *
	 * @since x.x.x
	 */
	public function test_missing_credentials_make_the_draft_check_unavailable(): void {
		$this->register_experiment();
		$this->stub_provider( '', 0 );
		remove_filter( 'wpai_has_ai_credentials', '__return_true' );
		add_filter( 'wpai_has_ai_credentials', '__return_false' );

		$response = $this->check_draft();

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'comment_draft_check_unavailable', $response->get_data()['code'] );
		$form = $this->render_form();
		$this->assertStringContainsString( 'name="comment"', $form );
		$this->assertStringNotContainsString( 'data-wpai-comment-draft-check=', $form );
	}

	/**
	 * Checks that an unusable provider response gives the browser no verdict.
	 *
	 * @since x.x.x
	 * @dataProvider unavailable_provider_responses
	 *
	 * @param string|\WP_Error $result Provider response.
	 */
	public function test_unusable_provider_response_reports_an_unavailable_check( $result ): void {
		$this->register_experiment();
		$this->stub_provider( $result, 1 );

		$response = $this->check_draft();

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'comment_draft_check_unavailable', $response->get_data()['code'] );
	}

	/**
	 * Supplies failures and malformed responses from the provider.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string|\WP_Error}> Unavailable response cases.
	 */
	public static function unavailable_provider_responses(): array {
		return array(
			'provider request failed' => array( new WP_Error( 'provider_failed' ) ),
			'invalid JSON'            => array( 'unstructured response' ),
			'score outside the range' => array( '{"toxicity_score":1.2,"sentiment":"negative"}' ),
		);
	}

	/**
	 * Checks that exhausted visitor and site budgets reject requests before generation.
	 *
	 * @since x.x.x
	 * @dataProvider exhausted_budgets
	 *
	 * @param int $site_count    Requests already made on the site.
	 * @param int $visitor_count Requests already made by the current visitor.
	 */
	public function test_exhausted_budget_prevents_provider_calls( int $site_count, int $visitor_count ): void {
		$this->register_experiment();
		$this->stub_provider( '', 0 );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$visitor = hash_hmac( 'sha256', 'user:' . $user_id, wp_salt() );

		// Supply the current window at read time so the test cannot cross a minute boundary.
		add_filter(
			'pre_transient_' . self::BUDGET_TRANSIENT,
			static function () use ( $site_count, $visitor_count, $visitor ): array {
				return array(
					'minute'   => intdiv( time(), MINUTE_IN_SECONDS ),
					'total'    => $site_count,
					'visitors' => array( $visitor => $visitor_count ),
				);
			}
		);

		$response = $this->check_draft();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'comment_draft_check_limit', $response->get_data()['code'] );
	}

	/**
	 * Supplies visitor and site budgets at their limits.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{int, int}> Exhausted budget cases.
	 */
	public static function exhausted_budgets(): array {
		return array(
			'visitor limit' => array( 20, 20 ),
			'site limit'    => array( 90, 0 ),
		);
	}

	/**
	 * Registers the experiment and initializes a fresh REST server.
	 *
	 * @since x.x.x
	 *
	 * @param bool $check_drafts Whether to opt in to checking drafts.
	 */
	private function register_experiment( bool $check_drafts = true ): void {
		if ( $check_drafts ) {
			update_option( Comment_Moderation::get_field_option_name( 'check_drafts' ), true );
		}
		$this->experiment->register();
		unset( $GLOBALS['wp_rest_server'] );
		rest_get_server();
	}

	/**
	 * Dispatches a draft check through the WordPress REST API.
	 *
	 * @since x.x.x
	 *
	 * @param string $text Draft to check.
	 * @return \WP_REST_Response REST response.
	 */
	private function check_draft( string $text = 'A draft comment.' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'post_id' => $this->post_id,
					'text'    => $text,
				)
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * Renders the core comment form for the test post.
	 *
	 * @since x.x.x
	 *
	 * @return string Rendered comment form.
	 */
	private function render_form(): string {
		ob_start();
		comment_form( array(), $this->post_id );

		return (string) ob_get_clean();
	}

	/**
	 * Stubs only the provider call so the real analysis validation runs.
	 *
	 * @since x.x.x
	 *
	 * @param string|\WP_Error $result    Generated text or provider error.
	 * @param int              $calls     Expected number of generation calls.
	 */
	private function stub_provider( $result, int $calls ): void {
		$builder = $this->getMockBuilder( WP_AI_Client_Prompt_Builder::class )
			->disableOriginalConstructor()
			->addMethods( array( 'is_supported_for_text_generation', 'generate_text' ) )
			->getMock();
		$builder->method( 'is_supported_for_text_generation' )->willReturn( true );
		$builder->expects( $this->exactly( $calls ) )
			->method( 'generate_text' )
			->willReturn( $result );

		add_filter(
			'wpai_comment_analysis_prompt_builder',
			static function () use ( $builder ): WP_AI_Client_Prompt_Builder {
				return $builder;
			}
		);
	}
}
