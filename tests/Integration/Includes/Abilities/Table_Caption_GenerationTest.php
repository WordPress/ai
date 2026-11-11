<?php
/**
 * Integration tests for the Table_Caption_Generation ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Table_Caption_Generation\Table_Caption_Generation;

/**
 * Table caption generation ability test case.
 *
 * @since x.x.x
 */
class Table_Caption_GenerationTest extends WP_UnitTestCase {

	/**
	 * Ability under test.
	 *
	 * @var \WordPress\AI\Abilities\Table_Caption_Generation\Table_Caption_Generation
	 */
	private $ability;

	/**
	 * Mock prompt builder.
	 *
	 * @var \WP_AI_Client_Prompt_Builder&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $builder;

	/**
	 * Valid input for the current post.
	 *
	 * @var array
	 */
	private $input;

	/**
	 * Prompt passed to the provider.
	 *
	 * @var string
	 */
	private $prompt = '';

	/**
	 * Sets up a real ability with a mocked provider response.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
		$this->input   = array(
			'post_id' => self::factory()->post->create(
				array(
					'post_author'  => $user_id,
					'post_title'   => 'Hosting plans',
					'post_content' => 'Existing post content and caption.',
				)
			),
			'table'   => array(
				'head' => array(
					array(
						array(
							'content' => 'Plan',
							'tag'     => 'th',
							'scope'   => 'col',
						),
					),
				),
				'body' => array(
					array(
						array(
							'content' => 'Starter',
							'tag'     => 'td',
						),
					),
				),
				'foot' => array(),
			),
		);
		$this->ability = new Table_Caption_Generation(
			'ai/table-caption-generation',
			array(
				'label'       => 'Table Caption Generation',
				'description' => 'Generates table captions for review.',
			)
		);
		$this->builder = $this->getMockBuilder( \WP_AI_Client_Prompt_Builder::class )
			->disableOriginalConstructor()
			->addMethods( array( 'is_supported_for_text_generation', 'generate_text' ) )
			->getMock();

		add_filter(
			'wpai_table_caption_generation_prompt_builder',
			function ( $builder, $prompt ) {
				$this->prompt = $prompt;
				return $this->builder;
			},
			10,
			2
		);
	}

	/**
	 * Restores user and prompt filters.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wpai_table_caption_generation_prompt_builder' );
		parent::tearDown();
	}

	/**
	 * Configures the provider to return a response without making an HTTP request.
	 *
	 * @param string|\WP_Error $response Provider response.
	 */
	private function mock_response( $response ): void {
		$this->builder->method( 'is_supported_for_text_generation' )->willReturn( true );
		$this->builder->expects( $this->once() )->method( 'generate_text' )->willReturn( $response );
	}

	/**
	 * Tests that an author can generate a caption for their post without saving it.
	 *
	 * @since x.x.x
	 */
	public function test_generates_caption_without_modifying_post(): void {
		$this->mock_response( 'Monthly hosting plan prices and storage limits.' );
		$before = get_post( $this->input['post_id'], ARRAY_A );

		$this->assertSame( 'Monthly hosting plan prices and storage limits.', $this->ability->execute( $this->input ) );
		$this->assertSame( $before, get_post( $this->input['post_id'], ARRAY_A ) );
	}

	/**
	 * Tests that full source data reaches the provider in escaped XML sections.
	 *
	 * @since x.x.x
	 */
	public function test_prompt_contains_full_normalized_reference_data(): void {
		$this->mock_response( 'Hosting plan comparison.' );
		$this->input['title']                            = str_repeat( 'Hosting & storage ', 30 ) . 'TITLE_END';
		$this->input['context']                          = str_repeat( 'Monthly prices & storage. ', 150 ) . 'CONTEXT_END';
		$this->input['table']['head'][0][0]              = array(
			'content' => '<strong>Plan &amp; storage</strong>',
			'tag'     => 'th',
			'scope'   => 'colgroup',
			'colspan' => 2,
			'rowspan' => 2,
		);
		$this->input['table']['body']                    = array_fill( 0, 55, array_fill( 0, 25, array( 'content' => 'Hosting plan details' ) ) );
		$this->input['table']['body'][54][24]['content'] = 'FINAL_CELL';

		$this->assertSame( 'Hosting plan comparison.', $this->ability->execute( $this->input ) );
		$xml = simplexml_load_string( '<reference>' . $this->prompt . '</reference>' );
		$this->assertNotFalse( $xml );
		$this->assertSame( $this->input['title'], (string) $xml->{'post-title'} );
		$this->assertSame( $this->input['context'], trim( (string) $xml->{'surrounding-text'} ) );
		$table = json_decode( (string) $xml->{'table-data'}, true );
		$this->assertSame( 'Plan & storage', $table['head'][0][0]['content'] );
		$this->assertSame( 'colgroup', $table['head'][0][0]['scope'] );
		$this->assertSame( 2, $table['head'][0][0]['colspan'] );
		$this->assertSame( 2, $table['head'][0][0]['rowspan'] );
		$this->assertSame( $this->input['table']['body'], $table['body'] );
	}

	/**
	 * Tests the saved title fallback when no edited title is supplied.
	 *
	 * @since x.x.x
	 */
	public function test_uses_saved_post_title_by_default(): void {
		$this->mock_response( 'Hosting plans.' );
		$this->ability->execute( $this->input );

		$this->assertStringContainsString( '<post-title>Hosting plans</post-title>', $this->prompt );
	}

