<?php
/**
 * Tests the Topic Overview ability's public permission and validation boundaries.
 *
 * @package WordPress\AI\Tests
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Topic_Overview\Topic_Overview;

class Topic_OverviewTest extends WP_UnitTestCase {
	private function ability(): Topic_Overview {
		return new Topic_Overview(
			'ai/topic-overview',
			array(
				'label'       => 'Topic Overview',
				'description' => 'Generates sourced topic sections.',
			)
		);
	}

	public function test_ability_uses_the_plugin_category_and_is_exposed_in_rest(): void {
		$ability = $this->ability();
		$this->assertSame( WPAI_DEFAULT_ABILITY_CATEGORY, $ability->get_category() );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
	}

	public function test_author_cannot_generate_topic_content(): void {
		$term_id = self::factory()->category->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertWPError( $this->ability()->execute( array( 'term_id' => $term_id ) ) );
	}

	public function test_editor_can_manage_a_category(): void {
		$term_id = self::factory()->category->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( $this->ability()->check_permissions( array( 'term_id' => $term_id ) ) );
	}

	public function test_execute_rejects_an_invalid_category(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( $this->ability()->execute( array( 'term_id' => 0 ) ) );
	}

	public function test_execute_rejects_an_ineligible_category_without_requesting_ai(): void {
		$term_id = self::factory()->category->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = $this->ability()->execute( array( 'term_id' => $term_id ) );
		$this->assertWPError( $result );
		$this->assertSame( 'topic_below_minimum', $result->get_error_code() );
	}
}
