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
		add_filter( 'manage_media_columns', array( $this, 'add_media_column' ) );
		add_filter( 'manage_upload_columns', array( $this, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_action( 'admin_head-upload.php', array( $this, 'print_admin_styles' ) );
		add_action( 'admin_head-post.php', array( $this, 'print_admin_styles' ) );
		add_filter( 'manage_upload_sortable_columns', array( $this, 'register_sortable_column' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_c2pa_column' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_fields' ), 10, 2 );
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_attachment_meta_box' ) );
	}

	/**
	 * Prints shared admin CSS.
	 *
	 * @since x.x.x
	 */
	public function print_admin_styles(): void {
		?>
		<style>
		[data-wpai-tooltip]{position:relative}
		[data-wpai-tooltip]::after{
			content:attr(data-wpai-tooltip);
			display:none;
			position:absolute;
			bottom:calc(100% + 6px);
			left:50%;
			transform:translateX(-50%);
			background:#1d2327;
			color:#fff;
			font-size:12px;
			line-height:1.4;
			padding:5px 8px;
			border-radius:3px;
			white-space:normal;
			width:200px;
			text-align:center;
			z-index:9999;
			pointer-events:none;
		}
		[data-wpai-tooltip]:hover::after{display:block}
		.compat-field-wpai_c2pa th.label,
		.compat-field-wpai_c2pa td.field{display:table-cell;vertical-align:top}
		.compat-field-wpai_c2pa th.label{width:33%;padding-top:0}
		.compat-field-wpai_c2pa th.label span.alignleft{float:none;display:inline}
		.compat-field-wpai_c2pa th.label br.clear{display:none}
		</style>
		<?php
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
	 * @since x.x.x
	 *
	 * @param int $post_id The attachment post ID.
	 * @return string Plain text.
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
	 * @since x.x.x
	 *
	 * @param string $column_name The column being rendered.
	 * @param int    $post_id     The attachment post ID.
	 */
	public function render_media_column( string $column_name, int $post_id ): void {
		if ( 'wpai_c2pa' !== $column_name ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_status_html() returns pre-escaped HTML.
		echo $this->get_status_html( $post_id );
	}

	/**
	 * Adds a Content Credentials field to the Attachment Details panel.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $form_fields Existing form fields.
	 * @param \WP_Post             $post        The attachment post object.
	 * @return array<string, mixed>
	 */
	public function add_attachment_fields( array $form_fields, \WP_Post $post ): array {
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
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The attachment post object.
	 */
	public function add_attachment_meta_box( \WP_Post $post ): void {
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
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The attachment post object.
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
	 * (no sort meta row) appear last.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Query $query The current query.
	 */
	public function sort_by_c2pa_column( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'wpai_c2pa' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set(
			'meta_query',
			array(
				'relation'       => 'OR',
				'wpai_c2pa_sort' => array(
					'key'     => self::SORT_META_KEY,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::SORT_META_KEY,
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$order = $query->get( 'order' );
		if ( ! is_string( $order ) || '' === $order ) {
			$order = 'DESC';
		}
		$query->set( 'orderby', array( 'wpai_c2pa_sort' => $order ) );
	}

	/**
	 * Captures C2PA presence and raw manifest for a freshly created attachment.
	 *
	 * @since x.x.x
	 *
	 * @param int $attachment_id The newly created attachment ID.
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

			$writer = new Sidecar_Writer();
			$rel    = $writer->write( $attachment_id, $manifest );

			$c2pa = array(
				'present'               => true,
				'format'                => $manifest->format,
				'container'             => $manifest->container,
				'manifest_sha256'       => $manifest->sha256,
				'manifest_length'       => $manifest->bytes_length,
				'sidecar_path_relative' => $rel,
				'decoded'               => null,
			);
		} catch ( \RuntimeException $e ) {
			$errors[] = array(
				'stage'   => 'sidecar_write',
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
