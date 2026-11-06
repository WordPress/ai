<?php
/**
 * Topic Pages publication and storage regression coverage.
 *
 * @package WordPress\AI\Tests
 */
namespace WordPress\AI\Tests\Integration\Includes\Experiments\Topic_Pages;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Topic_Pages\Lock;
use WordPress\AI\Experiments\Topic_Pages\Store;

class EditorialTest extends WP_UnitTestCase {
	private $term;
	private $posts;
	private $store;

	public function setUp(): void {
		parent::setUp();
		$this->term  = get_term( self::factory()->category->create() );
		$this->posts = self::factory()->post->create_many(
			6,
			array(
				'post_status'   => 'publish',
				'post_category' => array( $this->term->term_id ),
			)
		);
		$this->store = new Store();
	}

	private function content( $text = 'Reviewed coverage' ): string {
		return '<!-- wp:paragraph --><p>' . $text . ' <a href="' . get_permalink( $this->posts[0] ) . '">Source</a></p><!-- /wp:paragraph -->';
	}

	private function seed() {
		$version = array(
			'content'    => $this->content(),
			'source_ids' => array( $this->posts[0] ),
		);
		return $this->store->save_generated(
			$this->term->term_id,
			array(
				'summary'  => $version,
				'overview' => $version,
			),
			''
		);
	}

	public function test_save_publishes_existing_previews_together(): void {
		$state  = $this->seed();
		$result = $this->store->change( $this->term->term_id, 'save', 'all', $state['revision'] );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Reviewed coverage', $this->store->render( $this->term->term_id, 'summary' ) );
		$this->assertStringContainsString( 'Reviewed coverage', $this->store->render( $this->term->term_id, 'overview' ) );
		$this->assertNull( $result['sections']['summary']['draft'] );
	}

	public function test_invalid_preview_prevents_partial_publication(): void {
		$this->seed();
		$raw = get_term_meta( $this->term->term_id, Store::META_KEY, true );
		$raw['sections']['overview']['draft']['content'] = '<!-- wp:shortcode -->[secret]<!-- /wp:shortcode -->';
		update_term_meta( $this->term->term_id, Store::META_KEY, $raw );
		$state  = $this->store->get( $this->term->term_id );
		$result = $this->store->change( $this->term->term_id, 'save', 'all', $state['revision'] );
		$this->assertWPError( $result );
		$this->assertSame( $state, $this->store->get( $this->term->term_id ) );
		$this->assertSame( '', $this->store->render( $this->term->term_id, 'summary' ) );
	}

	public function test_generated_sections_stay_private_until_saved(): void {
		$state = $this->seed();
		$this->assertNotNull( $state['sections']['summary']['draft'] );
		$this->assertNotNull( $state['sections']['overview']['draft'] );
		$this->assertSame( '', $this->store->render( $this->term->term_id, 'summary' ) );
		$this->assertSame( '', $this->store->render( $this->term->term_id, 'overview' ) );
	}

	public function test_unrelated_links_and_missing_citations_cannot_replace_a_preview(): void {
		$state = $this->seed();
		foreach ( array( '<!-- wp:paragraph --><p>Uncited claim.</p><!-- /wp:paragraph -->', '<!-- wp:paragraph --><p><a href="https://unrelated.example/">Unsupported source</a></p><!-- /wp:paragraph -->' ) as $content ) {
			$version = array(
				'content'    => $content,
				'source_ids' => array( $this->posts[0] ),
			);
			$this->assertWPError( $this->store->save_generated( $this->term->term_id, array( 'summary' => $version ), $state['revision'] ) );
			$this->assertSame( $state, $this->store->get( $this->term->term_id ) );
		}
	}

	public function test_existing_links_follow_permalink_changes(): void {
		$state = $this->seed();
		$this->store->change( $this->term->term_id, 'save', 'summary', $state['revision'] );
		$old = (string) get_option( 'permalink_structure' );
		try {
			$this->set_permalink_structure( '/%postname%/' );
			wp_update_post(
				array(
					'ID'        => $this->posts[0],
					'post_name' => 'updated-topic-source',
				)
			);
			$this->assertStringContainsString( '/updated-topic-source/', $this->store->render( $this->term->term_id, 'summary' ) );
		} finally {
			$this->set_permalink_structure( $old );
		}
	}

