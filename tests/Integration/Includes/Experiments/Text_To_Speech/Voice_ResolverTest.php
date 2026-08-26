<?php
/**
 * Integration tests for text to speech voice resolution.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use ReflectionMethod;
use ReflectionProperty;
use WP_Connector_Registry;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Text_To_Speech\Job_Manager;
use WordPress\AI\Experiments\Text_To_Speech\Speech_Generator;
use WordPress\AI\Experiments\Text_To_Speech\Text_To_Speech;
use WordPress\AI\Experiments\Text_To_Speech\Voice_Resolver;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Stub option name exposing only the check the resolver makes.
 *
 * @since x.x.x
 */
final class Voice_Test_Option_Name {

	/**
	 * Whether this option is the output speech voice option.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	private bool $is_voice;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param bool $is_voice Whether this option is the output speech voice option.
	 */
	public function __construct( bool $is_voice ) {
		$this->is_voice = $is_voice;
	}

	/**
	 * Reports whether this option is the output speech voice option.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when this is the output speech voice option.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client option enum API.
	public function isOutputSpeechVoice(): bool {
		return $this->is_voice;
	}
}

/**
 * Stub supported option mirroring the AI client's SupportedOption shape.
 *
 * @since x.x.x
 */
final class Voice_Test_Supported_Option {

	/**
	 * The option name stub.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech\Voice_Test_Option_Name
	 */
	private Voice_Test_Option_Name $name;

	/**
	 * The declared supported values, or null when any value is accepted.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $values;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param bool                   $is_voice Whether this option is the output speech voice option.
	 * @param array<int, mixed>|null $values   The declared supported values, or null.
	 */
	public function __construct( bool $is_voice, ?array $values ) {
		$this->name   = new Voice_Test_Option_Name( $is_voice );
		$this->values = $values;
	}

	/**
	 * Returns the option name stub.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech\Voice_Test_Option_Name The option name stub.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client supported option API.
	public function getName(): Voice_Test_Option_Name {
		return $this->name;
	}

	/**
	 * Returns the declared supported values.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, mixed>|null The declared supported values, or null.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client supported option API.
	public function getSupportedValues(): ?array {
		return $this->values;
	}
}

/**
 * Stub model metadata declaring text to speech support and voice values.
 *
 * @since x.x.x
 */
final class Voice_Test_Model_Metadata {

	/**
	 * Voice values the stub model declares, or null to declare none.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, mixed>|null
	 */
	public static ?array $voice_values = null;

	/**
	 * Returns the stub model ID.
	 *
	 * @since x.x.x
	 *
	 * @return string The stub model ID.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client model metadata API.
	public function getId(): string {
		return 'voice-test-model';
	}

	/**
	 * Returns the stub model's supported capabilities.
	 *
	 * @since x.x.x
	 *
	 * @return list<object> Supported capabilities.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client model metadata API.
	public function getSupportedCapabilities(): array {
		return array(
			(object) array( 'value' => CapabilityEnum::TEXT_TO_SPEECH_CONVERSION ),
		);
	}

	/**
	 * Returns the stub model's supported options.
	 *
	 * @since x.x.x
	 *
	 * @return list<\WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech\Voice_Test_Supported_Option> Supported options.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client model metadata API.
	public function getSupportedOptions(): array {
		return array(
			// An unrelated option first, so the resolver has to skip it.
			new Voice_Test_Supported_Option( false, array( 'ignored' ) ),
			new Voice_Test_Supported_Option( true, self::$voice_values ),
		);
	}
}

/**
 * Stub model metadata directory for the voice test provider.
 *
 * @since x.x.x
 */
final class Voice_Test_Model_Metadata_Directory {

	/**
	 * Lists the stub model metadata.
	 *
	 * @since x.x.x
	 *
	 * @return list<\WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech\Voice_Test_Model_Metadata> Stub model metadata.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client model metadata directory API.
	public function listModelMetadata(): array {
		return array( new Voice_Test_Model_Metadata() );
	}

	/**
	 * Reports whether the stub provider has the given model.
	 *
	 * @since x.x.x
	 *
	 * @param string $model_id The model ID.
	 * @return bool True when the stub model matches.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client model metadata directory API.
	public function hasModelMetadata( string $model_id ): bool {
		return 'voice-test-model' === $model_id;
	}

	/**
	 * Returns the stub model metadata.
	 *
	 * @since x.x.x
	 *
	 * @param string $model_id The model ID.
	 * @return \WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech\Voice_Test_Model_Metadata The stub model metadata.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client model metadata directory API.
	public function getModelMetadata( string $model_id ): Voice_Test_Model_Metadata {
		unset( $model_id );
		return new Voice_Test_Model_Metadata();
	}
}

/**
 * Stub provider exposing text to speech model metadata.
 *
 * Mirrors only the static methods the resolver and the AI client registry
 * invoke, so it intentionally does not implement ProviderInterface.
 *
 * @since x.x.x
 */
final class Voice_Test_Provider {

