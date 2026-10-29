<?php
/**
 * Test case for the Experiments registration class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Abilities_Explorer\Abilities_Explorer;
use WordPress\AI\Experiments\Connector_Approval\Connector_Approval;
use WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation;
use WordPress\AI\Experiments\Block_Container_Label_Generation\Block_Container_Label_Generation;
use WordPress\AI\Experiments\Suggest_Reply\Suggest_Reply;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Experiments\MCP_Adapter\MCP_Adapter;
use WordPress\AI\Experiments\Type_Ahead\Type_Ahead;

/**
 * Tests for the Experiments class.
 *
 * @since 0.6.0
 */
class ExperimentsTest extends WP_UnitTestCase {
	/**
	 * Test that init hooks into the correct filter.
	 *
	 * @since 0.6.0
	 */
	public function test_init_hooks_filter() {
		$experiments = new Experiments();
		$experiments->init();

		$this->assertNotFalse(
			has_filter( 'wpai_default_feature_classes', array( Experiments::class, 'register_default_experiment_classes' ) ),
			'Should hook into wpai_default_feature_classes'
		);

		$results = apply_filters( 'wpai_default_feature_classes', array() );

		// Test a random experiment to ensure it's registered as a default experiment.
		$this->assertContains( Abilities_Explorer::class, $results, 'Abilities_Explorer should be registered as a default experiment.' );
		$this->assertContains( Type_Ahead::class, $results, 'Type_Ahead should be registered as a default experiment.' );
		$this->assertContains( Connector_Approval::class, $results, 'Connector_Approval should be registered as a default experiment.' );
		$this->assertContains( Comment_Moderation::class, $results, 'Comment_Moderation should be registered as a default experiment.' );
		$this->assertContains( Suggest_Reply::class, $results, 'Suggest_Reply should be registered as a default experiment.' );
		$this->assertContains( Block_Container_Label_Generation::class, $results, 'Block_Container_Label_Generation should be registered as a default experiment.' );
		$this->assertContains( MCP_Adapter::class, $results, 'MCP_Adapter should be registered as a default experiment.' );
	}

	/**
	 * Test that each experiment class is listed only once.
	 *
	 * @since 1.5.0
	 */
	public function test_experiment_classes_are_unique() {
		$classes = ( new \ReflectionClass( Experiments::class ) )->getConstant( 'EXPERIMENT_CLASSES' );

		$this->assertIsArray( $classes );
		$this->assertSame(
			array(),
			array_keys( array_filter( array_count_values( $classes ), static fn( int $count ): bool => $count > 1 ) ),
			'Each experiment class should be listed once in EXPERIMENT_CLASSES.'
		);
	}
}