	public function test_flat_saved_content_remains_visible_and_replaceable(): void {
		update_term_meta(
			$this->term->term_id,
			Store::META_KEY,
			array(
				'summary' => array(
					'content'      => $this->content( 'Earlier saved content' ),
					'sources'      => array( $this->posts[0] ),
					'fingerprint'  => str_repeat( 'a', 64 ),
					'generated_at' => gmdate( 'c' ),
				),
			)
		);
		$this->assertStringContainsString( 'Earlier saved content', $this->store->render( $this->term->term_id, 'summary' ) );
		$state  = $this->store->get( $this->term->term_id );
		$state  = $this->store->save_generated(
			$this->term->term_id,
			array(
				'summary' => array(
					'content'    => $this->content( 'Updated content' ),
					'source_ids' => array( $this->posts[0] ),
				),
			),
			$state['revision']
		);
		$result = $this->store->change( $this->term->term_id, 'save', 'summary', $state['revision'] );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Updated content', $this->store->render( $this->term->term_id, 'summary' ) );
	}

	public function test_ineligible_draft_prevents_partial_bulk_approval(): void {
		$state = $this->seed();
		$raw   = get_term_meta( $this->term->term_id, Store::META_KEY, true );
		$raw['sections']['overview']['draft']['source_ids'] = array( $this->posts[1] );
		update_term_meta( $this->term->term_id, Store::META_KEY, $raw );
		wp_update_post(
			array(
				'ID'          => $this->posts[1],
				'post_status' => 'private',
			)
		);
		$state = $this->store->get( $this->term->term_id );
		$this->assertWPError( $this->store->change( $this->term->term_id, 'save', 'all', $state['revision'] ) );
		$this->assertSame( '', $this->store->render( $this->term->term_id, 'summary' ) );
	}

	public function test_a_competing_writer_cannot_save_even_the_first_version(): void {
		$lock  = new Lock();
		$owner = $lock->acquire( $this->term->term_id, 'save' );
		try {
			$this->assertWPError( $this->seed() );
			$this->assertSame( '', get_term_meta( $this->term->term_id, Store::META_KEY, true ) );
		} finally {
			$lock->release( $this->term->term_id, 'save', $owner );
		}
	}

	public function test_a_lock_can_only_be_released_by_its_owner(): void {
		$lock  = new Lock();
		$owner = $lock->acquire( $this->term->term_id, 'generation' );
		$lock->release( $this->term->term_id, 'generation', 'different-owner' );
		$this->assertFalse( $lock->acquire( $this->term->term_id, 'generation' ) );
		$lock->release( $this->term->term_id, 'generation', $owner );
		$next = $lock->acquire( $this->term->term_id, 'generation' );
		$this->assertIsString( $next );
		$lock->release( $this->term->term_id, 'generation', $next );
	}
	public function test_retired_actions_are_rejected_without_changing_storage(): void {
		$state = $this->seed();
		foreach ( array( 'lock', 'approve', 'publish' ) as $action ) {
			$this->assertWPError( $this->store->change( $this->term->term_id, $action, 'summary', $state['revision'] ) );
			$this->assertSame( $state, $this->store->get( $this->term->term_id ) );
		}
	}

	public function test_invalid_stored_shapes_do_not_crash_the_editor(): void {
		update_term_meta(
			$this->term->term_id,
			Store::META_KEY,
			array(
				'revision' => 'old',
				'sections' => array(
					'summary'  => 'invalid',
					'overview' => array(
						'approved' => array(
							'content'    => array(),
							'source_ids' => 'wrong',
						),
					),
				),
			)
		);
		$state = $this->store->state( $this->term->term_id );
		$this->assertNull( $state['sections']['summary']['approved'] );
		$this->assertNull( $state['sections']['overview']['approved'] );
		$this->assertSame( '', $this->store->render( $this->term->term_id, 'summary' ) );
	}

