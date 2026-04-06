<?php
/**
 * Image Provenance experiment.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Image_Provenance;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Content_Provenance\C2PA_Manifest_Builder;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image Provenance experiment.
 *
 * Signs image attachments on upload with C2PA manifests and injects
 * C2PA-Manifest-URL headers for singular pages with featured images.
 * Provides REST endpoints for manifest lookup and retrieval.
 *
 * @since 0.6.0
 */
class Image_Provenance extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.6.0
	 */
	public static function get_id(): string {
		return 'image-provenance';
	}

	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.6.0
	 *
	 * @return array{label: string, description: string, category: string} Experiment metadata.
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Image Provenance', 'ai' ),
			'description' => __( 'Signs image attachments with C2PA manifests on upload and injects provenance headers for CDN verification.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * Registers hooks for the experiment.
	 *
	 * @since 0.6.0
	 */
	public function register(): void {
		if ( $this->get_image_option( 'auto_sign_images' ) ) {
			add_action( 'add_attachment', array( $this, 'sign_on_attachment_upload' ), 10, 1 );
		}

		add_action( 'send_headers', array( $this, 'inject_manifest_url_header' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Registers experiment settings.
	 *
	 * @since 0.6.0
	 */
	public function register_settings(): void {
		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'auto_sign_images' ),
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
	}

	/**
	 * Renders experiment settings fields.
	 *
	 * @since 0.6.0
	 */
	public function render_settings_fields(): void {
		$auto_sign = (bool) $this->get_image_option( 'auto_sign_images' );
		$name_auto = $this->get_field_option_name( 'auto_sign_images' );
		?>
		<fieldset class="ai-experiment-image-provenance-settings">
			<legend class="screen-reader-text">
				<?php esc_html_e( 'Image Provenance Settings', 'ai' ); ?>
			</legend>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Auto-sign images', 'ai' ); ?>
					</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( $name_auto ); ?>"
								value="1"
								<?php checked( $auto_sign ); ?>
							/>
							<?php esc_html_e( 'Automatically sign image attachments with C2PA manifests on upload', 'ai' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</fieldset>
		<?php
	}

	/**
	 * Signs an image attachment on upload.
	 *
	 * @since 0.6.0
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public function sign_on_attachment_upload( int $attachment_id ): void {
		$mime_type = get_post_mime_type( $attachment_id );

		if ( ! $mime_type ) {
			return;
		}

		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

		if ( ! in_array( $mime_type, $allowed_mimes, true ) ) {
			return;
		}

		$raw_url = wp_get_attachment_url( $attachment_id );

		if ( ! $raw_url ) {
			update_post_meta( $attachment_id, '_c2pa_image_status', 'error' );
			return;
		}

		// Strip query params — stored canonical must match lookup canonical (which also strips params).
		$parsed        = wp_parse_url( $raw_url );
		$canonical_url = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' ) . ( $parsed['path'] ?? '' );

		$metadata = array(
			'type'          => 'image',
			'url'           => $canonical_url,
			'attachment_id' => $attachment_id,
			'title'         => get_the_title( $attachment_id ),
		);

		$signer = $this->get_signer();
		$result = C2PA_Manifest_Builder::build( $canonical_url, 'c2pa.created', null, $metadata, $signer );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $attachment_id, '_c2pa_image_status', 'error' );
			return;
		}

		$manifest_url = rest_url( "c2pa-provenance/v1/images/manifest/{$attachment_id}" );

		update_post_meta( $attachment_id, '_c2pa_image_manifest', $result['manifest'] );
		update_post_meta( $attachment_id, '_c2pa_image_manifest_url', $manifest_url );
		update_post_meta( $attachment_id, '_c2pa_image_canonical_url', $canonical_url );
		update_post_meta( $attachment_id, '_c2pa_image_status', 'signed' );
		update_post_meta( $attachment_id, '_c2pa_image_signed_at', gmdate( 'c' ) );
	}

	/**
	 * Injects the C2PA-Manifest-URL header for singular pages with a featured image.
	 *
	 * @since 0.6.0
	 */
	public function inject_manifest_url_header(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id ) {
			return;
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( ! $thumbnail_id ) {
			return;
		}

		$manifest_url = get_post_meta( $thumbnail_id, '_c2pa_image_manifest_url', true );

		if ( ! $manifest_url ) {
			return;
		}

		header( 'C2PA-Manifest-URL: ' . esc_url_raw( $manifest_url ) );
	}

	/**
	 * Registers REST API routes for image manifest lookup and retrieval.
	 *
	 * @since 0.6.0
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'c2pa-provenance/v1',
			'/images/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_lookup_callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			'c2pa-provenance/v1',
			'/images/manifest/(?P<attachment_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_manifest_callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'attachment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST callback: look up a manifest by canonical image URL.
	 *
	 * Strips common CDN transform query parameters before matching.
	 *
	 * @since 0.6.0
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function rest_lookup_callback( \WP_REST_Request $request ) {
		$url    = (string) $request->get_param( 'url' );
		$parsed = wp_parse_url( $url );

		if ( ! $parsed ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_url' ), 400 );
		}

		// Strip CDN transform query params — keep scheme + host + path only.
		$canonical = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' ) . ( $parsed['path'] ?? '' );

		// Find attachment by stored canonical URL — single query, no loop.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts
		$results = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'suppress_filters' => false,
				'fields'           => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					array(
						'key'   => '_c2pa_image_canonical_url',
						'value' => $canonical,
					),
				),
			)
		);

		if ( empty( $results ) ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$attachment_id = (int) $results[0];
		$manifest_url  = get_post_meta( $attachment_id, '_c2pa_image_manifest_url', true );

		return new \WP_REST_Response(
			array(
				'record_id'    => (string) $attachment_id,
				'manifest_url' => $manifest_url,
			),
			200
		);
	}

	/**
	 * REST callback: return the stored manifest for an attachment.
	 *
	 * @since 0.6.0
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function rest_manifest_callback( \WP_REST_Request $request ) {
		$attachment_id = (int) $request->get_param( 'attachment_id' );
		$manifest      = get_post_meta( $attachment_id, '_c2pa_image_manifest', true );

		if ( ! $manifest ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		return new \WP_REST_Response( json_decode( $manifest, true ), 200 );
	}

	/**
	 * Returns the Signing_Interface implementation for the configured tier.
	 *
	 * @since 0.6.0
	 *
	 * @return \WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface
	 */
	private function get_signer(): Signing_Interface {
		return new Local_Signer( $this->get_local_keypair() );
	}

	/**
	 * Returns the value of an experiment setting option.
	 *
	 * @since 0.6.0
	 *
	 * @param string $name Base option name.
	 * @return mixed Option value, or false if not set.
	 */
	private function get_image_option( string $name ) {
		return get_option( $this->get_field_option_name( $name ) );
	}

	/**
	 * Retrieves or generates the local RSA keypair.
	 *
	 * @since 0.6.0
	 *
	 * @return array{private_key: string, public_key: string}
	 */
	private function get_local_keypair(): array {
		$stored = get_option( '_c2pa_local_keypair' );

		if ( is_array( $stored ) && ! empty( $stored['private_key'] ) ) {
			/** @var array{private_key: string, public_key: string} $stored */
			return $stored;
		}

		$resource = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		if ( false === $resource ) {
			return array(
				'private_key' => '',
				'public_key'  => '',
			);
		}

		$private_key_pem = '';
		openssl_pkey_export( $resource, $private_key_pem );
		$key_details = openssl_pkey_get_details( $resource );
		$public_key  = is_array( $key_details ) ? ( $key_details['key'] ?? '' ) : '';

		if ( empty( $private_key_pem ) || empty( $public_key ) ) {
			return array(
				'private_key' => '',
				'public_key'  => '',
			);
		}

		$keypair = array(
			'private_key' => $private_key_pem,
			'public_key'  => $public_key,
		);
		update_option( '_c2pa_local_keypair', $keypair, false );

		return $keypair;
	}
}
