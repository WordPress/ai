<?php
/**
 * Integration tests for the AI Workspace question ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\AI_Workspace;

use WP_Ability;
use WP_UnitTestCase;
use WordPress\AI\Abilities\AI_Workspace\Ask_User;
use WordPress\AI\Experiments\AI_Workspace\Turn_Context;
use WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures\Workspace_Tools;

/**
 * Ask_User test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Abilities\AI_Workspace\Ask_User
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
			Workspace_Tools::register( new Ask_User() );
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
	 * Surrounding spaces are trimmed, so a choice is stored as the text a click sends.
	 *
	 * @since x.x.x
	 */
	public function test_trims_the_question_and_choices(): void {
		$result = $this->ask(
			array(
				'question' => '  Go ahead?  ',
				'choices'  => array( ' Yes ', 'No  ' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Go ahead?', $result['question'] );
		$this->assertSame( array( 'Yes', 'No' ), $result['choices'] );
	}

	/**
	 * Returns inputs of only spaces, which the schema's `pattern` refuses.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: array<string, mixed>}> The invalid inputs.
	 */
	public function data_invalid_inputs(): array {
		return array(
			'blank choice'   => array(
				array(
					'question' => 'Pick one.',
					'choices'  => array( 'Yes', '   ' ),
				),
			),
			'blank question' => array(
				array(
					'question' => '   ',
					'choices'  => array( 'Yes', 'No' ),
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
	 * MCP exposure reads `mcp.public`, not `show_in_rest`, so both are checked.
	 *
	 * @since x.x.x
	 */
	public function test_is_hidden_from_rest_and_mcp_and_read_only(): void {
		$meta = $this->ability()->get_meta();

		$this->assertFalse( $meta['show_in_rest'] );
		$this->assertFalse( $meta['mcp']['public'] ?? null, 'The question ability must not be exposed to MCP.' );
		$this->assertTrue( $meta['annotations']['readonly'] );
	}
}