	/**
	 * Returns the stub model metadata directory.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech\Voice_Test_Model_Metadata_Directory Stub model metadata directory.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client provider API.
	public static function modelMetadataDirectory(): Voice_Test_Model_Metadata_Directory {
		return new Voice_Test_Model_Metadata_Directory();
	}
}

/**
 * Voice resolution test case.
 *
 * @since x.x.x
 */
class Voice_ResolverTest extends WP_UnitTestCase {

	/**
	 * Stub provider and connector ID.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PROVIDER_ID = 'wpai_voice_test_provider';

	/**
	 * The option holding the stub connector's API key.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const KEY_OPTION = 'connectors_ai_provider_wpai_voice_test_provider_api_key';

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		Voice_Test_Model_Metadata::$voice_values = null;

		// Keep resolution deterministic: without this, a real connector
		// configured elsewhere in the suite could win the preference list.
		add_filter( 'wpai_preferred_speech_models', '__return_empty_array' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tear_down(): void {
		remove_filter( 'wpai_preferred_speech_models', '__return_empty_array' );
		remove_all_filters( 'wpai_tts_default_voice' );
		remove_all_filters( 'wpai_tts_supported_voices' );

		$this->unregister_provider();

		$registry = WP_Connector_Registry::get_instance();
		if ( null !== $registry && $registry->is_registered( self::PROVIDER_ID ) ) {
			$registry->unregister( self::PROVIDER_ID );
		}

		delete_option( self::KEY_OPTION );
		delete_option( Text_To_Speech::get_field_option_name( 'voice' ) );

		Voice_Test_Model_Metadata::$voice_values = null;

		parent::tear_down();
	}

	/**
	 * Tests that declared voice values are returned in order.
	 *
	 * @since x.x.x
	 */
	public function test_supported_voices_returns_declared_values(): void {
		$this->connect_provider( array( 'v1', 'v2' ) );

		$this->assertSame( array( 'v1', 'v2' ), ( new Voice_Resolver() )->get_supported_voices() );
	}

	/**
	 * Tests that a provider declaring no values yields null, not an empty list.
	 *
	 * This is the state every bundled provider is in today, and it is what
	 * keeps the Voice setting a free-text field.
	 *
	 * @since x.x.x
	 */
	public function test_supported_voices_is_null_when_no_values_declared(): void {
		$this->connect_provider( null );

		$this->assertNull( ( new Voice_Resolver() )->get_supported_voices() );
	}

	/**
	 * Tests that unusable values are dropped while a literal "0" survives.
	 *
	 * @since x.x.x
	 */
	public function test_supported_voices_drops_unusable_values_but_keeps_zero(): void {
		$this->connect_provider( array( 'v1', '', array( 'nested' ), '0', 5 ) );

		$this->assertSame(
			array( 'v1', '0', '5' ),
			( new Voice_Resolver() )->get_supported_voices()
		);
	}

	/**
	 * Tests that the default voice is the first declared voice.
	 *
	 * @since x.x.x
	 */
	public function test_default_voice_is_first_declared_voice(): void {
		$this->connect_provider( array( 'v1', 'v2' ) );

		$this->assertSame( 'v1', ( new Voice_Resolver() )->get_default_voice() );
	}

	/**
	 * Tests that the default voice is empty when nothing is declared.
	 *
	 * @since x.x.x
	 */
	public function test_default_voice_is_empty_when_nothing_declared(): void {
		$this->connect_provider( null );

		$this->assertSame( '', ( new Voice_Resolver() )->get_default_voice() );
	}

	/**
	 * Tests that the filter supplies a default when the provider declares none.
	 *
	 * @since x.x.x
	 */
	public function test_default_voice_filter_supplies_a_default(): void {
		$this->connect_provider( null );

		add_filter(
			'wpai_tts_default_voice',
			static function (): string {
				return '  21m00Tcm4TlvDq8ikWAM  ';
			}
		);

		$this->assertSame( '21m00Tcm4TlvDq8ikWAM', ( new Voice_Resolver() )->get_default_voice() );
	}