	/**
	 * Tests that malformed inputs are rejected before provider execution.
	 *
	 * @since x.x.x
	 * @dataProvider invalid_inputs
	 *
	 * @param string $invalid_case Invalid input case.
	 */
	public function test_rejects_invalid_input( string $invalid_case ): void {
		switch ( $invalid_case ) {
			case 'missing post':
				unset( $this->input['post_id'] );
				break;
			case 'missing section':
				unset( $this->input['table']['foot'] );
				break;
			case 'invalid content':
				$this->input['table']['body'][0][0]['content'] = array();
				break;
			case 'invalid span':
				$this->input['table']['head'][0][0]['colspan'] = 0;
				break;
		}
		$this->builder->expects( $this->never() )->method( 'generate_text' );
		$result = $this->ability->execute( $this->input );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Provides invalid request cases.
	 *
	 * @return array<string, array<string>> Invalid cases.
	 */
	public function invalid_inputs(): array {
		return array(
			'missing post'    => array( 'missing post' ),
			'missing section' => array( 'missing section' ),
			'invalid content' => array( 'invalid content' ),
			'invalid span'    => array( 'invalid span' ),
		);
	}

	/**
	 * Tests that users without permission to edit this post cannot generate captions.
	 *
	 * @since x.x.x
	 * @dataProvider denied_roles
	 *
	 * @param string $role Role to test, or an empty string for logged-out users.
	 */
	public function test_denies_users_without_post_edit_permission( string $role ): void {
		wp_set_current_user( $role ? self::factory()->user->create( array( 'role' => $role ) ) : 0 );
		$result = $this->ability->check_permissions( $this->input );

		$this->assertWPError( $result );
		$this->assertSame( 'insufficient_permissions', $result->get_error_code() );
	}

	/**
	 * Provides users who cannot edit the fixture post.
	 *
	 * @return array<string, array<string>> Denied roles.
	 */
	public function denied_roles(): array {
		return array(
			'logged out'     => array( '' ),
			'subscriber'     => array( 'subscriber' ),
			'another author' => array( 'author' ),
		);
	}

	/**
	 * Tests that a deleted post is rejected before calling the provider.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_missing_post(): void {
		wp_delete_post( $this->input['post_id'], true );
		$result = $this->ability->check_permissions( $this->input );

		$this->assertWPError( $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Tests that a post type without editor support cannot generate captions.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_unsupported_post_type(): void {
		register_post_type(
			'caption_no_editor',
			array(
				'show_in_rest' => true,
				'supports'     => array( 'title' ),
			)
		);
		try {
			$this->input['post_id'] = self::factory()->post->create(
				array(
					'post_type'   => 'caption_no_editor',
					'post_author' => get_current_user_id(),
				)
			);
			$result                 = $this->ability->check_permissions( $this->input );

			$this->assertWPError( $result );
			$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		} finally {
			unregister_post_type( 'caption_no_editor' );
		}
	}

	/**
	 * Tests that formatting-only cells do not trigger a provider request.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_empty_table(): void {
		$this->input['table']['head']                  = array();
		$this->input['table']['body'][0][0]['content'] = '<p><br>&nbsp;</p>';
		$this->builder->expects( $this->never() )->method( 'generate_text' );
		$result = $this->ability->execute( $this->input );

		$this->assertWPError( $result );
		$this->assertSame( 'empty_table', $result->get_error_code() );
	}

	/**
	 * Tests caption sanitization without losing entities, percentages, or Unicode.
	 *
	 * @since x.x.x
	 * @dataProvider caption_responses
	 *
	 * @param string $response Provider output.
	 * @param string $expected Expected plain-text caption.
	 */
	public function test_sanitizes_caption( string $response, string $expected ): void {
		$this->mock_response( $response );
		$this->assertSame( $expected, $this->ability->execute( $this->input ) );
	}

	/**
	 * Provides captions requiring sanitization or character preservation.
	 *
	 * @return array<string, array<string>> Provider output and expected captions.
	 */
	public function caption_responses(): array {
		return array(
			'markup and quotes' => array( ' "<strong>Hosting plans</strong>" ', 'Hosting plans' ),
			'ampersand'         => array( 'Workshop Session &amp; Room', 'Workshop Session & Room' ),
			'literal tag'       => array( 'HTML &lt;table&gt; element reference', 'HTML <table> element reference' ),
			'script markup'     => array( '<script>alert(1)</script>Hosting plans', 'Hosting plans' ),
			'Unicode'           => array( '料金と容量の比較 — %50 discount', '料金と容量の比較 — %50 discount' ),
		);
	}

	/**
	 * Tests that an empty sanitized response returns a useful error.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_empty_provider_response(): void {
		$this->mock_response( '<strong></strong>' );
		$result = $this->ability->execute( $this->input );

		$this->assertWPError( $result );
		$this->assertSame( 'no_results', $result->get_error_code() );
	}

	/**
	 * Tests that provider errors are returned unchanged.
	 *
	 * @since x.x.x
	 */
	public function test_returns_provider_error(): void {
		$error = new WP_Error( 'provider_error', 'Caption generation failed.' );
		$this->mock_response( $error );

		$this->assertSame( $error, $this->ability->execute( $this->input ) );
	}

	/**
	 * Tests that models without text generation support are rejected.
	 *
	 * @since x.x.x
	 */
	public function test_returns_error_for_unsupported_model(): void {
		$this->builder->method( 'is_supported_for_text_generation' )->willReturn( false );
		$this->builder->expects( $this->never() )->method( 'generate_text' );
		$result = $this->ability->execute( $this->input );

		$this->assertWPError( $result );
		$this->assertSame( 'unsupported_model', $result->get_error_code() );
	}
}
