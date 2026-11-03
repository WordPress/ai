<?php
/**
 * Integration tests for the Command_Palette_AI_Search Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Command_Palette_AI_Search\Command_Palette_AI_Search;

/**
 * Testable subclass of Command_Palette_AI_Search to mock AI prompt generation.
 */
class Testable_Command_Palette_AI_Search extends Command_Palette_AI_Search {
	/**
	 * Mock response to return from choose_commands().
	 *
	 * @var list<string>|\WP_Error
	 */
	public $mock_response = array();

	/**
	 * Prompts passed to choose_commands().
	 *
	 * @var list<string>
	 */
	public $prompts = array();

	/**
	 * {@inheritDoc}
	 */
	protected function choose_commands( string $prompt, int $count ) {
		$this->prompts[] = $prompt;

		return $this->mock_response;
	}
}

/**
 * Command_Palette_AI_Search Ability test case.
 */
class Command_Palette_AI_SearchTest extends WP_UnitTestCase {

	/**
	 * Testable Command_Palette_AI_Search ability instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Testable_Command_Palette_AI_Search
	 */
	private $ability;

	/**
	 * Commands sent by the command palette.
	 *
	 * @var list<array<string, mixed>>
	 */
	private $commands = array(
		array(
			'name'     => 'options-general.php',
			'label'    => 'Settings > General',
			'keywords' => array( 'site icon', 'favicon' ),
		),
		array(
			'name'  => 'options-reading.php',
			'label' => 'Settings > Reading',
		),
		array(
			'name'  => 'plugin-install.php',
			'label' => 'Plugins > Add Plugin',
		),
		array(
			'name'  => 'themes.php',
			'label' => 'Appearance > Themes',
		),
	);

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->ability = new Testable_Command_Palette_AI_Search(
			'ai/command-palette-ai-search',
			array(
				'label'       => 'Command Palette AI Search',
				'description' => 'Finds the right admin screen for a search.',
			)
		);
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wpai_command_palette_ai_search_prompt_builder' );
		parent::tearDown();
	}

	/**
	 * Returns the ability without the mocked choose_commands().
	 *
	 * @return \WordPress\AI\Abilities\Command_Palette_AI_Search\Command_Palette_AI_Search The ability.
	 */
	private function real_ability(): Command_Palette_AI_Search {
		return new Command_Palette_AI_Search(
			'ai/command-palette-ai-search',
			array(
				'label'       => 'Command Palette AI Search',
				'description' => 'Finds the right admin screen for a search.',
			)
		);
	}

	/**
	 * Replaces the prompt builder with a mock.
	 *
	 * @param string|\WP_Error $response  What generate_text() returns.
	 * @param list<bool>       $supported What each is_supported_for_text_generation() call returns.
	 */
	private function mock_prompt_builder( $response, array $supported = array( true, true ) ): void {
		$mock_builder = $this->getMockBuilder( \WP_AI_Client_Prompt_Builder::class )
			->disableOriginalConstructor()
			->addMethods( array( 'as_json_response', 'is_supported_for_text_generation', 'generate_text' ) )
			->getMock();

		$mock_builder->method( 'as_json_response' )
			->willReturnSelf();

		$mock_builder->method( 'is_supported_for_text_generation' )
			->willReturnOnConsecutiveCalls( ...$supported );

		$mock_builder->method( 'generate_text' )
			->willReturn( $response );

		add_filter(
			'wpai_command_palette_ai_search_prompt_builder',
			static function () use ( $mock_builder ) {
				return $mock_builder;
			}
		);
	}

	/**
	 * Invokes choose_commands() on the real ability.
	 *
	 * @return mixed The method's return value.
	 */
	private function choose_commands() {
		$ability = $this->real_ability();
		$method  = ( new \ReflectionClass( $ability ) )->getMethod( 'choose_commands' );
		$method->setAccessible( true );

		return $method->invoke( $ability, '<search>hide my site from google</search>', 4 );
	}

	/**
	 * Invokes a protected method of the ability.
	 *
	 * @param string $name The method name.
	 * @param mixed  ...$args The method arguments.
	 * @return mixed The method's return value.
	 */
	private function invoke( string $name, ...$args ) {
		$method = ( new \ReflectionClass( $this->ability ) )->getMethod( $name );
		$method->setAccessible( true );

		return $method->invoke( $this->ability, ...$args );
	}

	/**
	 * Runs execute_callback() with the test commands.
	 *
	 * @param string $query The search.
	 * @return mixed The callback's return value.
	 */
	private function search( string $query ) {
		return $this->invoke(
			'execute_callback',
			array(
				'query'    => $query,
				'commands' => $this->commands,
			)
		);
	}

	/**
	 * Test that input_schema() requires the query and the commands.
	 */
	public function test_input_schema_returns_expected_structure() {
		$schema = $this->invoke( 'input_schema' );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array( 'query', 'commands' ), $schema['required'] );
		$this->assertSame( 'string', $schema['properties']['query']['type'] );
		$this->assertSame( 'array', $schema['properties']['commands']['type'] );
		$this->assertSame( array( 'name', 'label' ), $schema['properties']['commands']['items']['required'] );
	}

	/**
	 * Test that output_schema() returns a list of command names.
	 */
	public function test_output_schema_returns_expected_structure() {
		$schema = $this->invoke( 'output_schema' );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( 'array', $schema['properties']['commands']['type'] );
		$this->assertSame( 'string', $schema['properties']['commands']['items']['type'] );
	}

	/**
	 * Test that meta() returns show_in_rest set to true.
	 */
	public function test_meta_returns_expected_structure() {
		$meta = $this->invoke( 'meta' );

		$this->assertTrue( $meta['show_in_rest'] ?? false );
	}

	/**
	 * Test that permission_callback() returns an error for a logged out user.
	 */
	public function test_permission_callback_for_logged_out_user() {
		$result = $this->invoke( 'permission_callback', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() returns an error for a user without edit_posts.
	 */
	public function test_permission_callback_for_subscriber() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->invoke( 'permission_callback', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() allows a user with edit_posts.
	 */
	public function test_permission_callback_for_contributor() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertTrue( $this->invoke( 'permission_callback', array() ) );
	}

	/**
	 * Test that execute_callback() returns an error without a search or commands.
	 */
	public function test_execute_callback_without_query_or_commands() {
		$result = $this->invoke( 'execute_callback', array( 'commands' => $this->commands ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_data', $result->get_error_code() );

		$result = $this->invoke( 'execute_callback', array( 'query' => 'hide my site from google' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_data', $result->get_error_code() );
	}

	/**
	 * Test that the prompt lists each command with its ID, label and keywords.
	 */
	public function test_execute_callback_builds_prompt_from_commands() {
		$this->search( 'how do I change my favicon' );

		$prompt = $this->ability->prompts[0];

		$this->assertStringContainsString( '<search>how do I change my favicon</search>', $prompt );
		$this->assertStringContainsString( 'c0: Settings > General (site icon, favicon)', $prompt );
		$this->assertStringContainsString( 'c1: Settings > Reading', $prompt );
		$this->assertStringContainsString( 'c3: Appearance > Themes', $prompt );
		$this->assertStringContainsString( '<theme>', $prompt );
	}

	/**
	 * Test that execute_callback() maps the model's IDs back to command names.
	 */
	public function test_execute_callback_returns_command_names() {
		$this->ability->mock_response = array( 'c1', 'c0' );

		$result = $this->search( 'hide my site from google' );

		$this->assertSame( array( 'commands' => array( 'options-reading.php', 'options-general.php' ) ), $result );
	}

	/**
	 * Test that execute_callback() drops unknown and repeated IDs and caps the results.
	 */
	public function test_execute_callback_drops_invalid_ids_and_caps_results() {
		$this->ability->mock_response = array( 'foo', 'c99', 'c2', 'c2', '0', 'c3', 'c0', 'c1' );

		$result = $this->search( 'where do I install a new plugin' );

		$this->assertSame( array( 'commands' => array( 'plugin-install.php', 'themes.php', 'options-general.php' ) ), $result );
	}

	/**
	 * Test that execute_callback() returns an empty list when no command fits.
	 */
	public function test_execute_callback_returns_empty_list_when_none_fits() {
		$result = $this->search( 'how do I bake a cake' );

		$this->assertSame( array( 'commands' => array() ), $result );
	}

	/**
	 * Test that execute_callback() returns the error when the model's answer can't be used.
	 */
	public function test_execute_callback_returns_model_errors() {
		$this->ability->mock_response = new WP_Error( 'invalid_response', 'Bad response.' );

		$result = $this->search( 'stop spam comments please' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_response', $result->get_error_code() );
	}

	/**
	 * Test that the response schema only allows the IDs in the prompt.
	 */
	public function test_commands_schema_limits_ids() {
		$schema = $this->invoke( 'commands_schema', 3 );

		$this->assertSame( array( 'c0', 'c1', 'c2' ), $schema['properties']['commands']['items']['enum'] );
		$this->assertSame( 3, $schema['properties']['commands']['maxItems'] );
		$this->assertSame( array( 'commands' ), $schema['required'] );
	}

	/**
	 * Test that the system instruction asks for at most three IDs.
	 */
	public function test_get_system_instruction_includes_max_results() {
		$system_instruction = $this->real_ability()->get_system_instruction( null, array( 'max_results' => 3 ) );

		$this->assertStringContainsString( 'Return at most 3 IDs', $system_instruction );
	}

	/**
	 * Test that choose_commands() returns an error without a provider that supports text generation.
	 */
	public function test_choose_commands_returns_error_without_provider() {
		$result = $this->choose_commands();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_model', $result->get_error_code() );
	}

	/**
	 * Test that choose_commands() decodes the command IDs from the model's response.
	 */
	public function test_choose_commands_returns_ids() {
		$this->mock_prompt_builder( '{"commands":["c1","c0"]}' );

		$this->assertSame( array( 'c1', 'c0' ), $this->choose_commands() );
	}

	/**
	 * Test that choose_commands() falls back to a plain prompt when structured output is not supported.
	 */
	public function test_choose_commands_falls_back_without_structured_output() {
		$this->mock_prompt_builder( "Sure!\n```json\n{\"commands\":[\"c2\"]}\n```", array( false, true ) );

		$this->assertSame( array( 'c2' ), $this->choose_commands() );
	}

	/**
	 * Test that choose_commands() returns the error from the model.
	 */
	public function test_choose_commands_returns_generation_errors() {
		$this->mock_prompt_builder( new WP_Error( 'provider_error', 'Provider failed.' ) );

		$result = $this->choose_commands();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'provider_error', $result->get_error_code() );
	}

	/**
	 * Test that choose_commands() returns an error when the response is not a list of commands.
	 *
	 * @dataProvider data_unparseable_responses
	 *
	 * @param string $response The raw model response.
	 */
	public function test_choose_commands_returns_error_for_unparseable_response( string $response ) {
		$this->mock_prompt_builder( $response );

		$result = $this->choose_commands();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_response', $result->get_error_code() );
	}

	/**
	 * Data provider of model responses that cannot be parsed as command IDs.
	 *
	 * @return array<string, array{string}> The responses to test.
	 */
	public function data_unparseable_responses(): array {
		return array(
			'plain text'   => array( 'c1, c2' ),
			'empty string' => array( '' ),
			'wrong shape'  => array( '{"ids":["c1"]}' ),
			'not a list'   => array( '{"commands":"c1"}' ),
		);
	}
}