	/**
	 * Tests that the filter receives the resolved provider and model IDs.
	 *
	 * @since x.x.x
	 */
	public function test_default_voice_filter_receives_resolved_ids(): void {
		$this->connect_provider( array( 'v1' ) );

		$received = array();

		add_filter(
			'wpai_tts_default_voice',
			static function ( $voice, $provider_id, $model_id ) use ( &$received ) {
				$received = array( $voice, $provider_id, $model_id );
				return $voice;
			},
			10,
			3
		);

		( new Voice_Resolver() )->get_default_voice();

		$this->assertSame( array( 'v1', self::PROVIDER_ID, 'voice-test-model' ), $received );
	}

	/**
	 * Tests that the supported-voices filter can list voices for a keyless provider.
	 *
	 * @since x.x.x
	 */
	public function test_supported_voices_filter_can_supply_voices(): void {
		// No provider connected at all, standing in for a keyless connector.
		add_filter(
			'wpai_tts_supported_voices',
			static function (): array {
				return array( 'local-a', 'local-b' );
			}
		);

		$resolver = new Voice_Resolver();

		$this->assertSame( array( 'local-a', 'local-b' ), $resolver->get_supported_voices() );
		$this->assertSame( 'local-a', $resolver->get_default_voice() );

		remove_all_filters( 'wpai_tts_supported_voices' );
	}

	/**
	 * Tests that filtered voices are normalized the same way declared ones are.
	 *
	 * @since x.x.x
	 */
	public function test_supported_voices_filter_output_is_normalized(): void {
		add_filter(
			'wpai_tts_supported_voices',
			static function (): array {
				return array( 'ok', '', array( 'nested' ), 7 );
			}
		);

		$this->assertSame( array( 'ok', '7' ), ( new Voice_Resolver() )->get_supported_voices() );

		remove_all_filters( 'wpai_tts_supported_voices' );
	}

	/**
	 * Tests that the supported-voices filter receives the resolved model's values.
	 *
	 * @since x.x.x
	 */
	public function test_supported_voices_filter_receives_declared_values(): void {
		$this->connect_provider( array( 'v1', 'v2' ) );

		$received = array();

		add_filter(
			'wpai_tts_supported_voices',
			static function ( $values, $provider_id, $model_id ) use ( &$received ) {
				$received = array( $values, $provider_id, $model_id );
				return $values;
			},
			10,
			3
		);

		( new Voice_Resolver() )->get_supported_voices();

		remove_all_filters( 'wpai_tts_supported_voices' );

		$this->assertSame(
			array( array( 'v1', 'v2' ), self::PROVIDER_ID, 'voice-test-model' ),
			$received
		);
	}

	/**
	 * Tests that a malformed preferred-models entry is skipped, not fatal.
	 *
	 * @since x.x.x
	 */
	public function test_malformed_preferred_model_entry_is_skipped(): void {
		$this->connect_provider( array( 'v1' ) );

		remove_filter( 'wpai_preferred_speech_models', '__return_empty_array' );
		add_filter(
			'wpai_preferred_speech_models',
			static function (): array {
				return array(
					array( 'only-one-element' ),
					'not-an-array',
					array( 'provider', 123 ),
				);
			}
		);

		// Falls through the malformed entries to the capability scan.
		$this->assertSame( 'v1', ( new Voice_Resolver() )->get_default_voice() );

		remove_all_filters( 'wpai_preferred_speech_models' );
		add_filter( 'wpai_preferred_speech_models', '__return_empty_array' );
	}

	/**
	 * Tests that declared voices turn the Voice setting into a select.
	 *
	 * @since x.x.x
	 */
	public function test_settings_field_renders_select_for_declared_voices(): void {
		$this->connect_provider( array( 'v1', 'v2' ) );

		$fields = ( new Text_To_Speech() )->get_settings_fields();

		$this->assertCount( 1, $fields );
		$this->assertArrayHasKey( 'elements', $fields[0] );
		$this->assertSame(
			array( '', 'v1', 'v2' ),
			array_column( $fields[0]['elements'], 'value' )
		);

		// The empty choice names the voice that will actually be used.
		$this->assertStringContainsString( 'v1', $fields[0]['elements'][0]['label'] );
	}

