<?php
/**
 * Supported languages for AI Content translation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Translation;

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
			'ar'    => __( 'Arabic', 'ai' ),
			'zh-cn' => __( 'Chinese (Simplified)', 'ai' ),
			'zh-tw' => __( 'Chinese (Traditional)', 'ai' ),
			'nl-nl' => __( 'Dutch', 'ai' ),
			'en-gb' => __( 'English (UK)', 'ai' ),
			'en-us' => __( 'English (US)', 'ai' ),
			'fr-fr' => __( 'French', 'ai' ),
			'de-de' => __( 'German', 'ai' ),
			'hi'    => __( 'Hindi', 'ai' ),
			'it-it' => __( 'Italian', 'ai' ),
			'ja'    => __( 'Japanese', 'ai' ),
			'ko'    => __( 'Korean', 'ai' ),
			'pt-br' => __( 'Portuguese (Brazil)', 'ai' ),
			'es-es' => __( 'Spanish', 'ai' ),
		);

		/**
		 * Filters supported target languages for AI content translation.
		 *
		 * Codes are normalized with `sanitize_key()` and entries with a
		 * non-string or empty label are discarded.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, string> $languages Supported languages.
		 */
		$filtered = apply_filters( 'wpai_content_translation_languages', $languages );

		// A filter returning a non-array is ignored so the language picker and
		// the ability schema keep working rather than degrading to junk entries.
		if ( ! is_array( $filtered ) ) {
			$filtered = $languages;
		}

		/*
		 * Normalize the filtered list. Codes are sanitized the same way incoming
		 * `target_language` input is, so lookups agree, and entries that are not
		 * usable strings are dropped rather than propagated into the ability
		 * schema and the typed helpers below.
		 */
		$normalized = array();

		foreach ( $filtered as $code => $name ) {
			if ( ! is_string( $name ) || '' === trim( $name ) ) {
				continue;
			}

			$code = sanitize_key( (string) $code );

			if ( '' === $code ) {
				continue;
			}

			$normalized[ $code ] = $name;
		}

		return $normalized;
	}

	/**
	 * Returns the supported languages for AI content translation in a format suitable for JavaScript.
	 *
	 * @since x.x.x
	 *
	 * @return list<array{code: string, name: string}> Supported languages for JavaScript.
	 */
	public static function get_supported_languages_for_js(): array {
		$languages = array();

		/*
		 * Built with a loop rather than array_map() over array_keys(): PHP casts
		 * numeric-string array keys to integers, which would fail a typed
		 * string parameter under strict_types.
		 */
		foreach ( self::get_supported_languages() as $code => $name ) {
			$languages[] = array(
				'code' => (string) $code,
				'name' => $name,
			);
		}

		return $languages;
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

		if ( array_key_exists( $language_code, $languages ) ) {
			return (string) $languages[ $language_code ];
		}

		return null;
	}

	/**
	 * Returns the language codes of supported languages.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Array of supported language codes.
	 */
	public static function get_codes(): array {
		// Cast to string because PHP stores numeric-string array keys as integers.
		return array_map( 'strval', array_keys( self::get_supported_languages() ) );
	}
}
