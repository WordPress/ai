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
			'content'    => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->',
			'source_ids' => $this->posts,
		);
	}

	public function test_pending_generation_does_not_replace_approved_content(): void {
		$store = new Store();
		$state = $store->get( $this->term_id );
		$state = $store->save_generated( $this->term_id, 'summary', $this->version( 'First version' ), $state['revision'] );
		$this->assertSame( '', $store->render( $this->term_id, 'summary' ) );
		$state = $store->change( $this->term_id, 'save', 'summary', $state['revision'] );
		$this->assertStringContainsString( 'First version', $store->render( $this->term_id, 'summary' ) );
		$state = $store->save_generated( $this->term_id, 'summary', $this->version( 'Replacement' ), $state['revision'] );
		$this->assertStringContainsString( 'First version', $store->render( $this->term_id, 'summary' ) );
		$store->change( $this->term_id, 'save', 'summary', $state['revision'] );
		$this->assertStringContainsString( 'Replacement', $store->render( $this->term_id, 'summary' ) );
	}

	public function test_stale_editor_cannot_overwrite_newer_work(): void {
		$store = new Store();
		$old   = $store->get( $this->term_id )['revision'];
		$store->save_generated( $this->term_id, 'summary', $this->version( 'New work' ), $old );
		$result = $store->change( $this->term_id, 'save', 'summary', $old );
		$this->assertWPError( $result );
		$this->assertSame( 'topic_conflict', $result->get_error_code() );
	}

	public function test_private_or_unassigned_sources_hide_approved_output(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, 'summary', $this->version( 'Sensitive history' ), '' );
		$store->change( $this->term_id, 'save', 'summary', $state['revision'] );
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

	public function test_arbitrary_dynamic_blocks_cannot_be_generated(): void {
		$store              = new Store();
		$version            = $this->version( 'Draft' );
		$version['content'] = '<!-- wp:shortcode -->[private-data]<!-- /wp:shortcode -->';
		$this->assertWPError( $store->save_generated( $this->term_id, 'summary', $version, '' ) );
	}

	public function test_block_attributes_survive_approval_and_later_saves(): void {
		$store              = new Store();
		$version            = $this->version( 'Quoted content' );
		$version['content'] = '<!-- wp:paragraph --><p>Quoted content <a data-wpai-source-id="' . $this->posts[0] . '" href="' . get_permalink( $this->posts[0] ) . '">Source</a></p><!-- /wp:paragraph -->';
		$version['content'] = $version['content'] . '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size">A styled paragraph <a data-wpai-source-id="' . $this->posts[0] . '" href="' . get_permalink( $this->posts[0] ) . '">Source</a></p><!-- /wp:paragraph -->';
		$state              = $store->save_generated( $this->term_id, 'overview', $version, '' );
		$this->assertIsArray( $state );
		$state = $store->change( $this->term_id, 'save', 'overview', $state['revision'] );
		$this->assertIsArray( $state );
		$this->assertStringContainsString( 'has-small-font-size', $store->render( $this->term_id, 'overview' ) );
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

	public function test_large_topics_use_a_bounded_sample(): void {
		self::factory()->post->create_many(
			16,
			array(
				'post_status'   => 'publish',
				'post_category' => array( $this->term_id ),
			)
		);
		$result = ( new Sources() )->collect( $this->term_id );
		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 20, count( $result ) );
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
			'summary'  => array(
				array(
					'text'       => '<script>alert(1)</script> A supported claim.',
					'source_ids' => array( $this->posts[0] ),
				),
			),
			'overview' => array(
				array(
					'text'       => '<script>alert(1)</script> First overview point.',
					'source_ids' => array( $this->posts[0] ),
				),
				array(
					'text'       => 'Second overview point.',
					'source_ids' => array( $this->posts[1] ),
				),
			),
		);
		$result  = $prepare->invoke( $ability, $payload, array( 'summary', 'overview' ), $sources );
		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', $result['summary']['content'] );
		$this->assertStringNotContainsString( '<script>', $result['overview']['content'] );
		$this->assertStringNotContainsString( '<a', $result['summary']['content'] );
		$this->assertStringContainsString( esc_url( (string) get_permalink( $this->posts[0] ) ), $result['overview']['content'] );
		$payload['summary'][0]['source_ids'] = array( 99999999 );
		$this->assertWPError( $prepare->invoke( $ability, $payload, array( 'summary' ), $sources ) );
	}

	public function test_summary_previews_and_public_content_omit_citations(): void {
		$store    = new Store();
		$version  = array(
			'content'    => '<!-- wp:paragraph --><p>A short summary.</p><!-- /wp:paragraph -->',
			'source_ids' => $this->posts,
		);
		$overview = array(
			'content'    => str_repeat( '<!-- wp:paragraph --><p>An overview. <cite class="wpai-topic-citation">Sources: <a data-wpai-source-id="' . $this->posts[0] . '" href="' . get_permalink( $this->posts[0] ) . '">Source</a></cite></p><!-- /wp:paragraph -->', 2 ),
			'source_ids' => $this->posts,
		);
		$state    = $store->save_generated( $this->term_id, 'summary', $version, '' );
		$state    = $store->save_generated( $this->term_id, 'overview', $overview, $state['revision'] );
		$this->assertSame( '<p class="wp-block-paragraph">A short summary.</p>', $store->state( $this->term_id )['sections']['summary']['draft']['preview_html'] );
		$state = $store->change( $this->term_id, 'save', 'summary', $state['revision'] );
		$store->change( $this->term_id, 'save', 'overview', $state['revision'] );
		$this->assertSame( '<p class="wp-block-paragraph">A short summary.</p>', $store->render( $this->term_id, 'summary' ) );
		$this->assertStringContainsString( '<cite', $store->render( $this->term_id, 'overview' ) );
		$this->assertSame( $this->posts, $store->get( $this->term_id )['sections']['summary']['approved']['source_ids'] );
	}

	public function test_uncited_source_withdrawal_prevents_save_and_hides_published_text(): void {
		$store    = new Store();
		$link     = '<a data-wpai-source-id="' . $this->posts[0] . '" href="' . get_permalink( $this->posts[0] ) . '">Source</a>';
		$overview = array(
			'content'    => str_repeat( '<!-- wp:paragraph --><p>Reported coverage. ' . $link . '</p><!-- /wp:paragraph -->', 2 ),
			'source_ids' => $this->posts,
		);
		$state    = $store->save_generated( $this->term_id, 'overview', $overview, '' );
		$this->assertNotWPError( $state );
		$state = $store->change( $this->term_id, 'save', 'overview', $state['revision'] );
		$this->assertSame( $this->posts, $state['sections']['overview']['approved']['source_ids'] );
		$state = $store->save_generated( $this->term_id, 'overview', $overview, $state['revision'] );
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_category' => array( $this->term_id ),
			)
		);
		wp_update_post(
			array(
				'ID'          => $this->posts[1],
				'post_status' => 'private',
			)
		);
		$this->assertWPError( $store->change( $this->term_id, 'save', 'overview', $state['revision'] ) );
		$this->assertSame( '', $store->render( $this->term_id, 'overview' ) );
	}

	public function test_overview_requires_exactly_two_cited_paragraphs(): void {
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
		$entries = array(
			array(
				'text'       => 'The project began with a proposal.',
				'source_ids' => array( $this->posts[0] ),
			),
			array(
				'text'       => 'Later coverage described its opening.',
				'source_ids' => array( $this->posts[1] ),
			),
			array(
				'text'       => 'The latest report explains the next steps.',
				'source_ids' => array( $this->posts[2] ),
			),
		);
		$result  = $prepare->invoke( $ability, array( 'overview' => array_slice( $entries, 0, 2 ) ), array( 'overview' ), $sources );
		$this->assertIsArray( $result );
		$this->assertSame( 2, substr_count( $result['overview']['content'], '<p>' ) );
		$this->assertStringContainsString( '<cite', $result['overview']['content'] );
		$this->assertWPError( $prepare->invoke( $ability, array( 'overview' => array( $entries[0] ) ), array( 'overview' ), $sources ) );
		$this->assertWPError( $prepare->invoke( $ability, array( 'overview' => $entries ), array( 'overview' ), $sources ) );
	}

	public function test_approval_rejects_a_source_withdrawn_since_generation(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, 'summary', $this->version( 'Draft' ), '' );
		wp_update_post(
			array(
				'ID'          => $this->posts[0],
				'post_status' => 'draft',
			)
		);
		$result = $store->change( $this->term_id, 'save', 'summary', $state['revision'] );
		$this->assertWPError( $result );
		$this->assertNull( $store->get( $this->term_id )['sections']['summary']['approved'] );
	}

	public function test_public_block_renders_approved_content_without_inserting_it_into_the_description(): void {
		$store = new Store();
		$state = $store->save_generated( $this->term_id, 'summary', $this->version( 'Visible on archive' ), '' );
		$store->change( $this->term_id, 'save', 'summary', $state['revision'] );

		$topic_pages = new \WordPress\AI\Experiments\Topic_Pages\Topic_Pages();
		$topic_pages->register();
		$this->go_to( get_term_link( $this->term_id ) );
		$rendered = render_block(
			array(
				'blockName'    => 'ai/topic-summary',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( 'wp-block-ai-topic-summary', $rendered );
		$this->go_to( get_term_link( $this->term_id ) );
		$this->assertStringNotContainsString( 'Visible on archive', term_description( $this->term_id ) );

		foreach ( array( 'summary', 'overview', 'timeline' ) as $section ) {
			unregister_block_type( 'ai/topic-' . $section );
		}

		$this->assertStringContainsString( 'Visible on archive', $rendered );
	}

	public function test_blocks_inherit_the_current_archive_and_ignore_other_term_ids(): void {
		$other       = self::factory()->category->create();
		$other_posts = self::factory()->post->create_many(
			5,
			array(
				'post_status'   => 'publish',
				'post_category' => array( $other ),
				'post_title'    => 'Space report',
			)
		);
		foreach ( $this->posts as $id ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => 'Garden report',
				)
			);
		}
		$store = new Store();
		foreach ( array( array( $this->term_id, $this->posts, 'Garden' ), array( $other, $other_posts, 'Space' ) ) as [ $id, $sources, $label ] ) {
			$state = $store->save_generated(
				$id,
				'summary',
				array(
					'content'    => '<!-- wp:paragraph --><p>' . $label . ' summary</p><!-- /wp:paragraph -->',
					'source_ids' => $sources,
				),
				''
			);
			$state = $store->change( $id, 'save', 'summary', $state['revision'] );
			$link  = '<a data-wpai-source-id="' . $sources[0] . '" href="' . get_permalink( $sources[0] ) . '">Source</a>';
			$state = $store->save_generated(
				$id,
				'overview',
				array(
					'content'    => str_repeat( '<!-- wp:paragraph --><p>' . $label . ' overview ' . $link . '</p><!-- /wp:paragraph -->', 2 ),
					'source_ids' => $sources,
				),
				$state['revision']
			);
			$this->assertNotWPError( $store->change( $id, 'save', 'overview', $state['revision'] ) );
		}
		( new \WordPress\AI\Experiments\Topic_Pages\Topic_Pages() )->register();
		try {
			foreach ( array( array( $this->term_id, $other, 'Garden', 'Space' ), array( $other, $this->term_id, 'Space', 'Garden' ) ) as [ $id, $override, $expected, $unexpected ] ) {
				$this->go_to( get_term_link( $id ) );
				foreach ( Store::ALL_SECTIONS as $section ) {
					$html = do_blocks( '<!-- wp:ai/topic-' . $section . ' {"termId":' . $override . '} /-->' );
					$this->assertStringContainsString( $expected, $html );
					$this->assertStringNotContainsString( $unexpected, $html );
				}
			}
			$this->go_to( get_permalink( $this->posts[0] ) );
			foreach ( Store::ALL_SECTIONS as $section ) {
				$this->assertSame( '', do_blocks( '<!-- wp:ai/topic-' . $section . ' {"termId":' . $other . '} /-->' ) );
			}
		} finally {
			foreach ( Store::ALL_SECTIONS as $section ) {
				unregister_block_type( 'ai/topic-' . $section );
			}
		}
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
					'text'       => 'Supported claim.',
					'source_ids' => array( $this->posts[0] ),
				),
			),
		);
		$versions = $prepare->invoke( $ability, $payload, array( 'summary' ), ( new Sources() )->collect( $this->term_id ) );
		$store    = new Store();
		$store->save_generated( $this->term_id, 'summary', $versions['summary'], '' );
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