	/**
	 * Tests that the empty choice reflects a filtered default.
	 *
	 * @since x.x.x
	 */
	public function test_settings_field_default_label_reflects_the_filter(): void {
		$this->connect_provider( array( 'v1', 'v2' ) );

		add_filter(
			'wpai_tts_default_voice',
			static function (): string {
				return 'v2';
			}
		);

		$fields = ( new Text_To_Speech() )->get_settings_fields();

		$this->assertStringContainsString( 'v2', $fields[0]['elements'][0]['label'] );
		$this->assertStringNotContainsString( 'v1', $fields[0]['elements'][0]['label'] );
	}

	/**
	 * Tests that a saved voice the provider no longer offers stays selectable and is labeled.
	 *
	 * @since x.x.x
	 */
	public function test_settings_field_marks_a_saved_voice_that_is_no_longer_offered(): void {
		$this->connect_provider( array( 'v1', 'v2' ) );
		update_option( Text_To_Speech::get_field_option_name( 'voice' ), 'retired-voice' );

		$fields   = ( new Text_To_Speech() )->get_settings_fields();
		$elements = $fields[0]['elements'];

		$this->assertSame( array( '', 'v1', 'v2', 'retired-voice' ), array_column( $elements, 'value' ) );

		$last = end( $elements );
		$this->assertNotSame( 'retired-voice', $last['label'], 'The retired voice should be labeled, not shown bare.' );
		$this->assertStringContainsString( 'retired-voice', $last['label'] );
	}

	/**
	 * Tests that the Voice setting stays a free-text field when no voices are declared.
	 *
	 * @since x.x.x
	 */
	public function test_settings_field_stays_text_when_no_voices_declared(): void {
		$this->connect_provider( null );

		$fields = ( new Text_To_Speech() )->get_settings_fields();

		$this->assertSame( 'voice', $fields[0]['id'] );
		$this->assertSame( 'text', $fields[0]['type'] );
		$this->assertArrayNotHasKey( 'elements', $fields[0] );
	}

	/**
	 * Tests that the resolved default is frozen into the job rather than resolved per chunk.
	 *
	 * @since x.x.x
	 */
	public function test_start_job_freezes_the_resolved_default_voice(): void {
		$this->connect_provider( array( 'v1', 'v2' ) );

		$post_id = self::factory()->post->create(
			array( 'post_content' => 'Some body content worth narrating.' )
		);

		$result = ( new Job_Manager() )->start_job( $post_id, 1 );
		$this->assertNotWPError( $result );

		$job = get_post_meta( $post_id, Job_Manager::META_JOB, true );
		$this->assertIsArray( $job );
		$this->assertSame( 'v1', $job['voice'] );
	}

	/**
	 * Tests that an explicitly configured voice is not overwritten by the default.
	 *
	 * @since x.x.x
	 */
	public function test_start_job_keeps_an_explicitly_configured_voice(): void {
		$this->connect_provider( array( 'v1', 'v2' ) );
		update_option( Text_To_Speech::get_field_option_name( 'voice' ), 'v2' );

		$post_id = self::factory()->post->create(
			array( 'post_content' => 'Some body content worth narrating.' )
		);

		$result = ( new Job_Manager() )->start_job( $post_id, 1 );
		$this->assertNotWPError( $result );

		$job = get_post_meta( $post_id, Job_Manager::META_JOB, true );
		$this->assertSame( 'v2', $job['voice'] );
	}

	/**
	 * Tests that the short-circuit filter sees the resolved voice, not an empty string.
	 *
	 * @since x.x.x
	 */
	public function test_pre_generate_chunk_filter_receives_the_resolved_voice(): void {
		$this->connect_provider( array( 'v1' ) );

		$seen = null;

		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function ( $pre, $text, $voice ) use ( &$seen ) {
				unset( $text );
				$seen = $voice;
				return array( 'data' => base64_encode( 'audio' ) );
			},
			10,
			3
		);

		$post_id = self::factory()->post->create(
			array( 'post_content' => 'Some body content worth narrating.' )
		);

		( new Job_Manager() )->start_job( $post_id, 1 );
		( new Job_Manager() )->process_chunk( $post_id );

		remove_all_filters( 'wpai_tts_pre_generate_chunk' );

		$this->assertSame( 'v1', $seen );
	}

