<?php
/**
 * Integration tests for the Suggest_Image_Crops Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use ReflectionClass;
use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Image\Suggest_Image_Crops;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Suggest_Image_Crops Ability tests.
 *
 * @since x.x.x
 */
class Test_Suggest_Image_Crops_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'suggest-image-crops';
	}

	/**
	 * Loads experiment metadata.
	 *
	 * @since x.x.x
	 *
	 * @return array{label: string, description: string} Experiment metadata.
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Image Crop Suggestions',
			'description' => 'Suggests a focal point and crop windows for images using AI vision models.',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Suggest_Image_Crops Ability test case.
 *
 * @since x.x.x
 */
class Suggest_Image_CropsTest extends WP_UnitTestCase {

	/**
	 * Suggest_Image_Crops ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Image\Suggest_Image_Crops
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Suggest_Image_Crops_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Suggest_Image_Crops_Experiment();
		$this->ability    = new Suggest_Image_Crops(
			'ai/suggest-image-crops',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Invokes a protected method on the ability via reflection.
	 *
	 * @since x.x.x
	 *
	 * @param string             $method_name Method name to invoke.
	 * @param array<int, mixed>  $args        Positional arguments to pass.
	 * @return mixed The method's return value.
	 */
	private function invoke( string $method_name, array $args = array() ) {
		$reflection = new ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $this->ability, $args );
	}

	/**
	 * Test that guideline_categories() returns site and images.
	 *
	 * @since x.x.x
	 */
	public function test_guideline_categories_returns_site_and_images(): void {
		$this->assertSame(
			array( 'site', 'images' ),
			$this->invoke( 'guideline_categories' )
		);
	}

	/**
	 * Test that category() returns the default ai-experiments category.
	 *
	 * @since x.x.x
	 */
	public function test_category_returns_default_category(): void {
		$this->assertSame( 'ai-experiments', $this->invoke( 'category' ) );
	}

	/**
	 * Test that input_schema() exposes the documented fields.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_returns_expected_structure(): void {
		$schema = $this->invoke( 'input_schema' );

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'image_url', $schema['properties'] );
		$this->assertArrayHasKey( 'aspect_ratios', $schema['properties'] );
		$this->assertArrayHasKey( 'context', $schema['properties'] );

		$this->assertSame( 'integer', $schema['properties']['attachment_id']['type'] );
		$this->assertSame( 'absint', $schema['properties']['attachment_id']['sanitize_callback'] );

		$this->assertSame( 'string', $schema['properties']['image_url']['type'] );
		$this->assertIsArray( $schema['properties']['image_url']['sanitize_callback'] );

		$this->assertSame( 'array', $schema['properties']['aspect_ratios']['type'] );
		$this->assertSame( 'string', $schema['properties']['aspect_ratios']['items']['type'] );
		$this->assertSame( array( '1:1', '3:4', '16:9' ), $schema['properties']['aspect_ratios']['default'] );

		$this->assertSame( 'string', $schema['properties']['context']['type'] );
		$this->assertSame( 'sanitize_textarea_field', $schema['properties']['context']['sanitize_callback'] );
	}

	/**
	 * Test that output_schema() contains the expected properties.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_returns_expected_structure(): void {
		$schema = $this->invoke( 'output_schema' );

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );

		$this->assertArrayHasKey( 'focal_point', $schema['properties'] );
		$this->assertSame( 'object', $schema['properties']['focal_point']['type'] );
		$this->assertArrayHasKey( 'x', $schema['properties']['focal_point']['properties'] );
		$this->assertArrayHasKey( 'y', $schema['properties']['focal_point']['properties'] );

		$this->assertArrayHasKey( 'crops', $schema['properties'] );
		$this->assertSame( 'array', $schema['properties']['crops']['type'] );
		$crop_props = $schema['properties']['crops']['items']['properties'];
		foreach ( array( 'aspect_ratio', 'x', 'y', 'width', 'height' ) as $key ) {
			$this->assertArrayHasKey( $key, $crop_props, "Crop schema should expose {$key}" );
		}
	}

	/**
	 * Test that meta() returns the expected structure including the MCP entry.
	 *
	 * @since x.x.x
	 */
	public function test_meta_returns_expected_structure(): void {
		$meta = $this->invoke( 'meta' );

		$this->assertIsArray( $meta );
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertIsArray( $meta['mcp'] );
		$this->assertTrue( $meta['mcp']['public'] );
		$this->assertSame( 'tool', $meta['mcp']['type'] );
		$this->assertSame( 'media', $meta['mcp']['category'] );
	}

	/**
	 * Test that get_system_instruction() interpolates the requested aspect ratios.
	 *
	 * @since x.x.x
	 */
	public function test_system_instruction_includes_requested_aspect_ratios(): void {
		$instruction = $this->ability->get_system_instruction(
			'suggest-image-crops-system-instruction.php',
			array( 'aspect_ratios' => array( '1:1', '4:5', '21:9' ) )
		);

		$this->assertIsString( $instruction );
		$this->assertNotEmpty( $instruction );
		$this->assertStringContainsString( 'Requested aspect ratios: 1:1, 4:5, 21:9', $instruction );
	}

	/**
	 * Test that get_system_instruction() falls back to defaults when no ratios are passed.
	 *
	 * @since x.x.x
	 */
	public function test_system_instruction_falls_back_to_default_aspect_ratios(): void {
		$instruction = $this->ability->get_system_instruction( 'suggest-image-crops-system-instruction.php' );

		$this->assertStringContainsString( 'Requested aspect ratios: 1:1, 3:4, 16:9', $instruction );
	}

	/**
	 * Test that execute_callback() returns no_image_provided when no input is supplied.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_no_image_provided(): void {
		$result = $this->invoke( 'execute_callback', array( array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_image_provided', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() returns invalid_attachment for a missing attachment id.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_invalid_attachment(): void {
		$result = $this->invoke( 'execute_callback', array( array( 'attachment_id' => 99999 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_attachment', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() returns not_an_image for non-image attachments.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_not_an_image(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Test non-image attachment',
				'post_mime_type' => 'text/plain',
			),
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create non-image attachment for test' );
			return;
		}

		$result = $this->invoke( 'execute_callback', array( array( 'attachment_id' => $attachment_id ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_an_image', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() rejects an aspect_ratios input where every entry is invalid.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_rejects_only_invalid_aspect_ratios(): void {
		// Use a data URI so image resolution succeeds and the aspect-ratio check is what fails.
		$data_uri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

		$result = $this->invoke(
			'execute_callback',
			array(
				array(
					'image_url'     => $data_uri,
					'aspect_ratios' => array( 'square', '0:1', 'foo:bar' ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_aspect_ratios', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() grants access for an admin with edit_post on an attachment.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_attachment_id_and_edit_capability(): void {
		$attachment_id = $this->factory->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( $this->invoke( 'permission_callback', array( array( 'attachment_id' => $attachment_id ) ) ) );
	}

	/**
	 * Test that permission_callback() denies access without edit_post on the attachment.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_attachment_id_without_edit_capability(): void {
		$attachment_id = $this->factory->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->invoke( 'permission_callback', array( array( 'attachment_id' => $attachment_id ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() reports attachment_not_found for a missing id.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_nonexistent_attachment_id(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = $this->invoke( 'permission_callback', array( array( 'attachment_id' => 99999 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'attachment_not_found', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() allows users with upload_files for image_url input.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_image_url_and_upload_files(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue(
			$this->invoke( 'permission_callback', array( array( 'image_url' => 'https://example.com/image.jpg' ) ) )
		);
	}

	/**
	 * Test that permission_callback() denies image_url input without upload_files.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_image_url_without_upload_files(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->invoke( 'permission_callback', array( array( 'image_url' => 'https://example.com/image.jpg' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test that normalize_aspect_ratios() accepts a comma-separated string.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_aspect_ratios_accepts_comma_string(): void {
		$result = $this->invoke( 'normalize_aspect_ratios', array( '1:1, 4:5 , 16:9' ) );

		$this->assertSame( array( '1:1', '4:5', '16:9' ), $result );
	}

	/**
	 * Test that normalize_aspect_ratios() drops invalid entries and dedupes.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_aspect_ratios_drops_invalid_and_dedupes(): void {
		$result = $this->invoke(
			'normalize_aspect_ratios',
			array( array( '1:1', 'square', '0:1', '1:0', '4:5', '4:5', '16:9' ) )
		);

		$this->assertSame( array( '1:1', '4:5', '16:9' ), $result );
	}

	/**
	 * Test that normalize_aspect_ratios() returns empty array for unsupported input types.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_aspect_ratios_rejects_non_iterable(): void {
		$this->assertSame( array(), $this->invoke( 'normalize_aspect_ratios', array( 42 ) ) );
		$this->assertSame( array(), $this->invoke( 'normalize_aspect_ratios', array( null ) ) );
	}

	/**
	 * Test that parse_suggestions() returns a clean shape for a well-formed response.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_returns_valid_shape(): void {
		$response = wp_json_encode(
			array(
				'focal_point' => array(
					'x' => 0.4,
					'y' => 0.55,
				),
				'crops'       => array(
					array(
						'aspect_ratio' => '1:1',
						'x'            => 0.1,
						'y'            => 0.1,
						'width'        => 0.6,
						'height'       => 0.6,
					),
					array(
						'aspect_ratio' => '16:9',
						'x'            => 0.0,
						'y'            => 0.2,
						'width'        => 1.0,
						'height'       => 0.5,
					),
				),
			)
		);

		$result = $this->invoke( 'parse_suggestions', array( $response, array( '1:1', '16:9' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0.4, $result['focal_point']['x'] );
		$this->assertSame( 0.55, $result['focal_point']['y'] );
		$this->assertCount( 2, $result['crops'] );
		$this->assertSame( '1:1', $result['crops'][0]['aspect_ratio'] );
		$this->assertSame( '16:9', $result['crops'][1]['aspect_ratio'] );
		$this->assertArrayNotHasKey( 'subjects', $result );
	}

	/**
	 * Test that parse_suggestions() returns invalid_response for unparseable JSON.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_with_invalid_json(): void {
		$result = $this->invoke( 'parse_suggestions', array( 'not json', array( '1:1' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_response', $result->get_error_code() );
	}

	/**
	 * Test that parse_suggestions() returns invalid_focal_point when missing.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_requires_focal_point(): void {
		$response = wp_json_encode(
			array(
				'crops' => array(
					array(
						'aspect_ratio' => '1:1',
						'x'            => 0.0,
						'y'            => 0.0,
						'width'        => 1.0,
						'height'       => 1.0,
					),
				),
			)
		);

		$result = $this->invoke( 'parse_suggestions', array( $response, array( '1:1' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_focal_point', $result->get_error_code() );
	}

	/**
	 * Test that parse_suggestions() clamps coordinates that exceed [0, 1].
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_clamps_focal_point(): void {
		$response = wp_json_encode(
			array(
				'focal_point' => array(
					'x' => 1.4,
					'y' => -0.2,
				),
				'crops'       => array(
					array(
						'aspect_ratio' => '1:1',
						'x'            => 0.0,
						'y'            => 0.0,
						'width'        => 1.0,
						'height'       => 1.0,
					),
				),
			)
		);

		$result = $this->invoke( 'parse_suggestions', array( $response, array( '1:1' ) ) );

		$this->assertSame( 1.0, $result['focal_point']['x'] );
		$this->assertSame( 0.0, $result['focal_point']['y'] );
	}

	/**
	 * Test that parse_suggestions() drops crops whose aspect_ratio was not requested.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_drops_unrequested_aspect_ratio(): void {
		$response = wp_json_encode(
			array(
				'focal_point' => array(
					'x' => 0.5,
					'y' => 0.5,
				),
				'crops'       => array(
					array(
						'aspect_ratio' => '1:1',
						'x'            => 0.0,
						'y'            => 0.0,
						'width'        => 1.0,
						'height'       => 1.0,
					),
					array(
						'aspect_ratio' => '21:9',
						'x'            => 0.0,
						'y'            => 0.0,
						'width'        => 1.0,
						'height'       => 0.5,
					),
				),
			)
		);

		$result = $this->invoke( 'parse_suggestions', array( $response, array( '1:1' ) ) );

		$this->assertCount( 1, $result['crops'] );
		$this->assertSame( '1:1', $result['crops'][0]['aspect_ratio'] );
	}

	/**
	 * Test that parse_suggestions() drops crops where the focal point falls outside the window.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_drops_crop_outside_focal_point(): void {
		$response = wp_json_encode(
			array(
				'focal_point' => array(
					'x' => 0.9,
					'y' => 0.9,
				),
				'crops'       => array(
					array(
						'aspect_ratio' => '1:1',
						'x'            => 0.0,
						'y'            => 0.0,
						'width'        => 0.4,
						'height'       => 0.4,
					),
				),
			)
		);

		$result = $this->invoke( 'parse_suggestions', array( $response, array( '1:1' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_valid_crops', $result->get_error_code() );
	}

	/**
	 * Test that parse_suggestions() clamps crop dimensions that exceed the image bounds.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_clamps_oversized_crop(): void {
		$response = wp_json_encode(
			array(
				'focal_point' => array(
					'x' => 0.5,
					'y' => 0.5,
				),
				'crops'       => array(
					array(
						'aspect_ratio' => '1:1',
						'x'            => 0.4,
						'y'            => 0.4,
						'width'        => 1.0,
						'height'       => 1.0,
					),
				),
			)
		);

		$result = $this->invoke( 'parse_suggestions', array( $response, array( '1:1' ) ) );

		$this->assertCount( 1, $result['crops'] );
		$crop = $result['crops'][0];
		$this->assertEqualsWithDelta( 0.6, $crop['width'], 0.0001 );
		$this->assertEqualsWithDelta( 0.6, $crop['height'], 0.0001 );
	}

	/**
	 * Test that parse_suggestions() drops crops with zero or negative dimensions.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_drops_zero_size_crop(): void {
		$response = wp_json_encode(
			array(
				'focal_point' => array(
					'x' => 0.5,
					'y' => 0.5,
				),
				'crops'       => array(
					array(
						'aspect_ratio' => '1:1',
						'x'            => 0.5,
						'y'            => 0.5,
						'width'        => 0.0,
						'height'       => 0.5,
					),
				),
			)
		);

		$result = $this->invoke( 'parse_suggestions', array( $response, array( '1:1' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_valid_crops', $result->get_error_code() );
	}
}
