<?php
/**
 * Integration tests for the Content Translation Languages class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Content_Translation;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Translation\Languages;

/**
 * Languages test case.
 *
 * @since 1.3.0
 */
class LanguagesTest extends WP_UnitTestCase {

	/**
	 * Tear down test case.
	 *
	 * @since 1.3.0
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_content_translation_languages' );
		parent::tearDown();
	}

	/**
	 * Test that get_default_target_language() returns a supported code.
	 *
	 * @since 1.3.0
	 */
	public function test_get_default_target_language_is_supported(): void {
		$default = Languages::get_default_target_language();

		$this->assertSame( 'en-us', $default, 'Default target language should be en-us' );
		$this->assertContains(
			$default,
			Languages::get_codes(),
			'Default target language should be present in the supported codes'
		);
	}

	/**
	 * Test that get_supported_languages() returns a non-empty map of strings.
	 *
	 * @since 1.3.0
	 */
	public function test_get_supported_languages_returns_string_map(): void {
		$languages = Languages::get_supported_languages();

		$this->assertNotEmpty( $languages, 'Supported languages should not be empty' );

		foreach ( $languages as $code => $name ) {
			$this->assertIsString( $code, 'Every language code should be a string' );
			$this->assertIsString( $name, 'Every language name should be a string' );
			$this->assertNotSame( '', $code, 'Language codes should not be empty' );
			$this->assertNotSame( '', $name, 'Language names should not be empty' );
		}
	}

	/**
	 * Test that get_codes() returns the keys of the supported languages.
	 *
	 * @since 1.3.0
	 */
	public function test_get_codes_matches_supported_language_keys(): void {
		$this->assertSame(
			array_keys( Languages::get_supported_languages() ),
			Languages::get_codes(),
			'Codes should match the supported language keys'
		);
	}

	/**
	 * Test that get_supported_languages_for_js() returns a list of code/name pairs.
	 *
	 * @since 1.3.0
	 */
	public function test_get_supported_languages_for_js_returns_code_name_pairs(): void {
		$languages = Languages::get_supported_languages();
		$for_js    = Languages::get_supported_languages_for_js();

		$this->assertCount(
			count( $languages ),
			$for_js,
			'JS list should contain one entry per supported language'
		);
		$this->assertSame(
			array_keys( $for_js ),
			range( 0, count( $for_js ) - 1 ),
			'JS list should be a sequential list, not a map'
		);

		foreach ( $for_js as $language ) {
			$this->assertSame(
				array( 'code', 'name' ),
				array_keys( $language ),
				'Each JS entry should have exactly a code and a name'
			);
			$this->assertArrayHasKey(
				$language['code'],
				$languages,
				'Each JS entry code should be a supported language code'
			);
			$this->assertSame(
				$languages[ $language['code'] ],
				$language['name'],
				'Each JS entry name should match the supported language name'
			);
		}
	}

	/**
	 * Test that get_language_name() returns the name for a supported code.
	 *
	 * @since 1.3.0
	 */
	public function test_get_language_name_returns_name_for_supported_code(): void {
		$this->assertSame( 'French', Languages::get_language_name( 'fr-fr' ) );
		$this->assertSame(
			'Portuguese (Brazil)',
			Languages::get_language_name( 'pt-br' )
		);
	}

	/**
	 * Test that get_language_name() returns null for an unsupported code.
	 *
	 * @since 1.3.0
	 */
	public function test_get_language_name_returns_null_for_unsupported_code(): void {
		$this->assertNull( Languages::get_language_name( 'invalid' ) );
		$this->assertNull( Languages::get_language_name( '' ) );
	}

	/**
	 * Test that the filter can add a language.
	 *
	 * @since 1.3.0
	 */
	public function test_filter_can_add_a_language(): void {
		add_filter(
			'wpai_content_translation_languages',
			static function ( array $languages ): array {
				$languages['sv'] = 'Swedish';

				return $languages;
			}
		);

		$this->assertContains( 'sv', Languages::get_codes() );
		$this->assertSame( 'Swedish', Languages::get_language_name( 'sv' ) );
		$this->assertContains(
			array(
				'code' => 'sv',
				'name' => 'Swedish',
			),
			Languages::get_supported_languages_for_js()
		);
	}

