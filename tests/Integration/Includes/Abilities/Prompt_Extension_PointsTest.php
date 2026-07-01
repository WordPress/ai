<?php
/**
 * Integration tests for the prompt template extension points.
 *
 * Covers the per-ability filter hooks added in the prompt customization feature:
 * - wpai_{slug}_system_instruction
 * - wpai_{slug}_prompt
 * - wpai_{slug}_prompt_builder
 * and the get_ability_slug() helper on Abstract_Ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Title_Generation\Title_Generation;
use WordPress\AI\Abilities\Meta_Description\Meta_Description;

/**
 * Prompt extension points test case.
 *
 * @since x.x.x
 */
class Prompt_Extension_PointsTest extends WP_UnitTestCase {

	/**
	 * Title_Generation ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Title_Generation\Title_Generation
	 */
	private $title;

	/**
	 * Meta_Description ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Meta_Description\Meta_Description
	 */
	private $meta;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->title = new Title_Generation(
			'ai/title-generation',
			array(
				'label'       => 'Title Generation',
				'description' => 'Generates title suggestions from content',
			)
		);

		$this->meta = new Meta_Description(
			'ai/meta-description',
			array(
				'label'       => 'Meta Description',
				'description' => 'Generates a meta description',
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_system_instruction' );
		remove_all_filters( 'wpai_title_generation_system_instruction' );
		remove_all_filters( 'wpai_title_generation_prompt' );
		remove_all_filters( 'wpai_title_generation_prompt_builder' );
		remove_all_filters( 'wpai_meta_description_prompt' );
		parent::tearDown();
	}

	/**
	 * Invokes a protected/private method via reflection.
	 *
	 * @param object $object Object instance.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke( $object, string $method, array $args = array() ) {
		$reflection = new \ReflectionClass( $object );
		$ref_method = $reflection->getMethod( $method );
		$ref_method->setAccessible( true );

		return $ref_method->invokeArgs( $object, $args );
	}

	/**
	 * Slug should be derived from the ability name (strip ai/, hyphens to underscores).
	 *
	 * @since x.x.x
	 */
	public function test_get_ability_slug_derives_from_name(): void {
		$this->assertSame( 'title_generation', $this->invoke( $this->title, 'get_ability_slug' ) );
		$this->assertSame( 'meta_description', $this->invoke( $this->meta, 'get_ability_slug' ) );
	}

	/**
	 * The scoped system-instruction filter should modify the instruction.
	 *
	 * @since x.x.x
	 */
	public function test_scoped_system_instruction_filter_applies(): void {
		add_filter(
			'wpai_title_generation_system_instruction',
			static function ( $instruction ) {
				return $instruction . ' SENTINEL_SCOPED';
			}
		);

		$this->assertStringContainsString( 'SENTINEL_SCOPED', $this->title->get_system_instruction() );
	}

	/**
	 * The scoped system-instruction filter should run after the global one.
	 *
	 * @since x.x.x
	 */
	public function test_scoped_system_instruction_runs_after_global(): void {
		$order = array();

		add_filter(
			'wpai_system_instruction',
			static function ( $instruction ) use ( &$order ) {
				$order[] = 'global';
				return $instruction;
			}
		);
		add_filter(
			'wpai_title_generation_system_instruction',
			static function ( $instruction ) use ( &$order ) {
				$order[] = 'scoped';
				return $instruction;
			}
		);

		$this->title->get_system_instruction();

		$this->assertSame( array( 'global', 'scoped' ), $order );
	}

	/**
	 * The scoped system-instruction filter should NOT affect a different ability.
	 *
	 * @since x.x.x
	 */
	public function test_scoped_system_instruction_is_isolated(): void {
		add_filter(
			'wpai_title_generation_system_instruction',
			static function ( $instruction ) {
				return $instruction . ' TITLE_ONLY';
			}
		);

		$this->assertStringNotContainsString( 'TITLE_ONLY', $this->meta->get_system_instruction() );
	}

	/**
	 * The user-prompt filter should modify the assembled prompt before it is sent.
	 *
	 * @since x.x.x
	 */
	public function test_prompt_filter_modifies_prompt(): void {
		$captured = null;

		add_filter(
			'wpai_title_generation_prompt',
			static function ( $prompt ) use ( &$captured ) {
				$captured = $prompt . ' SENTINEL_PROMPT';
				return $captured;
			}
		);

		try {
			$this->invoke( $this->title, 'generate_title', array( 'Some post content.', '' ) );
		} catch ( \Throwable $e ) {
			// Only the prompt assembly matters here, not provider availability.
		}

		$this->assertNotNull( $captured, 'Prompt filter should have been called.' );
		$this->assertStringContainsString( '<content>Some post content.</content>', (string) $captured );
		$this->assertStringContainsString( 'SENTINEL_PROMPT', (string) $captured );
	}

	/**
	 * The prompt-builder filter should fire and receive the builder.
	 *
	 * @since x.x.x
	 */
	public function test_prompt_builder_filter_fires(): void {
		$called  = false;
		$builder = null;

		add_filter(
			'wpai_title_generation_prompt_builder',
			static function ( $prompt_builder ) use ( &$called, &$builder ) {
				$called  = true;
				$builder = $prompt_builder;
				return $prompt_builder;
			}
		);

		try {
			$this->invoke( $this->title, 'get_prompt_builder', array( '<content>Hi</content>' ) );
		} catch ( \Throwable $e ) {
			// Builder may fail support checks without a provider; the filter still fires first.
		}

		$this->assertTrue( $called, 'Prompt builder filter should have been called.' );
		$this->assertInstanceOf( \WP_AI_Client_Prompt_Builder::class, $builder );
	}

	/**
	 * Backward compatibility: the pre-existing meta description prompt filter
	 * should still receive its richer ($prompt, $content, $title) signature.
	 *
	 * @since x.x.x
	 */
	public function test_existing_meta_description_prompt_filter_signature_preserved(): void {
		$received_args = array();

		add_filter(
			'wpai_meta_description_prompt',
			static function ( $prompt, $content = null, $title = null ) use ( &$received_args ) {
				$received_args = array( $prompt, $content, $title );
				return $prompt;
			},
			10,
			3
		);

		try {
			$this->invoke( $this->meta, 'generate_description', array( 'Body content.', 'A Title', '' ) );
		} catch ( \Throwable $e ) {
			// Only the filter invocation matters here.
		}

		$this->assertCount( 3, $received_args );
		$this->assertStringContainsString( '<content>Body content.</content>', (string) $received_args[0] );
		$this->assertSame( 'Body content.', $received_args[1] );
		$this->assertSame( 'A Title', $received_args[2] );
	}
}
