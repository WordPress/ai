<?php
/**
 * Integration tests for the HTML_To_Markdown_Converter class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Markdown_Feeds\HTML_To_Markdown_Converter;

/**
 * HTML_To_Markdown_Converter test case.
 *
 * @since x.x.x
 */
class HTML_To_Markdown_ConverterTest extends WP_UnitTestCase {
	/**
	 * Test that basic HTML is converted into Markdown.
	 *
	 * @since x.x.x
	 */
	public function test_basic_conversion() {
		$converter = new HTML_To_Markdown_Converter();

		$code = "<pre><code>echo \"hi\";\n</code></pre>";

		$html = '<h2>Heading</h2>'
			. '<p>Hello <strong>world</strong> <a href="https://example.com">link</a>.</p>'
			. '<ul><li>One</li><li>Two</li></ul>'
			. '<p><img src="https://example.com/a.jpg" alt="Alt text" /></p>'
			. $code;

		$markdown = $converter->convert( $html );

		$this->assertStringContainsString( '## Heading', $markdown );
		$this->assertStringContainsString( 'Hello **world** [link](https://example.com).', $markdown );
		$this->assertStringContainsString( '- One', $markdown );
		$this->assertStringContainsString( '- Two', $markdown );
		$this->assertStringContainsString( '![Alt text](https://example.com/a.jpg)', $markdown );
		$this->assertStringContainsString( '```', $markdown );
		$this->assertStringContainsString( 'echo "hi";', $markdown );
	}
}
