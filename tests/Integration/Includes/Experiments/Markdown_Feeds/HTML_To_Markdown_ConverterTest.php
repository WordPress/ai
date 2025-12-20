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
	 * The converter instance.
	 *
	 * @var \WordPress\AI\Experiments\Markdown_Feeds\HTML_To_Markdown_Converter
	 */
	private HTML_To_Markdown_Converter $converter;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();
		$this->converter = new HTML_To_Markdown_Converter();
	}

	/**
	 * Test that basic HTML is converted into Markdown.
	 *
	 * @since x.x.x
	 */
	public function test_basic_conversion(): void {
		$code = "<pre><code>echo \"hi\";\n</code></pre>";

		$html = '<h2>Heading</h2>'
			. '<p>Hello <strong>world</strong> <a href="https://example.com">link</a>.</p>'
			. '<ul><li>One</li><li>Two</li></ul>'
			. '<p><img src="https://example.com/a.jpg" alt="Alt text" /></p>'
			. $code;

		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '## Heading', $markdown );
		$this->assertStringContainsString( 'Hello **world** [link](https://example.com).', $markdown );
		$this->assertStringContainsString( '- One', $markdown );
		$this->assertStringContainsString( '- Two', $markdown );
		$this->assertStringContainsString( '![Alt text](https://example.com/a.jpg)', $markdown );
		$this->assertStringContainsString( '```', $markdown );
		$this->assertStringContainsString( 'echo "hi";', $markdown );
	}

	/**
	 * Test conversion of all heading levels.
	 *
	 * @since x.x.x
	 */
	public function test_converts_all_heading_levels(): void {
		$html = '<h1>H1</h1><h2>H2</h2><h3>H3</h3><h4>H4</h4><h5>H5</h5><h6>H6</h6>';

		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '# H1', $markdown );
		$this->assertStringContainsString( '## H2', $markdown );
		$this->assertStringContainsString( '### H3', $markdown );
		$this->assertStringContainsString( '#### H4', $markdown );
		$this->assertStringContainsString( '##### H5', $markdown );
		$this->assertStringContainsString( '###### H6', $markdown );
	}

	/**
	 * Test conversion of bold text using both strong and b tags.
	 *
	 * @since x.x.x
	 */
	public function test_converts_bold_text(): void {
		$html     = '<p>This is <strong>bold</strong> and <b>also bold</b>.</p>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '**bold**', $markdown );
		$this->assertStringContainsString( '**also bold**', $markdown );
	}

	/**
	 * Test conversion of italic text using both em and i tags.
	 *
	 * @since x.x.x
	 */
	public function test_converts_italic_text(): void {
		$html     = '<p>This is <em>italic</em> and <i>also italic</i>.</p>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '*italic*', $markdown );
		$this->assertStringContainsString( '*also italic*', $markdown );
	}

	/**
	 * Test conversion of links with href attribute.
	 *
	 * @since x.x.x
	 */
	public function test_converts_links(): void {
		$html     = '<p>Visit <a href="https://example.com">Example Site</a> for more info.</p>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '[Example Site](https://example.com)', $markdown );
	}

	/**
	 * Test conversion of images with alt text.
	 *
	 * @since x.x.x
	 */
	public function test_converts_images_with_alt(): void {
		$html     = '<img src="https://example.com/photo.jpg" alt="A beautiful sunset" />';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '![A beautiful sunset](https://example.com/photo.jpg)', $markdown );
	}

	/**
	 * Test conversion of unordered lists.
	 *
	 * @since x.x.x
	 */
	public function test_converts_unordered_lists(): void {
		$html     = '<ul><li>Apple</li><li>Banana</li><li>Cherry</li></ul>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '- Apple', $markdown );
		$this->assertStringContainsString( '- Banana', $markdown );
		$this->assertStringContainsString( '- Cherry', $markdown );
	}

	/**
	 * Test conversion of ordered lists.
	 *
	 * @since x.x.x
	 */
	public function test_converts_ordered_lists(): void {
		$html     = '<ol><li>First</li><li>Second</li><li>Third</li></ol>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '1. First', $markdown );
		$this->assertStringContainsString( '2. Second', $markdown );
		$this->assertStringContainsString( '3. Third', $markdown );
	}

	/**
	 * Test conversion of blockquotes.
	 *
	 * @since x.x.x
	 */
	public function test_converts_blockquotes(): void {
		$html     = '<blockquote>This is a quoted text.</blockquote>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '> This is a quoted text.', $markdown );
	}

	/**
	 * Test conversion of inline code.
	 *
	 * @since x.x.x
	 */
	public function test_converts_inline_code(): void {
		$html     = '<p>Use the <code>console.log()</code> function for debugging.</p>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '`console.log()`', $markdown );
	}

	/**
	 * Test conversion of code blocks.
	 *
	 * @since x.x.x
	 */
	public function test_converts_code_blocks(): void {
		$html     = '<pre><code>const x = 42;</code></pre>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '```', $markdown );
		$this->assertStringContainsString( 'const x = 42;', $markdown );
	}

	/**
	 * Test conversion of horizontal rules.
	 *
	 * @since x.x.x
	 */
	public function test_converts_horizontal_rules(): void {
		$html     = '<p>Above the line.</p><hr /><p>Below the line.</p>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '---', $markdown );
	}

	/**
	 * Test conversion of line breaks.
	 *
	 * @since x.x.x
	 */
	public function test_converts_line_breaks(): void {
		$html     = '<p>Line one<br />Line two</p>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( "Line one\nLine two", $markdown );
	}

	/**
	 * Test conversion of simple tables with thead and tbody.
	 *
	 * @since x.x.x
	 */
	public function test_converts_tables_with_thead(): void {
		$html = '<table>
			<thead>
				<tr><th>Name</th><th>Age</th></tr>
			</thead>
			<tbody>
				<tr><td>Alice</td><td>30</td></tr>
				<tr><td>Bob</td><td>25</td></tr>
			</tbody>
		</table>';

		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '| Name | Age |', $markdown );
		$this->assertStringContainsString( '| --- | --- |', $markdown );
		$this->assertStringContainsString( '| Alice | 30 |', $markdown );
		$this->assertStringContainsString( '| Bob | 25 |', $markdown );
	}

	/**
	 * Test conversion of tables using th tags without explicit thead.
	 *
	 * @since x.x.x
	 */
	public function test_converts_tables_with_th_no_thead(): void {
		$html = '<table>
			<tr><th>Product</th><th>Price</th></tr>
			<tr><td>Widget</td><td>$10</td></tr>
		</table>';

		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '| Product | Price |', $markdown );
		$this->assertStringContainsString( '| --- | --- |', $markdown );
		$this->assertStringContainsString( '| Widget | $10 |', $markdown );
	}

	/**
	 * Test that script tags are completely removed.
	 *
	 * @since x.x.x
	 */
	public function test_removes_script_tags(): void {
		$html     = '<p>Safe content</p><script>malicious();</script><p>More safe content</p>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( 'Safe content', $markdown );
		$this->assertStringContainsString( 'More safe content', $markdown );
		$this->assertStringNotContainsString( 'malicious', $markdown );
		$this->assertStringNotContainsString( 'script', $markdown );
	}

	/**
	 * Test that style tags are completely removed.
	 *
	 * @since x.x.x
	 */
	public function test_removes_style_tags(): void {
		$html     = '<p>Content</p><style>body { color: red; }</style>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( 'Content', $markdown );
		$this->assertStringNotContainsString( 'color', $markdown );
		$this->assertStringNotContainsString( 'style', $markdown );
	}

	/**
	 * Test that empty input returns empty output.
	 *
	 * @since x.x.x
	 */
	public function test_empty_input(): void {
		$markdown = $this->converter->convert( '' );

		$this->assertEmpty( $markdown );
	}

	/**
	 * Test that whitespace-only input is handled gracefully.
	 *
	 * @since x.x.x
	 */
	public function test_whitespace_only_input(): void {
		$markdown = $this->converter->convert( '   ' );

		$this->assertEmpty( trim( $markdown ) );
	}

	/**
	 * Test nested formatting (bold within italic, etc.).
	 *
	 * @since x.x.x
	 */
	public function test_nested_formatting(): void {
		$html     = '<p>This is <strong><em>bold italic</em></strong> text.</p>';
		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '***bold italic***', $markdown );
	}

	/**
	 * Test complex nested structure with blockquote containing formatted text.
	 *
	 * @since x.x.x
	 */
	public function test_complex_blockquote(): void {
		$html = '<blockquote>
			<p>A quote with <strong>bold</strong> and <em>italic</em> text.</p>
		</blockquote>';

		$markdown = $this->converter->convert( $html );

		$this->assertStringContainsString( '>', $markdown );
		$this->assertStringContainsString( '**bold**', $markdown );
		$this->assertStringContainsString( '*italic*', $markdown );
	}

	/**
	 * Test that excessive whitespace is normalized.
	 *
	 * @since x.x.x
	 */
	public function test_normalizes_whitespace(): void {
		$html     = '<p>Multiple    spaces    here</p>';
		$markdown = $this->converter->convert( $html );

		// Should not contain multiple consecutive spaces.
		$this->assertStringNotContainsString( '  ', $markdown );
		$this->assertStringContainsString( 'Multiple spaces here', $markdown );
	}
}
