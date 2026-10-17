<?php
/**
 * Topic Pages editorial workflow tests.
 *
 * @package WordPress\AI\Tests
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Topic_Pages;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Topic_Overview\Topic_Overview;
use WordPress\AI\Experiments\Topic_Pages\Controller;
use WordPress\AI\Experiments\Topic_Pages\Sources;
use WordPress\AI\Experiments\Topic_Pages\Store;

class Topic_PagesTest extends WP_UnitTestCase {
	private int $term_id;
	private array $posts;

	public function setUp(): void {
		parent::setUp();
		$this->term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->posts   = self::factory()->post->create_many(
			5,
			array(
				'post_status'   => 'publish',
				'post_category' => array( $this->term_id ),
			)
		);
	}

	private function version( string $text ): array {
		return array(
			'content'    => '<!-- wp:paragraph --><p>' . $text . ' <a href="' . get_permalink( $this->posts[0] ) . '">Source</a></p><!-- /wp:paragraph -->',
			'source_ids' => $this->posts,
		);
	}


	public function test_pending_generation_and_edits_do_not_replace_approved_content(): void {
		$store = new Store();
		$state = $store->get( $this->term_id );
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'First version' ) ), $state['revision'] );
		$this->assertSame( '', $store->render( $this->term_id, 'summary' ) );
		$state = $store->change( $this->term_id, 'approve', 'summary', array(), $state['revision'] );
		$this->assertStringContainsString( 'First version', $store->render( $this->term_id, 'summary' ) );
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Replacement' ) ), $state['revision'] );
		$this->assertStringContainsString( 'First version', $store->render( $this->term_id, 'summary' ) );
		$state = $store->change( $this->term_id, 'save', 'summary', array( 'content' => $this->version( 'Editor version' )['content'] ), $state['revision'] );
		$this->assertTrue( $state['sections']['summary']['locked'] );
		$this->assertStringContainsString( 'First version', $store->render( $this->term_id, 'summary' ) );
		$store->change( $this->term_id, 'approve', 'summary', array(), $state['revision'] );
		$this->assertStringContainsString( 'Editor version', $store->render( $this->term_id, 'summary' ) );
	}

	public function test_stale_editor_cannot_overwrite_newer_work(): void {
		$store = new Store();
		$old   = $store->get( $this->term_id )['revision'];
		$store->save_generated( $this->term_id, array( 'summary' => $this->version( 'New work' ) ), $old );
		$result = $store->change( $this->term_id, 'lock', 'summary', array( 'locked' => true ), $old );
		$this->assertWPError( $result );
		$this->assertSame( 'topic_conflict', $result->get_error_code() );
	}

	public function test_private_or_unassigned_sources_hide_approved_output(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Sensitive history' ) ), '' );
		$store->change( $this->term_id, 'approve', 'summary', array(), $state['revision'] );
		// Keep the topic eligible so source visibility, not the minimum, hides it.
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_category' => array( $this->term_id ),
			)
		);
		wp_update_post(
			array(
				'ID'          => $this->posts[0],
				'post_status' => 'private',
			)
		);
		$this->assertSame( '', $store->render( $this->term_id, 'summary' ) );
		wp_update_post(
			array(
				'ID'          => $this->posts[0],
				'post_status' => 'publish',
			)
		);
		wp_set_post_terms( $this->posts[0], array(), 'category' );
		$this->assertSame( '', $store->render( $this->term_id, 'summary' ) );
	}

	public function test_threshold_excludes_password_protected_content(): void {
		wp_update_post(
			array(
				'ID'            => $this->posts[0],
				'post_password' => 'secret',
			)
		);
		$this->assertWPError( ( new Sources() )->collect( $this->term_id ) );
	}

	public function test_arbitrary_dynamic_blocks_cannot_be_saved(): void {
		$store  = new Store();
		$state  = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Draft' ) ), '' );
		$result = $store->change( $this->term_id, 'save', 'summary', array( 'content' => '<!-- wp:shortcode -->[private-data]<!-- /wp:shortcode -->' ), $state['revision'] );
		$this->assertWPError( $result );
	}

	public function test_block_attributes_survive_approval_and_later_saves(): void {
		$store              = new Store();
		$version            = $this->version( 'Quoted content' );
		$version['content'] = $version['content'] . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">A heading</h3><!-- /wp:heading -->';
		$state              = $store->save_generated( $this->term_id, array( 'overview' => $version ), '' );
		$this->assertIsArray( $state );
		$state = $store->change( $this->term_id, 'approve', 'overview', array(), $state['revision'] );
		$this->assertIsArray( $state );
		$this->assertStringContainsString( 'A heading', $store->render( $this->term_id, 'overview' ) );
	}

	public function test_only_term_managers_can_access_editorial_state(): void {
		$controller    = new Controller();
		$request       = new \WP_REST_Request( 'GET', '/ai/v1/topic-pages/' . $this->term_id );
		$request['id'] = $this->term_id;
		foreach ( array( 'subscriber', 'author', 'editor', 'administrator' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertSame( in_array( $role, array( 'editor', 'administrator' ), true ), $controller->permission( $request ), $role );
		}
		wp_set_current_user( 0 );
		$this->assertFalse( $controller->permission( $request ) );
	}

	public function test_large_topics_are_rejected_without_silent_sampling(): void {
		self::factory()->post->create_many(
			16,
			array(
				'post_status'   => 'publish',
				'post_category' => array( $this->term_id ),
			)
		);
		$result = ( new Sources() )->collect( $this->term_id );
		$this->assertWPError( $result );
		$this->assertSame( 'topic_too_large', $result->get_error_code() );
	}

	public function test_nonpublic_post_types_do_not_count_toward_eligibility(): void {
		register_post_type(
			'topic_private',
			array(
				'public'     => false,
				'taxonomies' => array( 'category' ),
			)
		);
		self::factory()->post->create(
			array(
				'post_type'     => 'topic_private',
				'post_status'   => 'publish',
				'post_category' => array( $this->term_id ),
			)
		);
		$filter = static fn() => array( 'post', 'topic_private' );
		add_filter( 'wpai_topic_pages_post_types', $filter );
		try {
			$this->assertSame( 5, ( new Sources() )->query( get_term( $this->term_id ), 1 )->found_posts );
		} finally {
			remove_filter( 'wpai_topic_pages_post_types', $filter );
			unregister_post_type( 'topic_private' );
		}
	}

	public function test_empty_eligible_post_types_return_no_sources(): void {
		$filter = static fn() => array();
		add_filter( 'wpai_topic_pages_post_types', $filter );
		try {
			$this->assertSame( 0, ( new Sources() )->query( get_term( $this->term_id ), 1 )->found_posts );
		} finally {
			remove_filter( 'wpai_topic_pages_post_types', $filter );
		}
	}

	public function test_generated_references_are_validated_and_html_is_escaped(): void {
		$ability = new Topic_Overview(
			'ai/topic-overview',
			array(
				'label'       => 'Topic overview',
				'description' => 'Topic overview',
			)
		);
		$prepare = new \ReflectionMethod( $ability, 'prepare_sections' );
		$prepare->setAccessible( true );
		$sources = ( new Sources() )->collect( $this->term_id );
		$payload = array(
			'summary' => array(
				array(
					'heading'    => '',
					'text'       => '<script>alert(1)</script> A supported claim.',
					'source_ids' => array( $this->posts[0] ),
				),
			),
		);
		$result  = $prepare->invoke( $ability, $payload, array( 'summary' ), $sources );
		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', $result['summary']['content'] );
		$this->assertStringContainsString( esc_url( get_permalink( $this->posts[0] ) ), $result['summary']['content'] );
		$payload['summary'][0]['source_ids'] = array( 99999999 );
		$this->assertWPError( $prepare->invoke( $ability, $payload, array( 'summary' ), $sources ) );
	}

	public function test_approval_rejects_a_source_withdrawn_since_generation(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Draft' ) ), '' );
		wp_update_post(
			array(
				'ID'          => $this->posts[0],
				'post_status' => 'draft',
			)
		);
		$result = $store->change( $this->term_id, 'approve', 'summary', array(), $state['revision'] );
		$this->assertWPError( $result );
		$this->assertNull( $store->get( $this->term_id )['sections']['summary']['approved'] );
	}

	public function test_public_block_renders_approved_content_without_inserting_it_into_the_description(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, array( 'summary' => $this->version( 'Visible on archive' ) ), '' );
		$store->change( $this->term_id, 'approve', 'summary', array(), $state['revision'] );

		$topic_pages = new \WordPress\AI\Experiments\Topic_Pages\Topic_Pages();
		$topic_pages->register();
		$rendered = render_block(
			array(
				'blockName'    => 'ai/topic-summary',
				'attrs'        => array( 'termId' => $this->term_id ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( 'wp-block-ai-topic-summary', $rendered );
		$this->go_to( get_term_link( $this->term_id ) );
		$this->assertStringNotContainsString( 'Visible on archive', term_description( $this->term_id ) );
		foreach ( Store::SECTIONS as $section ) {
			unregister_block_type( 'ai/topic-' . $section ); }
		$this->assertStringContainsString( 'Visible on archive', $rendered );
	}
	public function test_generation_tracks_source_changes_even_within_the_same_second(): void {
		$ability = new Topic_Overview(
			'ai/topic-overview',
			array(
				'label'       => 'Topic overview',
				'description' => 'Topic overview',
			)
		);
		$prepare = new \ReflectionMethod( $ability, 'prepare_sections' );
		$prepare->setAccessible( true );
		$payload  = array(
			'summary' => array(
				array(
					'heading'    => '',
					'text'       => 'Supported claim.',
					'source_ids' => array( $this->posts[0] ),
				),
			),
		);
		$versions = $prepare->invoke( $ability, $payload, array( 'summary' ), ( new Sources() )->collect( $this->term_id ) );
		$store    = new Store();
		$store->save_generated( $this->term_id, $versions, '' );
		$this->assertSame( array(), $store->state( $this->term_id )['stale'] );
		wp_update_post(
			array(
				'ID'           => $this->posts[0],
				'post_content' => 'New source details.',
			)
		);
		$this->assertContains( 'summary', $store->state( $this->term_id )['stale'] );
	}
}
