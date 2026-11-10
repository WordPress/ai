<?php
/**
 * Small Topic Pages workflow.
 *
 * @package WordPress\AI\Tests
 */
namespace WordPress\AI\Tests\Integration\Includes\Experiments\Topic_Pages;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Topic_Pages\Sources;
use WordPress\AI\Experiments\Topic_Pages\Store;

class SimpleFlowTest extends WP_UnitTestCase {

	private int $term_id;
	private array $posts;

	public function setUp(): void {
		parent::setUp();
		$this->term_id = self::factory()->category->create();
		$this->posts   = array();

		for ( $i = 0; $i < 30; $i++ ) {
			$this->posts[] = self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_category' => array( $this->term_id ),
					'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( '2020-01-01' ) + $i * MONTH_IN_SECONDS ),
					'post_content'  => str_repeat( 'Reported coverage. ', 500 ),
				)
			);
		}
	}

	public function test_history_sample_is_bounded_and_preserves_both_ends(): void {
		$ids = ( new Sources() )->sample( $this->term_id, 10 );
		$this->assertLessThanOrEqual( 10, count( $ids ) );
		$this->assertSame( $this->posts[0], $ids[0] );
		$this->assertSame( end( $this->posts ), end( $ids ) );
		$this->assertCount( count( array_unique( $ids ) ), $ids );
	}

	public function test_summary_uses_only_the_latest_five_posts(): void {
		$records = ( new Sources() )->collect( $this->term_id, 'summary' );
		$this->assertIsArray( $records );
		$this->assertSame( array_slice( $this->posts, -5 ), array_column( $records, 'id' ) );
	}

	public function test_large_overview_uses_bounded_text_instead_of_rejecting_the_category(): void {
		$records = ( new Sources() )->collect( $this->term_id, 'overview' );
		$this->assertIsArray( $records );
		$this->assertLessThanOrEqual( 20, count( $records ) );
		$this->assertLessThanOrEqual( 60000, array_sum( array_map( 'strlen', array_column( $records, 'content' ) ) ) );
	}

	public function test_parent_category_includes_public_child_posts(): void {
		$parent = self::factory()->category->create();
		wp_update_term( $this->term_id, 'category', array( 'parent' => $parent ) );
		$this->assertTrue( ( new Sources() )->eligible( $parent ) );
		$this->assertTrue( ( new Sources() )->is_public_source( $this->posts[0], get_term( $parent ) ) );
	}

	public function test_discard_removes_only_the_selected_private_preview(): void {
		$store   = new Store();
		$version = array(
			'content'    => '<!-- wp:paragraph --><p>Coverage <a href="' . get_permalink( $this->posts[0] ) . '">Source</a></p><!-- /wp:paragraph -->',
			'source_ids' => array( $this->posts[0] ),
		);
		$state   = $store->save_generated(
			$this->term_id,
			array(
				'summary'  => $version,
				'overview' => $version,
			),
			''
		);
		$state   = $store->change( $this->term_id, 'discard', 'summary', $state['revision'] );
		$this->assertIsArray( $state );
		$this->assertNull( $state['sections']['summary']['draft'] );
		$this->assertNotNull( $state['sections']['overview']['draft'] );
		$this->assertSame( '', $store->render( $this->term_id, 'summary' ) );
	}

	private function version( string $text = 'Coverage', string $section = 'summary' ): array {
		return array(
			'content'     => '<!-- wp:paragraph --><p>' . $text . ' <a href="' . get_permalink( $this->posts[29] ) . '">Source</a></p><!-- /wp:paragraph -->',
			'source_ids'  => array( $this->posts[29] ),
			'fingerprint' => hash( 'sha256', (string) wp_json_encode( ( new Sources() )->collect( $this->term_id, $section ) ) ),
		);
	}

	public function test_first_and_later_generations_stay_private_until_saved(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'First version' ) ), '' );
		$this->assertIsArray( $state );
		$this->assertSame( '', $store->render( $this->term_id, 'summary' ) );
		$state = $store->change( $this->term_id, 'save', 'summary', $state['revision'] );
		$this->assertStringContainsString( 'First version', $store->render( $this->term_id, 'summary' ) );
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Replacement' ) ), $state['revision'] );
		$this->assertStringNotContainsString( 'Replacement', $store->render( $this->term_id, 'summary' ) );
		$state = $store->change( $this->term_id, 'save', 'summary', $state['revision'] );
		$this->assertNull( $state['sections']['summary']['draft'] );
		$this->assertStringContainsString( 'Replacement', $store->render( $this->term_id, 'summary' ) );
	}

	public function test_save_without_a_preview_keeps_the_published_text(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Keep published' ) ), '' );
		$state = $store->change( $this->term_id, 'save', 'summary', $state['revision'] );
		$this->assertWPError( $store->change( $this->term_id, 'save', 'summary', $state['revision'] ) );
		$this->assertSame( $state, $store->get( $this->term_id ) );
	}

	public function test_discard_keeps_published_text_and_stale_tabs_cannot_save(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Published text' ) ), '' );
		$state = $store->change( $this->term_id, 'save', 'summary', $state['revision'] );
		$old   = $state['revision'];
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Discard this' ) ), $old );
		$this->assertWPError( $store->change( $this->term_id, 'save', 'summary', $old ) );
		$state = $store->change( $this->term_id, 'discard', 'summary', $state['revision'] );
		$this->assertNull( $state['sections']['summary']['draft'] );
		$this->assertStringContainsString( 'Published text', $store->render( $this->term_id, 'summary' ) );
	}

	public function test_source_changes_during_generation_are_rejected(): void {
		$version = $this->version();
		wp_update_post(
			array(
				'ID'           => $this->posts[29],
				'post_content' => 'Updated reporting.',
			)
		);
		$result = ( new Store() )->save_generated( $this->term_id, array( 'summary' => $version ), '' );
		$this->assertWPError( $result );
		$this->assertSame( 'topic_sources_changed', $result->get_error_code() );
		$this->assertSame( '', get_term_meta( $this->term_id, Store::META_KEY, true ) );
	}

	public function test_ineligible_category_does_not_publish_a_completed_generation(): void {
		$version = $this->version();

		foreach ( array_slice( $this->posts, 0, 26 ) as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
		}

		$this->assertWPError( ( new Store() )->save_generated( $this->term_id, array( 'summary' => $version ), '' ) );
		$this->assertFalse( ( new Sources() )->eligible( $this->term_id ) );
	}

	public function test_equal_date_sample_is_bounded_and_keeps_both_ends(): void {
		foreach ( $this->posts as $id ) {
			wp_update_post(
				array(
					'ID'        => $id,
					'post_date' => '2020-01-01 12:00:00',
				)
			);
		}

		$ids = ( new Sources() )->sample( $this->term_id, 10 );
		$this->assertCount( 10, $ids );
		$this->assertSame( $this->posts[0], $ids[0] );
		$this->assertSame( $this->posts[29], end( $ids ) );
	}

	public function test_cached_sample_rechecks_visibility_and_invalidates_parent_selections(): void {
		$parent = self::factory()->category->create();
		wp_update_term( $this->term_id, 'category', array( 'parent' => $parent ) );
		$sources = new Sources();
		$sources->sample( $parent, 10 );
		$sources->sample( $this->term_id, 10 );
		wp_update_post(
			array(
				'ID'            => $this->posts[0],
				'post_password' => 'secret',
			)
		);
		$this->assertNotContains( $this->posts[0], $sources->sample( $this->term_id, 10 ) );
		Sources::invalidate( array( $this->term_id ), 'category' );
		$this->assertFalse( get_transient( 'wpai_topic_sample_' . $parent . '_10' ) );
		$this->assertFalse( get_transient( 'wpai_topic_sample_' . $this->term_id . '_10' ) );
	}

	public function test_timeline_is_a_bounded_list_of_real_posts_without_pagination(): void {
		$html = ( new \WordPress\AI\Experiments\Topic_Pages\Topic_Pages() )->timeline( $this->term_id );
		$this->assertSame( 10, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( get_permalink( $this->posts[0] ), $html );
		$this->assertStringContainsString( get_permalink( $this->posts[29] ), $html );
		$this->assertStringNotContainsString( 'page-numbers', $html );
		$this->assertLessThan( strpos( $html, get_permalink( $this->posts[29] ) ), strpos( $html, get_permalink( $this->posts[0] ) ) );
	}

	public function test_save_route_uses_stored_preview_and_ignores_injected_edits(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Safe preview' ) ), '' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$controller = new \WordPress\AI\Experiments\Topic_Pages\Controller();
		$register   = static function () use ( $controller ) {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $register );
		$request = new \WP_REST_Request( 'POST', '/ai/v1/topic-pages/' . $this->term_id );
		$request->set_body_params(
			array(
				'action'   => 'save',
				'section'  => 'summary',
				'revision' => $state['revision'],
				'content'  => '<script>Injected</script>',
			)
		);

		try {
			$response = rest_get_server()->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertStringContainsString( 'Safe preview', $store->render( $this->term_id, 'summary' ) );
			$this->assertStringNotContainsString( 'Injected', $store->render( $this->term_id, 'summary' ) );
			$request->set_param( 'action', 'approve' );
			$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
			wp_set_current_user( 0 );
			$request->set_param( 'action', 'discard' );
			$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
		} finally {
			remove_action( 'rest_api_init', $register );
		}
	}

	public function test_generation_rechecks_sources_changed_by_a_different_request(): void {
		global $wpdb;
		$version = $this->version();
		// Simulate a separate request: this process still holds the original post in its local cache.
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'Reporting corrected in another request.' ), array( 'ID' => $this->posts[29] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Deliberately leave the request-local cache unchanged.
		$this->assertNotSame( 'Reporting corrected in another request.', get_post( $this->posts[29] )->post_content );
		$result = ( new Store() )->save_generated( $this->term_id, array( 'summary' => $version ), '' );
		$this->assertWPError( $result );
		$this->assertSame( 'topic_sources_changed', $result->get_error_code() );
		$this->assertSame( '', get_term_meta( $this->term_id, Store::META_KEY, true ) );
	}

	public function test_multibyte_excerpts_are_valid_utf8_and_stay_within_the_byte_limit(): void {
		foreach ( array_slice( $this->posts, -5 ) as $id ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => str_repeat( '🌱ब', 5000 ),
				)
			);
		}

		$records = ( new Sources() )->collect( $this->term_id, 'summary' );
		$this->assertLessThanOrEqual( 60000, array_sum( array_map( 'strlen', array_column( $records, 'content' ) ) ) );

		foreach ( $records as $record ) {
			$this->assertTrue( wp_is_valid_utf8( $record['content'] ) );
			$this->assertStringNotContainsString( '�', $record['content'] );
		}
	}

	public function test_posts_without_titles_have_readable_source_and_timeline_links(): void {
		wp_update_post(
			array(
				'ID'         => $this->posts[29],
				'post_title' => '',
			)
		);
		$records = ( new Sources() )->collect( $this->term_id, 'summary' );
		$this->assertSame( '(no title)', $records[4]['title'] );
		$this->assertStringContainsString( '>(no title)</a>', ( new \WordPress\AI\Experiments\Topic_Pages\Topic_Pages() )->timeline( $this->term_id ) );
	}
}
