<?php
/**
 * Integration tests for the Job_Manager class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Background\Lock;
use WordPress\AI\Experiments\Text_To_Speech\Audio_Combiner;
use WordPress\AI\Experiments\Text_To_Speech\Job_Manager;
use WordPress\AI\Experiments\Text_To_Speech\Speech_Generator;

/**
 * Job_Manager test case.
 *
 * @since 1.5.0
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
	 * @since 1.5.0
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
	 * @since 1.5.0
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
	 * @since 1.5.0
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
	 * @since 1.5.0
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
	 * @since 1.5.0
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
	 * @since 1.5.0
	 */
	public function test_start_job_blocks_duplicate_jobs(): void {
		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		$result = $this->job_manager->start_job( $post_id, get_current_user_id() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'job_in_progress', $result->get_error_code() );
	}

	/**
	 * Test that a job WP-Cron never starts is failed and unscheduled after the
	 * pending timeout, and can then be restarted.
	 *
	 * @since 1.5.0
	 */
	public function test_pending_job_expires_and_can_restart(): void {
		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );

		// Still within the pending window.
		update_post_meta( $post_id, Job_Manager::META_UPDATED, time() - Job_Manager::PENDING_TIMEOUT_SECONDS + 5 );
		$this->assertSame( 'pending', $this->job_manager->get_status( $post_id )['status'] );

		update_post_meta( $post_id, Job_Manager::META_UPDATED, time() - Job_Manager::PENDING_TIMEOUT_SECONDS );

		$status = $this->job_manager->get_status( $post_id );
		$this->assertSame( 'error', $status['status'] );
		$this->assertStringContainsString( 'WP-Cron', $status['error'] );
		$this->assertFalse( wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) ) );
		$this->assertSame( '', get_post_meta( $post_id, Job_Manager::META_JOB, true ) );

		$result = $this->job_manager->start_job( $post_id, get_current_user_id() );
		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
	}

	/**
	 * Test that the pending timeout is filterable.
	 *
	 * @since 1.5.0
	 */
	public function test_pending_timeout_is_filterable(): void {
		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		update_post_meta( $post_id, Job_Manager::META_UPDATED, time() - Job_Manager::PENDING_TIMEOUT_SECONDS );

		$filter = static function (): int {
			return 600;
		};
		add_filter( 'wpai_tts_pending_timeout', $filter );

		$status = $this->job_manager->get_status( $post_id )['status'];

		remove_filter( 'wpai_tts_pending_timeout', $filter );

		$this->assertSame( 'pending', $status );
	}

	/**
	 * Test that a processing job is only failed once the request timeout,
	 * cron lock, and slack have all elapsed.
	 *
	 * @since 1.5.0
	 */
	public function test_processing_job_expires_after_request_timeout(): void {
		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		update_post_meta( $post_id, Job_Manager::META_STATUS, 'processing' );

		$timeout = Speech_Generator::DEFAULT_REQUEST_TIMEOUT + WP_CRON_LOCK_TIMEOUT + Job_Manager::PROCESSING_TIMEOUT_SLACK_SECONDS;

		// Longer than the pending timeout, but a chunk may still be in flight.
		update_post_meta( $post_id, Job_Manager::META_UPDATED, time() - $timeout + 5 );
		$this->assertSame( 'processing', $this->job_manager->get_status( $post_id )['status'] );

		update_post_meta( $post_id, Job_Manager::META_UPDATED, time() - $timeout );

		$status = $this->job_manager->get_status( $post_id );
		$this->assertSame( 'error', $status['status'] );
		$this->assertNotEmpty( $status['error'] );
		$this->assertFalse( wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) ) );
	}

	/**
	 * Test that job-state meta keys are protected so they stay out of the
	 * Custom Fields meta box.
	 *
	 * @since 1.5.0
	 */
	public function test_job_state_meta_is_protected(): void {
		foreach (
			array(
				Job_Manager::META_AUDIO_ID,
				Job_Manager::META_STATUS,
				Job_Manager::META_ERROR,
				Job_Manager::META_UPDATED,
				Job_Manager::META_JOB,
			) as $meta_key
		) {
			$this->assertTrue( is_protected_meta( $meta_key, 'post' ), $meta_key );
		}
	}

	/**
	 * Test the full multi-chunk lifecycle: chunks generated, combined, and
	 * imported as a single attachment.
	 *
	 * @since 1.5.0
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
		$this->assertSame( 'inherit', $attachment->post_status );
		$this->assertSame( $post_id, $attachment->post_parent );
		$this->assertSame( 1, (int) get_post_meta( $status['audio_id'], 'wpai_generated', true ) );

		// Combined file holds every chunk's fake bytes, in order.
		$file = get_attached_file( $status['audio_id'] );

		// The filename carries a random suffix so its URL is not guessable.
		$this->assertMatchesRegularExpression( '/^post-audio-' . $post_id . '-[a-z0-9]{12}\.mp3$/', basename( $file ) );

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

		// Job state is cleaned up, and the per-post lock was released.
		$this->assertSame( '', get_post_meta( $post_id, Job_Manager::META_JOB, true ) );
		$this->assertTrue( ( new Lock( 'tts_job_' . $post_id ) )->acquire() );
	}

	/**
	 * Test that audio for a password-protected post is private, so core's
	 * REST media endpoints never hand its URL to visitors.
	 *
	 * @since 1.5.0
	 */
	public function test_audio_for_password_protected_post_is_hidden_from_rest(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content'  => 'First sentence here. Second sentence here.',
				'post_password' => 'secret',
			)
		);

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		$this->run_all_chunk_events( $post_id );

		$audio_id = $this->job_manager->get_status( $post_id )['audio_id'];
		$this->assertGreaterThan( 0, $audio_id );
		$this->assertSame( 'private', get_post( $audio_id )->post_status );
		$this->assertNotEmpty( wp_get_attachment_url( $audio_id ), 'The player still needs the file URL.' );

		wp_set_current_user( 0 );

		$single = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/media/' . $audio_id ) );
		$this->assertSame( 401, $single->get_status() );

		$list = new \WP_REST_Request( 'GET', '/wp/v2/media' );
		$list->set_param( 'parent', $post_id );
		$this->assertSame( array(), rest_do_request( $list )->get_data() );
	}

	/**
	 * Test that a multi-chunk job is refused before anything is generated or
	 * scheduled when the provider cannot output MP3.
	 *
	 * @since 1.5.0
	 */
	public function test_multi_chunk_job_requires_mp3_provider(): void {
		remove_all_filters( 'wpai_tts_pre_generate_chunk' );
		Fake_Speech_Provider::install( array( 'audio/wav' ) );
		add_filter(
			'wpai_tts_max_chunk_length',
			static function () {
				return 30;
			}
		);

		$post_id = $this->create_post();

		try {
			$result = $this->job_manager->start_job( $post_id, get_current_user_id() );
		} finally {
			Fake_Speech_Provider::uninstall();
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tts_mp3_unsupported', $result->get_error_code() );
		$this->assertFalse( wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) ) );
		$this->assertSame( '', get_post_meta( $post_id, Job_Manager::META_JOB, true ) );
	}

	/**
	 * Test that a single-chunk job does not require MP3, so providers that
	 * output other audio formats still work for short posts.
	 *
	 * @since 1.5.0
	 */
	public function test_single_chunk_job_does_not_require_mp3(): void {
		remove_all_filters( 'wpai_tts_pre_generate_chunk' );
		Fake_Speech_Provider::install( array( 'audio/wav' ) );

		$post_id = $this->create_post();

		try {
			$result = $this->job_manager->start_job( $post_id, get_current_user_id() );
		} finally {
			Fake_Speech_Provider::uninstall();
		}

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
	}

	/**
	 * Test that chunks request MP3 only when the job has several of them.
	 *
	 * @since 1.5.0
	 */
	public function test_chunks_request_mp3_only_when_joined(): void {
		$requested = array();

		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function ( $pre, $text, $voice, $mime_type ) use ( &$requested ) {
				$requested[] = $mime_type;

				return $pre;
			},
			5,
			4
		);

		$single = $this->create_post( 'Just one short sentence.' );
		$this->job_manager->start_job( $single, get_current_user_id() );
		$this->run_all_chunk_events( $single );
		$this->assertSame( array( '' ), $requested );

		$requested = array();
		add_filter(
			'wpai_tts_max_chunk_length',
			static function () {
				return 30;
			}
		);

		$multi = $this->create_post();
		$this->job_manager->start_job( $multi, get_current_user_id() );
		$this->run_all_chunk_events( $multi );
		$this->assertNotEmpty( $requested );
		$this->assertSame( array( Audio_Combiner::MIME_TYPE ), array_values( array_unique( $requested ) ) );
	}

	/**
	 * Test that a chunk event leaves the job alone while another runner holds
	 * the post's lock, and schedules a fallback retry.
	 *
	 * @since 1.5.0
	 */
	public function test_process_chunk_skips_when_lock_is_held(): void {
		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		wp_clear_scheduled_hook( Job_Manager::CRON_HOOK, array( $post_id ) );

		$other_runner = new Lock( 'tts_job_' . $post_id );
		$this->assertTrue( $other_runner->acquire() );

		$this->job_manager->process_chunk( $post_id );

		$job = get_post_meta( $post_id, Job_Manager::META_JOB, true );
		$this->assertSame( 0, (int) $job['next'] );
		$this->assertSame( 'pending', get_post_meta( $post_id, Job_Manager::META_STATUS, true ) );
		$this->assertFileDoesNotExist( $job['temp_file'] );

		$retry_at = wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) );
		$this->assertNotFalse( $retry_at );
		$this->assertGreaterThanOrEqual( time() + Job_Manager::LOCKED_RETRY_SECONDS - 1, $retry_at );

		$other_runner->release();
	}

	/**
	 * Test that a chunk whose job was restarted while it was generating is
	 * dropped instead of being written into the new job.
	 *
	 * @since 1.5.0
	 */
	public function test_process_chunk_drops_result_of_superseded_job(): void {
		$post_id = $this->create_post();

		$this->job_manager->start_job( $post_id, get_current_user_id() );
		wp_clear_scheduled_hook( Job_Manager::CRON_HOOK, array( $post_id ) );

		$old_job = get_post_meta( $post_id, Job_Manager::META_JOB, true );

		// Simulate the job being expired and restarted by another request
		// while the provider call for the old run is still in flight. The
		// write goes straight to the database, as another request's would,
		// leaving this request's meta cache stale.
		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function ( $pre ) use ( $post_id, $old_job ) {
				global $wpdb;

				$new_job              = $old_job;
				$new_job['token']     = 'replacement-token';
				$new_job['temp_file'] = $old_job['temp_file'] . '.new';

				$wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => maybe_serialize( $new_job ) ),
					array(
						'post_id'  => $post_id,
						'meta_key' => Job_Manager::META_JOB,
					)
				);

				return $pre;
			},
			20
		);

		$this->job_manager->process_chunk( $post_id );

		$job = get_post_meta( $post_id, Job_Manager::META_JOB, true );
		$this->assertSame( 'replacement-token', $job['token'] );
		$this->assertSame( 0, (int) $job['next'] );
		$this->assertNotSame( 'error', get_post_meta( $post_id, Job_Manager::META_STATUS, true ) );
		$this->assertFileDoesNotExist( $old_job['temp_file'] );
		$this->assertFalse( wp_next_scheduled( Job_Manager::CRON_HOOK, array( $post_id ) ) );
	}

	/**
	 * Test that regeneration deletes the previous audio attachment only after
	 * the new one exists.
	 *
	 * @since 1.5.0
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
	 * @since 1.5.0
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
	 * @since 1.5.0
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
