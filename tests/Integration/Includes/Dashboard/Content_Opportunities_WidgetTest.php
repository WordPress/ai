<?php
/**
 * Tests for the Content_Opportunities_Widget class.
 *
 * @package WordPress\AI\Tests\Integration\Dashboard
 */

namespace WordPress\AI\Tests\Integration\Dashboard;

use WP_UnitTestCase;
use WordPress\AI\Admin\Dashboard\Content_Opportunities_Widget;

/**
 * Content_Opportunities_Widget test case.
 *
 * @since x.x.x
 */
class Content_Opportunities_WidgetTest extends WP_UnitTestCase {

	/**
	 * Tests that render() outputs the React mount point with the expected ID.
	 *
	 * @since x.x.x
	 */
	public function test_render_outputs_root_element(): void {
		$widget = new Content_Opportunities_Widget();

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'id="' . Content_Opportunities_Widget::ROOT_ID . '"',
			$output,
			'Should render the React mount point with the documented root ID'
		);
	}

	/**
	 * Tests that render() includes a no-JS fallback message.
	 *
	 * @since x.x.x
	 */
	public function test_render_includes_fallback_message(): void {
		$widget = new Content_Opportunities_Widget();

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ai-content-gap-suggestions__fallback',
			$output,
			'Should render a fallback message for before the script mounts'
		);
	}

	/**
	 * Tests that render() does not trigger an AI request by itself.
	 *
	 * The widget only renders static markup - suggestion generation is
	 * triggered client-side by a user action, never on page load.
	 *
	 * @since x.x.x
	 */
	public function test_render_does_not_reference_suggestions_data(): void {
		$widget = new Content_Opportunities_Widget();

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'ai-content-gap-suggestions__list', $output );
	}
}