	/**
	 * Test that the filter can remove a language.
	 *
	 * @since 1.3.0
	 */
	public function test_filter_can_remove_a_language(): void {
		add_filter(
			'wpai_content_translation_languages',
			static function ( array $languages ): array {
				unset( $languages['fr-fr'] );

				return $languages;
			}
		);

		$this->assertNotContains( 'fr-fr', Languages::get_codes() );
		$this->assertNull( Languages::get_language_name( 'fr-fr' ) );
	}

	/**
	 * Test that the filter can replace the entire language list.
	 *
	 * @since 1.3.0
	 */
	public function test_filter_can_replace_the_language_list(): void {
		add_filter(
			'wpai_content_translation_languages',
			static function (): array {
				return array( 'cy' => 'Welsh' );
			}
		);

		$this->assertSame( array( 'cy' ), Languages::get_codes() );
		$this->assertSame(
			array(
				array(
					'code' => 'cy',
					'name' => 'Welsh',
				),
			),
			Languages::get_supported_languages_for_js()
		);
	}

	/**
	 * Test that language codes added through the filter are sanitized.
	 *
	 * @since 1.3.0
	 */
	public function test_filter_codes_are_sanitized(): void {
		add_filter(
			'wpai_content_translation_languages',
			static function ( array $languages ): array {
				$languages['pt_BR!'] = 'Portuguese (Brazil)';

				return $languages;
			}
		);

		$codes = Languages::get_codes();

		$this->assertContains( 'pt_br', $codes, 'Codes should be normalized with sanitize_key()' );
		$this->assertNotContains( 'pt_BR!', $codes, 'Unsanitized codes should not be exposed' );
		$this->assertSame(
			'Portuguese (Brazil)',
			Languages::get_language_name( 'pt_br' ),
			'The sanitized code should resolve to the language name'
		);
	}

	/**
	 * Test that unusable entries added through the filter are discarded.
	 *
	 * A filter returning non-string labels or an empty code would otherwise
	 * reach the typed helpers and raise a TypeError under strict_types.
	 *
	 * @since 1.3.0
	 */
	public function test_filter_discards_unusable_entries(): void {
		add_filter(
			'wpai_content_translation_languages',
			static function ( array $languages ): array {
				$languages['nb'] = null;
				$languages['da'] = array( 'Danish' );
				$languages['fi'] = '   ';
				$languages['!!'] = 'Bogus code';

				return $languages;
			}
		);

		$codes = Languages::get_codes();

		$this->assertNotContains( 'nb', $codes, 'Null labels should be discarded' );
		$this->assertNotContains( 'da', $codes, 'Array labels should be discarded' );
		$this->assertNotContains( 'fi', $codes, 'Blank labels should be discarded' );
		$this->assertNotContains( '', $codes, 'Codes that sanitize to empty should be discarded' );

		// The built-in languages should still be intact.
		$this->assertContains( 'fr-fr', $codes );
	}

	/**
	 * Test that numeric language codes added through the filter do not fatal.
	 *
	 * PHP casts numeric-string array keys to integers, so the helpers must
	 * tolerate integer keys rather than assuming strings.
	 *
	 * @since 1.3.0
	 */
	public function test_filter_with_numeric_code_does_not_error(): void {
		add_filter(
			'wpai_content_translation_languages',
			static function ( array $languages ): array {
				$languages['123'] = 'Numeric Code Language';

				return $languages;
			}
		);

		$codes = Languages::get_codes();

		$this->assertContains( '123', $codes, 'Numeric codes should be returned as strings' );

		foreach ( $codes as $code ) {
			$this->assertIsString( $code, 'Every code should be a string' );
		}

		$this->assertContains(
			array(
				'code' => '123',
				'name' => 'Numeric Code Language',
			),
			Languages::get_supported_languages_for_js(),
			'Numeric codes should be stringified for JS'
		);
		$this->assertSame(
			'Numeric Code Language',
			Languages::get_language_name( '123' )
		);
	}

	/**
	 * Test that a filter returning a non-array falls back to the defaults.
	 *
	 * @since 1.3.0
	 */
	public function test_filter_returning_non_array_falls_back_to_defaults(): void {
		$defaults = Languages::get_supported_languages();

		add_filter(
			'wpai_content_translation_languages',
			static function () {
				return 'not-an-array';
			}
		);

		$this->assertSame(
			$defaults,
			Languages::get_supported_languages(),
			'An invalid filter return value should leave the defaults intact'
		);
		$this->assertSame( 'French', Languages::get_language_name( 'fr-fr' ) );
	}
}