	public function test_expired_generation_owner_cannot_persist_over_its_replacement(): void {
		global $wp_current_filter;
		$id      = $this->term->term_id;
		$version = array(
			'content'    => $this->content(),
			'source_ids' => array( $this->posts[0] ),
		);
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the registration hook only while registering this test ability.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			wp_register_ability(
				'ai/topic-overview',
				array(
					'label'               => 'Review fixture',
					'description'         => 'Simulates a provider completing after lease replacement.',
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'permission_callback' => '__return_true',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => static function () use ( $id, $version ) {
						update_option(
							'wpai_topic_pages_generation_' . $id,
							array(
								'token'   => 'new-owner',
								'expires' => time() + 300,
							),
							false
						);
						return array( 'summary' => $version );
					},
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
		try {
			$request = new \WP_REST_Request( 'POST', '/ai/v1/topic-pages/' . $id );
			$request->set_param( 'id', $id );
			$request->set_param( 'action', 'generate' );
			$request->set_param( 'section', 'summary' );
			$request->set_param( 'revision', '' );
			$result = ( new \WordPress\AI\Experiments\Topic_Pages\Controller() )->update( $request );
			$this->assertWPError( $result );
			$this->assertSame( '', get_term_meta( $id, Store::META_KEY, true ) );
			$this->assertSame( 'new-owner', get_option( 'wpai_topic_pages_generation_' . $id )['token'] );
		} finally {
			wp_unregister_ability( 'ai/topic-overview' );
			delete_option( 'wpai_topic_pages_generation_' . $id );
		}
	}

	public function test_uninstall_removes_only_owned_topic_metadata(): void {
		$this->seed();
		update_term_meta( $this->term->term_id, 'unrelated_data', 'Keep this' );
		$method = new \ReflectionMethod( \WordPress\AI\Admin\Uninstall::class, 'delete_meta' );
		$method->setAccessible( true );
		$method->invoke( null );
		$this->assertSame( '', get_term_meta( $this->term->term_id, Store::META_KEY, true ) );
		$this->assertSame( 'Keep this', get_term_meta( $this->term->term_id, 'unrelated_data', true ) );
	}

	public function test_comparison_uses_sanitized_approved_content_while_a_draft_is_pending(): void {
		$state = $this->seed();
		$state = $this->store->change( $this->term->term_id, 'save', 'summary', $state['revision'] );
		$this->store->save_generated(
			$this->term->term_id,
			array(
				'summary' => array(
					'content'    => $this->content( 'Unapproved replacement' ),
					'source_ids' => array( $this->posts[0] ),
				),
			),
			$state['revision']
		);
		$raw = get_term_meta( $this->term->term_id, Store::META_KEY, true );
		$raw['sections']['summary']['approved']['content'] = str_replace( '<p>', '<p onclick="alert(1)">', $raw['sections']['summary']['approved']['content'] );
		update_term_meta( $this->term->term_id, Store::META_KEY, $raw );
		$state = $this->store->state( $this->term->term_id );
		$this->assertStringNotContainsString( 'onclick', $state['sections']['summary']['approved']['preview_html'] );
		$this->assertArrayHasKey( 'preview_html', $state['sections']['summary']['approved'] );
		$this->assertStringContainsString( 'Reviewed coverage', $state['sections']['summary']['approved']['preview_html'] );
		$this->assertStringNotContainsString( 'Unapproved replacement', $state['sections']['summary']['approved']['preview_html'] );
	}
	public function test_retired_timeline_text_is_preserved_when_saving_new_sections(): void {
		$legacy = array(
			'approved' => array( 'content' => 'Earlier timeline text' ),
			'draft'    => array( 'content' => 'Earlier private timeline' ),
		);
		foreach ( array( array( 'timeline' => $legacy ), array( 'sections' => array( 'timeline' => $legacy ) ) ) as $raw ) {
			update_term_meta( $this->term->term_id, Store::META_KEY, $raw );
			$state  = $this->store->get( $this->term->term_id );
			$result = $this->store->save_generated(
				$this->term->term_id,
				array(
					'summary' => array(
						'content'    => $this->content(),
						'source_ids' => array( $this->posts[0] ),
					),
				),
				$state['revision']
			);
			$this->assertNotWPError( $result );
			$this->assertArrayNotHasKey( 'timeline', $result['sections'] );
			$this->assertSame( $legacy, get_term_meta( $this->term->term_id, Store::META_KEY, true )['sections']['timeline'] );
		}
	}
}
