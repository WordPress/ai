<?php
/**
 * A stub text to speech provider for integration tests.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use ReflectionProperty;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * A configured provider with one text to speech model that outputs only the
 * MIME types it is installed with.
 *
 * Duck-typed: the registry only calls these static methods when matching
 * models, so it never generates anything.
 *
 * @since 1.5.0
 */
final class Fake_Speech_Provider {

	/**
	 * Provider ID the stub is registered under.
	 *
	 * @var string
	 */
	public const ID = 'fake-speech-provider';

	/**
	 * Output MIME types the stub model declares.
	 *
	 * @var list<string>
	 */
	private static array $mime_types = array();

	/**
	 * The registry's provider maps from before install(), or null.
	 *
	 * @var array{0: array<string, string>, 1: array<string, string>}|null
	 */
	private static ?array $saved = null;

	/**
	 * Makes the stub the only registered provider, so no real provider's
	 * availability check can make an HTTP request.
	 *
	 * @since 1.5.0
	 *
	 * @param list<string> $mime_types Output MIME types the stub model declares.
	 */
	public static function install( array $mime_types ): void {
		self::$mime_types = $mime_types;

		if ( null === self::$saved ) {
			self::$saved = self::get_maps();
		}

		self::set_maps(
			array( self::ID => self::class ),
			array( self::class => self::ID )
		);
	}

	/**
	 * Leaves no provider registered at all. Call install() first.
	 *
	 * @since 1.5.0
	 */
	public static function remove_all_providers(): void {
		self::set_maps( array(), array() );
	}

	/**
	 * Restores the providers registered before install().
	 *
	 * @since 1.5.0
	 */
	public static function uninstall(): void {
		if ( null === self::$saved ) {
			return;
		}

		self::set_maps( self::$saved[0], self::$saved[1] );
		self::$saved = null;
	}

	/**
	 * Returns the provider metadata.
	 *
	 * @since 1.5.0
	 *
	 * @return \WordPress\AiClient\Providers\DTO\ProviderMetadata The metadata.
	 */
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata( self::ID, 'Fake Speech Provider', ProviderTypeEnum::cloud() );
	}

	/**
	 * Returns an availability check that always reports the provider as configured.
	 *
	 * @since 1.5.0
	 *
	 * @return object The availability check.
	 */
	public static function availability(): object {
		return new class() {
			// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client API.
			public function isConfigured(): bool {
				return true;
			}
		};
	}

	/**
	 * Returns a directory listing one text to speech model.
	 *
	 * @since 1.5.0
	 *
	 * @return object The model metadata directory.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client API.
	public static function modelMetadataDirectory(): object {
		$mime_types = self::$mime_types;

		return new class( $mime_types ) {
			/**
			 * Output MIME types the model declares.
			 *
			 * @var list<string>
			 */
			private array $mime_types;

			/**
			 * Constructor.
			 *
			 * @param list<string> $mime_types Output MIME types the model declares.
			 */
			public function __construct( array $mime_types ) {
				$this->mime_types = $mime_types;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client API.
			public function listModelMetadata(): array {
				return array(
					new ModelMetadata(
						'fake-tts',
						'Fake TTS',
						array( CapabilityEnum::textToSpeechConversion() ),
						array(
							new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
							new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::audio() ) ) ),
							new SupportedOption( OptionEnum::outputMimeType(), $this->mime_types ),
							new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::inline() ) ),
							new SupportedOption( OptionEnum::outputSpeechVoice() ),
							new SupportedOption( OptionEnum::customOptions() ),
						)
					),
				);
			}
		};
	}

	/**
	 * Returns the registry's provider maps.
	 *
	 * @since 1.5.0
	 *
	 * @return array{0: array<string, string>, 1: array<string, string>} IDs to classes, and classes to IDs.
	 */
	private static function get_maps(): array {
		$registry = AiClient::defaultRegistry();

		$ids = new ReflectionProperty( $registry, 'registeredIdsToClassNames' );
		$ids->setAccessible( true );

		$classes = new ReflectionProperty( $registry, 'registeredClassNamesToIds' );
		$classes->setAccessible( true );

		return array( (array) $ids->getValue( $registry ), (array) $classes->getValue( $registry ) );
	}

	/**
	 * Replaces the registry's provider maps.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, string> $ids_to_classes Provider IDs to class names.
	 * @param array<string, string> $classes_to_ids Class names to provider IDs.
	 */
	private static function set_maps( array $ids_to_classes, array $classes_to_ids ): void {
		$registry = AiClient::defaultRegistry();

		$ids = new ReflectionProperty( $registry, 'registeredIdsToClassNames' );
		$ids->setAccessible( true );
		$ids->setValue( $registry, $ids_to_classes );

		$classes = new ReflectionProperty( $registry, 'registeredClassNamesToIds' );
		$classes->setAccessible( true );
		$classes->setValue( $registry, $classes_to_ids );
	}
}
