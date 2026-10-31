<?php
/**
 * Text to speech background job manager.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Text_To_Speech;

use WP_Error;
use WordPress\AI\Background\Lock;
use WordPress\AI\Background\Scheduler;

use function WordPress\AI\get_default_request_timeout;
use function WordPress\AI\normalize_content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages background text to speech generation jobs.
 *
 * A job converts a post's content into audio: the content is normalized and
 * split into chunks, one chunk is generated per WP-Cron event and appended
 * to a temporary file, and the final event sideloads the combined file into
 * the media library as an attachment of the post.
 *
 * All job state lives in post meta, so it survives requests and can be
 * polled by the editor via `GET /ai/v1/text-to-speech/{id}`.
 *
 * @since 1.5.0
 */
class Job_Manager {

	/**
	 * Cron hook fired once per chunk. Receives the post ID as its argument.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const CRON_HOOK = 'wpai_tts_process_chunk';

	/**
	 * The feature ID this manager belongs to.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const FEATURE_ID = 'text-to-speech';

	/**
	 * Post meta key holding the generated audio attachment ID.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const META_AUDIO_ID = '_wpai_tts_audio_id';

	/**
	 * Post meta key holding the front-end display toggle.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const META_DISPLAY = 'wpai_tts_display_audio';

	/**
	 * Post meta key holding the job status.
	 *
	 * One of '' (never run), 'pending', 'processing', 'complete', 'error'.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const META_STATUS = '_wpai_tts_status';

	/**
	 * Post meta key holding the last error message.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const META_ERROR = '_wpai_tts_error';

	/**
	 * Post meta key holding the last-updated timestamp, used for stuck-job
	 * detection.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const META_UPDATED = '_wpai_tts_updated';

	/**
	 * Post meta key holding the transient job state blob.
	 *
	 * Deliberately not registered for REST; the editor reads job state
	 * through the status endpoint instead.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const META_JOB = '_wpai_tts_job';

	/**
	 * Filename prefix of the temp file a job appends chunks to, in the root
	 * of the uploads directory. The full name is `{prefix}{post ID}-{random}.part`.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const TEMP_FILE_PREFIX = 'wpai-tts-';

	/**
	 * Default seconds a job may stay pending before it is failed.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	public const PENDING_TIMEOUT_SECONDS = 90;

	/**
	 * Slack, in seconds, added on top of the request timeout and WP-Cron
	 * spawn lock when deciding a processing job has stalled.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	public const PROCESSING_TIMEOUT_SLACK_SECONDS = 60;

	/**
	 * Seconds to wait before retrying a chunk event that found another
	 * runner already working on the same post.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	public const LOCKED_RETRY_SECONDS = 30;

	/**
	 * Starts (or restarts) an audio generation job for a post.
	 *
	 * Builds the chunk list synchronously so content problems surface
	 * immediately, persists job state in post meta, and schedules the first
	 * chunk event. Any previously generated audio attachment is left in
	 * place until the new audio has been imported successfully.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post to generate audio for.
	 * @param int $user_id The user who triggered generation; becomes the
	 *                     attachment author.
	 * @return array<string, mixed>|\WP_Error The job status payload (see
	 *                                        get_status()), or a WP_Error.
	 */
	public function start_job( int $post_id, int $user_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				/* translators: %d: Post ID. */
				sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
			);
		}

		$this->maybe_expire_job( $post_id );

		$status = (string) get_post_meta( $post_id, self::META_STATUS, true );

		if ( in_array( $status, array( 'pending', 'processing' ), true ) ) {
			return new WP_Error(
				'job_in_progress',
				esc_html__( 'Audio generation is already in progress for this post.', 'ai' )
			);
		}

		// Match how other abilities read post content for AI context.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$body = normalize_content( (string) apply_filters( 'the_content', $post->post_content ) );

		// Require actual body content: narrating a bare title is not a useful
		// audio version of the post, and the title alone is never enough.
		if ( '' === $body ) {
			return new WP_Error(
				'no_content',
				esc_html__( 'This post has no content to generate audio from.', 'ai' )
			);
		}

		// Prepend the post title so the audio announces it before the body.
		// The period gives text to speech a sentence break between the two.
		$title   = normalize_content( get_the_title( $post ) );
		$content = '' !== $title ? $title . '. ' . $body : $body;

		/**
		 * Filters the maximum chunk length, in characters, for text to
		 * speech generation.
		 *
		 * The default of 4000 leaves headroom under most provider limits.
		 *
		 * @since 1.5.0
		 *
		 * @param int $max_length The maximum chunk length in characters.
		 * @param int $post_id    The post being converted.
		 */
		$max_length = (int) apply_filters( 'wpai_tts_max_chunk_length', 4000, $post_id );

		$chunks = Content_Chunker::chunk( $content, $max_length );

		if ( empty( $chunks ) ) {
			return new WP_Error(
				'no_content',
				esc_html__( 'This post has no content to generate audio from.', 'ai' )
			);
		}

		// Remove any temp file left behind by a previous job.
		$this->delete_temp_file( $post_id );

		$upload_dir = wp_upload_dir();
		$temp_file  = trailingslashit( $upload_dir['basedir'] ) . sprintf(
			'%s%d-%s.part',
			self::TEMP_FILE_PREFIX,
			$post_id,
			wp_generate_password( 8, false )
		);

		$voice = (string) get_option( 'wpai_feature_' . self::FEATURE_ID . '_field_voice', '' );

		/*
		 * Resolve the default once, here, so it is frozen into the job below.
		 * Resolving per chunk would let a mid-job change in connector
		 * availability switch voices partway through the audio.
		 */
		if ( '' === $voice ) {
			$voice = ( new Voice_Resolver() )->get_default_voice();
		}

		// Chunks can only be joined as MP3.
		if ( count( $chunks ) > 1 ) {
			$support = ( new Speech_Generator() )->check_support( $voice, Audio_Combiner::MIME_TYPE );

			if ( is_wp_error( $support ) ) {
				return $support;
			}
		}

		$job = array(
			'chunks'    => $chunks,
			'next'      => 0,
			'total'     => count( $chunks ),
			'temp_file' => $temp_file,
			'mime_type' => '',
			'voice'     => $voice,
			'user_id'   => $user_id,
			'token'     => wp_generate_password( 20, false ),
			'started'   => time(),
		);

		update_post_meta( $post_id, self::META_JOB, $job );
		update_post_meta( $post_id, self::META_STATUS, 'pending' );
		update_post_meta( $post_id, self::META_ERROR, '' );
		update_post_meta( $post_id, self::META_UPDATED, time() );

		$this->schedule_next( $post_id );

		return $this->get_status( $post_id );
	}

	/**
	 * Processes the next pending chunk for a post. Cron callback.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post being converted.
	 */
	public function process_chunk( int $post_id ): void {
		$lock = new Lock( 'tts_job_' . $post_id, $this->get_processing_timeout( $post_id ) );

		if ( ! $lock->acquire() ) {
			// Another runner is working on this post and schedules the next
			// chunk itself when it finishes; this is only a fallback.
			Scheduler::schedule_at( self::CRON_HOOK, time() + self::LOCKED_RETRY_SECONDS, array( $post_id ) );
			return;
		}

		try {
			$has_more = $this->run_chunk( $post_id );
		} finally {
			$lock->release();
		}

		if ( ! $has_more ) {
			return;
		}

		$this->schedule_next( $post_id );
	}

	/**
	 * Generates audio for the next chunk and appends it to the temp file,
	 * finalizing the job after the last chunk.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post being converted.
	 * @return bool True when another chunk remains to be scheduled.
	 */
	private function run_chunk( int $post_id ): bool {
		$job = $this->get_fresh_job( $post_id );

		if ( ! is_array( $job ) || ! isset( $job['chunks'], $job['next'], $job['total'], $job['temp_file'], $job['token'] ) ) {
			return false;
		}

		update_post_meta( $post_id, self::META_STATUS, 'processing' );
		update_post_meta( $post_id, self::META_UPDATED, time() );

		$index = (int) $job['next'];
		$total = (int) $job['total'];

		if ( ! isset( $job['chunks'][ $index ] ) ) {
			$this->fail_job( $post_id, $job, esc_html__( 'Audio generation state was corrupted. Please try again.', 'ai' ) );
			return false;
		}

		$result = ( new Speech_Generator() )->generate_chunk(
			(string) $job['chunks'][ $index ],
			(string) $job['voice'],
			$total > 1 ? Audio_Combiner::MIME_TYPE : ''
		);

		// The provider call can be slow. If the job was expired, restarted, or
		// deleted meanwhile, this result belongs to a run that no longer exists.
		if ( ! $this->is_current_job( $post_id, (string) $job['token'] ) ) {
			return false;
		}

		if ( is_wp_error( $result ) ) {
			$this->fail_job( $post_id, $job, $result->get_error_message() );
			return false;
		}

		$bytes = base64_decode( $result['data'], true );

		if ( false === $bytes || '' === $bytes ) {
			$this->fail_job( $post_id, $job, esc_html__( 'The provider returned invalid audio data.', 'ai' ) );
			return false;
		}

		$is_first = 0 === $index;
		$is_last  = $index + 1 >= $total;

		if ( $is_first ) {
			$job['mime_type'] = $result['mime_type'];
		} elseif ( $job['mime_type'] !== $result['mime_type'] ) {
			$this->fail_job( $post_id, $job, esc_html__( 'The provider returned inconsistent audio formats across chunks.', 'ai' ) );
			return false;
		}

		if ( $total > 1 && Audio_Combiner::MIME_TYPE !== $job['mime_type'] ) {
			$this->fail_job( $post_id, $job, esc_html__( 'Combining audio chunks requires MP3 output, which the provider did not return.', 'ai' ) );
			return false;
		}

		$appended = Audio_Combiner::append_chunk( (string) $job['temp_file'], $bytes, $is_first, $is_last );

		if ( is_wp_error( $appended ) ) {
			$this->fail_job( $post_id, $job, $appended->get_error_message() );
			return false;
		}

		$job['next'] = $index + 1;
		update_post_meta( $post_id, self::META_JOB, $job );
		update_post_meta( $post_id, self::META_UPDATED, time() );

		if ( ! $is_last ) {
			return true;
		}

		$this->finalize_job( $post_id, $job );

		return false;
	}

	/**
	 * Checks whether the stored job is still the run with the given token.
	 *
	 * @since 1.5.0
	 *
	 * @param int    $post_id The post ID.
	 * @param string $token   The token of the run being processed.
	 * @return bool True when the stored job carries the token.
	 */
	private function is_current_job( int $post_id, string $token ): bool {
		$job = $this->get_fresh_job( $post_id );

		return is_array( $job ) && isset( $job['token'] ) && $token === $job['token'];
	}

	/**
	 * Returns the stored job state, bypassing this request's meta cache.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post ID.
	 * @return mixed The stored job state, or an empty string when there is none.
	 */
	private function get_fresh_job( int $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );

		return get_post_meta( $post_id, self::META_JOB, true );
	}

	/**
	 * Returns the current job/audio status payload for a post.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post ID.
	 * @return array{status: string, done: int, total: int, error: string, audio_id: int, audio_url: string, display_audio: bool} The status payload.
	 */
	public function get_status( int $post_id ): array {
		$this->maybe_expire_job( $post_id );

		$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
		$job    = get_post_meta( $post_id, self::META_JOB, true );
		$error  = (string) get_post_meta( $post_id, self::META_ERROR, true );

		$audio_id  = absint( get_post_meta( $post_id, self::META_AUDIO_ID, true ) );
		$audio_url = $audio_id ? (string) wp_get_attachment_url( $audio_id ) : '';

		return array(
			'status'        => '' === $status ? 'idle' : $status,
			'done'          => is_array( $job ) ? (int) $job['next'] : 0,
			'total'         => is_array( $job ) ? (int) $job['total'] : 0,
			'error'         => $error,
			'audio_id'      => $audio_id,
			'audio_url'     => $audio_url,
			'display_audio' => (bool) get_post_meta( $post_id, self::META_DISPLAY, true ),
		);
	}

	/**
	 * Fails an in-progress job that has made no progress within its timeout.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post ID.
	 */
	private function maybe_expire_job( int $post_id ): void {
		$status = (string) get_post_meta( $post_id, self::META_STATUS, true );

		if ( 'pending' === $status ) {
			$timeout = $this->get_pending_timeout( $post_id );
			$message = __( 'Audio generation never started. Background processing (WP-Cron) does not appear to be running on this site.', 'ai' );
		} elseif ( 'processing' === $status ) {
			$timeout = $this->get_processing_timeout( $post_id );
			$message = __( 'Audio generation stopped responding. Please try again.', 'ai' );
		} else {
			return;
		}

		$updated = (int) get_post_meta( $post_id, self::META_UPDATED, true );

		if ( time() - $updated < $timeout ) {
			return;
		}

		$job = get_post_meta( $post_id, self::META_JOB, true );

		wp_clear_scheduled_hook( self::CRON_HOOK, array( $post_id ) );
		$this->fail_job( $post_id, is_array( $job ) ? $job : array(), $message );
	}

	/**
	 * Returns the seconds a job may stay pending before it is failed.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post ID.
	 * @return int The pending timeout, in seconds.
	 */
	private function get_pending_timeout( int $post_id ): int {
		/**
		 * Filters how long a text to speech job may wait for WP-Cron to start
		 * it before it is failed.
		 *
		 * Raise this on sites whose system cron runs less often than once a
		 * minute.
		 *
		 * @since 1.5.0
		 *
		 * @param int $timeout The timeout, in seconds.
		 * @param int $post_id The post ID.
		 */
		return (int) apply_filters( 'wpai_tts_pending_timeout', self::PENDING_TIMEOUT_SECONDS, $post_id );
	}

	/**
	 * Returns the seconds a processing job may go before it is failed.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post ID.
	 * @return int The processing timeout, in seconds.
	 */
	private function get_processing_timeout( int $post_id ): int {
		// Core defines WP_CRON_LOCK_TIMEOUT (default one minute) at bootstrap.
		$cron_lock = defined( 'WP_CRON_LOCK_TIMEOUT' ) ? (int) constant( 'WP_CRON_LOCK_TIMEOUT' ) : MINUTE_IN_SECONDS;

		$timeout = get_default_request_timeout( self::FEATURE_ID, Speech_Generator::DEFAULT_REQUEST_TIMEOUT )
			+ $cron_lock
			+ self::PROCESSING_TIMEOUT_SLACK_SECONDS;

		/**
		 * Filters how long a text to speech job may go without progress
		 * before it is failed.
		 *
		 * Each chunk after the first is run by its own WP-Cron event. With
		 * WP-Cron disabled, that event waits for the next system cron run, so
		 * raise this above the system cron interval on such sites.
		 *
		 * @since 1.5.0
		 *
		 * @param int $timeout The timeout, in seconds. Default the provider
		 *                     request timeout plus the WP-Cron lock timeout
		 *                     plus 60 seconds.
		 * @param int $post_id The post ID.
		 */
		return max( 1, (int) apply_filters( 'wpai_tts_processing_timeout', $timeout, $post_id ) );
	}

	/**
	 * Deletes a post's generated audio and all of its text to speech state.
	 *
	 * Cancels any in-flight job (scheduled cron event and temp file), deletes
	 * the audio attachment, and removes every piece of text to speech post
	 * meta, returning the post to the state it was in before any audio was
	 * generated.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post whose audio should be deleted.
	 * @return array{status: string, done: int, total: int, error: string, audio_id: int, audio_url: string, display_audio: bool} The status payload after deletion.
	 */
	public function delete_audio( int $post_id ): array {
		// Stop any in-flight job before removing the state it depends on.
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $post_id ) );
		$this->delete_temp_file( $post_id );

		$audio_id = absint( get_post_meta( $post_id, self::META_AUDIO_ID, true ) );

		if ( $audio_id ) {
			wp_delete_attachment( $audio_id, true );
		}

		foreach (
			array(
				self::META_AUDIO_ID,
				self::META_STATUS,
				self::META_ERROR,
				self::META_UPDATED,
				self::META_JOB,
				self::META_DISPLAY,
			) as $meta_key
		) {
			delete_post_meta( $post_id, $meta_key );
		}

		return $this->get_status( $post_id );
	}

	/**
	 * Imports the combined audio file as an attachment and completes the job.
	 *
	 * The previously generated attachment (if any) is deleted only after the
	 * new one has been created successfully, so regeneration can never leave
	 * the post without audio.
	 *
	 * @since 1.5.0
	 *
	 * @param int                  $post_id The post ID.
	 * @param array<string, mixed> $job     The job state.
	 */
	protected function finalize_job( int $post_id, array $job ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$mime_type = (string) $job['mime_type'];
		$extension = wp_get_default_extension_for_mime_type( $mime_type );

		if ( ! $extension ) {
			$extension = 'mp3';
		}

		/**
		 * Filters the base filename (without extension) used when importing
		 * generated post audio.
		 *
		 * The returned value is sanitized via `sanitize_file_name()` and the
		 * extension is appended afterwards.
		 *
		 * @since 1.5.0
		 *
		 * @param string $filename The base filename, without extension.
		 * @param int    $post_id  The post the audio belongs to.
		 */
		$filename = (string) apply_filters(
			'wpai_tts_audio_filename',
			sprintf( 'post-audio-%d-%s', $post_id, strtolower( wp_generate_password( 12, false ) ) ),
			$post_id
		);

		$file_array = array(
			'name'     => sanitize_file_name( $filename ) . '.' . $extension,
			'type'     => $mime_type,
			'tmp_name' => (string) $job['temp_file'],
		);

		$post_data = array(
			'post_title'     => sprintf(
				/* translators: %s: Post title. */
				__( 'Audio for “%s”', 'ai' ),
				get_the_title( $post_id )
			),
			'post_mime_type' => $mime_type,
			'post_status'    => $this->get_audio_post_status( $post_id ),
			'post_author'    => (int) $job['user_id'],
			'meta_input'     => array(
				'wpai_generated' => 1,
			),
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, null, $post_data );

		// media_handle_sideload() moves the temp file on success; remove it
		// explicitly if it is still around (failure path).
		if ( file_exists( (string) $job['temp_file'] ) ) {
			wp_delete_file( (string) $job['temp_file'] );
		}

		if ( is_wp_error( $attachment_id ) ) {
			$this->fail_job( $post_id, $job, $attachment_id->get_error_message() );
			return;
		}

		$old_audio_id = absint( get_post_meta( $post_id, self::META_AUDIO_ID, true ) );

		update_post_meta( $post_id, self::META_AUDIO_ID, $attachment_id );
		update_post_meta( $post_id, self::META_STATUS, 'complete' );
		update_post_meta( $post_id, self::META_ERROR, '' );
		update_post_meta( $post_id, self::META_UPDATED, time() );
		delete_post_meta( $post_id, self::META_JOB );

		if ( ! $old_audio_id || $old_audio_id === $attachment_id ) {
			return;
		}

		wp_delete_attachment( $old_audio_id, true );
	}

	/**
	 * Updates the post's audio attachment to match the post's password state.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post whose audio should be updated.
	 */
	public function sync_audio_visibility( int $post_id ): void {
		$audio_id = absint( get_post_meta( $post_id, self::META_AUDIO_ID, true ) );
		$audio    = $audio_id ? get_post( $audio_id ) : null;

		if ( ! $audio || 'attachment' !== $audio->post_type ) {
			return;
		}

		$status = $this->get_audio_post_status( $post_id );

		if ( $status === $audio->post_status ) {
			return;
		}

		wp_update_post(
			array(
				'ID'          => $audio_id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Returns the post status a post's audio attachment should have.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post the audio belongs to.
	 * @return string 'private' while the post has a password, otherwise 'inherit'.
	 */
	private function get_audio_post_status( int $post_id ): string {
		$post = get_post( $post_id );

		return $post && '' !== $post->post_password ? 'private' : 'inherit';
	}

	/**
	 * Marks a job as failed and cleans up its transient state.
	 *
	 * Previously generated audio (and its meta) is intentionally preserved.
	 *
	 * @since 1.5.0
	 *
	 * @param int                  $post_id The post ID.
	 * @param array<string, mixed> $job     The job state.
	 * @param string               $message The error message to store.
	 */
	protected function fail_job( int $post_id, array $job, string $message ): void {
		if ( ! empty( $job['temp_file'] ) && file_exists( (string) $job['temp_file'] ) ) {
			wp_delete_file( (string) $job['temp_file'] );
		}

		update_post_meta( $post_id, self::META_STATUS, 'error' );
		update_post_meta( $post_id, self::META_ERROR, $message );
		update_post_meta( $post_id, self::META_UPDATED, time() );
		delete_post_meta( $post_id, self::META_JOB );
	}

	/**
	 * Schedules the next chunk event and asks WP-Cron to spawn immediately.
	 *
	 * The editor's status polling issues REST requests every few seconds
	 * while a job runs, which also triggers WP-Cron spawning on low-traffic
	 * sites.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post ID.
	 */
	protected function schedule_next( int $post_id ): void {
		Scheduler::schedule_at( self::CRON_HOOK, time(), array( $post_id ) );

		if ( ! function_exists( 'spawn_cron' ) ) {
			return;
		}

		spawn_cron();
	}

	/**
	 * Deletes the temp file referenced by a post's current job blob, if any.
	 *
	 * @since 1.5.0
	 *
	 * @param int $post_id The post ID.
	 */
	protected function delete_temp_file( int $post_id ): void {
		$job = get_post_meta( $post_id, self::META_JOB, true );

		if ( ! is_array( $job ) || empty( $job['temp_file'] ) || ! file_exists( (string) $job['temp_file'] ) ) {
			return;
		}

		wp_delete_file( (string) $job['temp_file'] );
	}
}