	/**
	 * Tests that a provider error about a missing voice maps to an actionable error.
	 *
	 * @since x.x.x
	 */
	public function test_missing_voice_error_is_mapped_and_keeps_the_original_message(): void {
		$original = 'The outputSpeechVoice option is required for ElevenLabs text-to-speech.';
		$error    = $this->map_error( $original );

		$this->assertNotNull( $error );
		$this->assertSame( 'tts_voice_required', $error->get_error_code() );
		$this->assertStringContainsString( $original, $error->get_error_message() );
	}

	/**
	 * Tests that a rejected voice value is not reported as a missing voice.
	 *
	 * @since x.x.x
	 */
	public function test_rejected_voice_value_is_not_mapped_to_missing_voice(): void {
		$this->assertNull(
			$this->map_error( "The outputSpeechVoice value 'nope' is not supported by this model." )
		);
	}

	/**
	 * Tests that unrelated provider errors are left alone.
	 *
	 * @since x.x.x
	 */
	public function test_unrelated_errors_are_not_mapped(): void {
		$this->assertNull( $this->map_error( 'Connection timed out after 120 seconds.' ) );
	}

	/**
	 * Runs a message through Speech_Generator's voice error mapping.
	 *
	 * @since x.x.x
	 *
	 * @param string $message The provider error message.
	 * @return \WP_Error|null The mapped error, or null when the message is about something else.
	 */
	private function map_error( string $message ) {
		$method = new ReflectionMethod( Speech_Generator::class, 'maybe_voice_required_error' );
		$method->setAccessible( true );

		return $method->invoke( new Speech_Generator(), $message );
	}

	/**
	 * Registers the stub provider and an authenticated connector for it.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, mixed>|null $voice_values Voice values the stub model declares, or null.
	 */
	private function connect_provider( ?array $voice_values ): void {
		Voice_Test_Model_Metadata::$voice_values = $voice_values;

		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry ) {
			$this->markTestSkipped( 'WP_Connector_Registry is not available.' );
		}

		if ( $registry->is_registered( self::PROVIDER_ID ) ) {
			$registry->unregister( self::PROVIDER_ID );
		}

		$registry->register(
			self::PROVIDER_ID,
			array(
				'name'           => 'Voice Test Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'api_key',
				),
			)
		);

		update_option( self::KEY_OPTION, 'test-api-key' );

		$this->register_provider();
	}

	/**
	 * Adds the stub provider to the AI client registry's internal maps.
	 *
	 * Bypasses registerProvider(), which requires a fully-formed
	 * ProviderMetadata and HTTP transporter that these tests do not need.
	 *
	 * @since x.x.x
	 */
	private function register_provider(): void {
		$ai_registry = AiClient::defaultRegistry();

		$ids_to_classes = new ReflectionProperty( $ai_registry, 'registeredIdsToClassNames' );
		$ids_to_classes->setAccessible( true );
		$id_map                      = (array) $ids_to_classes->getValue( $ai_registry );
		$id_map[ self::PROVIDER_ID ] = Voice_Test_Provider::class;
		$ids_to_classes->setValue( $ai_registry, $id_map );

		$classes_to_ids = new ReflectionProperty( $ai_registry, 'registeredClassNamesToIds' );
		$classes_to_ids->setAccessible( true );
		$class_map                               = (array) $classes_to_ids->getValue( $ai_registry );
		$class_map[ Voice_Test_Provider::class ] = self::PROVIDER_ID;
		$classes_to_ids->setValue( $ai_registry, $class_map );
	}

	/**
	 * Removes the stub provider from the AI client registry's internal maps.
	 *
	 * @since x.x.x
	 */
	private function unregister_provider(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$ai_registry = AiClient::defaultRegistry();

		$ids_to_classes = new ReflectionProperty( $ai_registry, 'registeredIdsToClassNames' );
		$ids_to_classes->setAccessible( true );
		$id_map = (array) $ids_to_classes->getValue( $ai_registry );
		unset( $id_map[ self::PROVIDER_ID ] );
		$ids_to_classes->setValue( $ai_registry, $id_map );

		$classes_to_ids = new ReflectionProperty( $ai_registry, 'registeredClassNamesToIds' );
		$classes_to_ids->setAccessible( true );
		$class_map = (array) $classes_to_ids->getValue( $ai_registry );
		unset( $class_map[ Voice_Test_Provider::class ] );
		$classes_to_ids->setValue( $ai_registry, $class_map );
	}
}
