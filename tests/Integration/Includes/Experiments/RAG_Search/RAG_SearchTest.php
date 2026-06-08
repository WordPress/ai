<?php
/**
 * Integration tests for the RAG Search experiment.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\RAG_Search
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\RAG_Search;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\RAG_Search\RAG_Search;

/**
 * RAG Search experiment test case.
 *
 * @since 1.1.0
 */
class RAG_SearchTest extends WP_UnitTestCase {
	/**
	 * Cleans up options.
	 */
	public function tearDown(): void {
		delete_option( 'wpai_feature_rag-search_field_augment_search' );

		parent::tearDown();
	}

	/**
	 * Tests feature metadata.
	 *
	 * @since 1.1.0
	 */
	public function test_metadata(): void {
		$experiment = new RAG_Search();

		$this->assertSame( 'rag-search', $experiment::get_id() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertSame( 'embedding_generation', $experiment->get_capability() );
	}

	/**
	 * Tests settings fields.
	 *
	 * @since 1.1.0
	 */
	public function test_get_settings_fields_returns_augment_search_field(): void {
		$experiment = new RAG_Search();
		$fields     = $experiment->get_settings_fields_metadata();

		$this->assertCount( 1, $fields );
		$this->assertSame( 'wpai_feature_rag-search_field_augment_search', $fields[0]['id'] );
		$this->assertSame( 'boolean', $fields[0]['type'] );
		$this->assertFalse( $fields[0]['default'] );
	}

	/**
	 * Tests setting lookup.
	 *
	 * @since 1.1.0
	 */
	public function test_is_search_augmentation_enabled_reads_option(): void {
		$experiment = new RAG_Search();

		$this->assertFalse( $experiment->is_search_augmentation_enabled() );

		update_option( 'wpai_feature_rag-search_field_augment_search', true );

		$this->assertTrue( $experiment->is_search_augmentation_enabled() );
	}
}
