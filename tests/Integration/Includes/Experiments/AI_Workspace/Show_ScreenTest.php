<?php
/**
 * Integration tests for the AI Workspace screen ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_Ability;
use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Show_Screen;
use WordPress\AI\Experiments\AI_Workspace\Turn_Context;
use WordPress\AI\Experiments\AI_Workspace\Turn_Runner;

/**
 * Show_Screen test case.
 *
 * The screen ability writes nothing, so these tests are about what it lets
 * through: a valid screen comes back unchanged, and a screen that is too large,
 * uses a media component or another catalog, or is built wrong is refused.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Show_Screen
 */
class Show_ScreenTest extends WP_UnitTestCase {

	/**
	 * The basic catalog ID.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CATALOG = 'https://a2ui.org/specification/v0_9_1/catalogs/basic/catalog.json';

	/**
	 * Shared administrator ID.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private static $admin_id = 0;

	/**
	 * Creates the shared user.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
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
			( new Show_Screen() )->register();
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

		if ( wp_has_ability( Show_Screen::ABILITY ) ) {
			wp_unregister_ability( Show_Screen::ABILITY );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Builds a small, valid screen.
	 *
	 * @since x.x.x
	 *
	 * @param list<array<string, mixed>> $components Optional. The components. Default a card with a heading.
	 * @return list<array<string, mixed>> The messages.
	 */
	private function screen( array $components = array() ): array {
		if ( array() === $components ) {
			$components = array(
				array(
					'id'        => 'root',
					'component' => 'Card',
					'child'     => 'title',
				),
				array(
					'id'        => 'title',
					'component' => 'Text',
					'variant'   => 'h3',
					'text'      => 'Three drafts this week',
				),
			);
		}

		return array(
			array(
				'version'       => 'v0.9.1',
				'createSurface' => array(
					'surfaceId' => 'main',
					'catalogId' => self::CATALOG,
				),
			),
			array(
				'version'          => 'v0.9.1',
				'updateComponents' => array(
					'surfaceId'  => 'main',
					'components' => $components,
				),
			),
		);
	}

	/**
	 * Runs the ability as an administrator inside a workspace turn.
	 *
	 * @since x.x.x
	 *
	 * @param list<mixed> $messages The messages.
	 * @return mixed The ability result.
	 */
	private function show( array $messages ) {
		wp_set_current_user( self::$admin_id );
		Turn_Context::enter( 'conversation-1', self::$admin_id );

		$ability = wp_get_ability( Show_Screen::ABILITY );

		$this->assertInstanceOf( WP_Ability::class, $ability );

		return $ability->execute( array( 'messages' => $messages ) );
	}

	/**
	 * A valid screen comes back unchanged.
	 *
	 * @since x.x.x
	 */
	public function test_returns_a_valid_screen_unchanged(): void {
		$messages = $this->screen();

		$result = $this->show( $messages );

		$this->assertIsArray( $result );
		$this->assertSame( $messages, $result['messages'] );
	}

	/**
	 * Screens the ability refuses.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: callable(self): list<mixed>}> The invalid screens.
	 */
	public function data_invalid_screens(): array {
		return array(
			'an image'             => array(
				static function ( self $test ): array {
					return $test->screen(
						array(
							array(
								'id'        => 'root',
								'component' => 'Image',
								'url'       => 'https://example.com/pixel.gif',
							),
						)
					);
				},
			),
			'a video'              => array(
				static function ( self $test ): array {
					return $test->screen(
						array(
							array(
								'id'        => 'root',
								'component' => 'Video',
								'url'       => 'https://example.com/clip.mp4',
							),
						)
					);
				},
			),
			'an unknown component' => array(
				static function ( self $test ): array {
					return $test->screen(
						array(
							array(
								'id'        => 'root',
								'component' => 'Iframe',
							),
						)
					);
				},
			),
			'another catalog'      => array(
				static function ( self $test ): array {
					$messages = $test->screen();
					$messages[0]['createSurface']['catalogId'] = 'https://example.com/catalog.json';

					return $messages;
				},
			),
			'no createSurface'     => array(
				static function ( self $test ): array {
					return array_slice( $test->screen(), 1 );
				},
			),
			'two createSurface'    => array(
				static function ( self $test ): array {
					$messages = $test->screen();

					return array( $messages[0], $messages[0], $messages[1] );
				},
			),
			'a deleteSurface'      => array(
				static function ( self $test ): array {
					$messages   = $test->screen();
					$messages[] = array(
						'version'       => 'v0.9.1',
						'deleteSurface' => array( 'surfaceId' => 'main' ),
					);

					return $messages;
				},
			),
			'two message types'    => array(
				static function ( self $test ): array {
					$messages = $test->screen();
					$messages[1]['updateDataModel'] = array( 'surfaceId' => 'main' );

					return $messages;
				},
			),
			'another version'      => array(
				static function ( self $test ): array {
					$messages               = $test->screen();
					$messages[1]['version'] = 'v1.0';

					return $messages;
				},
			),
			'too large'            => array(
				static function ( self $test ): array {
					return $test->screen(
						array(
							array(
								'id'        => 'root',
								'component' => 'Text',
								'text'      => str_repeat( 'x', Show_Screen::MAX_BYTES ),
							),
						)
					);
				},
			),
		);
	}

	/**
	 * An invalid screen is refused, with a message for the model.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_invalid_screens
	 *
	 * @param callable(self): list<mixed> $build Builds the screen.
	 */
	public function test_refuses_an_invalid_screen( callable $build ): void {
		$result = Show_Screen::validate_messages( $build( $this ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_screen', $result->get_error_code() );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	/**
	 * Outside a workspace turn, there is no one to show a screen to.
	 *
	 * @since x.x.x
	 */
	public function test_refuses_outside_a_workspace_turn(): void {
		wp_set_current_user( self::$admin_id );

		$ability = wp_get_ability( Show_Screen::ABILITY );

		$this->assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( array( 'messages' => $this->screen() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'workspace_context_required', $result->get_error_code() );
	}

	/**
	 * The ability stays off the REST surface, so MCP clients are not offered it.
	 *
	 * @since x.x.x
	 */
	public function test_is_hidden_from_rest(): void {
		$ability = wp_get_ability( Show_Screen::ABILITY );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
	}

	/**
	 * Showing a screen ends the turn, like asking a question.
	 *
	 * @since x.x.x
	 */
	public function test_ends_the_turn(): void {
		$this->assertContains( Show_Screen::ABILITY, Turn_Runner::TURN_ENDING_ABILITIES );
	}
}
