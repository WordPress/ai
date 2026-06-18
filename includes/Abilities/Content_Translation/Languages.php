<?php
/**
 * Supported languages for AI Content translation.
 *
 * @package WordPress\AI\Abilities\Content_Translation
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Translation;

/**
 * Class providing supported languages for AI content translation.
 *
 * @since x.x.x
 */
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
	 * Returns the default target language for translation.
	 *
	 * @since x.x.x
	 *
	 * @return string The default target language code.
	 */
	public static function get_default_target_language(): string {
		return self::DEFAULT_TARGET_LANGUAGE;
	}

	/**
	 * Returns the supported languages for AI content translation.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string> Supported languages.
	 */
	public static function get_supported_languages(): array {
		$languages = array(
			'en-us' => __( 'English (US)', 'ai' ),
			'en-gb' => __( 'English (UK)', 'ai' ),
			'es-es' => __( 'Spanish (Spain)', 'ai' ),
			'fr-fr' => __( 'French (France)', 'ai' ),
			'de-de' => __( 'German', 'ai' ),
			'it-it' => __( 'Italian', 'ai' ),
			'pt-br' => __( 'Portuguese (Brazil)', 'ai' ),
			'nl-nl' => __( 'Dutch', 'ai' ),
			'ja'    => __( 'Japanese', 'ai' ),
			'zh-cn' => __( 'Chinese (Simplified)', 'ai' ),
			'zh-tw' => __( 'Chinese (Traditional)', 'ai' ),
			'ko'    => __( 'Korean', 'ai' ),
			'ar'    => __( 'Arabic', 'ai' ),
			'hi'    => __( 'Hindi', 'ai' ),
		);

		/**
		 * Filters supported target languages for AI content translation.
		 *
		 * @param array<string, string> $languages Supported languages.
		 */
		return (array) apply_filters( 'wpai_content_translation_languages', $languages );
	}

	/**
	 * Returns the supported languages for AI content translation in a format suitable for JavaScript.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, array{code: string, name: string}> Supported languages for JavaScript.
	 */
	public static function get_supported_languages_for_js(): array {
		$languages = self::get_supported_languages();

		return array_map(
			static function ( string $code, string $name ): array {
				return array(
					'code' => $code,
					'name' => $name,
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
			return $languages[ $language_code ];
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
