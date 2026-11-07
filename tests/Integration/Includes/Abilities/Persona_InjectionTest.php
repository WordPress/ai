<?php
/**
 * Integration tests for persona injection into ability system instructions.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis;
use WordPress\AI\Abilities\Title_Generation\Title_Generation;
use WordPress\AI\Services\Personas;

/**
 * Persona injection test case.
 *
 * @since x.x.x
 */
class Persona_InjectionTest extends WP_UnitTestCase {

	/**
	 * Title_Generation ability instance, which opts into personas.
	 *
	 * @var \WordPress\AI\Abilities\Title_Generation\Title_Generation
	 */
	private $title;

	/**
	 * Comment_Analysis ability instance, which does not opt into personas.
	 *
	 * @var \WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis
	 */
	private $comment_analysis;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		Personas::reset_cache();

		// The experiment registers this post type; the service treats it as the
		// signal that personas are enabled.
		if ( ! post_type_exists( Personas::POST_TYPE ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral
			register_post_type( Personas::POST_TYPE, array( 'public' => false ) );
		}

		$this->title = new Title_Generation(
			'ai/title-generation',
			array(
				'label'       => 'Title Generation',
				'description' => 'Generates title suggestions from content',
			)
		);

		$this->comment_analysis = new Comment_Analysis(
			'ai/comment-analysis',
			array(
				'label'       => 'Comment Analysis',
				'description' => 'Analyzes a comment',
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		Personas::reset_cache();
		delete_option( Personas::DEFAULT_OPTION );

		if ( post_type_exists( Personas::POST_TYPE ) ) {
			unregister_post_type( Personas::POST_TYPE );
		}

		parent::tearDown();
	}

	/**
	 * An opted-in ability should carry the active persona.
	 *
	 * @since x.x.x
	 */
	public function test_opted_in_ability_receives_the_persona(): void {
		update_option( Personas::DEFAULT_OPTION, 'technical' );

		$instruction = $this->title->get_system_instruction();

		$this->assertStringContainsString( '<persona>', $instruction );
		$this->assertStringContainsString( 'Technical expert', $instruction );
	}

	/**
	 * An ability that has not opted in should be untouched.
	 *
	 * @since x.x.x
	 */
	public function test_ability_without_opt_in_is_unchanged(): void {
		update_option( Personas::DEFAULT_OPTION, 'technical' );

		$this->assertStringNotContainsString( '<persona>', $this->comment_analysis->get_system_instruction() );
	}

	/**
	 * With no persona selected, the instruction should be byte-for-byte the same.
	 *
	 * @since x.x.x
	 */
	public function test_no_persona_selected_leaves_instruction_unchanged(): void {
		$with_personas_available = $this->title->get_system_instruction();

		unregister_post_type( Personas::POST_TYPE );
		Personas::reset_cache();

		$this->assertSame(
			$this->title->get_system_instruction(),
			$with_personas_available,
			'An unselected persona should not alter the system instruction'
		);
	}

	/**
	 * The reserved post_id key should resolve the per-post override.
	 *
	 * @since x.x.x
	 */
	public function test_reserved_post_id_resolves_the_per_post_persona(): void {
		update_option( Personas::DEFAULT_OPTION, 'technical' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Personas::META_KEY, 'journalistic' );

		$instruction = $this->title->get_system_instruction( null, array( 'post_id' => $post_id ) );

		$this->assertStringContainsString( 'Journalistic', $instruction );
		$this->assertStringNotContainsString( 'Technical expert', $instruction );
	}
}
