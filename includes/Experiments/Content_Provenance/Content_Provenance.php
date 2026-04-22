<?php
/**
 * Content Provenance experiment.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Content_Provenance\Signing\BYOK_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Connected_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Settings\Settings_Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C2PA Content Provenance experiment.
 *
 * Embeds cryptographic proof of origin into published content using the
 * C2PA 2.3 text authentication specification (Section A.7). Proof survives
 * copy-paste, scraping, and syndication. Zero editorial workflow change.
 *
 * @since x.x.x
 */
class Content_Provenance extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'content-provenance';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{label: string, description: string, category: string}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Content Provenance', 'ai' ),
			'description' => __( 'Embeds cryptographic proof of origin into published content using the C2PA 2.3 text authentication specification. Proof survives copy-paste, scraping, and syndication.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * Registers all WordPress hooks for this experiment.
	 *
	 * Sets up signing hooks, REST routes, well-known endpoint, and editor assets.
	 * Also hooks into the experiment-enabled toggle so the local keypair is
	 * generated on first activation.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// Sign or re-sign after every post save completes. wp_after_insert_post
		// fires once per save, after ALL other hooks (publish_post, post_updated,
		// transition_post_status) have run, preventing double-signing when both
		// publish_post and post_updated fire for the same edit.
		add_action( 'wp_after_insert_post', array( $this, 'sign_after_save' ), 20, 3 );

		// Register c2pa/sign and c2pa/verify abilities.
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Block editor sidebar panel.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );

		// REST endpoints for verification and status.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Rewrite rule for /.well-known/c2pa.
		add_action( 'init', array( $this, 'add_well_known_rewrite' ) );

		// Serve the well-known discovery document.
		add_action( 'template_redirect', array( $this, 'handle_well_known_request' ) );

		// Keypair generation on toggle.
		add_action(
			'update_option_wpai_feature_content-provenance_enabled',
			array( $this, 'on_toggle' ),
			10,
			2
		);

		// Frontend provenance badge on published content.
		Verification_Badge::configure(
			(bool) $this->get_signing_option( 'show_badge', true ),
			(string) ( $this->get_signing_option( 'badge_position', 'below' ) )
		);
		Verification_Badge::register_hooks();
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Registers experiment-specific settings with the WordPress Settings API.
	 *
	 * All options are namespaced via get_field_option_name() and exposed to
	 * the REST API so the React-based settings page can read and write them.
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'signing_tier' ),
			array(
				'type'              => 'string',
				'default'           => 'local',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( 'local', 'connected', 'byok' ),
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'connected_service_url' ),
			array(
				'type'              => 'string',
				'description'       => __( 'Signing endpoint for your CA-verified provider.', 'ai' ),
				'default'           => Connected_Signer::DEFAULT_SERVICE_URL,
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'connected_service_api_key' ),
			array(
				'type'              => 'string',
				'description'       => __( 'API key from your CA-verified signing provider.', 'ai' ),
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'byok_key_path' ),
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_file_path' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'byok_certificate' ),
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_file_path' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'auto_sign' ),
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( '1', '' ),
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'show_badge' ),
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( '1', '' ),
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_field_option_name( 'badge_position' ),
			array(
				'type'              => 'string',
				'default'           => 'below',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( 'below', 'above' ),
					),
				),
			)
		);

		// Mask the API key when read via get_option() so the REST API
		// returns the masked value instead of the encrypted ciphertext.
		add_filter(
			'option_' . $this->get_field_option_name( 'connected_service_api_key' ),
			array( self::class, 'mask_api_key_option' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'       => 'signing_tier',
				'label'    => __( 'Signing tier', 'ai' ),
				'type'     => 'text',
				'default'  => 'local',
				'elements' => array(
					array(
						'value' => 'local',
						'label' => __( 'Local (self-signed)', 'ai' ),
					),
					array(
						'value' => 'connected',
						'label' => __( 'Connected - CA-verified provider', 'ai' ),
					),
					array(
						'value' => 'byok',
						'label' => __( 'BYOK (your own certificate)', 'ai' ),
					),
				),
			),
			array(
				'id'          => 'connected_service_url',
				'label'       => __( 'Service URL', 'ai' ),
				'description' => __( 'Signing endpoint for your CA-verified provider. Pre-configured with a default; update to match your provider.', 'ai' ),
				'type'        => 'text',
				'default'     => Connected_Signer::DEFAULT_SERVICE_URL,
			),
			array(
				'id'          => 'connected_service_api_key',
				'label'       => __( 'API key', 'ai' ),
				'description' => $this->get_known_providers_description(),
				'type'        => 'text',
				'default'     => '',
			),
			array(
				'id'      => 'byok_key_path',
				'label'   => __( 'Private key path', 'ai' ),
				'type'    => 'text',
				'default' => '',
			),
			array(
				'id'      => 'byok_certificate',
				'label'   => __( 'Certificate path', 'ai' ),
				'type'    => 'text',
				'default' => '',
			),
			array(
				'id'       => 'auto_sign',
				'label'    => __( 'Auto-sign on publish', 'ai' ),
				'type'     => 'text',
				'default'  => '1',
				'elements' => array(
					array(
						'value' => '1',
						'label' => __( 'Enabled', 'ai' ),
					),
					array(
						'value' => '',
						'label' => __( 'Disabled', 'ai' ),
					),
				),
			),
			array(
				'id'       => 'show_badge',
				'label'    => __( 'Show provenance badge', 'ai' ),
				'type'     => 'text',
				'default'  => '1',
				'elements' => array(
					array(
						'value' => '1',
						'label' => __( 'Enabled', 'ai' ),
					),
					array(
						'value' => '',
						'label' => __( 'Disabled', 'ai' ),
					),
				),
			),
			array(
				'id'       => 'badge_position',
				'label'    => __( 'Badge position', 'ai' ),
				'type'     => 'text',
				'default'  => 'below',
				'elements' => array(
					array(
						'value' => 'below',
						'label' => __( 'Below content', 'ai' ),
					),
					array(
						'value' => 'above',
						'label' => __( 'Above content', 'ai' ),
					),
				),
			),
		);
	}


	/**
	 * Builds the API key description with a list of known compatible providers.
	 *
	 * @since x.x.x
	 *
	 * @return string Translated description string.
	 */
	private function get_known_providers_description(): string {
		$names = array_map(
			static fn( array $provider ): string => $provider['name'],
			Connected_Signer::KNOWN_PROVIDERS
		);

		return sprintf(
			/* translators: %s: comma-separated list of known signing providers. */
			__( 'API key from your CA-verified signing provider. Known compatible providers: %s.', 'ai' ),
			implode( ', ', $names )
		);
	}

	/**
	 * Signs or re-signs a post after a save completes.
	 *
	 * Hooked to 'wp_after_insert_post' at priority 20. This hook fires once
	 * per save, after all other save-related hooks (publish_post, post_updated,
	 * transition_post_status) have finished, preventing double-signing.
	 *
	 * First publication uses c2pa.created. Subsequent edits that change the
	 * content use c2pa.edited with the previous manifest as an ingredient.
	 * Content-unchanged saves are skipped.
	 *
	 * @since x.x.x
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 * @param bool     $update  Whether this is an update (true) or new insert (false).
	 */
	public function sign_after_save( int $post_id, \WP_Post $post, bool $update ): void {
		if ( ! $this->get_signing_option( 'auto_sign' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$stripped     = wp_strip_all_tags( $post->post_content );
		$stripped     = (string) preg_replace( '/\n{3,}/', "\n\n", $stripped );
		$current_hash = md5( trim( $stripped ) );
		$stored_hash  = get_post_meta( $post_id, '_c2pa_content_hash', true );
		$is_signed    = 'signed' === get_post_meta( $post_id, '_c2pa_status', true );

		// Skip if already signed and content unchanged since last signature.
		if ( $is_signed && $stored_hash === $current_hash ) {
			return;
		}

		// First signature or content change after a failed signing attempt.
		if ( ! $is_signed || ! $stored_hash ) {
			$this->sign_post( $post_id, $post, 'c2pa.created' );
			return;
		}

		// Content changed on a previously signed post: re-sign with ingredient.
		$this->sign_post( $post_id, $post, 'c2pa.edited' );
	}

	/**
	 * Builds, signs, embeds, and persists a C2PA manifest for a post.
	 *
	 * Strips HTML to obtain plain text, builds the C2PA claims structure,
	 * signs it via the configured signing tier, embeds the manifest using
	 * Unicode variation selectors, and stores the result back to the post.
	 * Failures are logged and stored in post meta — publication is never blocked.
	 *
	 * @since x.x.x
	 *
	 * @param int           $post_id  Post ID.
	 * @param \WP_Post      $post     Post object to sign.
	 * @param string        $action   C2PA action string: 'c2pa.created' or 'c2pa.edited'.
	 * @param \WP_Post|null $previous Optional previous post object for ingredient chain.
	 * @return bool True on success, false on failure.
	 */
	public function sign_post( int $post_id, \WP_Post $post, string $action, ?\WP_Post $previous = null ): bool {
		$plain_text = wp_strip_all_tags( $post->post_content );
		// Collapse excessive blank lines left by Gutenberg block comment
		// stripping (<!-- wp:paragraph --> etc.) to a single blank line.
		// This gives clean paragraph spacing with white-space:pre-line.
		$plain_text = (string) preg_replace( '/\n{3,}/', "\n\n", $plain_text );
		$plain_text = trim( $plain_text );

		if ( empty( $plain_text ) ) {
			return false;
		}

		$previous_manifest = null;
		if ( 'c2pa.edited' === $action ) {
			$raw_manifest = get_post_meta( $post_id, '_c2pa_manifest', true );
			if ( $raw_manifest ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Manifest is stored base64-encoded in post meta.
				$decoded           = base64_decode( (string) $raw_manifest, true );
				$previous_manifest = false !== $decoded ? $decoded : null;
			}
		}

		$signer = $this->get_signer();

		$raw_permalink = get_permalink( $post_id );
		$metadata      = array(
			'title'   => $post->post_title,
			'url'     => $raw_permalink ? (string) $raw_permalink : '',
			'author'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'post_id' => $post_id,
		);

		$result = C2PA_Manifest_Builder::build(
			$plain_text,
			$action,
			$previous_manifest,
			$metadata,
			$signer
		);

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_c2pa_status', 'error' );
			return false;
		}

		$new_content = Unicode_Embedder::embed( $plain_text, $result['manifest'] );

		// Store embedded content (with invisible Unicode markers) in post meta
		// so other plugins reading post_content get clean text without markers.
		// The frontend the_content filter injects embeddings for published pages.
		update_post_meta( $post_id, '_c2pa_embedded_content', $new_content );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary JUMBF data must be base64-encoded for safe storage in WordPress post meta.
		update_post_meta( $post_id, '_c2pa_manifest', base64_encode( $result['manifest'] ) );
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_signed_at', gmdate( 'c' ) );
		update_post_meta( $post_id, '_c2pa_signer_tier', $signer->get_tier() );
		update_post_meta( $post_id, '_c2pa_content_hash', md5( $plain_text ) );

		return true;
	}

	/**
	 * Registers the c2pa/sign and c2pa/verify abilities.
	 *
	 * Hooked to 'wp_abilities_api_init'.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'c2pa/sign',
			array(
				'label'         => __( 'C2PA: Sign Content', 'ai' ),
				'description'   => __( 'Embed C2PA provenance into text content.', 'ai' ),
				'ability_class' => \WordPress\AI\Abilities\Content_Provenance\C2PA_Sign::class,
			)
		);

		wp_register_ability(
			'c2pa/verify',
			array(
				'label'         => __( 'C2PA: Verify Provenance', 'ai' ),
				'description'   => __( 'Verify C2PA provenance in text content.', 'ai' ),
				'ability_class' => \WordPress\AI\Abilities\Content_Provenance\C2PA_Verify::class,
			)
		);
	}

	/**
	 * Registers REST API endpoints for verification and signing status.
	 *
	 * Hooked to 'rest_api_init'. The /verify route is publicly accessible so
	 * third-party tools can verify provenance without authentication. The
	 * /status route requires edit_post capability for the requested post.
	 *
	 * @since x.x.x
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'c2pa-provenance/v1',
			'/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_verify_callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'text'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
					'post_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'c2pa-provenance/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status_callback' ),
				'permission_callback' => static function ( \WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
				},
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'c2pa-provenance/v1',
			'/embedded-content',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_embedded_content_callback' ),
				'permission_callback' => static function ( \WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
				},
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST callback: verify C2PA provenance in submitted text.
	 *
	 * Extracts and validates the embedded manifest, returning a structured
	 * response that includes verification status, the parsed manifest, and
	 * any error detail.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function rest_verify_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = $request->get_param( 'post_id' );
		$text    = $request->get_param( 'text' );

		// Prefer post_id: read the canonical signed bytes from meta.
		// This avoids the lossy innerText/wpautop extraction path.
		if ( $post_id ) {
			$embedded = get_post_meta( (int) $post_id, '_c2pa_embedded_content', true );
			if ( $embedded ) {
				$text = (string) $embedded;
			}
		}

		if ( ! $text ) {
			return new \WP_REST_Response(
				array(
					'verified'    => false,
					'status'      => 'error',
					'manifest'    => null,
					'signed_at'   => null,
					'signer_tier' => null,
				),
				400
			);
		}

		$result = C2PA_Manifest_Builder::extract_and_verify( $text );

		$manifest    = $result['manifest'];
		$signed_at   = null;
		$signer_tier = null;

		if ( is_array( $manifest ) ) {
			$signed_at   = $manifest['signed_at'] ?? null;
			$signer_tier = $manifest['signer'] ?? null;
		}

		return new \WP_REST_Response(
			array(
				'verified'    => $result['verified'],
				'status'      => $result['status'],
				'manifest'    => $manifest,
				'signed_at'   => $signed_at,
				'signer_tier' => $signer_tier,
			),
			200
		);
	}

	/**
	 * REST callback: return the canonical signed bytes for a post.
	 *
	 * Returns the _c2pa_embedded_content meta value, which is the exact
	 * NFC-normalized plain text plus the invisible wrapper as stored at
	 * signing time. Client-side verification tools can use this instead
	 * of lossy innerText extraction.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function rest_embedded_content_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id  = (int) $request->get_param( 'post_id' );
		$embedded = get_post_meta( $post_id, '_c2pa_embedded_content', true );

		if ( ! $embedded ) {
			return new \WP_REST_Response(
				array( 'text' => null ),
				404
			);
		}

		return new \WP_REST_Response(
			array( 'text' => (string) $embedded ),
			200
		);
	}

	/**
	 * REST callback: return the signing status for a specific post.
	 *
	 * Reads post meta written by sign_post() and returns a summarised status
	 * payload for use in the block editor sidebar panel.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function rest_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );

		$raw_status = get_post_meta( $post_id, '_c2pa_status', true );
		$status     = $raw_status ? (string) $raw_status : 'unsigned';
		$raw_signed = get_post_meta( $post_id, '_c2pa_signed_at', true );
		$signed_at  = $raw_signed ? (string) $raw_signed : null;
		$raw_tier   = get_post_meta( $post_id, '_c2pa_signer_tier', true );
		$tier       = $raw_tier ? (string) $raw_tier : null;
		$raw_mfst   = get_post_meta( $post_id, '_c2pa_manifest', true );
		$manifest   = $raw_mfst ? (string) $raw_mfst : null;

		// Provide a summary rather than the full binary manifest.
		$manifest_preview = null;
		if ( $manifest ) {
			$manifest_preview = array(
				'format' => 'jumbf',
				'size'   => strlen( $manifest ),
			);
		}

		return new \WP_REST_Response(
			array(
				'status'           => $status,
				'signed_at'        => $signed_at,
				'signer_tier'      => $tier,
				'manifest_preview' => $manifest_preview,
			),
			200
		);
	}

	/**
	 * Enqueues block editor assets for the provenance sidebar panel.
	 *
	 * Only loads on the post edit and new-post screens. Passes runtime
	 * configuration to the JS bundle via wp_localize_script.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$hook_suffix = $screen->base;

		if ( 'post' !== $hook_suffix && 'post-new' !== $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script( 'content_provenance', 'experiments/content-provenance' );
		Asset_Loader::localize_script(
			'content_provenance',
			'ContentProvenanceData',
			array(
				'enabled'     => $this->is_enabled(),
				'signerTier'  => ( $this->get_signing_option( 'signing_tier' ) ? (string) $this->get_signing_option( 'signing_tier' ) : 'local' ),
				'settingsUrl' => admin_url( 'admin.php?page=ai' ),
			)
		);
	}

	/**
	 * Enqueues frontend styles for the provenance badge on singular views.
	 *
	 * @since x.x.x
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! is_singular() || is_admin() ) {
			return;
		}

		if ( ! $this->get_signing_option( 'show_badge', true ) ) {
			return;
		}

		Asset_Loader::enqueue_style( 'content-provenance-frontend', 'experiments/content-provenance-frontend' );
	}

	/**
	 * Registers the /.well-known/c2pa rewrite rule.
	 *
	 * Delegates to Well_Known_Handler for rewrite registration.
	 *
	 * @since x.x.x
	 */
	public function add_well_known_rewrite(): void {
		Well_Known_Handler::add_rewrite_rule();
	}

	/**
	 * Serves the /.well-known/c2pa discovery document when requested.
	 *
	 * Delegates to Well_Known_Handler for spec-compliant C2PA discovery.
	 *
	 * @since x.x.x
	 */
	public function handle_well_known_request(): void {
		Well_Known_Handler::maybe_handle();
	}

	/**
	 * Handles the experiment enable/disable toggle.
	 *
	 * Generates the local keypair on first activation so it is available
	 * immediately when the first post is published.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $new_value The new option value.
	 */
	public function on_toggle( $old_value, $new_value ): void {
		if ( '1' !== $new_value ) {
			return;
		}
		$this->ensure_local_keypair();
	}

	/**
	 * Generates and persists the local keypair if one does not already exist.
	 *
	 * Stores the keypair as a site option so it persists across requests.
	 * Uses EC P-256 with a self-signed X.509 certificate for C2PA compliance.
	 * Called once on experiment activation.
	 *
	 * @since x.x.x
	 */
	public function ensure_local_keypair(): void {
		$existing = get_option( '_c2pa_local_keypair' );

		if ( is_array( $existing ) && ! empty( $existing['private_key'] ) && ! empty( $existing['certificate_pem'] ) ) {
			return;
		}

		$keypair = $this->generate_keypair();

		if ( is_wp_error( $keypair ) ) {
			return;
		}

		update_option( '_c2pa_local_keypair', $keypair, false );
	}

	/**
	 * Returns the configured signer for external callers (e.g. the c2pa/sign Ability).
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface
	 */
	public function get_public_signer(): Signing_Interface {
		return $this->get_signer();
	}

	/**
	 * Returns the Signing_Interface implementation for the configured tier.
	 *
	 * Reads the signing_tier option and instantiates the appropriate backend.
	 * Defaults to Local_Signer when no tier is set.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface
	 */
	private function get_signer(): Signing_Interface {
		$raw_tier = $this->get_signing_option( 'signing_tier' );
		$tier     = $raw_tier ? (string) $raw_tier : 'local';

		if ( 'connected' === $tier ) {
			$encrypted_key = $this->get_raw_api_key();
			return new Connected_Signer(
				(string) $this->get_signing_option( 'connected_service_url' ),
				self::decrypt_value( $encrypted_key )
			);
		}

		if ( 'byok' === $tier ) {
			return new BYOK_Signer(
				(string) $this->get_signing_option( 'byok_key_path' ),
				(string) $this->get_signing_option( 'byok_certificate' )
			);
		}

		return new Local_Signer( $this->get_local_keypair() );
	}

	/**
	 * Sanitizes the API key setting, preserving the existing value when the
	 * submitted value is the masked placeholder.
	 *
	 * @since x.x.x
	 *
	 * @param string $value Submitted API key value.
	 * @return string Sanitized API key.
	 */
	public function sanitize_api_key( string $value ): string {
		$value = sanitize_text_field( $value );

		// If the submitted value is all asterisks followed by up to 4 chars,
		// the user did not change the key — keep the stored encrypted value.
		if ( preg_match( '/^\*+.{0,4}$/', $value ) ) {
			$option_name = $this->get_field_option_name( 'connected_service_api_key' );

			// Temporarily remove the masking filter to read raw ciphertext.
			remove_filter( 'option_' . $option_name, array( self::class, 'mask_api_key_option' ) );
			$stored = get_option( $option_name, '' );
			add_filter( 'option_' . $option_name, array( self::class, 'mask_api_key_option' ) );

			return is_string( $stored ) ? $stored : '';
		}

		if ( '' === $value ) {
			return '';
		}

		return self::encrypt_value( $value );
	}

	/**
	 * Masks the encrypted API key for display via get_option().
	 *
	 * Hooked to `option_{name}` so the REST API returns a masked value
	 * instead of the raw encrypted ciphertext.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The raw option value (encrypted ciphertext).
	 * @return string Masked API key showing only the last 4 characters.
	 */
	public static function mask_api_key_option( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$decrypted = self::decrypt_value( $value );
		if ( '' === $decrypted ) {
			return '';
		}

		$visible = min( 4, strlen( $decrypted ) );
		return str_repeat( '*', max( 0, strlen( $decrypted ) - $visible ) ) . substr( $decrypted, -$visible );
	}

	/**
	 * Sanitizes a BYOK file path, rejecting traversal attempts.
	 *
	 * @since x.x.x
	 *
	 * @param string $value Submitted file path.
	 * @return string Sanitized path, or empty string on failure.
	 */
	public function sanitize_file_path( string $value ): string {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			return '';
		}

		// Reject paths with traversal sequences before resolving.
		if ( false !== strpos( $value, '..' ) ) {
			add_settings_error(
				$this->get_field_option_name( 'byok_key_path' ),
				'c2pa_path_traversal',
				esc_html__( 'File path must not contain directory traversal sequences.', 'ai' )
			);
			return '';
		}

		return $value;
	}

	/**
	 * Encrypts a value for at-rest storage using AES-256-CBC with the site auth key.
	 *
	 * @since x.x.x
	 *
	 * @param string $value Plaintext value.
	 * @return string Base64-encoded ciphertext with IV prefix, or original value on failure.
	 */
	private static function encrypt_value( string $value ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = openssl_random_pseudo_bytes( 16 );

		if ( false === $iv ) {
			return $value;
		}

		$encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encrypted binary data must be base64-encoded for safe storage.
		return 'enc:' . base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypts a value encrypted by encrypt_value().
	 *
	 * Returns the original string if the value is not encrypted (no 'enc:' prefix).
	 *
	 * @since x.x.x
	 *
	 * @param string $value Stored value (encrypted or plaintext).
	 * @return string Decrypted plaintext.
	 */
	private static function decrypt_value( string $value ): string {
		if ( 0 !== strpos( $value, 'enc:' ) ) {
			return $value;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding encrypted data stored by encrypt_value().
		$raw = base64_decode( substr( $value, 4 ), true );

		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$key       = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv        = substr( $raw, 0, 16 );
		$encrypted = substr( $raw, 16 );

		$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * Returns the value of an experiment setting option.
	 *
	 * Wraps get_option() with the namespaced option name produced by
	 * get_field_option_name() to reduce boilerplate at call sites.
	 *
	 * When no $default is passed, get_option() is called without an explicit
	 * default so that WordPress applies the registered-setting default
	 * (set via register_setting()). Passing an explicit default suppresses
	 * that behaviour, matching WordPress core semantics.
	 *
	 * @since x.x.x
	 *
	 * @param string $name     Base option name (e.g. 'signing_tier').
	 * @param mixed  $fallback Optional. Explicit fallback value.
	 * @return mixed Option value, registered default, or $fallback.
	 */
	private function get_signing_option( string $name, $fallback = null ) {
		$option_name = $this->get_field_option_name( $name );

		// Only forward an explicit default when the caller provided one.
		// WordPress skips its own registered defaults when a second argument
		// is passed to get_option() (it checks func_num_args()).
		return func_num_args() > 1
			? get_option( $option_name, $fallback )
			: get_option( $option_name );
	}

	/**
	 * Reads the raw encrypted API key, bypassing the masking filter.
	 *
	 * @since x.x.x
	 *
	 * @return string Encrypted API key ciphertext, or empty string.
	 */
	private function get_raw_api_key(): string {
		$option_name = $this->get_field_option_name( 'connected_service_api_key' );
		remove_filter( 'option_' . $option_name, array( self::class, 'mask_api_key_option' ) );
		$value = (string) get_option( $option_name, '' );
		add_filter( 'option_' . $option_name, array( self::class, 'mask_api_key_option' ) );
		return $value;
	}

	/**
	 * Retrieves or generates the local EC P-256 keypair.
	 *
	 * Reads the persisted keypair from the '_c2pa_local_keypair' site option.
	 * If none exists or the stored keypair uses the legacy RSA format (missing
	 * certificate_pem), generates a new EC P-256 keypair and persists it.
	 *
	 * @since x.x.x
	 *
	 * @return array{private_key: string, certificate_pem: string}
	 */
	private function get_local_keypair(): array {
		$stored = get_option( '_c2pa_local_keypair' );

		if ( is_array( $stored ) && ! empty( $stored['private_key'] ) && ! empty( $stored['certificate_pem'] ) ) {
			/** @var array{private_key: string, certificate_pem: string} $stored */
			return $stored;
		}

		$keypair = $this->generate_keypair();

		if ( is_wp_error( $keypair ) ) {
			// Return a placeholder — signing will fail gracefully downstream.
			return array(
				'private_key'     => '',
				'certificate_pem' => '',
			);
		}

		update_option( '_c2pa_local_keypair', $keypair, false );

		return $keypair;
	}

	/**
	 * Generates a fresh EC P-256 keypair with self-signed X.509 certificate.
	 *
	 * @since x.x.x
	 *
	 * @return array{private_key: string, certificate_pem: string}|\WP_Error Keypair array or WP_Error on failure.
	 */
	private function generate_keypair() {
		return Local_Signer::generate_keypair();
	}
}
