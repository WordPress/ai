<?php
/**
 * Category template selection.
 *
 * @package WordPress\AI\Tests
 */
namespace WordPress\AI\Tests\Integration\Includes\Experiments\Topic_Pages;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Topic_Pages\Template;

class TemplateTest extends WP_UnitTestCase {
	private int $term_id;
	private string $stylesheet;

	public function setUp(): void {
		parent::setUp();
		$this->stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		$this->term_id = self::factory()->category->create();
		self::factory()->post->create_many(
			5,
			array(
				'post_status'   => 'publish',
				'post_category' => array( $this->term_id ),
			)
		);
		$this->go_to( get_category_link( $this->term_id ) );
	}

	public function tearDown(): void {
		unregister_block_template( 'ai//' . Template::SLUG );
		switch_theme( $this->stylesheet );
		parent::tearDown();
	}

	public function test_preset_keeps_theme_layout_and_adds_each_section_once(): void {
		$template = new Template();
		$template->register();
		$preset = get_block_template( get_stylesheet() . '//' . Template::SLUG );
		$this->assertNotNull( $preset );
		foreach ( array( 'summary', 'overview', 'timeline' ) as $section ) {
			$this->assertSame( 1, substr_count( $preset->content, '<!-- wp:ai/topic-' . $section ) );
		}
		$this->assertStringContainsString( '"slug":"header"', $preset->content );
		$this->assertStringContainsString( '"slug":"footer"', $preset->content );
		$this->assertStringContainsString( 'wp:query', $preset->content );
		$this->assertSame( Template::SLUG . '.php', $template->hierarchy( array( 'category.php', 'archive.php' ) )[0] );
	}

	public function test_specific_category_template_wins_over_the_preset(): void {
		$term = get_term( $this->term_id );
		$id   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'category-' . $term->slug,
				'post_content' => '<!-- wp:paragraph --><p>Custom category.</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
		$original = array( 'category-' . $term->slug . '.php', 'category.php' );
		$this->assertSame( $original, ( new Template() )->hierarchy( $original ) );
	}

	public function test_category_below_five_keeps_the_normal_archive(): void {
		$posts = ( new \WP_Query(
			array(
				'category'       => $this->term_id,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		) )->posts;
		wp_delete_post( $posts[0]->ID, true );
		$original = array( 'category.php', 'archive.php' );
		$this->assertSame( $original, ( new Template() )->hierarchy( $original ) );
	}
	public function test_preset_adds_missing_blocks_without_duplicating_existing_ones(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'category',
				'post_content' => '<!-- wp:query-title {"type":"archive"} /--><!-- wp:ai/topic-summary /--><!-- wp:query /-->',
			)
		);
		wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
		( new Template() )->register();
		$preset = get_block_template( get_stylesheet() . '//' . Template::SLUG );
		foreach ( array( 'summary', 'overview', 'timeline' ) as $section ) {
			$this->assertSame( 1, substr_count( $preset->content, '<!-- wp:ai/topic-' . $section ) );
		}
	}
}
