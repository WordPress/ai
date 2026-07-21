<?php
/**
 * Integration tests for the Job_Manager class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Text_To_Speech\Job_Manager;

/**
 * Job_Manager test case.
 *
 * @since x.x.x
 */
class Job_ManagerTest extends WP_UnitTestCase {

	/**
	 * The Job_Manager under test.
	 *
	 * @var Job_Manager
	 */
	private $job_manager;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->job_manager = new Job_Manager();

		// Fake audio bytes so no AI provider is required. Each chunk becomes
		// recognizable bytes so the combined file can be asserted against.
		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function ( $pre, $text ) {
				return array(
					'data'      => base64_encode( '[' . $text . ']' ),
					'mime_type' => 'audio/mpeg',
				);
			},
			10,
			2
		);

		// The fake bytes are not real MP3 data, so bypass WordPress's
		// content-based file type sniffing during sideload.
		add_filter(
			'wp_check_filetype_and_ext',
			static function () {
				return array(
					'ext'             => 'mp3',
					'type'            => 'audio/mpeg',
					'proper_filename' => false,
				);
			}
		);
	}

	/**
	 * Creates a test post with enough content for a TTS job.
	 *
	 * @since x.x.x
	 *
	 * @param string $content The post content.
	 * @return int The post ID.
	 */
	private function create_post( string $content = 'First sentence here. Second sentence here. Third sentence here.' ): int {
		return self::factory()->post->create( array( 'post_content' => $content ) );
	}

	/**
	 * Runs all scheduled chunk events for a post, simulating WP-Cron.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id The post ID.
	 */
	private function run_all_chunk_events( int $post_id ): void {
		$guard = 0;

		while ( wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) ) && $guard < 50 ) {
			wp_unschedule_event( (int) wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) ), Job_Manager::CRON_HOOK, array( $post_id ) );
			$this->job_manager->process_chunk( $post_id );
			$guard++;
		}
	}

	/**
	 * Test that start_job() records a pending job and schedules a cron event.
	 *
	 * @since x.x.x
	 */
	public function test_start_job_schedules_cron_event(): void {
		$post_id = $this->create_post();

		$result = $this->job_manager->start_job( $post_id, get_current_user_id() );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertNotFalse( wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) ) );
		$this->assertIsArray( get_post_meta( $post_id, Job_Manager::META_JOB, true ) );
	}

	/**
	 * Test that start_job() rejects posts with no content.
	 *
	 * @since x.x.x
	 */
	public function test_start_job_requires_content(): void {
		$post_id = $this->create_post( '' );

		$result = $this->job_manager->start_job( $post_id, get_current_user_id() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_content', $result->get_error_code() );
	}

	/**
	 * Test that start_job() rejects a second start while a job is running.
	 *
	 * @since x.x.x
	 */
	public function test_start_job_blocks_duplicate_jobs(): void {
		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		$result = $this->job_manager->start_job( $post_id, get_current_user_id() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'job_in_progress', $result->get_error_code() );
	}

	/**
	 * Test the full multi-chunk lifecycle: chunks generated, combined, and
	 * imported as a single attachment.
	 *
	 * @since x.x.x
	 */
	public function test_full_job_lifecycle_creates_attachment(): void {
		// Force multiple chunks with a small limit.
		add_filter(
			'wpai_tts_max_chunk_length',
			static function () {
				return 30;
			}
		);

		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );

		$job = get_post_meta( $post_id, Job_Manager::META_JOB, true );
		$this->assertGreaterThan( 1, (int) $job['total'] );

		$this->run_all_chunk_events( $post_id );

		$status = $this->job_manager->get_status( $post_id );

		$this->assertSame( 'complete', $status['status'] );
		$this->assertGreaterThan( 0, $status['audio_id'] );
		$this->assertNotEmpty( $status['audio_url'] );

		$attachment = get_post( $status['audio_id'] );
		$this->assertNotNull( $attachment );
		$this->assertSame( 'attachment', $attachment->post_type );
		$this->assertSame( $post_id, $attachment->post_parent );
		$this->assertSame( 1, (int) get_post_meta( $status['audio_id'], 'wpai_generated', true ) );

		// Combined file holds every chunk's fake bytes, in order.
		$file     = get_attached_file( $status['audio_id'] );
		$contents = file_get_contents( $file );
		$expected = implode(
			'',
			array_map(
				static function ( string $chunk ): string {
					return '[' . $chunk . ']';
				},
				$job['chunks']
			)
		);
		$this->assertSame( $expected, $contents );

		// Job state is cleaned up.
		$this->assertSame( '', get_post_meta( $post_id, Job_Manager::META_JOB, true ) );
	}

	/**
	 * Test that regeneration deletes the previous audio attachment only after
	 * the new one exists.
	 *
	 * @since x.x.x
	 */
	public function test_regeneration_deletes_old_attachment(): void {
		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		$this->run_all_chunk_events( $post_id );
		$first_id = $this->job_manager->get_status( $post_id )['audio_id'];

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		$this->run_all_chunk_events( $post_id );
		$second_id = $this->job_manager->get_status( $post_id )['audio_id'];

		$this->assertNotSame( $first_id, $second_id );
		$this->assertNull( get_post( $first_id ) );
		$this->assertNotNull( get_post( $second_id ) );
	}

	/**
	 * Test that a generation failure marks the job errored and preserves any
	 * previously generated audio.
	 *
	 * @since x.x.x
	 */
	public function test_failed_generation_marks_job_errored(): void {
		$post_id = $this->create_post();

		// Successful first generation.
		$this->job_manager->start_job( $post_id, get_current_user_id() );
		$this->run_all_chunk_events( $post_id );
		$first_id = $this->job_manager->get_status( $post_id )['audio_id'];

		// Second generation fails.
		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function () {
				return new WP_Error( 'tts_failed', 'Provider exploded.' );
			},
			20
		);

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		$this->run_all_chunk_events( $post_id );

		$status = $this->job_manager->get_status( $post_id );

		$this->assertSame( 'error', $status['status'] );
		$this->assertSame( 'Provider exploded.', $status['error'] );
		// Old audio untouched.
		$this->assertSame( $first_id, $status['audio_id'] );
		$this->assertNotNull( get_post( $first_id ) );
		// Job blob cleaned up.
		$this->assertSame( '', get_post_meta( $post_id, Job_Manager::META_JOB, true ) );
	}

	/**
	 * Test that delete_audio() removes the attachment and all TTS meta.
	 *
	 * @since x.x.x
	 */
	public function test_delete_audio_removes_attachment_and_meta(): void {
		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		$this->run_all_chunk_events( $post_id );

		$audio_id = $this->job_manager->get_status( $post_id )['audio_id'];
		$this->assertGreaterThan( 0, $audio_id );
		$this->assertNotNull( get_post( $audio_id ) );

		update_post_meta( $post_id, Job_Manager::META_DISPLAY, true );

		$status = $this->job_manager->delete_audio( $post_id );

		// The attachment is gone.
		$this->assertNull( get_post( $audio_id ) );

		// The returned payload reflects a clean slate.
		$this->assertSame( 'idle', $status['status'] );
		$this->assertSame( 0, $status['audio_id'] );
		$this->assertSame( '', $status['audio_url'] );

		// Every piece of TTS meta is removed.
		foreach (
			array(
				Job_Manager::META_AUDIO_ID,
				Job_Manager::META_STATUS,
				Job_Manager::META_ERROR,
				Job_Manager::META_UPDATED,
				Job_Manager::META_JOB,
				Job_Manager::META_DISPLAY,
			) as $meta_key
		) {
			$this->assertSame( '', get_post_meta( $post_id, $meta_key, true ) );
		}

		// No chunk event is left scheduled.
		$this->assertFalse( wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) ) );
	}
}
