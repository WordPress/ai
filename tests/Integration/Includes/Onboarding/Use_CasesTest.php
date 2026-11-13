<?php
/**
 * Integration tests for the Use_Cases class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Onboarding
 */

namespace WordPress\AI\Tests\Integration\Includes\Onboarding;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Features\Registry;
use WordPress\AI\Onboarding\Use_Cases;

/**
 * Base stub feature for the use case tests.
 */
abstract class Use_Cases_Test_Feature extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Stub ' . static::get_id(),
			'description' => 'A stub feature for the use case tests.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub for Title Generation.
 */
class Use_Cases_Test_Title_Generation extends Use_Cases_Test_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'title-generation';
	}
}

/**
 * Stub for Type-ahead Text.
 */
class Use_Cases_Test_Type_Ahead extends Use_Cases_Test_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'type-ahead';
	}
}

/**
 * Stub for Suggest Reply.
 */
class Use_Cases_Test_Suggest_Reply extends Use_Cases_Test_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'suggest-reply';
	}
}

/**
 * Use_Cases test case.
 *
 * @covers \WordPress\AI\Onboarding\Use_Cases
 * @since x.x.x
 */
class Use_CasesTest extends WP_UnitTestCase {

	/**
	 * Removes filters added by the tests.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_onboarding_use_cases' );

		parent::tearDown();
	}

	/**
	 * Builds a registry holding the given features.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Abstracts\Abstract_Feature ...$features The features to register.
	 * @return \WordPress\AI\Features\Registry The registry.
	 */
	private function get_registry( Abstract_Feature ...$features ): Registry {
		$registry = new Registry();

		foreach ( $features as $feature ) {
			$registry->register_feature( $feature );
		}

		return $registry;
	}

	/**
	 * Tests that only use cases with a registered feature are returned.
	 *
	 * @since x.x.x
	 */
	public function test_get_all_keeps_use_cases_with_registered_features(): void {
		$use_cases = new Use_Cases(
			$this->get_registry( new Use_Cases_Test_Title_Generation(), new Use_Cases_Test_Suggest_Reply() )
		);

		$all = $use_cases->get_all();

		$this->assertSame( array( 'write-publish', 'comments' ), wp_list_pluck( $all, 'id' ) );
		$this->assertSame( array( 'title-generation' ), $all[0]['features'] );
		$this->assertSame( array( 'suggest-reply' ), $all[1]['features'] );
	}

	/**
	 * Tests that an add-on is dropped when its feature is not registered.
	 *
	 * @since x.x.x
	 */
	public function test_get_all_drops_addon_without_registered_feature(): void {
		$use_cases = new Use_Cases(
			$this->get_registry( new Use_Cases_Test_Title_Generation() )
		);

		$all = $use_cases->get_all();

		$this->assertCount( 1, $all );
		$this->assertNull( $all[0]['addon'] );
	}

	/**
	 * Tests that an add-on is kept, and selectable, when its feature is registered.
	 *
	 * @since x.x.x
	 */
	public function test_addon_is_selectable_when_registered(): void {
		$use_cases = new Use_Cases(
			$this->get_registry( new Use_Cases_Test_Title_Generation(), new Use_Cases_Test_Type_Ahead() )
		);

		$all = $use_cases->get_all();

		$this->assertSame( 'type-ahead', $all[0]['addon']['id'] );
		$this->assertSame( array( 'type-ahead' ), $all[0]['addon']['features'] );
		$this->assertNotContains( 'type-ahead', $all[0]['features'], 'An automatic tool must not be turned on by the use case itself.' );
		$this->assertSame( array( 'write-publish', 'type-ahead' ), $use_cases->get_selectable_ids() );
	}

	/**
	 * Tests that the automatic tools are only ever offered as add-ons by default.
	 *
	 * @since x.x.x
	 */
	public function test_default_use_cases_keep_automatic_tools_as_addons(): void {
		$use_cases = new Use_Cases(
			$this->get_registry( new Use_Cases_Test_Title_Generation(), new Use_Cases_Test_Type_Ahead(), new Use_Cases_Test_Suggest_Reply() )
		);

		foreach ( $use_cases->get_all() as $use_case ) {
			$this->assertNotContains( 'type-ahead', $use_case['features'] );
			$this->assertNotContains( 'comment-moderation', $use_case['features'] );
		}
	}

	/**
	 * Tests that the filter can add a use case and that invalid entries are dropped.
	 *
	 * @since x.x.x
	 */
	public function test_filter_can_add_use_cases_and_invalid_entries_are_dropped(): void {
		add_filter(
			'wpai_onboarding_use_cases',
			static function ( array $use_cases ): array {
				$use_cases[] = array(
					'id'       => 'custom',
					'label'    => 'Custom',
					'features' => array( 'suggest-reply', 'not-registered' ),
				);
				$use_cases[] = array(
					'id'       => 'custom',
					'label'    => 'Duplicate',
					'features' => array( 'suggest-reply' ),
				);
				$use_cases[] = array(
					'id'       => 'empty',
					'label'    => 'No registered features',
					'features' => array( 'not-registered' ),
				);
				$use_cases[] = 'not-an-array';

				return $use_cases;
			}
		);

		$use_cases = new Use_Cases(
			$this->get_registry( new Use_Cases_Test_Suggest_Reply() )
		);

		$all = $use_cases->get_all();

		$this->assertSame( array( 'comments', 'custom' ), wp_list_pluck( $all, 'id' ) );
		$this->assertSame( 'Custom', $all[1]['label'] );
		$this->assertSame( array( 'suggest-reply' ), $all[1]['features'] );
		$this->assertNull( $all[1]['try'] );
	}

	/**
	 * Tests that a non-array filter result yields no use cases.
	 *
	 * @since x.x.x
	 */
	public function test_non_array_filter_result_returns_no_use_cases(): void {
		add_filter( 'wpai_onboarding_use_cases', '__return_false' );

		$use_cases = new Use_Cases(
			$this->get_registry( new Use_Cases_Test_Title_Generation() )
		);

		$this->assertSame( array(), $use_cases->get_all() );
		$this->assertSame( array(), $use_cases->get_selectable_ids() );
	}
}
