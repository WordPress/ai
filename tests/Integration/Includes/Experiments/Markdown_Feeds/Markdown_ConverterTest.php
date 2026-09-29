<?php
/**
 * Integration tests for the Markdown_Converter class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds
 */

namespace WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Markdown_Feeds\Markdown_Converter;

/**
 * Markdown_Converter test case.
 *
 * @since x.x.x
 */
class Markdown_ConverterTest extends WP_UnitTestCase {

	/**
	 * Converter under test.
	 *
	 * @var Markdown_Converter
	 */
	private $converter;

	/**
	 * Sets up the converter.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->converter = new Markdown_Converter();
	}

	/**
	 * Tests that headings convert to ATX markdown headings.
	 */
	public function test_converts_headings(): void {
		$markdown = $this->converter->convert( '<h2>Section Title</h2>' );

		$this->assertStringContainsString( '## Section Title', $markdown );
	}

	/**
	 * Tests bold and emphasis conversion (syntax verified against upstream spec fixtures).
	 */
	public function test_converts_bold_and_emphasis(): void {
		$markdown = $this->converter->convert( '<p>The <em>dog</em> did it, I <strong>swear</strong>!</p>' );

		$this->assertStringContainsString( '_dog_', $markdown );
		$this->assertStringContainsString( '**swear**', $markdown );
	}

	/**
	 * Tests link conversion to inline markdown links.
	 */
	public function test_converts_links(): void {
		$markdown = $this->converter->convert( '<p>See <a href="https://example.com/page">the docs</a> now.</p>' );

		$this->assertStringContainsString( '[the docs](https://example.com/page)', $markdown );
	}

	/**
	 * Tests that relative link URLs resolve against the base URL.
	 */
	public function test_resolves_relative_urls_against_base_url(): void {
		$markdown = $this->converter->convert(
			'<p><a href="/about/">About</a></p>',
			'https://example.com/blog/my-post/'
		);

		$this->assertStringContainsString( 'https://example.com/about/', $markdown );
	}

	/**
	 * Tests that list items each land on their own line.
	 */
	public function test_converts_unordered_lists(): void {
		$markdown = $this->converter->convert( '<ul><li>Eggs</li><li>Milk</li><li>Bread</li></ul>' );
		$lines    = array_map( 'trim', explode( "\n", $markdown ) );

		$this->assertNotEmpty( preg_grep( '/Eggs$/', $lines ) );
		$this->assertNotEmpty( preg_grep( '/Milk$/', $lines ) );
		$this->assertNotEmpty( preg_grep( '/Bread$/', $lines ) );
	}

	/**
	 * Tests that code blocks survive conversion.
	 */
	public function test_converts_code_blocks(): void {
		$markdown = $this->converter->convert( "<pre><code class=\"language-php\">echo 'hi';</code></pre>" );

		$this->assertStringContainsString( "echo 'hi';", $markdown );
	}

	/**
	 * Tests that empty input produces empty output.
	 */
	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', $this->converter->convert( '' ) );
		$this->assertSame( '', $this->converter->convert( "   \n  " ) );
	}

	/**
	 * Tests the fallback path: the renderer aborts on incomplete HTML and
	 * returns '', so the converter must fall back to stripped text.
	 */
	public function test_malformed_html_falls_back_to_stripped_text(): void {
		$markdown = $this->converter->convert( '<p>Hello world</p><div class="unterminated' );

		$this->assertNotSame( '', $markdown );
		$this->assertStringContainsString( 'Hello world', $markdown );
		$this->assertStringNotContainsString( '<p>', $markdown );
	}
}
