<?php
/**
 * Topic Pages publication and storage regression coverage.
 *
 * @package WordPress\AI\Tests
 */
namespace WordPress\AI\Tests\Integration\Includes\Experiments\Topic_Pages;

use WP_UnitTestCase;
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

	private function content( $text = 'Reviewed coverage', $post_id = null ): string {
		$id = $post_id ?? $this->posts[0];

		return '<!-- wp:paragraph --><p>' . $text . ' <a data-wpai-source-id="' . $id . '" href="' . get_permalink( $id ) . '">Source</a></p><!-- /wp:paragraph -->';
	}

	private function seed() {
		$state = $this->store->save_generated(
			$this->term->term_id,
			'summary',
			array(
				'content'    => '<!-- wp:paragraph --><p>Reviewed coverage</p><!-- /wp:paragraph -->',
				'source_ids' => array( $this->posts[0] ),
			),
			''
		);
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		return $this->store->save_generated(
			$this->term->term_id,
			'overview',
			array(
				'content'    => $this->content() . $this->content( 'Later coverage' ),
				'source_ids' => array( $this->posts[0] ),
			),
			$state['revision']
		);
	}

	public function test_saving_one_section_keeps_the_other_preview_private(): void {
		$state  = $this->seed();
		$result = $this->store->change( $this->term->term_id, 'save', 'summary', $state['revision'] );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Reviewed coverage', $this->store->render( $this->term->term_id, 'summary' ) );
		$this->assertSame( '', $this->store->render( $this->term->term_id, 'overview' ) );
		$this->assertNotNull( $result['sections']['overview']['draft'] );
	}

	public function test_invalid_preview_cannot_be_published(): void {
		$this->seed();
		$raw = get_term_meta( $this->term->term_id, Store::META_KEY, true );
		$raw['sections']['overview']['draft']['content'] = '<!-- wp:shortcode -->[secret]<!-- /wp:shortcode -->';
		update_term_meta( $this->term->term_id, Store::META_KEY, $raw );
		$state = $this->store->get( $this->term->term_id );
		$this->assertWPError( $this->store->change( $this->term->term_id, 'save', 'overview', $state['revision'] ) );
		$this->assertSame( $state, $this->store->get( $this->term->term_id ) );
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

		foreach ( array( str_repeat( '<!-- wp:paragraph --><p>Uncited claim.</p><!-- /wp:paragraph -->', 2 ), str_repeat( '<!-- wp:paragraph --><p><a href="https://unrelated.example/">Unsupported source</a></p><!-- /wp:paragraph -->', 2 ) ) as $content ) {
			$version = array(
				'content'    => $content,
				'source_ids' => array( $this->posts[0] ),
			);
			$this->assertWPError( $this->store->save_generated( $this->term->term_id, 'overview', $version, $state['revision'] ) );
			$this->assertSame( $state, $this->store->get( $this->term->term_id ) );
		}

		$unrelated = array(
			'content'    => '<!-- wp:paragraph --><p><a href="https://unrelated.example/">Unsupported source</a></p><!-- /wp:paragraph -->',
			'source_ids' => array( $this->posts[0] ),
		);
		$this->assertWPError( $this->store->save_generated( $this->term->term_id, 'summary', $unrelated, $state['revision'] ) );
		$this->assertSame( $state, $this->store->get( $this->term->term_id ) );
	}

	public function test_existing_links_follow_permalink_changes(): void {
		$state = $this->seed();
		$this->store->change( $this->term->term_id, 'save', 'overview', $state['revision'] );
		$old = (string) get_option( 'permalink_structure' );

		try {
			$this->set_permalink_structure( '/%postname%/' );
			wp_update_post(
				array(
					'ID'        => $this->posts[0],
					'post_name' => 'updated-topic-source',
				)
			);
			$this->assertStringContainsString( '/updated-topic-source/', $this->store->render( $this->term->term_id, 'overview' ) );
		} finally {
			$this->set_permalink_structure( $old );
		}
	}

	public function test_withdrawn_source_prevents_publication(): void {
		$state = $this->seed();
		$raw   = get_term_meta( $this->term->term_id, Store::META_KEY, true );
		$raw['sections']['overview']['draft']['content']    = $this->content( 'Ineligible draft', $this->posts[1] ) . $this->content( 'Later coverage', $this->posts[1] );
		$raw['sections']['overview']['draft']['source_ids'] = array( $this->posts[1] );
		update_term_meta( $this->term->term_id, Store::META_KEY, $raw );
		wp_update_post(
			array(
				'ID'          => $this->posts[1],
				'post_status' => 'private',
			)
		);
		$state = $this->store->get( $this->term->term_id );
		$this->assertWPError( $this->store->change( $this->term->term_id, 'save', 'overview', $state['revision'] ) );
		$this->assertSame( '', $this->store->render( $this->term->term_id, 'summary' ) );
	}

	public function test_invalid_actions_are_rejected_without_changing_storage(): void {
		$state = $this->seed();

		foreach ( array( 'invalid' ) as $action ) {
			$this->assertWPError( $this->store->change( $this->term->term_id, $action, 'summary', $state['revision'] ) );
			$this->assertSame( $state, $this->store->get( $this->term->term_id ) );
		}
	}

	public function test_rest_rejects_bulk_actions_without_changing_storage(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$register = array( new \WordPress\AI\Experiments\Topic_Pages\Controller(), 'register_routes' );
		add_action( 'rest_api_init', $register );
		try {
			$server = rest_get_server();
			foreach ( array( 'generate', 'save', 'discard' ) as $action ) {
				$request = new \WP_REST_Request( 'POST', '/ai/v1/topic-pages/' . $this->term->term_id );
				$request->set_body_params(
					array(
						'action'   => $action,
						'section'  => 'all',
						'revision' => '',
					)
				);
				$this->assertSame( 400, $server->dispatch( $request )->get_status() );
			}
			$this->assertSame( '', get_term_meta( $this->term->term_id, Store::META_KEY, true ) );
		} finally {
			remove_action( 'rest_api_init', $register );
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
			'summary',
			array(
				'content'    => '<!-- wp:paragraph --><p>Unapproved replacement</p><!-- /wp:paragraph -->',
				'source_ids' => array( $this->posts[0] ),
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
}
