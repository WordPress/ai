<?php
/**
 * Integration tests for the core/media-upload Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Media
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Media;

use MockAction;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WordPress\AI\Abilities\Media\Media;

/**
 * Media upload ability test case.
 *
 * Tests named like core's REST attachments controller tests port them to the ability. A
 * core test that sends the file as the request body sends it here as base64 data.
 *
 * @since x.x.x
 */
class MediaUploadTest extends Media_Ability_TestCase {

	/**
	 * The URLs requested by the mocked downloads.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private array $downloads = array();

	/**
	 * The arguments of the most recent mocked download.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, mixed>
	 */
	private array $download_args = array();

	/**
	 * Uploads a file through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return mixed The ability result.
	 */
	private function upload( $input ) {
		return $this->execute_ability( 'core/media-upload', $input );
	}

	/**
	 * Returns the input that uploads the JPEG test image, merged with further input.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input Optional. Further input. Default empty array.
	 * @return array<string, mixed> The ability input.
	 */
	private function get_upload_input( array $input = array() ): array {
		return array_merge(
			array(
				'data'     => $this->encode_file( self::$test_file ),
				'filename' => 'canola.jpg',
			),
			$input
		);
	}

	/**
	 * Returns the files in the current uploads folder.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The file paths.
	 */
	private function get_uploaded_files(): array {
		$files = glob( trailingslashit( wp_get_upload_dir()['path'] ) . '*' );

		return false === $files ? array() : $files;
	}

