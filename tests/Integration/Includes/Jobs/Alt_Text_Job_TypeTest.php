<?php
/**
 * Tests for the alt text job type.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Jobs
 */

namespace WordPress\AI\Tests\Integration\Includes\Jobs;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Jobs\Job;
use WordPress\AI\Jobs\Job_Types\Alt_Text_Job_Type;

/**
 * Alt_Text_Job_Type test case.
 *
 * @since 1.6.0
 *
 * @covers \WordPress\AI\Jobs\Job_Types\Alt_Text_Job_Type
 */
class Alt_Text_Job_TypeTest extends WP_UnitTestCase {

	/**
	 * Test ability name.
	 *
	 * @var string
	 */
	private const ABILITY = 'test/alt-text';

	/**
	 * Result the fake ability returns.
	 *
	 * @var mixed
	 */
	public $ability_result = array(
		'alt_text'      => 'A small blue square.',
		'is_decorative' => false,
	);

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Attachment ID.
	 *
	 * @var int
	 */
	private int $attachment_id;

	/**
	 * Sets up the test ability and image.
	 *
	 * @since 1.6.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		$this->attachment_id = (int) self::factory()->attachment->create_upload_object(
			TESTS_REPO_ROOT_DIR . '/tests/data/sample.png'
		);

		$registry = \WP_Abilities_Registry::get_instance();

		if ( null !== $registry && ! $registry->is_registered( self::ABILITY ) ) {
			$registry->register(
				self::ABILITY,
				array(
					'label'               => 'Fake alt text',
					'description'         => 'Fake alt text',
					'category'            => 'site',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => function () {
						return $this->ability_result;
					},
					'permission_callback' => '__return_true',
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		}

		add_filter(
			'wpai_alt_text_job_ability',
			static function (): string {
				return self::ABILITY;
			}
		);
	}

	/**
	 * Cleans up the test ability.
	 *
	 * @since 1.6.0
	 */
	public function tear_down(): void {
		remove_all_filters( 'wpai_alt_text_job_ability' );
		wp_unregister_ability( self::ABILITY );

		parent::tear_down();
	}

	/**
	 * Builds a job for the attachment.
	 *
	 * @return \WordPress\AI\Jobs\Job The job.
	 */
	private function job(): Job {
		return new Job(
			'test-job',
			Alt_Text_Job_Type::ID,
			array( 'attachment_id' => $this->attachment_id ),
			$this->user_id
		);
	}

	/**
	 * A user who can edit the image may enqueue the job.
	 *
	 * @since 1.6.0
	 */
	public function test_can_enqueue_accepts_an_editable_image(): void {
		$type = new Alt_Text_Job_Type();

		$this->assertTrue( $type->can_enqueue( array( 'attachment_id' => $this->attachment_id ), $this->user_id ) );
	}

	/**
	 * A non-image post cannot be used.
	 *
	 * @since 1.6.0
	 */
	public function test_can_enqueue_rejects_a_non_image_post(): void {
		$post_id = self::factory()->post->create();
		$type    = new Alt_Text_Job_Type();

		$result = $type->can_enqueue( array( 'attachment_id' => $post_id ), $this->user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_alt_text_invalid_attachment', $result->get_error_code() );
	}

	/**
	 * A user without upload access cannot enqueue the job.
	 *
	 * @since 1.6.0
	 */
	public function test_can_enqueue_rejects_a_user_without_upload_access(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$type          = new Alt_Text_Job_Type();

		$result = $type->can_enqueue( array( 'attachment_id' => $this->attachment_id ), $subscriber_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_alt_text_forbidden', $result->get_error_code() );
	}

	/**
	 * Running the job saves the generated alt text.
	 *
	 * @since 1.6.0
	 */
	public function test_handle_saves_alt_text(): void {
		$type   = new Alt_Text_Job_Type();
		$result = $type->handle( array( 'attachment_id' => $this->attachment_id ), $this->job() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['saved'] );
		$this->assertSame( 'A small blue square.', $result['alt_text'] );
		$this->assertSame( 'A small blue square.', get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Decorative results leave existing alt text untouched.
	 *
	 * @since 1.6.0
	 */
	public function test_handle_preserves_alt_text_for_decorative_images(): void {
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'Hand-written alt text' );

		$this->ability_result = array(
			'alt_text'      => '',
			'is_decorative' => true,
		);

		$type   = new Alt_Text_Job_Type();
		$result = $type->handle( array( 'attachment_id' => $this->attachment_id ), $this->job() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['saved'] );
		$this->assertSame( 'Hand-written alt text', get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * A WP_Error from the ability fails the job.
	 *
	 * @since 1.6.0
	 */
	public function test_handle_returns_ability_errors(): void {
		$this->ability_result = new WP_Error( 'boom', 'Broke.' );

		$type   = new Alt_Text_Job_Type();
		$result = $type->handle( array( 'attachment_id' => $this->attachment_id ), $this->job() );

		$this->assertWPError( $result );
		$this->assertSame( 'boom', $result->get_error_code() );
	}
}
