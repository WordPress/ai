<?php
/**
 * Text to speech background job manager.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Text_To_Speech;

use WP_Error;

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
 * @since x.x.x
 */
class Job_Manager {

	/**
	 * Cron hook fired once per chunk. Receives the post ID as its argument.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const CRON_HOOK = 'wpai_tts_process_chunk';

	/**
	 * The feature ID this manager belongs to.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const FEATURE_ID = 'text-to-speech';

	/**
	 * Post meta key holding the generated audio attachment ID.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const META_AUDIO_ID = 'wpai_tts_audio_id';

	/**
	 * Post meta key holding the front-end display toggle.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const META_DISPLAY = 'wpai_tts_display_audio';

	/**
	 * Post meta key holding the job status.
	 *
	 * One of '' (never run), 'pending', 'processing', 'complete', 'error'.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const META_STATUS = 'wpai_tts_status';

	/**
	 * Post meta key holding the last error message.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const META_ERROR = 'wpai_tts_error';

	/**
	 * Post meta key holding the last-updated timestamp, used for stuck-job
	 * detection.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const META_UPDATED = 'wpai_tts_updated';

	/**
	 * Post meta key holding the transient job state blob.
	 *
	 * Deliberately not registered for REST; the editor reads job state
	 * through the status endpoint instead.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const META_JOB = 'wpai_tts_job';

	/**
	 * Seconds after which a pending/processing job with no progress is
	 * considered stuck and may be restarted.
	 *
	 * @since x.x.x
	 * @var int
	 */
	public const STALE_JOB_SECONDS = 600;

	/**
	 * Starts (or restarts) an audio generation job for a post.
	 *
	 * Builds the chunk list synchronously so content problems surface
	 * immediately, persists job state in post meta, and schedules the first
	 * chunk event. Any previously generated audio attachment is left in
	 * place until the new audio has been imported successfully.
	 *
	 * @since x.x.x
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

		$status  = (string) get_post_meta( $post_id, self::META_STATUS, true );
		$updated = (int) get_post_meta( $post_id, self::META_UPDATED, true );

		if (
			in_array( $status, array( 'pending', 'processing' ), true ) &&
			( time() - $updated ) < self::STALE_JOB_SECONDS
		) {
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
		 * @since x.x.x
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
			'wpai-tts-%d-%s.part',
			$post_id,
			wp_generate_password( 8, false )
		);

		$job = array(
			'chunks'    => $chunks,
			'next'      => 0,
			'total'     => count( $chunks ),
			'temp_file' => $temp_file,
			'mime_type' => '',
			'voice'     => (string) get_option( 'wpai_feature_' . self::FEATURE_ID . '_field_voice', '' ),
			'user_id'   => $user_id,
			'hash'      => md5( $content ),
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
	 * Generates audio for one chunk, appends it to the temp file, and either
	 * schedules the next chunk event or finalizes the job.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id The post being converted.
	 */
	public function process_chunk( int $post_id ): void {
		$job = get_post_meta( $post_id, self::META_JOB, true );

		if ( ! is_array( $job ) || ! isset( $job['chunks'], $job['next'], $job['total'], $job['temp_file'] ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_STATUS, 'processing' );
		update_post_meta( $post_id, self::META_UPDATED, time() );

		$index = (int) $job['next'];
		$total = (int) $job['total'];

		if ( ! isset( $job['chunks'][ $index ] ) ) {
			$this->fail_job( $post_id, $job, esc_html__( 'Audio generation state was corrupted. Please try again.', 'ai' ) );
			return;
		}

		$result = ( new Speech_Generator() )->generate_chunk( (string) $job['chunks'][ $index ], (string) $job['voice'] );

		if ( is_wp_error( $result ) ) {
			$this->fail_job( $post_id, $job, $result->get_error_message() );
			return;
		}

		$bytes = base64_decode( $result['data'], true );

		if ( false === $bytes || '' === $bytes ) {
			$this->fail_job( $post_id, $job, esc_html__( 'The provider returned invalid audio data.', 'ai' ) );
			return;
		}

		$is_first = 0 === $index;
		$is_last  = $index + 1 >= $total;

		if ( $is_first ) {
			$job['mime_type'] = $result['mime_type'];
		} elseif ( $job['mime_type'] !== $result['mime_type'] ) {
			$this->fail_job( $post_id, $job, esc_html__( 'The provider returned inconsistent audio formats across chunks.', 'ai' ) );
			return;
		}

		if ( $total > 1 && 'audio/mpeg' !== $job['mime_type'] ) {
			$this->fail_job( $post_id, $job, esc_html__( 'Combining audio chunks requires MP3 output, which the provider did not return.', 'ai' ) );
			return;
		}

		$appended = Audio_Combiner::append_chunk( (string) $job['temp_file'], $bytes, $is_first, $is_last );

		if ( is_wp_error( $appended ) ) {
			$this->fail_job( $post_id, $job, $appended->get_error_message() );
			return;
		}

		$job['next'] = $index + 1;
		update_post_meta( $post_id, self::META_JOB, $job );
		update_post_meta( $post_id, self::META_UPDATED, time() );

		if ( ! $is_last ) {
			$this->schedule_next( $post_id );
			return;
		}

		$this->finalize_job( $post_id, $job );
	}

	/**
	 * Returns the current job/audio status payload for a post.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id The post ID.
	 * @return array{status: string, done: int, total: int, error: string, audio_id: int, audio_url: string, display_audio: bool} The status payload.
	 */
	public function get_status( int $post_id ): array {
		$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
		$job    = get_post_meta( $post_id, self::META_JOB, true );

		$audio_id  = absint( get_post_meta( $post_id, self::META_AUDIO_ID, true ) );
		$audio_url = $audio_id ? (string) wp_get_attachment_url( $audio_id ) : '';

		return array(
			'status'        => '' === $status ? 'idle' : $status,
			'done'          => is_array( $job ) ? (int) $job['next'] : 0,
			'total'         => is_array( $job ) ? (int) $job['total'] : 0,
			'error'         => (string) get_post_meta( $post_id, self::META_ERROR, true ),
			'audio_id'      => $audio_id,
			'audio_url'     => $audio_url,
			'display_audio' => (bool) get_post_meta( $post_id, self::META_DISPLAY, true ),
		);
	}

	/**
	 * Deletes a post's generated audio and all of its text to speech state.
	 *
	 * Cancels any in-flight job (scheduled cron event and temp file), deletes
	 * the audio attachment, and removes every piece of text to speech post
	 * meta, returning the post to the state it was in before any audio was
	 * generated.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
		 * @since x.x.x
		 *
		 * @param string $filename The base filename, without extension.
		 * @param int    $post_id  The post the audio belongs to.
		 */
		$filename = (string) apply_filters( 'wpai_tts_audio_filename', 'post-audio-' . $post_id, $post_id );

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
	 * Marks a job as failed and cleans up its transient state.
	 *
	 * Previously generated audio (and its meta) is intentionally preserved.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 *
	 * @param int $post_id The post ID.
	 */
	protected function schedule_next( int $post_id ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, array( $post_id ) );
		}

		if ( ! function_exists( 'spawn_cron' ) ) {
			return;
		}

		spawn_cron();
	}

	/**
	 * Deletes the temp file referenced by a post's current job blob, if any.
	 *
	 * @since x.x.x
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
