<?php
/**
 * Integration tests for the command palette loader the AI Workspace adds.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Experiments\AI_Workspace;

use WP_Scripts;
use WP_Styles;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\AI_Workspace;
use WordPress\AI\Experiments\AI_Workspace\Admin_Page;

/**
 * Command palette loader test case.
 *
 * @since x.x.x
 */
class Command_PaletteTest extends WP_UnitTestCase {

	/**
	 * Script handle of the palette loader.
	 *
	 * @var string
	 */
	private const HANDLE = 'ai_command_palette_search';

	/**
	 * Scripts registry from before the test.
	 *
	 * @var \WP_Scripts|null
	 */
	private $old_wp_scripts;

	/**
	 * Styles registry from before the test.
	 *
	 * @var \WP_Styles|null
	 */
	private $old_wp_styles;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->old_wp_scripts  = $GLOBALS['wp_scripts'] ?? null;
		$this->old_wp_styles   = $GLOBALS['wp_styles'] ?? null;
		$GLOBALS['wp_scripts'] = new WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh registries, since other tests leave scripts that depend on wp-commands enqueued.
		$GLOBALS['wp_styles']  = new WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh registries, since other tests leave scripts that depend on wp-commands enqueued.
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		$GLOBALS['wp_scripts'] = $this->old_wp_scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the registries replaced in setUp().
		$GLOBALS['wp_styles']  = $this->old_wp_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the registries replaced in setUp().
		parent::tearDown();
	}

	/**
	 * Logs in a new user with the given role.
	 *
	 * @since x.x.x
	 *
	 * @param string $role The role.
	 */
	private function log_in_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * A subscriber gets no AI suggestions, so the loader is not loaded for them.
	 *
	 * @since x.x.x
	 */
	public function test_loader_is_not_enqueued_for_a_subscriber() {
		$this->log_in_as( 'subscriber' );
		wp_enqueue_script( 'wp-commands' );

		( new AI_Workspace() )->enqueue_command_palette_assets();

		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ), 'Should not enqueue the script for a subscriber.' );
		$this->assertFalse( wp_style_is( self::HANDLE, 'enqueued' ), 'Should not enqueue the style for a subscriber.' );
	}

	/**
	 * A user who can open the workspace is offered the Ask AI row, with where it leads.
	 *
	 * @since x.x.x
	 */
	public function test_loader_offers_ask_ai_to_a_workspace_user() {
		$this->log_in_as( 'administrator' );
		wp_enqueue_script( 'wp-commands' );

		( new AI_Workspace() )->enqueue_command_palette_assets();

		$this->assertSame(
			array(
				'askAi' => array(
					'workspaceUrl'    => Admin_Page::url(),
					'promptArg'       => Admin_Page::PROMPT_QUERY_ARG,
					'promptMaxLength' => 500,
				),
			),
			$this->read_loader_data()
		);
	}

	/**
	 * An editor gets AI suggestions but no Ask AI row, and is not told where the workspace is.
	 *
	 * @since x.x.x
	 */
	public function test_loader_does_not_offer_ask_ai_to_an_editor() {
		$this->log_in_as( 'editor' );
		wp_enqueue_script( 'wp-commands' );

		( new AI_Workspace() )->enqueue_command_palette_assets();

		$this->assertSame( array( 'askAi' => null ), $this->read_loader_data() );
	}

	/**
	 * Decodes the loader's localized data.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The data.
	 */
	private function read_loader_data(): array {
		$script = (string) wp_scripts()->get_data( self::HANDLE, 'data' );
		$start  = strpos( $script, '{' );
		$end    = strrpos( $script, '}' );

		$this->assertNotFalse( $start, 'The loader must be localized.' );
		$this->assertNotFalse( $end );

		$decoded = json_decode( substr( $script, (int) $start, (int) $end - (int) $start + 1 ), true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}
}
