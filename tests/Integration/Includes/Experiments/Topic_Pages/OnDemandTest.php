<?php
/**
 * Generation happens only after an editor requests it.
 *
 * @package WordPress\AI\Tests
 */
namespace WordPress\AI\Tests\Integration\Includes\Experiments\Topic_Pages;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Topic_Pages\Store;
use WordPress\AI\Experiments\Topic_Pages\Topic_Pages;

class OnDemandTest extends WP_UnitTestCase {
	public function test_enabling_and_source_changes_do_not_schedule_generation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'wpai_feature_topic-pages_enabled', true );
		$feature = new Topic_Pages();
		$feature->register();
		try {
			$id = self::factory()->category->create();
			self::factory()->post->create_many(
				5,
				array(
					'post_status'   => 'publish',
					'post_category' => array( $id ),
				)
			);
			$this->assertSame( '', get_term_meta( $id, Store::META_KEY, true ) );
			$this->assertFalse( has_action( 'wpai_topic_pages_scan' ) );
			$this->assertFalse( has_action( 'wpai_topic_pages_generate' ) );
			$this->assertFalse( wp_next_scheduled( 'wpai_topic_pages_scan' ) );
			$this->assertFalse( wp_next_scheduled( 'wpai_topic_pages_generate', array( $id ) ) );
			$this->assertFalse( has_action( 'admin_bar_menu', array( $feature, 'admin_bar' ) ) );
			$this->assertFalse( has_filter( 'category_row_actions', array( $feature, 'row_actions' ) ) );
		} finally {
			foreach ( array( 'summary', 'overview', 'timeline' ) as $section ) {
				unregister_block_type( 'ai/topic-' . $section );
			}
		}
	}
}
