<?php
/**
 * C2PA Monitor experiment.
 *
 * Read-only capture of C2PA Content Credentials presence and the raw
 * JUMBF manifest bytes at attachment upload. Stores a structured record
 * in postmeta and writes the raw manifest to a sidecar file.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\C2pa_Monitor;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C2PA Monitor experiment class.
 *
 * Hooks into add_attachment and captures a structured `_wpai_monitor_record`
 * for every uploaded image. The capture is read-only, fail-open, and never
 * blocks the upload pipeline.
 *
 * @since x.x.x
 */
class C2pa_Monitor extends Abstract_Feature {
	/**
	 * Postmeta key used to store the structured monitor record.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const POSTMETA_KEY = '_wpai_monitor_record';

	/**
	 * Postmeta key used for sortable column ordering.
	 *
	 * Stores a single integer: 1 = credentials present, 0 = absent.
	 * Written alongside POSTMETA_KEY so the Media Library can ORDER BY it.
	 * Not written when no scan record exists (unsupported MIME / pre-existing upload).
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const SORT_META_KEY = '_wpai_c2pa_present';

	/**
	 * Schema version for the postmeta record. Increment on breaking changes.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Hard cap on a single image scan. Files larger than this are skipped.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_SCAN_BYTES = 67108864; // 64 MB.

	/**
	 * JSON-LD context URL embedded in every stored postmeta record.
	 *
	 * Permanent identifier served via w3id.org, which 302-redirects to the
	 * OpenVerifiable JSON-LD context maintained in the DIF credential-schemas
	 * repo (community-schemas/WordPress/schemas/wpai-monitor-record/context.json).
	 * Using the w3id.org identifier keeps the value baked into every stored
	 * record stable even if the underlying document location changes. Bump
	 * SCHEMA_VERSION only if the context vocabulary itself changes, not when
	 * the redirect target moves.
	 *
	 * @see https://github.com/perma-id/w3id.org/pull/6376 w3id.org redirect registration.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CONTEXT_URL = 'https://w3id.org/openverifiable/v1';

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'c2pa-monitor';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'C2PA Monitor', 'ai' ),
			'description' => __( 'Detects C2PA Content Credentials in uploaded images, writes the raw manifest to a sidecar file, and stores a structured record in postmeta. Read-only and fail-open; never blocks an upload.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'stability'   => 'experimental',
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'add_attachment', array( $this, 'capture_for_attachment' ), 20, 1 );
		add_action( 'delete_attachment', array( $this, 'delete_sidecar_for_attachment' ), 10, 1 );
		add_filter( 'manage_media_columns', array( $this, 'add_media_column' ) );
		add_filter( 'manage_upload_columns', array( $this, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_filter( 'manage_upload_sortable_columns', array( $this, 'register_sortable_column' ) );
		add_filter( 'posts_clauses', array( $this, 'sort_by_c2pa_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_fields' ), 10, 2 );
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_attachment_meta_box' ) );
	}

	/**
	 * Enqueues shared admin CSS on every admin page.
	 *
	 * Using admin_enqueue_scripts (rather than admin_head-upload.php /
	 * admin_head-post.php) ensures the styles are present whenever the media
	 * modal is opened — including from the block editor, the widgets screen,
	 * the customizer, and any third-party admin page that embeds the media
	 * library frame.
	 *
	 * wp_register_style with a false src is the canonical way to attach
	 * inline-only styles without registering a real file.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function enqueue_admin_styles(): void {
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion -- No real resource; version is irrelevant for inline-only styles.
		wp_register_style( 'wpai-c2pa-monitor', false, array(), false );
		wp_enqueue_style( 'wpai-c2pa-monitor' );
		wp_add_inline_style(
			'wpai-c2pa-monitor',
			// Tooltip for the compact Media Library list-table column.
			'[data-wpai-tooltip]{position:relative}'
			. '[data-wpai-tooltip]::after{'
			. 'content:attr(data-wpai-tooltip);'
			. 'display:none;'
			. 'position:absolute;'
			. 'bottom:calc(100% + 6px);'
			. 'left:50%;'
			. 'transform:translateX(-50%);'
			. 'background:#1d2327;'
			. 'color:#fff;'
			. 'font-size:12px;'
			. 'line-height:1.4;'
			. 'padding:5px 8px;'
			. 'border-radius:3px;'
			. 'white-space:normal;'
			. 'width:200px;'
			. 'text-align:center;'
			. 'z-index:9999;'
			. 'pointer-events:none;'
			. '}'
			. '[data-wpai-tooltip]:hover::after{display:block}'
			// Alignment fix for the compat field in the Attachment Details modal
			// and the upload.php?item=<id> screen. Core lays these rows out as
			// floats rather than table cells: the label is float:left with
			// margin-right:4% and the field is float:right at width:65%. The
			// label therefore has to stay at 30% for the two to share a line —
			// anything wider overflows and drops the field onto its own row.
			. '.compat-field-wpai_c2pa th.label{width:30%}'
			// Core floats the label span and pads it 8px from the top so it
			// centres against an input. This field renders static text instead,
			// so undo both to line the label up with the first line of the value.
			. '.compat-field-wpai_c2pa th.label span.alignleft{float:none;display:inline}'
			. '.compat-field-wpai_c2pa th.label br.clear{display:none}'
		);
	}

	/**
	 * Registers the C2PA status column in the Media Library list table.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_media_column( array $columns ): array {
		$columns['wpai_c2pa'] = __( 'Content Credentials', 'ai' );
		return $columns;
	}

	/**
	 * Returns the HTML badge representing the C2PA status for the given attachment.
	 *
	 * Used by render_media_column(), add_attachment_fields(), and
	 * render_attachment_meta_box(). Returns one of three states:
	 * - "✓ Credentials" (linked to the CAI verify tool) when a manifest was detected.
	 * - "No credentials" when the attachment was scanned and none were found.
	 * - "—" when no scan record exists (e.g. uploaded before the experiment
	 *   was enabled, or a non-image MIME type).
	 *
	 * Pass `false` for $with_tooltip when rendering on screens that have
	 * enough room for a visible help text paragraph; the CSS tooltip is best
	 * reserved for the compact Media Library list table column.
	 *
	 * @since x.x.x
	 *
	 * @param int  $post_id      The attachment post ID.
	 * @param bool $with_tooltip Whether to emit the data-wpai-tooltip attribute.
	 * @return string HTML fragment (already escaped).
	 */
	private function get_status_html( int $post_id, bool $with_tooltip = true ): string {
		$raw = get_post_meta( $post_id, self::POSTMETA_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '<span aria-label="' . esc_attr__( 'Not scanned', 'ai' ) . '">—</span>';
		}

		$record = json_decode( $raw, true );
		if ( ! is_array( $record ) || ! isset( $record['c2pa']['present'] ) ) {
			return '<span aria-label="' . esc_attr__( 'Not scanned', 'ai' ) . '">—</span>';
		}

		if ( $record['c2pa']['present'] ) {
			$tooltip = $with_tooltip
				? ' data-wpai-tooltip="' . esc_attr__( 'Unverified — credentials were detected but have not been validated. Click to open the Content Authenticity Initiative verify tool.', 'ai' ) . '"'
				: '';
			return '<a href="https://verify.contentauthenticity.org/" target="_blank" rel="noopener noreferrer"'
				. ' style="color:#2271b1;text-decoration:none"'
				. $tooltip . '>'
				. '&#10003; ' . esc_html__( 'Credentials', 'ai' )
				. '</a>';
		}

		$tooltip = $with_tooltip
			? ' data-wpai-tooltip="' . esc_attr__( 'No C2PA Content Credentials were detected in this file.', 'ai' ) . '"'
			: '';
		return '<span style="color:#666"' . $tooltip . '>'
			. esc_html__( 'No credentials', 'ai' )
			. '</span>';
	}

