<?php
/**
 * Supported languages for AI Content translation.
 *
 * @package WordPress\AI\Abilities\Content_Translation
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Translation;

final class Languages {

	/**
	 * The default target language for translation.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const DEFAULT_TARGET_LANGUAGE = 'en-us';

	/**
	 * The default target language for translation.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public static function get_default_target_language(): string {
		return self::DEFAULT_TARGET_LANGUAGE;
	}

	/**
	 * Returns the supported languages for AI content translation.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{locale: string, name: string}> Supported languages.
	 */
	public static function get_supported_languages(): array {
		$languages = array(
			'en-us' => array(
				'locale' => 'en_US',
				'name'   => __( 'English (US)', 'ai' ),
			),
			'en-gb' => array(
				'locale' => 'en_GB',
				'name'   => __( 'English (UK)', 'ai' ),
			),
			'es-es' => array(
				'locale' => 'es_ES',
				'name'   => __( 'Spanish (Spain)', 'ai' ),
			),
			'fr-fr' => array(
				'locale' => 'fr_FR',
				'name'   => __( 'French (France)', 'ai' ),
			),
			'de-de' => array(
				'locale' => 'de_DE',
				'name'   => __( 'German', 'ai' ),
			),
			'it-it' => array(
				'locale' => 'it_IT',
				'name'   => __( 'Italian', 'ai' ),
			),
			'pt-br' => array(
				'locale' => 'pt_BR',
				'name'   => __( 'Portuguese (Brazil)', 'ai' ),
			),
			'nl-nl' => array(
				'locale' => 'nl_NL',
				'name'   => __( 'Dutch', 'ai' ),
			),
			'ja'    => array(
				'locale' => 'ja',
				'name'   => __( 'Japanese', 'ai' ),
			),
			'zh-cn' => array(
				'locale' => 'zh_CN',
				'name'   => __( 'Chinese (Simplified)', 'ai' ),
			),
		);

		/**
		 * Filters supported target languages for AI content translation.
		 *
		 * @param array<string, array{locale: string, name: string}> $languages Supported languages.
		 */
		return (array) apply_filters( 'wpai_content_translation_languages', $languages );
	}

	/**
	 * Returns the supported languages for AI content translation in a format suitable for JavaScript.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, array{code: string, locale: string, name: string}> Supported languages for JavaScript.
	 */
	public static function get_supported_languages_for_js(): array {
		$languages = self::get_supported_languages();

		return array_map(
			static function ( string $code, array $language ): array {
				return array(
					'code' => $code,
					'name' => $language['name'],
				);
			},
			array_keys( $languages ),
			$languages
		);
	}

	/**
	 * Returns the name of a language given its code.
	 *
	 * @since x.x.x
	 *
	 * @param string $language_code The language code.
	 * @return string|null The name of the language, or null if not found.
	 */
	public static function get_language_name( string $language_code ): ?string {
		$languages = self::get_supported_languages();

		if ( isset( $languages[ $language_code ] ) ) {
			return $languages[ $language_code ]['name'];
		}

		return null;
	}

	/**
	 * Returns the language codes of supported languages.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Array of supported language codes.
	 */
	public static function get_codes(): array {
		return array_keys( self::get_supported_languages() );
	}

	/**
	 * Checks if a language is supported for translation.
	 *
	 * @since x.x.x
	 *
	 * @param string $language_code The language code to check.
	 * @return bool True if the language is supported, false otherwise.
	 */
	public static function is_supported( string $language_code ): bool {
		return array_key_exists( $language_code, self::get_supported_languages() );
	}
}
