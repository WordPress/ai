<?php
/**
 * Integration tests for the AI Workspace question ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_Ability;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Ask_User;
use WordPress\AI\Experiments\AI_Workspace\Turn_Context;

/**
 * Ask_User test case.
 *
 * The question ability writes nothing, so these tests are about its shape: what
 * the schema refuses, that a valid question comes back exactly as given, and
 * that, like the proposal ability, it stays off the REST and MCP surfaces and
 * outside workspace turns.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Ask_User
 */
class Ask_UserTest extends WP_UnitTestCase {

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Creates the shared users.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'        => $factory->user->create( array( 'role' => 'editor' ) ),
		);
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Ask_User() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		Turn_Context::leave();

		if ( wp_has_ability( Ask_User::ABILITY ) ) {
			wp_unregister_ability( Ask_User::ABILITY );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Returns the registered ability.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Ability The ability.
	 */
	private function ability(): WP_Ability {
		$ability = wp_get_ability( Ask_User::ABILITY );

		$this->assertInstanceOf( WP_Ability::class, $ability );

		return $ability;
	}

	/**
	 * Runs the ability as an administrator inside a workspace turn.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function ask( array $input ) {
		wp_set_current_user( self::$user_ids['administrator'] );
		Turn_Context::enter( 'conversation-1', self::$user_ids['administrator'] );

		return $this->ability()->execute( $input );
	}

	/**
	 * A valid question with three choices comes back unchanged.
	 *
	 * @since x.x.x
	 */
	public function test_returns_the_question_and_choices_unchanged(): void {
		$input = array(
			'question' => 'How should the series start?',
			'choices'  => array( 'Create these 5 drafts', 'Change the angle', 'Fewer parts' ),
		);

		$result = $this->ask( $input );

		$this->assertIsArray( $result );
		$this->assertSame( $input['question'], $result['question'] );
		$this->assertSame( $input['choices'], $result['choices'] );
	}

	/**
	 * Inputs the schema refuses.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: array<string, mixed>}> The invalid inputs.
	 */
	public function data_invalid_inputs(): array {
		return array(
			'one choice'           => array(
				array(
					'question' => 'Go ahead?',
					'choices'  => array( 'Yes' ),
				),
			),
			'six choices'          => array(
				array(
					'question' => 'Pick one.',
					'choices'  => array( 'One', 'Two', 'Three', 'Four', 'Five', 'Six' ),
				),
			),
			'over-length choice'   => array(
				array(
					'question' => 'Pick one.',
					'choices'  => array( 'Short', str_repeat( 'x', Ask_User::MAX_CHOICE_LENGTH + 1 ) ),
				),
			),
			'over-length question' => array(
				array(
					'question' => str_repeat( 'x', Ask_User::MAX_QUESTION_LENGTH + 1 ),
					'choices'  => array( 'Yes', 'No' ),
				),
			),
			'empty choice'         => array(
				array(
					'question' => 'Pick one.',
					'choices'  => array( 'Yes', '' ),
				),
			),
			'repeated choice'      => array(
				array(
					'question' => 'Pick one.',
					'choices'  => array( 'Yes', 'Yes' ),
				),
			),
			'no question'          => array(
				array(
					'choices' => array( 'Yes', 'No' ),
				),
			),
		);
	}

	/**
	 * The schema refuses a question it cannot render as a short set of buttons.
	 *
	 * @dataProvider data_invalid_inputs
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The invalid input.
	 */
	public function test_refuses_invalid_input( array $input ): void {
		$result = $this->ask( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Five choices, each at the length limit, are still accepted.
	 *
	 * @since x.x.x
	 */
	public function test_accepts_the_limits(): void {
		$choices = array();

		for ( $i = 0; $i < Ask_User::MAX_CHOICES; $i++ ) {
			$choices[] = str_pad( (string) $i, Ask_User::MAX_CHOICE_LENGTH, 'x' );
		}

		$result = $this->ask(
			array(
				'question' => str_repeat( 'q', Ask_User::MAX_QUESTION_LENGTH ),
				'choices'  => $choices,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $choices, $result['choices'] );
	}

	/**
	 * Outside a workspace turn there is nobody to answer, so the ability refuses.
	 *
	 * @since x.x.x
	 */
	public function test_refuses_outside_a_workspace_turn(): void {
		wp_set_current_user( self::$user_ids['administrator'] );

		$result = $this->ability()->execute(
			array(
				'question' => 'Go ahead?',
				'choices'  => array( 'Yes', 'No' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_context_required', $result->get_error_code() );
	}

	/**
	 * It asks for the capability the workspace already requires, and no other.
	 *
	 * @since x.x.x
	 */
	public function test_requires_the_workspace_capability(): void {
		$ability = $this->ability();

		wp_set_current_user( self::$user_ids['administrator'] );
		$this->assertTrue( $ability->check_permissions( array() ) );

		wp_set_current_user( self::$user_ids['editor'] );
		$this->assertNotTrue( $ability->check_permissions( array() ) );
	}

	/**
	 * It is withheld from the REST and MCP surfaces, and declares that it writes nothing.
	 *
	 * @since x.x.x
	 */
	public function test_is_hidden_from_rest_and_read_only(): void {
		$meta = $this->ability()->get_meta();

		$this->assertFalse( $meta['show_in_rest'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertFalse( $meta['annotations']['open_world'] );
	}
}