	/**
	 * Returns a plain-text explanation of the C2PA status for the given attachment.
	 *
	 * Intended for use as visible help text on screens with enough room to
	 * display it (Attachment details, Edit Media meta box), where a CSS tooltip
	 * would either clip or be inaccessible.
	 *
	 * Returns an empty string for the "not scanned" state because "—" is
	 * self-explanatory in context and a missing explanation is less confusing
	 * than a generic one.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id The attachment post ID.
	 * @return string Plain text (already escaped via esc_html__()).
	 */
	private function get_status_help_text( int $post_id ): string {
		$raw = get_post_meta( $post_id, self::POSTMETA_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		$record = json_decode( $raw, true );
		if ( ! is_array( $record ) || ! isset( $record['c2pa']['present'] ) ) {
			return '';
		}

		if ( $record['c2pa']['present'] ) {
			return esc_html__(
				'C2PA Content Credentials were detected in this file. To verify them, download the original image and drag it into the Content Authenticity Initiative verify tool.',
				'ai'
			);
		}

		return esc_html__( 'No C2PA Content Credentials were detected in this file.', 'ai' );
	}

	/**
	 * Renders the C2PA status cell for the given attachment.
	 *
	 * Outputs one of three states:
	 * - "✓ Credentials" when a C2PA manifest was detected.
	 * - "No credentials" when the attachment was scanned and none were found.
	 * - "—" when no scan record exists (e.g. uploaded before the experiment
	 *   was enabled, or a non-image MIME type).
	 *
	 * @since x.x.x
	 *
	 * @param string $column_name The column being rendered.
	 * @param int    $post_id     The attachment post ID.
	 * @return void
	 */
	public function render_media_column( string $column_name, int $post_id ): void {
		if ( 'wpai_c2pa' !== $column_name ) {
			return;
		}

		if ( ! wp_attachment_is_image( $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_status_html() returns pre-escaped HTML.
		echo $this->get_status_html( $post_id );
	}

	/**
	 * Adds a Content Credentials field to the Attachment Details panel.
	 *
	 * Fires on the `attachment_fields_to_edit` filter, which populates fields
	 * shown in the media modal and on the `upload.php?item=<id>` screen.
	 *
	 * `show_in_edit` is set to false so this field is suppressed on the classic
	 * Edit Media screen (`post.php?post=<id>&action=edit`), where the meta box
	 * registered via add_attachment_meta_box() is shown instead. Without this
	 * flag WordPress would render the field in the main column *and* the meta
	 * box in the sidebar, duplicating the information.
	 *
	 * Help text is provided via the `helps` key rather than a CSS tooltip because
	 * the tooltip's `position:absolute` positioning gets clipped by the media
	 * modal's overflow container.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $form_fields Existing form fields.
	 * @param \WP_Post             $post        The attachment post object.
	 * @return array<string, mixed>
	 */
	public function add_attachment_fields( array $form_fields, \WP_Post $post ): array {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$form_fields['wpai_c2pa'] = array(
			'label'        => __( 'Content Credentials', 'ai' ),
			'input'        => 'html',
			'show_in_edit' => false,
			'html'         => $this->get_status_html( $post->ID, false ),
			'helps'        => $this->get_status_help_text( $post->ID ),
		);
		return $form_fields;
	}

	/**
	 * Registers the Content Credentials meta box on the Edit Media screen.
	 *
	 * Fires on the `add_meta_boxes_attachment` action, which runs when loading
	 * the classic `post.php?post=<id>&action=edit` screen for an attachment.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The attachment post object.
	 * @return void
	 */
	public function add_attachment_meta_box( \WP_Post $post ): void {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return;
		}

		add_meta_box(
			'wpai-c2pa-monitor',
			__( 'Content Credentials', 'ai' ),
			array( $this, 'render_attachment_meta_box' ),
			'attachment',
			'side',
			'default'
		);
	}

	/**
	 * Renders the Content Credentials meta box on the Edit Media screen.
	 *
	 * Outputs the status badge without the CSS tooltip (which is reserved for
	 * the compact list table column), followed by a visible help text paragraph.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The attachment post object.
	 * @return void
	 */
	public function render_attachment_meta_box( \WP_Post $post ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helpers return pre-escaped HTML/text.
		echo '<p>' . $this->get_status_html( $post->ID, false ) . '</p>';

		$help = $this->get_status_help_text( $post->ID );
		if ( '' === $help ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_status_help_text() uses esc_html__().
		echo '<p class="description">' . $help . '</p>';
	}

	/**
	 * Marks the Content Credentials column as sortable.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string|array<int, string|bool>> $columns Sortable columns map.
	 * @return array<string, string|array<int, string|bool>>
	 */
	public function register_sortable_column( array $columns ): array {
		// Second element `true` means the initial click sorts descending (credentials first).
		$columns['wpai_c2pa'] = array( 'wpai_c2pa', true );
		return $columns;
	}

	/**
	 * Modifies the Media Library query when sorting by the Content Credentials column.
	 *
	 * Attachments with credentials (sort key = 1) appear first on a descending
	 * sort; those with no credentials (0) come next; unscanned attachments
	 * (no sort meta row) appear last. A named clause with `compare => 'EXISTS'`
	 * uses a LEFT JOIN so rows without the meta key are still returned.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Query $query The current query.
	 * @return void
	 */
	/**
	 * Adds an explicit LEFT JOIN + COALESCE ORDER BY for the Content Credentials column.
	 *
	 * Hooked to `posts_clauses` (not `pre_get_posts`) so we can inject a named
	 * table alias directly into the SQL rather than going through `WP_Meta_Query`.
	 *
	 * The `WP_Meta_Query` approach (EXISTS + NOT EXISTS with `relation => OR`)
	 * rewrites every join to LEFT JOIN and adds DISTINCT, causing unscanned
	 * attachments — which have no `_wpai_c2pa_present` row but do have many
	 * other postmeta rows — to produce non-deterministic sort values (WordPress
	 * reads `wp_postmeta.meta_value` from an arbitrary row, often a serialised
	 * metadata blob, which sorts above '1' as a string).
	 *
	 * The LEFT JOIN here carries the meta_key condition in the ON clause, so
	 * every attachment gets exactly one joined row. COALESCE maps unscanned
	 * attachments (NULL) to -1, placing them last on a DESC sort and first on
	 * ASC. The handler is only active on the Media Library list screen.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $clauses   SQL clause fragments.
	 * @param \WP_Query             $query     The current query.
	 * @return array<string, string>
	 */
	public function sort_by_c2pa_column( array $clauses, \WP_Query $query ): array {
		if (
			! is_admin()
			|| ! $query->is_main_query()
			|| 'wpai_c2pa' !== $query->get( 'orderby' )
			|| ! function_exists( 'get_current_screen' )
			|| ! ( get_current_screen() instanceof \WP_Screen )
			|| 'upload' !== get_current_screen()->base
		) {
			return $clauses;
		}

		global $wpdb;

		// Appending the join a second time would produce a duplicate table
		// alias and fail the whole query, so bail if it is already present.
		if ( isset( $clauses['join'] ) && false !== strpos( $clauses['join'], 'wpai_c2pa_sort' ) ) {
			return $clauses;
		}

		$order = $query->get( 'order' );
		if ( ! is_string( $order ) || '' === $order ) {
			$order = 'DESC';
		}
		$order = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

		$clauses['join'] .= $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are trusted WordPress globals.
			" LEFT JOIN {$wpdb->postmeta} AS wpai_c2pa_sort ON ( {$wpdb->posts}.ID = wpai_c2pa_sort.post_id AND wpai_c2pa_sort.meta_key = %s )",
			self::SORT_META_KEY
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- order validated to ASC/DESC above; table names trusted globals.
		$clauses['orderby'] = "COALESCE( wpai_c2pa_sort.meta_value + 0, -1 ) {$order}, {$wpdb->posts}.ID DESC";

		return $clauses;
	}

	/**
	 * Removes the sidecar file when the attachment is deleted from the Media Library.
	 *
	 * Postmeta is removed automatically by WordPress core on delete. This handler
	 * cleans up the corresponding file in the ai-c2pa uploads subdirectory.
	 *
	 * @since x.x.x
	 *
	 * @param int $attachment_id The attachment post ID that is being deleted.
	 * @return void
	 */
	public function delete_sidecar_for_attachment( int $attachment_id ): void {
		( new Sidecar_Writer() )->delete( $attachment_id );
	}

	/**
	 * Captures C2PA presence and raw manifest for a freshly created attachment.
	 *
	 * Wrapped in a fail-open boundary: issues are recorded in the `errors`
	 * array inside the persisted postmeta (when this experiment applies to the
	 * attachment) alongside whatever partial data was collected. This handler
	 * never throws, never returns an error, and never blocks the upload.
	 * Unsupported MIME types are left untouched: no postmeta is written.
	 *
	 * @since x.x.x
	 *
	 * @param int $attachment_id The newly created attachment ID.
	 * @return void
	 */
	public function capture_for_attachment( int $attachment_id ): void {
		$started_at     = microtime( true );
		$should_persist = true;
		$errors         = array();
		$source         = array(
			'attachment_id'          => $attachment_id,
			'original_path_relative' => '',
			'size_bytes'             => 0,
			'mime'                   => '',
		);
		$c2pa           = array(
			'present' => false,
			'format'  => null,
		);

		try {
			$mime           = (string) get_post_mime_type( $attachment_id );
			$source['mime'] = $mime;

			if ( ! self::is_supported_mime( $mime ) ) {
				$should_persist = false;
				return;
			}

			$path = self::get_original_path( $attachment_id );
			if ( '' === $path || ! is_readable( $path ) ) {
				$errors[] = array(
					'stage'   => 'resolve_path',
					'message' => esc_html__( 'Attachment file is not readable.', 'ai' ),
				);
				return;
			}

			$size = filesize( $path );
			if ( false === $size ) {
				$errors[] = array(
					'stage'   => 'stat',
					'message' => esc_html__( 'Could not determine the file size.', 'ai' ),
				);
				return;
			}

			$source['size_bytes']             = (int) $size;
			$source['original_path_relative'] = self::relative_to_uploads( $path );

			if ( $size > self::MAX_SCAN_BYTES ) {
				$errors[] = array(
					'stage'   => 'size_cap',
					/* translators: %d: maximum number of bytes the scanner will read. */
					'message' => sprintf( esc_html__( 'File exceeds the maximum scan size of %d bytes.', 'ai' ), self::MAX_SCAN_BYTES ),
				);
				return;
			}

			$detector       = new Format_Detector();
			$format         = $detector->detect_format( $path );
			$c2pa['format'] = $format;

			if ( null === $format ) {
				return;
			}

			$location = $detector->find_manifest_location( $path, $format );
			if ( null === $location ) {
				return;
			}

			$reader   = new Manifest_Reader();
			$manifest = $reader->read( $path, $location );
			if ( null === $manifest ) {
				$errors[] = array(
					'stage'   => 'read_manifest',
					'message' => esc_html__( 'The manifest could not be read.', 'ai' ),
				);
				return;
			}

			// Record the detection result before attempting the sidecar write.
			// A write failure (disk full, permissions) must not erase the fact
			// that a manifest was successfully found and read.
			$c2pa = array(
				'present'               => true,
				'format'                => $manifest->format,
				'container'             => $manifest->container,
				'manifest_sha256'       => $manifest->sha256,
				'manifest_length'       => $manifest->bytes_length,
				'sidecar_path_relative' => null,
				'decoded'               => null,
			);

			try {
				$writer                        = new Sidecar_Writer();
				$c2pa['sidecar_path_relative'] = $writer->write( $attachment_id, $manifest );
			} catch ( \RuntimeException $e ) {
				$errors[] = array(
					'stage'   => 'sidecar_write',
					'message' => $e->getMessage(),
				);
			}
		} catch ( \RuntimeException $e ) {
			$errors[] = array(
				'stage'   => 'scan',
				'message' => $e->getMessage(),
			);
		} catch ( \Throwable $e ) {
			$errors[] = array(
				'stage'   => 'unexpected',
				'message' => $e->getMessage(),
			);
		} finally {
			if ( $should_persist ) {
				$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
				Record::store(
					$attachment_id,
					array(
						'@context'       => array( 'https://schema.org/', self::CONTEXT_URL ),
						'schema_version' => self::SCHEMA_VERSION,
						'captured_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
						'duration_ms'    => $duration_ms,
						'source'         => $source,
						'traditional'    => array(
							'exif' => array(),
							'iptc' => array(),
							'xmp'  => array(),
						),
						'c2pa'           => $c2pa,
						'errors'         => $errors,
					)
				);
			}
		}
	}

	/**
	 * Returns true for image MIME types this experiment knows how to inspect.
	 *
	 * @since x.x.x
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function is_supported_mime( string $mime ): bool {
		return in_array(
			$mime,
			array( 'image/jpeg', 'image/png', 'image/webp' ),
			true
		);
	}

	/**
	 * Resolves the absolute path to the original uploaded file.
	 *
	 * Falls back to get_attached_file() when wp_get_original_image_path() does
	 * not return a usable path (non-image attachments, edited media, etc.).
	 *
	 * @since x.x.x
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Absolute filesystem path, or empty string when unresolved.
	 */
	private static function get_original_path( int $attachment_id ): string {
		$path = wp_get_original_image_path( $attachment_id );
		if ( is_string( $path ) && '' !== $path ) {
			return $path;
		}

		$path = get_attached_file( $attachment_id );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Returns the path relative to the uploads basedir, or the absolute path
	 * if it lives outside uploads.
	 *
	 * @since x.x.x
	 *
	 * @param string $absolute Absolute path.
	 * @return string Relative path or original absolute path.
	 */
	private static function relative_to_uploads( string $absolute ): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return $absolute;
		}

		$basedir = trailingslashit( (string) $uploads['basedir'] );
		if ( 0 === strpos( $absolute, $basedir ) ) {
			return substr( $absolute, strlen( $basedir ) );
		}

		return $absolute;
	}
}
