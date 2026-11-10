<?php
/**
 * Topic Pages source and publication validation.
 *
 * @package WordPress\AI\Tests
 */
namespace WordPress\AI\Tests\Integration\Includes\Experiments\Topic_Pages;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Topic_Overview\Topic_Overview;
use WordPress\AI\Experiments\Topic_Pages\Controller;
use WordPress\AI\Experiments\Topic_Pages\Sources;
use WordPress\AI\Experiments\Topic_Pages\Store;

class ValidationTest extends WP_UnitTestCase {

	private int $term_id;
	private array $posts;

	public function setUp(): void {
		parent::setUp();
		$this->term_id = self::factory()->category->create();
		$this->posts   = self::factory()->post->create_many(
			6,
			array(
				'post_status'   => 'publish',
				'post_category' => array( $this->term_id ),
			)
		);
	}

	public function test_generation_retains_every_input_without_showing_summary_citations(): void {
		$ability = new Topic_Overview(
			'ai/topic-overview',
			array(
				'label'       => 'Topic',
				'description' => 'Generates topic content.',
			)
		);
		$prepare = new \ReflectionMethod( $ability, 'prepare_sections' );
		$prepare->setAccessible( true );
		foreach ( Store::SECTIONS as $section ) {
			$sources  = ( new Sources() )->collect( $this->term_id, $section );
			$entry    = array(
				'text'       => 'Reported coverage.',
				'source_ids' => array( $sources[0]['id'] ),
			);
			$versions = $prepare->invoke( $ability, array( $section => array_fill( 0, 'summary' === $section ? 1 : 2, $entry ) ), array( $section ), $sources );
			$this->assertSame( array_column( $sources, 'id' ), $versions[ $section ]['source_ids'] );
			if ( 'summary' !== $section ) {
				continue;
			}

			$this->assertStringNotContainsString( '<a', $versions[ $section ]['content'] );
		}
	}

	public function test_store_rejects_wrong_paragraph_counts_and_untracked_citations(): void {
		$link      = '<a data-wpai-source-id="' . $this->posts[0] . '" href="' . get_permalink( $this->posts[0] ) . '">Source</a>';
		$paragraph = '<!-- wp:paragraph --><p>Coverage. ' . $link . '</p><!-- /wp:paragraph -->';
		$store     = new Store();
		foreach ( array(
			'summary'  => str_repeat( '<!-- wp:paragraph --><p>Coverage.</p><!-- /wp:paragraph -->', 2 ),
			'overview' => $paragraph,
		) as $section => $content ) {
			$this->assertWPError(
				$store->save_generated(
					$this->term_id,
					$section,
					array(
						'content'    => $content,
						'source_ids' => $this->posts,
					),
					''
				)
			);
		}
		$this->assertWPError(
			$store->save_generated(
				$this->term_id,
				'overview',
				array(
					'content'    => $paragraph . $paragraph,
					'source_ids' => array( $this->posts[1] ),
				),
				''
			)
		);
	}

	public function test_each_overview_paragraph_requires_a_tracked_citation(): void {
		$paragraph = '<!-- wp:paragraph --><p>Coverage <a data-wpai-source-id="' . $this->posts[0] . '" href="' . get_permalink( $this->posts[0] ) . '">Source</a></p><!-- /wp:paragraph -->';
		$uncited   = '<!-- wp:paragraph --><p>An unsupported claim.</p><!-- /wp:paragraph -->';
		$this->assertWPError(
			( new Store() )->save_generated(
				$this->term_id,
				'overview',
				array(
					'content'    => $paragraph . $uncited,
					'source_ids' => $this->posts,
				),
				''
			)
		);
	}

	public function test_eligibility_warning_uses_the_configured_minimum(): void {
		$filter = static fn() => 10;
		add_filter( 'wpai_topic_pages_minimum_posts', $filter );
		try {
			$result = ( new Sources() )->eligible_term( $this->term_id );
			$this->assertWPError( $result );
			$this->assertStringContainsString( 'at least 10 public posts', $result->get_error_message() );
		} finally {
			remove_filter( 'wpai_topic_pages_minimum_posts', $filter );
		}
	}

	public function test_rest_rejects_bulk_actions_without_changing_storage(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$register = array( new Controller(), 'register_routes' );
		add_action( 'rest_api_init', $register );
		try {
			$server = rest_get_server();
			foreach ( array( 'generate', 'save', 'discard' ) as $action ) {
				$request = new \WP_REST_Request( 'POST', '/ai/v1/topic-pages/' . $this->term_id );
				$request->set_body_params(
					array(
						'action'   => $action,
						'section'  => 'all',
						'revision' => '',
					)
				);
				$this->assertSame( 400, $server->dispatch( $request )->get_status() );
			}
			$this->assertSame( '', get_term_meta( $this->term_id, Store::META_KEY, true ) );
		} finally {
			remove_action( 'rest_api_init', $register );
		}
	}
}
