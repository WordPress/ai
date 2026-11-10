<?php
/**
 * Topic Pages source and publication validation.
 *
 * @package WordPress\AI\Tests
 */
namespace WordPress\AI\Tests\Integration\Includes\Experiments\Topic_Pages;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Topic_Pages\Sources;

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

}
