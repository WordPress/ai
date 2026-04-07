<?php
/**
 * Integration tests for Content Guidelines support in Abstract_Ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abstracts
 */

namespace WordPress\AI\Tests\Integration\Includes\Abstracts;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Services\Content_Guidelines;

/**
 * Test ability that does NOT opt into guidelines (default behavior).
 *
 * @since x.x.x
 */
class Test_Ability_No_Guidelines extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute_callback( $input ) {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function permission_callback( $input ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return array();
	}
}

/**
 * Test ability that opts into guidelines.
 *
 * @since x.x.x
 */
class Test_Ability_With_Guidelines extends Test_Ability_No_Guidelines {

	/**
	 * {@inheritDoc}
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'copy' );
	}

	/**
	 * Exposes get_content_guidelines_for_prompt() for testing.
	 *
	 * @param string|null $block_name Optional block name.
	 * @return string Formatted guidelines.
	 */
	public function public_get_content_guidelines_for_prompt( ?string $block_name = null ): string {
		return $this->get_content_guidelines_for_prompt( $block_name );
	}
}

/**
 * Abstract_Ability guidelines integration test case.
 *
 * @since x.x.x
 */
class Abstract_Ability_Guidelines_Test extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		Content_Guidelines::reset_cache();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		Content_Guidelines::reset_cache();
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_all_filters( 'wpai_use_content_guidelines' );
		parent::tearDown();
	}

	/**
	 * Tests that the default guideline_categories() returns an empty array.
	 *
	 * @since x.x.x
	 */
	public function test_default_guideline_categories_is_empty(): void {
		$ability = new Test_Ability_No_Guidelines(
			'ai/test-no-guidelines',
			array(
				'label'       => 'Test No Guidelines',
				'description' => 'Test ability without guidelines.',
			)
		);

		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'guideline_categories' );
		$method->setAccessible( true );

		$this->assertSame( array(), $method->invoke( $ability ) );
	}

	/**
	 * Tests that get_content_guidelines_for_prompt() returns empty when no categories are declared.
	 *
	 * @since x.x.x
	 */
	public function test_get_content_guidelines_for_prompt_returns_empty_when_no_categories(): void {
		$ability = new Test_Ability_No_Guidelines(
			'ai/test-no-guidelines',
			array(
				'label'       => 'Test No Guidelines',
				'description' => 'Test ability without guidelines.',
			)
		);

		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'get_content_guidelines_for_prompt' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( $ability ) );
	}

	/**
	 * Tests that get_system_instruction() does NOT append the guidelines paragraph
	 * when no categories are declared.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_does_not_append_when_no_categories(): void {
		$ability = new Test_Ability_No_Guidelines(
			'ai/test-no-guidelines',
			array(
				'label'       => 'Test No Guidelines',
				'description' => 'Test ability without guidelines.',
			)
		);

		$instruction = $ability->get_system_instruction();

		$this->assertStringNotContainsString(
			'content-guidelines',
			$instruction,
			'Should not contain guidelines paragraph when no categories declared'
		);
	}

	/**
	 * Tests that get_system_instruction() appends the guidelines paragraph
	 * when categories are declared and a system instruction file exists.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_appends_guidelines_paragraph(): void {
		$ability = new Test_Ability_With_Guidelines(
			'ai/test-with-guidelines',
			array(
				'label'       => 'Test With Guidelines',
				'description' => 'Test ability with guidelines.',
			)
		);

		// Create a temporary system instruction file.
		$reflection  = new \ReflectionClass( $ability );
		$file_name   = $reflection->getFileName();
		$feature_dir = dirname( $file_name );
		$test_file   = trailingslashit( $feature_dir ) . 'system-instruction.php';

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		file_put_contents( $test_file, "<?php\nreturn 'You are a test assistant.';" );

		try {
			$instruction = $ability->get_system_instruction();

			$this->assertStringContainsString( 'You are a test assistant.', $instruction );
			$this->assertStringContainsString( 'content-guidelines', $instruction );
			$this->assertStringContainsString( 'Do not fabricate content to satisfy guidelines.', $instruction );
		} finally {
			if ( file_exists( $test_file ) ) {
				wp_delete_file( $test_file );
			}
		}
	}

	/**
	 * Tests that get_system_instruction() does NOT append the guidelines paragraph
	 * when the base instruction is empty (no system instruction file).
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_does_not_append_when_base_empty(): void {
		$ability = new Test_Ability_With_Guidelines(
			'ai/test-with-guidelines',
			array(
				'label'       => 'Test With Guidelines',
				'description' => 'Test ability with guidelines.',
			)
		);

		// No system instruction file exists for this test ability.
		$instruction = $ability->get_system_instruction();

		$this->assertSame( '', $instruction, 'Should return empty when no base instruction exists' );
	}

	/**
	 * Tests that get_content_guidelines_for_prompt() returns formatted guidelines
	 * when categories are declared and guidelines exist.
	 *
	 * @since 0.7.0
	 */
	public function test_get_content_guidelines_for_prompt_delegates_to_helper(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post(
			array(
				'site' => 'Professional tone.',
				'copy' => 'Keep it short.',
			)
		);

		$ability = new Test_Ability_With_Guidelines(
			'ai/test-with-guidelines',
			array(
				'label'       => 'Test With Guidelines',
				'description' => 'Test ability with guidelines.',
			)
		);

		$result = $ability->public_get_content_guidelines_for_prompt();

		$this->assertStringContainsString( '<content-guidelines>', $result );
		$this->assertStringContainsString( '<site-context>Professional tone.</site-context>', $result );
		$this->assertStringContainsString( '<copy-guidelines>Keep it short.</copy-guidelines>', $result );
	}

	/**
	 * Tests that block name is passed through for block-specific guidelines.
	 *
	 * @since 0.7.0
	 */
	public function test_block_name_passthrough_for_block_specific_guidelines(): void {
		$this->register_guidelines_cpt();
		$post_id = $this->create_guidelines_post( array( 'site' => 'Professional tone.' ) );
		update_post_meta( $post_id, '_content_guideline_block_core_paragraph', 'Keep paragraphs concise.' );

		$ability = new Test_Ability_With_Guidelines(
			'ai/test-with-guidelines',
			array(
				'label'       => 'Test With Guidelines',
				'description' => 'Test ability with guidelines.',
			)
		);

		$result = $ability->public_get_content_guidelines_for_prompt( 'core/paragraph' );

		$this->assertStringContainsString(
			'<block-guidelines>Keep paragraphs concise.</block-guidelines>',
			$result
		);
	}

	/**
	 * Registers the wp_content_guideline CPT for testing.
	 *
	 * @return void
	 */
	private function register_guidelines_cpt(): void {
		if ( post_type_exists( 'wp_content_guideline' ) ) {
			return;
		}

		// phpcs:disable WordPress.NamingConventions.ValidPostTypeSlug.ReservedPrefix
		register_post_type(
			'wp_content_guideline',
			array( 'public' => false )
		);
		// phpcs:enable WordPress.NamingConventions.ValidPostTypeSlug.ReservedPrefix
	}

	/**
	 * Creates a guidelines post with the given category meta values.
	 *
	 * @param array<string, string> $categories Keyed array of category => guideline text.
	 * @return int The created post ID.
	 */
	private function create_guidelines_post( array $categories ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_content_guideline',
				'post_status' => 'publish',
				'post_title'  => 'Content Guidelines',
			)
		);

		$meta_key_map = array(
			'copy'       => '_content_guideline_copy',
			'images'     => '_content_guideline_images',
			'site'       => '_content_guideline_site',
			'additional' => '_content_guideline_additional',
		);

		foreach ( $categories as $category => $value ) {
			if ( ! isset( $meta_key_map[ $category ] ) ) {
				continue;
			}

			update_post_meta( $post_id, $meta_key_map[ $category ], $value );
		}

		Content_Guidelines::reset_cache();

		return $post_id;
	}
}