	/**
	 * Returns the IDs of the attachments, up to ten.
	 *
	 * @since x.x.x
	 *
	 * @return list<int> The attachment IDs.
	 */
	private function get_attachment_ids(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 10,
			)
		);

		return $query->posts;
	}

	/**
	 * Short-circuits download_url()'s HTTP request, writing the JPEG test image into the file
	 * the request would have streamed to.
	 *
	 * @since x.x.x
	 *
	 * @param false|array<string, mixed>|\WP_Error $response A preempted response, or false to continue.
	 * @param array<string, mixed>                 $args     HTTP request arguments.
	 * @param string                               $url      The request URL.
	 * @return array<string, mixed> A faked 200 response.
	 */
	public function mock_image_download( $response, $args, $url ): array {
		$this->downloads[]   = $url;
		$this->download_args = $args;

		if ( ! empty( $args['filename'] ) ) {
			copy( DIR_TESTDATA . '/images/canola.jpg', $args['filename'] );
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(),
			'cookies'  => array(),
			'body'     => '',
		);
	}

	/**
	 * Fails any download, recording that one was attempted.
	 *
	 * @since x.x.x
	 *
	 * @param false|array<string, mixed>|\WP_Error $response A preempted response, or false to continue.
	 * @param array<string, mixed>                 $args     HTTP request arguments.
	 * @param string                               $url      The request URL.
	 * @return \WP_Error The failure.
	 */
	public function fail_download( $response, $args, $url ): WP_Error {
		$this->downloads[] = $url;

		return new WP_Error( 'http_request_failed', 'Could not resolve host.' );
	}

	/**
	 * Uploads with the given input, then updates the attachment with the same input, and
	 * checks the stored and returned text fields after each.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string>                $input           The title, description, and caption.
	 * @param array<string, array<string, string>> $expected_output The expected raw and rendered values.
	 */
	private function verify_attachment_roundtrip( array $input, array $expected_output ): void {
		$fields = array( 'id', 'title_raw', 'title_rendered', 'description_raw', 'description_rendered', 'caption_raw', 'caption_rendered' );

		// Create the post.
		$actual_output = $this->upload( $this->get_upload_input( array_merge( $input, array( 'fields' => $fields ) ) ) );
		$this->assertIsArray( $actual_output );
		$this->check_roundtrip_output( $expected_output, $actual_output );

		// Update the post.
		$actual_output = $this->execute_ability( 'core/media-update', array_merge( array( 'id' => $actual_output['id'] ), $input, array( 'fields' => $fields ) ) );
		$this->assertIsArray( $actual_output );
		$this->check_roundtrip_output( $expected_output, $actual_output );
	}

	/**
	 * Compares a round-tripped attachment with the expected values and the stored post.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array<string, string>> $expected_output The expected raw and rendered values.
	 * @param array<string, mixed>                 $actual_output   The returned fields.
	 */
	private function check_roundtrip_output( array $expected_output, array $actual_output ): void {
		// Remove <p class="attachment"> from rendered description.
		// See https://core.trac.wordpress.org/ticket/38679
		$content = explode( "\n", trim( $actual_output['description_rendered'] ) );
		if ( preg_match( '/^<p class="attachment">/', $content[0] ) ) {
			$actual_output['description_rendered'] = implode( "\n", array_slice( $content, 1 ) );
		}

		// Compare expected API output to actual API output.
		$this->assertSame( $expected_output['title']['raw'], $actual_output['title_raw'] );
		$this->assertSame( $expected_output['title']['rendered'], trim( $actual_output['title_rendered'] ) );
		$this->assertSame( $expected_output['description']['raw'], $actual_output['description_raw'] );
		$this->assertSame( $expected_output['description']['rendered'], trim( $actual_output['description_rendered'] ) );
		$this->assertSame( $expected_output['caption']['raw'], $actual_output['caption_raw'] );
		$this->assertSame( $expected_output['caption']['rendered'], trim( $actual_output['caption_rendered'] ) );

		// Compare expected API output to WP internal values.
		$post = get_post( $actual_output['id'] );
		$this->assertSame( $expected_output['title']['raw'], $post->post_title );
		$this->assertSame( $expected_output['description']['raw'], $post->post_content );
		$this->assertSame( $expected_output['caption']['raw'], $post->post_excerpt );
	}

	/**
	 * The ability is registered in the `content` category as a non-destructive write that
	 * reaches other sites, with a data mode and a URL mode that reject each other's input.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_media_upload_ability(): void {
		$ability     = wp_get_ability( 'core/media-upload' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();
		$output      = $ability->get_output_schema();
		$query_item  = wp_get_ability( 'core/media-query' )->get_output_schema()['oneOf'][0];

		$this->assertSame( 'Media Upload', $ability->get_label(), 'The ability should use the expected label.' );
		$this->assertSame( 'content', $ability->get_category(), 'The ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'An upload only adds an attachment.' );
		$this->assertFalse( $annotations['idempotent'], 'Every upload creates a new attachment.' );
		$this->assertTrue( $annotations['open_world'], 'A URL upload downloads the file from another site.' );

		$this->assertSame( 'object', $schema['type'], 'The input should be an object.' );
		$this->assertCount( 2, $schema['oneOf'], 'The input should have two modes.' );

		[ $data, $url ] = $schema['oneOf'];

		$this->assertSame( array( 'data', 'filename' ), $data['required'], 'The data mode should require the data and the file name.' );
		$this->assertFalse( $data['additionalProperties'], 'The data mode should reject other properties.' );
		$this->assertSame(
			array( 'data', 'filename', 'title', 'caption', 'description', 'alt_text', 'post', 'author', 'status', 'slug', 'date', 'date_gmt', 'fields' ),
			array_keys( $data['properties'] ),
			'The data mode should take the file and the attachment fields.'
		);
		$this->assertSame( array( 'url' ), $url['required'], 'The URL mode should require the URL.' );
		$this->assertFalse( $url['additionalProperties'], 'The URL mode should reject other properties.' );
		$this->assertSame( array( 'url', 'post', 'fields' ), array_keys( $url['properties'] ), 'The URL mode should only take the parent post and the field selection.' );
		$this->assertArrayNotHasKey( 'format', $url['properties']['url'], 'The URL should not use a format the client does not know.' );

		$this->assertFalse( $output['additionalProperties'], 'The output should reject unknown properties.' );
		$this->assertSame( wp_list_pluck( $query_item['properties'], 'type' ), wp_list_pluck( $output['properties'], 'type' ), 'An uploaded attachment should have the same fields as a queried one.' );
	}

	/**
	 * Registering the abilities replaces a write ability that was registered before.
	 *
	 * @dataProvider data_write_abilities
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The ability name.
	 * @param string $label        The plugin's label for the ability.
	 */
	public function test_override_replaces_an_existing_write_ability( string $ability_name, string $label ): void {
		wp_unregister_ability( $ability_name );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				$ability_name,
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided media ability.',
					'category'            => 'content',
					'execute_callback'    => static function (): array {
						return array();
					},
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->assertSame( 'Core Provided', wp_get_ability( $ability_name )->get_label(), 'The core-provided ability should be registered first.' );

		$this->register_ability();

		$this->assertSame( $label, wp_get_ability( $ability_name )->get_label(), 'The plugin-provided ability should replace it.' );
	}

	/**
	 * Provides the write abilities and their labels.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: string}> The ability names and labels.
	 */
	public function data_write_abilities(): array {
		return array(
			'upload' => array( 'core/media-upload', 'Media Upload' ),
			'update' => array( 'core/media-update', 'Media Update' ),
			'delete' => array( 'core/media-delete', 'Media Delete' ),
		);
	}

	/**
	 * Uploads a file with its title, caption, description, and alt text.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_create_item(): void {
		$this->login_as( 'author' );

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'title'       => 'My title is very cool',
					'caption'     => 'This is a better caption.',
					'description' => 'Without a description, my attachment is descriptionless.',
					'alt_text'    => 'Alt text is stored outside post schema.',
					'fields'      => array( 'id', 'media_type', 'title_raw', 'caption_raw', 'description_raw', 'alt_text' ),
				)
			)
		);

		$this->assertIsArray( $data );
		$this->assertSame( 'image', $data['media_type'] );

		$attachment = get_post( $data['id'] );
		$this->assertSame( 'My title is very cool', $data['title_raw'] );
		$this->assertSame( 'My title is very cool', $attachment->post_title );
		$this->assertSame( 'This is a better caption.', $data['caption_raw'] );
		$this->assertSame( 'This is a better caption.', $attachment->post_excerpt );
		$this->assertSame( 'Without a description, my attachment is descriptionless.', $data['description_raw'] );
		$this->assertSame( 'Without a description, my attachment is descriptionless.', $attachment->post_content );
		$this->assertSame( 'Alt text is stored outside post schema.', $data['alt_text'] );
		$this->assertSame( 'Alt text is stored outside post schema.', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Titles an upload without a title after its file name.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_default_filename_title(): void {
		$this->login_as( 'author' );

		$data = $this->upload(
			array(
				'data'     => $this->encode_file( self::$test_file2 ),
				'filename' => 'codeispoetry.png',
				'fields'   => array( 'title_raw' ),
			)
		);

		$this->assertIsArray( $data );
		$this->assertSame( 'codeispoetry', $data['title_raw'] );
	}

	/**
	 * A user who can upload files but not edit posts can upload.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_create_item_with_upload_files_role(): void {
		$this->login_as( 'uploader' );

		$this->assertIsArray( $this->upload( $this->get_upload_input() ) );
	}

	/**
	 * Empty data is an error.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_empty_body(): void {
		$this->login_as( 'author' );

		$this->assertErrorResponse( 'media_upload_no_data', $this->upload( $this->get_upload_input( array( 'data' => '' ) ) ), 400 );
	}

	/**
	 * A user who cannot upload files is denied.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_invalid_upload_files_capability(): void {
		$this->login_as( 'contributor' );

		$this->assertErrorResponse( 'ability_invalid_permissions', $this->upload( $this->get_upload_input() ) );
	}

	/**
	 * An author cannot attach an upload to another user's post.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_invalid_edit_permissions(): void {
		$post_id = self::factory()->post->create( array( 'post_author' => self::$user_ids['editor'] ) );
		$this->login_as( 'author' );

		$this->assertErrorResponse( 'media_cannot_edit', $this->upload( $this->get_upload_input( array( 'post' => $post_id ) ) ), 403 );
	}

	/**
	 * A user who can upload files but not edit posts cannot attach an upload to a post.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_invalid_upload_permissions(): void {
		$post_id = self::factory()->post->create( array( 'post_author' => self::$user_ids['editor'] ) );
		$this->login_as( 'uploader' );

		$this->assertErrorResponse( 'media_cannot_edit', $this->upload( $this->get_upload_input( array( 'post' => $post_id ) ) ), 403 );
	}

	/**
	 * An attachment cannot be the parent of an upload.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_invalid_post_type(): void {
		$attachment_id = self::factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 0,
			)
		);
		$this->login_as( 'editor' );

		$this->assertErrorResponse( 'media_invalid_param', $this->upload( $this->get_upload_input( array( 'post' => $attachment_id ) ) ), 400 );
	}

	/**
	 * Stores the alt text of an upload.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_create_item_alt_text(): void {
		$this->login_as( 'author' );

		$attachment = $this->upload(
			$this->get_upload_input(
				array(
					'alt_text' => 'test alt text',
					'fields'   => array( 'alt_text' ),
				)
			)
		);

		$this->assertSame( 'test alt text', $attachment['alt_text'] );
	}

	/**
	 * Strips markup from the alt text of an upload.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_create_item_unsafe_alt_text(): void {
		$this->login_as( 'author' );

		$attachment = $this->upload(
			$this->get_upload_input(
				array(
					'alt_text' => '<script>alert(document.cookie)</script>',
					'fields'   => array( 'alt_text' ),
				)
			)
		);

		$this->assertSame( '', $attachment['alt_text'] );
	}

	/**
	 * Stores the attached file relative to the uploads folder.
	 *
	 * @ticket 40861
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_create_item_ensure_relative_path(): void {
		$this->login_as( 'author' );

		$attachment = $this->upload( $this->get_upload_input() );

		$this->assertStringNotContainsString( ABSPATH, get_post_meta( $attachment['id'], '_wp_attached_file', true ) );
	}

	/**
	 * Round-trips text fields as a user without unfiltered HTML.
	 *
	 * @dataProvider data_attachment_roundtrip_as_author
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 *
	 * @param array<string, string>                $raw      The title, description, and caption.
	 * @param array<string, array<string, string>> $expected The expected raw and rendered values.
	 */
	public function test_attachment_roundtrip_as_author( array $raw, array $expected ): void {
		$this->login_as( 'author' );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->verify_attachment_roundtrip( $raw, $expected );
	}

	/**
	 * Provides text field inputs and the values stored for a user without unfiltered HTML.
	 *
	 * @since x.x.x
	 *
	 * @return list<array{0: array<string, string>, 1: array<string, array<string, string>>}> Inputs and expected values.
	 */
	public function data_attachment_roundtrip_as_author(): array {
		return array(
			array(
				// Raw values.
				array(
					'title'       => '\o/ ¯\_(ツ)_/¯',
					'description' => '\o/ ¯\_(ツ)_/¯',
					'caption'     => '\o/ ¯\_(ツ)_/¯',
				),
				// Expected returned values.
				array(
					'title'       => array(
						'raw'      => '\o/ ¯\_(ツ)_/¯',
						'rendered' => '\o/ ¯\_(ツ)_/¯',
					),
					'description' => array(
						'raw'      => '\o/ ¯\_(ツ)_/¯',
						'rendered' => '<p>\o/ ¯\_(ツ)_/¯</p>',
					),
					'caption'     => array(
						'raw'      => '\o/ ¯\_(ツ)_/¯',
						'rendered' => '<p>\o/ ¯\_(ツ)_/¯</p>',
					),
				),
			),
			array(
				// Raw values.
				array(
					'title'       => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
					'description' => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
					'caption'     => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
				),
				// Expected returned values.
				array(
					'title'       => array(
						'raw'      => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
						'rendered' => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
					),
					'description' => array(
						'raw'      => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
						'rendered' => '<p>\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;</p>',
					),
					'caption'     => array(
						'raw'      => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
						'rendered' => '<p>\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;</p>',
					),
				),
			),
			array(
				// Raw values.
				array(
					'title'       => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'caption'     => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				),
				// Expected returned values.
				array(
					'title'       => array(
						'raw'      => 'div <strong>strong</strong> oh noes',
						'rendered' => 'div <strong>strong</strong> oh noes',
					),
					'description' => array(
						'raw'      => '<div>div</div> <strong>strong</strong> oh noes',
						'rendered' => "<div>div</div>\n<p> <strong>strong</strong> oh noes</p>",
					),
					'caption'     => array(
						'raw'      => '<div>div</div> <strong>strong</strong> oh noes',
						'rendered' => "<div>div</div>\n<p> <strong>strong</strong> oh noes</p>",
					),
				),
			),
			array(
				// Raw values.
				array(
					'title'       => '<a href="#" target="_blank" unfiltered=true>link</a>',
					'description' => '<a href="#" target="_blank" unfiltered=true>link</a>',
					'caption'     => '<a href="#" target="_blank" unfiltered=true>link</a>',
				),
				// Expected returned values.
				array(
					'title'       => array(
						'raw'      => '<a href="#">link</a>',
						'rendered' => '<a href="#">link</a>',
					),
					'description' => array(
						'raw'      => '<a href="#" target="_blank">link</a>',
						'rendered' => '<p><a href="#" target="_blank">link</a></p>',
					),
					'caption'     => array(
						'raw'      => '<a href="#" target="_blank">link</a>',
						'rendered' => '<p><a href="#" target="_blank">link</a></p>',
					),
				),
			),
		);
	}

	/**
	 * Round-trips text fields as an editor, who has unfiltered HTML on a single site only.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_attachment_roundtrip_as_editor_unfiltered_html(): void {
		$this->login_as( 'editor' );
		if ( is_multisite() ) {
			$this->assertFalse( current_user_can( 'unfiltered_html' ) );
			$this->verify_attachment_roundtrip(
				array(
					'title'       => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'caption'     => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				),
				array(
					'title'       => array(
						'raw'      => 'div <strong>strong</strong> oh noes',
						'rendered' => 'div <strong>strong</strong> oh noes',
					),
					'description' => array(
						'raw'      => '<div>div</div> <strong>strong</strong> oh noes',
						'rendered' => "<div>div</div>\n<p> <strong>strong</strong> oh noes</p>",
					),
					'caption'     => array(
						'raw'      => '<div>div</div> <strong>strong</strong> oh noes',
						'rendered' => "<div>div</div>\n<p> <strong>strong</strong> oh noes</p>",
					),
				)
			);
		} else {
			$this->assertTrue( current_user_can( 'unfiltered_html' ) );
			$this->verify_attachment_roundtrip(
				array(
					'title'       => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'caption'     => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				),
				array(
					'title'       => array(
						'raw'      => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
						'rendered' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					),
					'description' => array(
						'raw'      => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
						'rendered' => "<div>div</div>\n<p> <strong>strong</strong> <script>oh noes</script></p>",
					),
					'caption'     => array(
						'raw'      => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
						'rendered' => "<div>div</div>\n<p> <strong>strong</strong> <script>oh noes</script></p>",
					),
				)
			);
		}
	}

	/**
	 * Round-trips text fields as a super admin, who has unfiltered HTML.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_attachment_roundtrip_as_superadmin_unfiltered_html(): void {
		$superadmin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $superadmin_id );
		}
		wp_set_current_user( $superadmin_id );

		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		$this->verify_attachment_roundtrip(
			array(
				'title'       => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'caption'     => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
			),
			array(
				'title'       => array(
					'raw'      => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'rendered' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				),
				'description' => array(
					'raw'      => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'rendered' => "<div>div</div>\n<p> <strong>strong</strong> <script>oh noes</script></p>",
				),
				'caption'     => array(
					'raw'      => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'rendered' => "<div>div</div>\n<p> <strong>strong</strong> <script>oh noes</script></p>",
				),
			)
		);
	}

	/**
	 * Rejects an upload larger than the network's maximum file size.
	 *
	 * @ticket 43751
	 * @group multisite
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_create_item_with_data_exceeds_multisite_max_filesize(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->login_as( 'author' );
		update_site_option( 'fileupload_maxk', 1 );
		update_site_option( 'upload_space_check_disabled', false );

		$result = $this->upload(
			$this->get_upload_input(
				array(
					'title'   => 'My title is very cool',
					'caption' => 'This is a better caption.',
				)
			)
		);

		$this->assertErrorResponse( 'media_upload_file_too_big', $result, 400 );
	}

	/**
	 * Rejects an upload larger than the site's remaining upload space.
	 *
	 * @ticket 43751
	 * @group multisite
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_create_item_with_data_exceeds_multisite_site_upload_space(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->login_as( 'author' );
		add_filter( 'get_space_allowed', '__return_zero' );
		update_site_option( 'upload_space_check_disabled', false );

		$result = $this->upload(
			$this->get_upload_input(
				array(
					'title'   => 'My title is very cool',
					'caption' => 'This is a better caption.',
				)
			)
		);

		$this->assertErrorResponse( 'media_upload_limited_space', $result, 400 );
	}

	/**
	 * Stores an upload attached to a post in the folder of the post's month.
	 *
	 * @ticket 61189
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_create_item_year_month_based_folders(): void {
		update_option( 'uploads_use_yearmonth_folders', 1 );

		$this->login_as( 'editor' );

		$published_post = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date'     => '2017-02-14 00:00:00',
				'post_date_gmt' => '2017-02-14 00:00:00',
			)
		);

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'title'       => 'My title is very cool',
					'caption'     => 'This is a better caption.',
					'description' => 'Without a description, my attachment is descriptionless.',
					'alt_text'    => 'Alt text is stored outside post schema.',
					'post'        => $published_post,
					'fields'      => array( 'post', 'source_url' ),
				)
			)
		);

		update_option( 'uploads_use_yearmonth_folders', 0 );

		$this->assertIsArray( $data );

		$attachment = get_post( $data['id'] );

		$this->assertSame( $attachment->post_parent, $data['post'] );
		$this->assertSame( $attachment->post_parent, $published_post );
		$this->assertSame( wp_get_attachment_url( $attachment->ID ), $data['source_url'] );
		$this->assertStringContainsString( '2017/02', $data['source_url'] );
	}

	/**
	 * Stores an upload attached to a page in the current month's folder.
	 *
	 * @ticket 61189
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_create_item_year_month_based_folders_page_post_type(): void {
		update_option( 'uploads_use_yearmonth_folders', 1 );

		$this->login_as( 'editor' );

		$published_post = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_date'     => '2017-02-14 00:00:00',
				'post_date_gmt' => '2017-02-14 00:00:00',
			)
		);

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'title'       => 'My title is very cool',
					'caption'     => 'This is a better caption.',
					'description' => 'Without a description, my attachment is descriptionless.',
					'alt_text'    => 'Alt text is stored outside post schema.',
					'post'        => $published_post,
					'fields'      => array( 'post', 'source_url' ),
				)
			)
		);

		update_option( 'uploads_use_yearmonth_folders', 0 );

		$time   = current_time( 'mysql' );
		$y      = substr( $time, 0, 4 );
		$m      = substr( $time, 5, 2 );
		$subdir = "/$y/$m";

		$this->assertIsArray( $data );

		$attachment = get_post( $data['id'] );

		$this->assertSame( $attachment->post_parent, $data['post'] );
		$this->assertSame( $attachment->post_parent, $published_post );
		$this->assertSame( wp_get_attachment_url( $attachment->ID ), $data['source_url'] );
		$this->assertStringNotContainsString( '2017/02', $data['source_url'] );
		$this->assertStringContainsString( $subdir, $data['source_url'] );
	}

	/**
	 * A URL upload generates the image sub-sizes.
	 *
	 * @ticket 65517
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_create_item_from_url_generates_subsizes_by_default(): void {
		$this->login_as( 'editor' );

		add_filter( 'pre_http_request', array( $this, 'mock_image_download' ), 10, 3 );

		$data = $this->upload( array( 'url' => 'https://example.com/full.jpg' ) );

		$this->assertIsArray( $data );

		$metadata = wp_get_attachment_metadata( $data['id'], true );
		$this->assertNotEmpty( $metadata['sizes'] ?? array(), 'Sub-sizes should be generated.' );
	}

	/**
	 * A URL upload is attached to the given post.
	 *
	 * @ticket 65517
	 *
	 * @since x.x.x
	 */
	public function test_create_item_from_url_attaches_to_post(): void {
		$this->login_as( 'editor' );

		$parent_post = self::factory()->post->create();

		add_filter( 'pre_http_request', array( $this, 'mock_image_download' ), 10, 3 );

		$data = $this->upload(
			array(
				'url'  => 'https://example.com/attached.jpg',
				'post' => $parent_post,
			)
		);

		$this->assertIsArray( $data );
		$this->assertSame( $parent_post, get_post( $data['id'] )->post_parent );
	}

	/**
	 * A failed download returns its error and creates no attachment.
	 *
	 * @ticket 65517
	 *
	 * @since x.x.x
	 */
	public function test_create_item_from_url_returns_error_on_download_failure(): void {
		$this->login_as( 'editor' );

		add_filter( 'pre_http_request', array( $this, 'fail_download' ), 10, 3 );

		$result = $this->upload( array( 'url' => 'https://example.com/missing.jpg' ) );

		// download_url()'s error is returned as it is.
		$this->assertErrorResponse( 'http_request_failed', $result );
		$this->assertSame( array(), $this->get_attachment_ids() );
	}

	/**
	 * A URL upload larger than the network's maximum file size is rejected.
	 *
	 * @ticket 65517
	 * @group multisite
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_create_item_from_url_exceeds_multisite_max_filesize(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->login_as( 'editor' );
		update_site_option( 'fileupload_maxk', 1 );
		update_site_option( 'upload_space_check_disabled', false );

		// Ensure ample space is available so the file-size limit is what rejects it.
		add_filter( 'pre_get_space_used', '__return_zero' );
		add_filter( 'pre_http_request', array( $this, 'mock_image_download' ), 10, 3 );

		$result = $this->upload( array( 'url' => 'https://example.com/too-big.jpg' ) );

		$this->assertErrorResponse( 'media_upload_file_too_big', $result, 400 );
	}

	/**
	 * A URL upload larger than the site's remaining upload space is rejected.
	 *
	 * @ticket 65517
	 * @group multisite
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_create_item_from_url_exceeds_multisite_site_upload_space(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->login_as( 'editor' );
		add_filter( 'get_space_allowed', '__return_zero' );
		update_site_option( 'upload_space_check_disabled', false );

		add_filter( 'pre_http_request', array( $this, 'mock_image_download' ), 10, 3 );

		$result = $this->upload( array( 'url' => 'https://example.com/no-space.jpg' ) );

		$this->assertErrorResponse( 'media_upload_limited_space', $result, 400 );
	}

	/**
	 * A URL upload larger than the site's maximum upload size is rejected on a single site
	 * too, and its downloaded file is removed.
	 *
	 * @ticket 65517
	 *
	 * @since x.x.x
	 */
	public function test_create_item_from_url_exceeds_max_upload_size(): void {
		$this->login_as( 'editor' );

		// The fixture the download is mocked with is comfortably larger than this.
		add_filter( 'upload_size_limit', array( $this, 'filter_small_upload_size_limit' ), 20 );
		add_filter( 'pre_http_request', array( $this, 'mock_image_download' ), 10, 3 );

		$result = $this->upload( array( 'url' => 'https://example.com/too-big.jpg' ) );

		$this->assertErrorResponse( 'media_upload_file_too_big', $result, 400 );
		$this->assertFileDoesNotExist( $this->download_args['filename'], 'The downloaded file should be removed.' );
	}

	/**
	 * The download is capped one byte past the maximum upload size.
	 *
	 * @ticket 65517
	 *
	 * @since x.x.x
	 */
	public function test_create_item_from_url_limits_the_download_size(): void {
		$this->login_as( 'editor' );

		add_filter( 'pre_http_request', array( $this, 'mock_image_download' ), 10, 3 );

		$this->upload( array( 'url' => 'https://example.com/photo.jpg' ) );

		$this->assertNotSame( array(), $this->download_args, 'The download request should have been made.' );
		$this->assertSame(
			(int) wp_max_upload_size() + 1,
			$this->download_args['limit_response_size'],
			'The download should be capped one byte past the maximum upload size.'
		);
	}

	/**
	 * The download size cap is removed even when a hook throws during the download.
	 *
	 * @since x.x.x
	 */
	public function test_url_upload_removes_the_size_cap_when_the_download_throws(): void {
		$this->login_as( 'editor' );

		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'The download failed.' );
			}
		);

		$this->assertErrorResponse( 'ability_callback_exception', $this->upload( array( 'url' => 'https://example.com/photo.jpg' ) ), null, 'The exception should be reported.' );
		$this->assertFalse( has_filter( 'http_request_args' ), 'The download size cap should be removed.' );
	}

	/**
	 * Filters the maximum upload size down to a value smaller than the image fixture used
	 * to mock the download.
	 *
	 * @since x.x.x
	 *
	 * @return int A deliberately small upload size limit, in bytes.
	 */
	public function filter_small_upload_size_limit(): int {
		return 1024;
	}

	/**
	 * A URL without a file name is rejected before anything is downloaded.
	 *
	 * @ticket 65517
	 *
	 * @since x.x.x
	 */
	public function test_create_item_from_url_rejects_url_without_filename(): void {
		$this->login_as( 'editor' );

		add_filter( 'pre_http_request', array( $this, 'fail_download' ), 10, 3 );

		$result = $this->upload( array( 'url' => 'https://example.com/?img=123' ) );

		$this->assertErrorResponse( 'media_invalid_url', $result, 400 );
		$this->assertSame( array(), $this->downloads, 'No download should be attempted for a URL without a filename.' );
	}

	/**
	 * A URL that does not name an image file is rejected before anything is downloaded.
	 *
	 * @ticket 65517
	 *
	 * @dataProvider data_create_item_from_url_rejects_non_image_extension
	 *
	 * @since x.x.x
	 *
	 * @param string $url URL with a disallowed file extension.
	 */
	public function test_create_item_from_url_rejects_non_image_extension( string $url ): void {
		$this->login_as( 'editor' );

		add_filter( 'pre_http_request', array( $this, 'fail_download' ), 10, 3 );

		$result = $this->upload( array( 'url' => $url ) );

		$this->assertErrorResponse( 'media_invalid_url', $result, 400 );
		$this->assertSame( array(), $this->downloads, 'No download should be attempted for a non-image URL.' );
	}

	/**
	 * Provides URLs that do not name an image file.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string}> The URLs.
	 */
	public function data_create_item_from_url_rejects_non_image_extension(): array {
		return array(
			'PHP script'       => array( 'https://example.com/evil.php' ),
			'HTML document'    => array( 'https://example.com/page.html' ),
			'video file'       => array( 'https://example.com/clip.mp4' ),
			'no extension'     => array( 'https://example.com/image' ),
			'double extension' => array( 'https://example.com/photo.jpg.php' ),
		);
	}

	/**
	 * A user who cannot upload files is denied, and a direct call fails before anything is
	 * downloaded.
	 *
	 * @ticket 65517
	 *
	 * @since x.x.x
	 */
	public function test_create_item_from_url_requires_upload_capability(): void {
		$this->login_as( 'subscriber' );

		add_filter( 'pre_http_request', array( $this, 'fail_download' ), 10, 3 );

		$input = array( 'url' => 'https://example.com/denied.jpg' );

		$this->assertErrorResponse( 'ability_invalid_permissions', $this->upload( $input ) );
		$this->assertErrorResponse( 'media_cannot_create', ( new Media() )->execute_media_upload( $input ), 403 );
		$this->assertSame( array(), $this->downloads, 'No download should be attempted without upload_files.' );
	}

	/**
	 * A URL that is not a string fails schema validation.
	 *
	 * @ticket 65517
	 *
	 * @since x.x.x
	 */
	public function test_create_item_from_url_rejects_non_string_url(): void {
		$this->login_as( 'editor' );

		$this->assertErrorResponse( 'ability_invalid_input', $this->upload( array( 'url' => array( 'https://example.com/image.jpg' ) ) ) );
	}

	/**
	 * URLs that are not safe to request from the server are rejected before anything is
	 * downloaded.
	 *
	 * @ticket 65517
	 *
	 * @since x.x.x
	 */
	public function test_url_arg_rejects_unsafe_urls(): void {
		$this->login_as( 'editor' );

		add_filter( 'pre_http_request', array( $this, 'mock_image_download' ), 10, 3 );

		// A well-formed URL on the site's own host passes validation.
		$this->assertIsArray( $this->upload( array( 'url' => home_url( '/image.jpg' ) ) ), 'A safe URL should pass validation.' );

		// A disallowed scheme, a malformed URL, and a private address are rejected.
		$invalid_urls = array(
			'ftp://example.org/image.jpg',
			'javascript:alert(1)',
			'not-a-url',
			'http://127.0.0.1/private.jpg',
		);

		foreach ( $invalid_urls as $invalid ) {
			$this->assertErrorResponse( 'media_invalid_param', $this->upload( array( 'url' => $invalid ) ), 400, 'An unsafe URL should be rejected.' );
		}

		$this->assertSame( array( home_url( '/image.jpg' ) ), $this->downloads, 'Only the safe URL should be downloaded.' );
	}

	/**
	 * Without `fields`, an upload returns the lean default fields.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_upload_returns_the_default_fields(): void {
		$this->login_as( 'author' );

		$data = $this->upload( $this->get_upload_input() );

		$this->assertIsArray( $data, 'The upload should succeed.' );
		$this->assertSame(
			array( 'id', 'date', 'slug', 'title_rendered', 'alt_text', 'media_type', 'mime_type', 'source_url' ),
			array_keys( $data ),
			'The default fields should be returned.'
		);
		$this->assertSame( pathinfo( get_attached_file( $data['id'] ), PATHINFO_FILENAME ), $data['title_rendered'], 'The title should come from the stored file name.' );
		$this->assertSame( 'image/jpeg', $data['mime_type'], 'The MIME type should come from the file.' );
	}

	/**
	 * Text fields given as objects use their `raw` value; an empty raw title is ignored.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_upload_accepts_text_fields_as_raw_objects(): void {
		$this->login_as( 'author' );

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'title'       => array( 'raw' => '' ),
					'caption'     => array( 'raw' => 'A raw caption' ),
					'description' => array( 'raw' => 'A raw description' ),
					'fields'      => array( 'title_raw', 'caption_raw', 'description_raw' ),
				)
			)
		);

		$this->assertIsArray( $data, 'The upload should succeed.' );
		$this->assertSame( pathinfo( get_attached_file( $data['id'] ), PATHINFO_FILENAME ), $data['title_raw'], 'An empty raw title should fall back to the stored file name.' );
		$this->assertSame( 'A raw caption', $data['caption_raw'], 'The raw caption should be stored.' );
		$this->assertSame( 'A raw description', $data['description_raw'], 'The raw description should be stored.' );
	}

	/**
	 * Filtered image meta values that are not strings are ignored.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_upload_ignores_image_meta_that_is_not_a_string(): void {
		$this->login_as( 'author' );

		add_filter(
			'wp_read_image_metadata',
			static function ( $meta ) {
				return array_merge(
					$meta,
					array(
						'title'   => null,
						'caption' => null,
						'alt'     => null,
					)
				);
			}
		);

		$data = $this->upload( $this->get_upload_input( array( 'fields' => array( 'title_raw', 'caption_raw', 'alt_text' ) ) ) );

		$this->assertIsArray( $data, 'The upload should succeed.' );
		$this->assertSame( pathinfo( get_attached_file( $data['id'] ), PATHINFO_FILENAME ), $data['title_raw'], 'The title should come from the stored file name.' );
		$this->assertSame( '', $data['caption_raw'], 'The caption should be empty.' );
		$this->assertSame( '', $data['alt_text'], 'The alt text should be empty.' );
	}

	/**
	 * Data that is not valid base64 is rejected before anything is stored.
	 *
	 * @since x.x.x
	 */
	public function test_upload_rejects_invalid_base64_data(): void {
		$this->login_as( 'author' );

		$result = $this->upload( $this->get_upload_input( array( 'data' => 'not base64!' ) ) );

		$this->assertErrorResponse( 'media_invalid_param', $result, 400, 'Invalid base64 data should be rejected.' );
		$this->assertSame( array(), $this->get_attachment_ids(), 'No attachment should be created.' );
	}

	/**
	 * A file type the site does not allow is rejected, and its temporary file is removed.
	 *
	 * @since x.x.x
	 */
	public function test_upload_rejects_a_disallowed_file_type(): void {
		$this->login_as( 'editor' );
		$files = $this->get_uploaded_files();

		$result = $this->upload(
			array(
				'data'     => base64_encode( '<?php echo "Hello";' ),
				'filename' => 'evil.php',
			)
		);

		$this->assertErrorResponse( 'media_upload_sideload_error', $result, 500, 'A PHP file should be rejected.' );
		$this->assertSame( array(), $this->get_attachment_ids(), 'No attachment should be created.' );
		$this->assertSame( $files, $this->get_uploaded_files(), 'No file should be stored.' );
		$this->assertSame( array(), glob( get_temp_dir() . 'evil*' ), 'The temporary file should be removed.' );
	}

	/**
	 * An invalid author is an error, reported before the file is stored.
	 *
	 * @since x.x.x
	 */
	public function test_upload_reports_an_invalid_author(): void {
		$this->login_as( 'editor' );
		$files       = $this->get_uploaded_files();
		$handle_file = new MockAction();
		add_filter( 'wp_handle_upload', array( $handle_file, 'filter' ) );

		$result = $this->upload( $this->get_upload_input( array( 'author' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) ) );

		$this->assertErrorResponse( 'media_invalid_author', $result, 400, 'An invalid author should be rejected.' );
		$this->assertSame( array(), $this->get_attachment_ids(), 'No attachment should be created.' );
		$this->assertSame( $files, $this->get_uploaded_files(), 'No file should be stored.' );
		$this->assertSame( 0, $handle_file->get_call_count(), 'The file should not be handled.' );
	}

	/**
	 * When the attachment cannot be inserted, the stored file is removed.
	 *
	 * @since x.x.x
	 */
	public function test_upload_removes_the_file_when_the_insert_fails(): void {
		$this->login_as( 'editor' );
		$files = $this->get_uploaded_files();

		// The date is valid input, but there is no year 0 to store it in.
		$result = $this->upload( $this->get_upload_input( array( 'date' => '0000-01-01T00:00:00Z' ) ) );

		$this->assertErrorResponse( 'invalid_date', $result, 400, 'The insert should fail.' );
		$this->assertSame( array(), $this->get_attachment_ids(), 'No attachment should be created.' );
		$this->assertSame( $files, $this->get_uploaded_files(), 'The stored file should be removed.' );
	}

	/**
	 * Uploading as another user requires the capability to edit their posts.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_upload_as_another_author_requires_edit_others(): void {
		$this->login_as( 'author' );
		$files = $this->get_uploaded_files();

		$result = $this->upload( $this->get_upload_input( array( 'author' => self::$user_ids['editor'] ) ) );
		$this->assertErrorResponse( 'media_cannot_edit_others', $result, 403, 'An author should not upload as another user.' );
		$this->assertSame( $files, $this->get_uploaded_files(), 'No file should be stored.' );

		$this->login_as( 'editor' );

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'author' => self::$user_ids['author'],
					'fields' => array( 'author' ),
				)
			)
		);
		$this->assertIsArray( $data, 'An editor should upload as another user.' );
		$this->assertSame( self::$user_ids['author'], $data['author'], 'The given author should be stored.' );
	}

	/**
	 * A private upload requires the capability to publish posts, and a refused upload stores
	 * no file.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_upload_private_requires_the_publish_capability(): void {
		$this->login_as( 'uploader' );
		$files = $this->get_uploaded_files();

		$result = $this->upload( $this->get_upload_input( array( 'status' => 'private' ) ) );
		$this->assertErrorResponse( 'media_cannot_publish', $result, 403, 'A user who cannot publish should not upload privately.' );
		$this->assertSame( array(), $this->get_attachment_ids(), 'No attachment should be created.' );
		$this->assertSame( $files, $this->get_uploaded_files(), 'No file should be stored.' );

		$this->login_as( 'author' );

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'status' => 'private',
					'fields' => array( 'status' ),
				)
			)
		);
		$this->assertIsArray( $data, 'An author should upload privately.' );
		$this->assertSame( 'private', $data['status'], 'The private status should be stored.' );
	}

	/**
	 * A public status is stored as `inherit`, as all attachments that are not private are.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_upload_stores_a_public_status_as_inherit(): void {
		$this->login_as( 'editor' );

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'status' => 'publish',
					'fields' => array( 'status' ),
				)
			)
		);

		$this->assertIsArray( $data, 'The upload should succeed.' );
		$this->assertSame( 'inherit', $data['status'], 'The returned status should be inherit.' );
		$this->assertSame( 'inherit', get_post( $data['id'] )->post_status, 'The stored status should be inherit.' );
	}

	/**
	 * The slug is sanitized, and a date without an offset is read in the site's timezone.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_upload_sets_the_slug_and_the_date(): void {
		$this->login_as( 'editor' );
		update_option( 'timezone_string', 'America/New_York' );

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'slug'   => 'My Upload Slug!',
					'date'   => '2016-12-12T14:00:00',
					'fields' => array( 'slug', 'date', 'date_gmt' ),
				)
			)
		);

		$this->assertIsArray( $data, 'The upload should succeed.' );
		$this->assertSame( 'my-upload-slug', $data['slug'], 'The slug should be sanitized.' );
		$this->assertSame( '2016-12-12T14:00:00', $data['date'], 'The local date should be returned.' );
		$this->assertSame( '2016-12-12T19:00:00', $data['date_gmt'], 'The GMT date should be returned.' );

		$post = get_post( $data['id'] );
		$this->assertSame( '2016-12-12 14:00:00', $post->post_date, 'The local date should be stored.' );
		$this->assertSame( '2016-12-12 19:00:00', $post->post_date_gmt, 'The GMT date should be stored.' );
	}

	/**
	 * An upload fires the insert hooks and generates its metadata once.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_upload_fires_the_insert_hooks_once(): void {
		$this->login_as( 'author' );

		$add_attachment = new MockAction();
		$after_insert   = new MockAction();
		$metadata       = new MockAction();
		add_action( 'add_attachment', array( $add_attachment, 'action' ) );
		add_action( 'wp_after_insert_post', array( $after_insert, 'action' ), 10, 4 );
		add_filter( 'wp_generate_attachment_metadata', array( $metadata, 'filter' ) );

		$data = $this->upload( $this->get_upload_input() );

		$this->assertIsArray( $data, 'The upload should succeed.' );
		$this->assertSame( 1, $add_attachment->get_call_count(), 'add_attachment should fire once.' );
		$this->assertSame( 1, $after_insert->get_call_count(), 'wp_after_insert_post should fire once.' );
		[ $post_id, $post, $update, $post_before ] = $after_insert->get_args()[0];
		$this->assertSame( $data['id'], $post_id, 'wp_after_insert_post should report the attachment.' );
		$this->assertSame( $data['id'], $post->ID, 'wp_after_insert_post should pass the attachment.' );
		$this->assertFalse( $update, 'wp_after_insert_post should report a new attachment.' );
		$this->assertNull( $post_before, 'A new attachment should have no previous state.' );
		$this->assertSame( 1, $metadata->get_call_count(), 'The metadata should be generated once.' );
	}

	/**
	 * A URL upload rejects the data mode's input, and data with a URL matches neither mode.
	 *
	 * @since x.x.x
	 */
	public function test_url_upload_rejects_the_data_mode_input(): void {
		$this->login_as( 'editor' );

		add_filter( 'pre_http_request', array( $this, 'fail_download' ), 10, 3 );

		$with_title = $this->upload(
			array(
				'url'   => 'https://example.com/photo.jpg',
				'title' => 'A title',
			)
		);
		$this->assertErrorResponse( 'ability_invalid_input', $with_title, null, 'A URL upload should not take a title.' );

		$with_data = $this->upload( $this->get_upload_input( array( 'url' => 'https://example.com/photo.jpg' ) ) );
		$this->assertErrorResponse( 'ability_invalid_input', $with_data, null, 'Data and a URL should not be combined.' );

		$this->assertSame( array(), $this->downloads, 'Nothing should be downloaded.' );
	}

	/**
	 * A URL upload checks the parent post before anything is downloaded.
	 *
	 * @since x.x.x
	 */
	public function test_url_upload_checks_the_parent_first(): void {
		$editor_post   = self::factory()->post->create( array( 'post_author' => self::$user_ids['editor'] ) );
		$attachment_id = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_author'    => self::$user_ids['author'],
			)
		);

		add_filter( 'pre_http_request', array( $this, 'fail_download' ), 10, 3 );

		$this->login_as( 'author' );
		$result = $this->upload(
			array(
				'url'  => 'https://example.com/photo.jpg',
				'post' => $editor_post,
			)
		);
		$this->assertErrorResponse( 'media_cannot_edit', $result, 403, "An author should not attach to another user's post." );

		$result = $this->upload(
			array(
				'url'  => 'https://example.com/photo.jpg',
				'post' => $attachment_id,
			)
		);
		$this->assertErrorResponse( 'media_invalid_param', $result, 400, 'An attachment should not be a parent.' );

		$this->assertSame( array(), $this->downloads, 'Nothing should be downloaded.' );
	}

	/**
	 * A URL upload returns the downloaded image, titled after the URL's file name.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_url_upload_returns_the_downloaded_image(): void {
		$this->login_as( 'author' );

		add_filter( 'pre_http_request', array( $this, 'mock_image_download' ), 10, 3 );

		$data = $this->upload(
			array(
				'url'    => 'https://example.com/photo.jpg',
				'fields' => array( 'title_raw', 'author', 'status', 'mime_type', 'media_type', 'post' ),
			)
		);

		$this->assertSame(
			array(
				'id'         => $data['id'],
				'status'     => 'inherit',
				'title_raw'  => 'photo',
				'author'     => self::$user_ids['author'],
				'media_type' => 'image',
				'mime_type'  => 'image/jpeg',
				'post'       => null,
			),
			$data,
			'The downloaded image should be returned.'
		);
		$this->assertSame( array( 'https://example.com/photo.jpg' ), $this->downloads, 'The URL should be downloaded once.' );
	}

	/**
	 * The write abilities restore the global post, which preparing a response replaces.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_restores_the_global_post(): void {
		$this->login_as( 'editor' );
		$previous        = self::factory()->post->create_and_get();
		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test sets up a global post.

		$data = $this->upload( $this->get_upload_input() );
		$this->assertSame( $previous, $GLOBALS['post'], 'An upload should restore the global post.' );

		$this->execute_ability(
			'core/media-update',
			array(
				'id'    => $data['id'],
				'title' => 'Updated',
			)
		);
		$this->assertSame( $previous, $GLOBALS['post'], 'An update should restore the global post.' );

		unset( $GLOBALS['post'] );
		$this->execute_ability(
			'core/media-delete',
			array(
				'id'    => $data['id'],
				'force' => true,
			)
		);
		$this->assertArrayNotHasKey( 'post', $GLOBALS, 'A deletion should leave no global post behind.' );
	}

	/**
	 * An upload stores and returns what the media endpoint stores and returns for the same
	 * input.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_upload_matches_the_media_endpoint(): void {
		$this->login_as( 'editor' );
		update_option( 'timezone_string', 'America/New_York' );

		$input  = array(
			'title'       => 'A <em>title</em>',
			'caption'     => 'A caption',
			'description' => 'A description',
			'alt_text'    => '<b>An alt text</b>',
			'post'        => self::factory()->post->create(),
			'date'        => '2016-12-12T14:00:00',
		);
		$fields = array( 'date', 'date_gmt', 'status', 'title_raw', 'title_rendered', 'author', 'description_raw', 'caption_raw', 'caption_rendered', 'alt_text', 'media_type', 'mime_type', 'post' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$request->set_header( 'Content-Type', 'image/jpeg' );
		$request->set_header( 'Content-Disposition', 'attachment; filename=canola.jpg' );
		$request->set_body( (string) file_get_contents( self::$test_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads a local test file.
		foreach ( $input as $name => $value ) {
			$request->set_param( $name, $value );
		}
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status(), 'The endpoint should create the attachment.' );

		$result = $this->upload( $this->get_upload_input( array_merge( $input, array( 'fields' => $fields ) ) ) );
		$this->assertIsArray( $result, 'The ability should create the attachment.' );

		$rest_post    = get_post( $response->get_data()['id'] );
		$ability_post = get_post( $result['id'] );
		unset( $result['id'] );

		$expected = $this->flatten_rest_fields( $response->get_data(), $fields );
		ksort( $expected );
		ksort( $result );
		$this->assertSame( $expected, $result, 'The ability should return what the endpoint returns.' );

		foreach ( array( 'post_title', 'post_excerpt', 'post_content', 'post_status', 'post_parent', 'post_author', 'post_mime_type', 'post_date', 'post_date_gmt' ) as $column ) {
			$this->assertSame( $rest_post->$column, $ability_post->$column, "The {$column} column should match." );
		}
		$this->assertSame(
			array_keys( wp_get_attachment_metadata( $rest_post->ID )['sizes'] ),
			array_keys( wp_get_attachment_metadata( $ability_post->ID )['sizes'] ),
			'The same sub-sizes should be generated.'
		);
	}
}
