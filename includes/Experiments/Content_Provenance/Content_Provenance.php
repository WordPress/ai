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
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Content_Provenance\Signing\BYOK_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Connected_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface;

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
 * @since 0.5.0
 */
class Content_Provenance extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 */
	public static function get_id(): string {
		return 'content-provenance';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
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
	 * @since 0.5.0
	 */
	public function register(): void {
		// Sign on first publication.
		add_action( 'publish_post', array( $this, 'sign_on_publish' ), 20, 2 );

		// Re-sign when content is updated.
		add_action( 'post_updated', array( $this, 'sign_on_update' ), 20, 3 );

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
	}

	/**
	 * Registers experiment-specific settings with the WordPress Settings API.
	 *
	 * All options are namespaced via get_field_option_name() and grouped under
	 * the 'ai_experiments' settings group used by the experiments settings page.
	 *
	 * @since 0.5.0
	 */
	public function register_settings(): void {
		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'signing_tier' ),
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'local',
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'connected_service_url' ),
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'connected_service_api_key' ),
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'byok_certificate' ),
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'auto_sign' ),
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'show_badge' ),
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);

		register_setting(
			'ai_experiments',
			$this->get_field_option_name( 'badge_position' ),
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'below',
			)
		);
	}

	/**
	 * Renders the experiment settings fields inside the experiment card.
	 *
	 * Outputs signing-tier selection, conditional service configuration inputs,
	 * badge display controls, and a short explanation of trust tiers per PRD §4.1.
	 *
	 * @since 0.5.0
	 */
	public function render_settings_fields(): void {
		$signing_tier_raw          = $this->get_signing_option( 'signing_tier' );
		$signing_tier              = $signing_tier_raw ? (string) $signing_tier_raw : 'local';
		$connected_service_url     = (string) $this->get_signing_option( 'connected_service_url' );
		$connected_service_api_key = (string) $this->get_signing_option( 'connected_service_api_key' );
		$byok_certificate          = (string) $this->get_signing_option( 'byok_certificate' );
		$auto_sign                 = (bool) $this->get_signing_option( 'auto_sign' );
		$show_badge                = (bool) $this->get_signing_option( 'show_badge' );
		$badge_position_raw        = (string) $this->get_signing_option( 'badge_position' );
		$badge_position            = $badge_position_raw ? $badge_position_raw : 'below';

		$tier_name_signing     = $this->get_field_option_name( 'signing_tier' );
		$tier_name_service_url = $this->get_field_option_name( 'connected_service_url' );
		$tier_name_api_key     = $this->get_field_option_name( 'connected_service_api_key' );
		$tier_name_byok_cert   = $this->get_field_option_name( 'byok_certificate' );
		$tier_name_auto_sign   = $this->get_field_option_name( 'auto_sign' );
		$tier_name_show_badge  = $this->get_field_option_name( 'show_badge' );
		$tier_name_badge_pos   = $this->get_field_option_name( 'badge_position' );
		?>
		<fieldset class="ai-experiment-content-provenance-settings">
			<legend class="screen-reader-text">
				<?php esc_html_e( 'Content Provenance Settings', 'ai' ); ?>
			</legend>

			<details>
				<summary><?php esc_html_e( 'Signing Tier', 'ai' ); ?></summary>
				<p class="description">
					<?php
					esc_html_e(
						'Choose how content is signed. Local signing requires no external services and uses a key stored in the database. Connected signing delegates to an external key-management service for a fuller trust chain. Bring-Your-Own-Key (BYOK) lets you supply your own CA-backed private key.',
						'ai'
					);
					?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_signing ); ?>">
								<?php esc_html_e( 'Signing Tier', 'ai' ); ?>
							</label>
						</th>
						<td>
							<select
								id="<?php echo esc_attr( $tier_name_signing ); ?>"
								name="<?php echo esc_attr( $tier_name_signing ); ?>"
							>
								<option value="local" <?php selected( $signing_tier, 'local' ); ?>>
									<?php esc_html_e( 'Local (self-signed, no external service)', 'ai' ); ?>
								</option>
								<option value="connected" <?php selected( $signing_tier, 'connected' ); ?>>
									<?php esc_html_e( 'Connected (external signing service)', 'ai' ); ?>
								</option>
								<option value="byok" <?php selected( $signing_tier, 'byok' ); ?>>
									<?php esc_html_e( 'BYOK (Bring Your Own Key)', 'ai' ); ?>
								</option>
							</select>
						</td>
					</tr>
				</table>
			</details>

			<details <?php echo 'connected' === $signing_tier ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Connected Service Configuration', 'ai' ); ?></summary>
				<p class="description">
					<?php esc_html_e( 'Configure the external signing service endpoint and credentials. Only required when the Connected signing tier is selected above.', 'ai' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_service_url ); ?>">
								<?php esc_html_e( 'Service URL', 'ai' ); ?>
							</label>
						</th>
						<td>
							<input
								type="url"
								id="<?php echo esc_attr( $tier_name_service_url ); ?>"
								name="<?php echo esc_attr( $tier_name_service_url ); ?>"
								value="<?php echo esc_attr( $connected_service_url ); ?>"
								class="regular-text"
								placeholder="https://signing.example.com/sign"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_api_key ); ?>">
								<?php esc_html_e( 'API Key', 'ai' ); ?>
							</label>
						</th>
						<td>
							<input
								type="password"
								id="<?php echo esc_attr( $tier_name_api_key ); ?>"
								name="<?php echo esc_attr( $tier_name_api_key ); ?>"
								value="<?php echo esc_attr( $connected_service_api_key ); ?>"
								class="regular-text"
								autocomplete="new-password"
							/>
						</td>
					</tr>
				</table>
			</details>

			<details <?php echo 'byok' === $signing_tier ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'BYOK Certificate Configuration', 'ai' ); ?></summary>
				<p class="description">
					<?php esc_html_e( 'Provide the filesystem path to your PEM-encoded private key. The key must be readable by the web server process. Only required when the BYOK tier is selected.', 'ai' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_byok_cert ); ?>">
								<?php esc_html_e( 'Private Key Path', 'ai' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="<?php echo esc_attr( $tier_name_byok_cert ); ?>"
								name="<?php echo esc_attr( $tier_name_byok_cert ); ?>"
								value="<?php echo esc_attr( $byok_certificate ); ?>"
								class="large-text"
								placeholder="/etc/ssl/private/my-signing-key.pem"
							/>
						</td>
					</tr>
				</table>
			</details>

			<details>
				<summary><?php esc_html_e( 'Publishing Options', 'ai' ); ?></summary>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Auto-sign', 'ai' ); ?>
						</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( $tier_name_auto_sign ); ?>"
									value="1"
									<?php checked( $auto_sign ); ?>
								/>
								<?php esc_html_e( 'Automatically sign content on publish and update', 'ai' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Provenance Badge', 'ai' ); ?>
						</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( $tier_name_show_badge ); ?>"
									value="1"
									<?php checked( $show_badge ); ?>
								/>
								<?php esc_html_e( 'Show provenance badge on signed content', 'ai' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $tier_name_badge_pos ); ?>">
								<?php esc_html_e( 'Badge Position', 'ai' ); ?>
							</label>
						</th>
						<td>
							<select
								id="<?php echo esc_attr( $tier_name_badge_pos ); ?>"
								name="<?php echo esc_attr( $tier_name_badge_pos ); ?>"
							>
								<option value="below" <?php selected( $badge_position, 'below' ); ?>>
									<?php esc_html_e( 'Below content', 'ai' ); ?>
								</option>
								<option value="above" <?php selected( $badge_position, 'above' ); ?>>
									<?php esc_html_e( 'Above content', 'ai' ); ?>
								</option>
								<option value="inline" <?php selected( $badge_position, 'inline' ); ?>>
									<?php esc_html_e( 'Inline (end of content)', 'ai' ); ?>
								</option>
							</select>
						</td>
					</tr>
				</table>
			</details>
		</fieldset>
		<?php
	}

	/**
	 * Signs a post on first publication if auto-sign is enabled.
	 *
	 * Hooked to 'publish_post' at priority 20 so it runs after standard WP
	 * publish routines. Skips revisions and auto-drafts.
	 *
	 * @since 0.5.0
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 */
	public function sign_on_publish( int $post_id, \WP_Post $post ): void {
		if ( ! $this->get_signing_option( 'auto_sign' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->sign_post( $post_id, $post, 'c2pa.created' );
	}

	/**
	 * Re-signs a post when its content changes after initial publication.
	 *
	 * Hooked to 'post_updated' at priority 20. Skips non-published posts and
	 * updates that do not change the post content, to avoid churning signatures.
	 *
	 * @since 0.5.0
	 *
	 * @param int      $post_id     The post ID.
	 * @param \WP_Post $post_after  The post object after the update.
	 * @param \WP_Post $post_before The post object before the update.
	 */
	public function sign_on_update( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( ! $this->get_signing_option( 'auto_sign' ) ) {
			return;
		}

		if ( 'publish' !== $post_after->post_status ) {
			return;
		}

		if ( $post_after->post_content === $post_before->post_content ) {
			return;
		}

		$this->sign_post( $post_id, $post_after, 'c2pa.edited', $post_before );
	}

	/**
	 * Builds, signs, embeds, and persists a C2PA manifest for a post.
	 *
	 * Strips HTML to obtain plain text, builds the C2PA claims structure,
	 * signs it via the configured signing tier, embeds the manifest using
	 * Unicode variation selectors, and stores the result back to the post.
	 * Failures are logged and stored in post meta — publication is never blocked.
	 *
	 * @since 0.5.0
	 *
	 * @param int           $post_id  Post ID.
	 * @param \WP_Post      $post     Post object to sign.
	 * @param string        $action   C2PA action string: 'c2pa.created' or 'c2pa.edited'.
	 * @param \WP_Post|null $previous Optional previous post object for ingredient chain.
	 * @return bool True on success, false on failure.
	 */
	public function sign_post( int $post_id, \WP_Post $post, string $action, ?\WP_Post $previous = null ): bool {
		$plain_text = wp_strip_all_tags( $post->post_content );

		if ( empty( $plain_text ) ) {
			return false;
		}

		$previous_manifest = null;
		if ( 'c2pa.edited' === $action ) {
			$raw_manifest      = get_post_meta( $post_id, '_c2pa_manifest', true );
			$previous_manifest = $raw_manifest ? (string) $raw_manifest : null;
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

		$new_content = Unicode_Embedder::embed( $post->post_content, $result['manifest'] );

		// Temporarily remove own hooks to avoid recursive triggering.
		remove_action( 'publish_post', array( $this, 'sign_on_publish' ), 20 );
		remove_action( 'post_updated', array( $this, 'sign_on_update' ), 20 );

		$update_result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		// Restore hooks.
		add_action( 'publish_post', array( $this, 'sign_on_publish' ), 20, 2 );
		add_action( 'post_updated', array( $this, 'sign_on_update' ), 20, 3 );

		if ( is_wp_error( $update_result ) ) {
			update_post_meta( $post_id, '_c2pa_status', 'error' );
			return false;
		}

		update_post_meta( $post_id, '_c2pa_manifest', $result['manifest'] );
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_signed_at', gmdate( 'c' ) );
		update_post_meta( $post_id, '_c2pa_signer_tier', $signer->get_tier() );

		return true;
	}

	/**
	 * Registers the c2pa/sign and c2pa/verify abilities.
	 *
	 * Hooked to 'wp_abilities_api_init'.
	 *
	 * @since 0.5.0
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
	 * @since 0.5.0
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
					'text' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
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
	}

	/**
	 * REST callback: verify C2PA provenance in submitted text.
	 *
	 * Extracts and validates the embedded manifest, returning a structured
	 * response that includes verification status, the parsed manifest, and
	 * any error detail.
	 *
	 * @since 0.5.0
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function rest_verify_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$text   = (string) $request->get_param( 'text' );
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
	 * REST callback: return the signing status for a specific post.
	 *
	 * Reads post meta written by sign_post() and returns a summarised status
	 * payload for use in the block editor sidebar panel.
	 *
	 * @since 0.5.0
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

		// Provide a truncated preview rather than the full manifest.
		$manifest_preview = null;
		if ( $manifest ) {
			$decoded = json_decode( $manifest, true );
			if ( is_array( $decoded ) ) {
				$manifest_preview = array(
					'magic'   => $decoded['magic'] ?? null,
					'version' => $decoded['version'] ?? null,
					'signer'  => $decoded['signer'] ?? null,
				);
			}
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
	 * @since 0.5.0
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
				'enabled'    => $this->is_enabled(),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'restUrl'    => rest_url( 'c2pa-provenance/v1' ),
				'signerTier' => ( $this->get_signing_option( 'signing_tier' ) ? (string) $this->get_signing_option( 'signing_tier' ) : 'local' ),
			)
		);
	}

	/**
	 * Registers the /.well-known/c2pa rewrite rule.
	 *
	 * Adds a custom rewrite rule that maps the well-known URI to a custom
	 * query var so handle_well_known_request() can intercept and serve it.
	 *
	 * @since 0.5.0
	 */
	public function add_well_known_rewrite(): void {
		add_rewrite_rule(
			'^\.well-known/c2pa/?$',
			'index.php?c2pa_well_known=1',
			'top'
		);

		add_filter(
			'query_vars',
			static function ( array $vars ): array {
				$vars[] = 'c2pa_well_known';
				return $vars;
			}
		);
	}

	/**
	 * Serves the /.well-known/c2pa discovery document when requested.
	 *
	 * Outputs a JSON manifest discovery document that identifies this site as
	 * a C2PA-capable content origin and provides the verification endpoint URL.
	 *
	 * @since 0.5.0
	 */
	public function handle_well_known_request(): void {
		if ( ! get_query_var( 'c2pa_well_known' ) ) {
			return;
		}

		$document = array(
			'@context'        => 'https://c2pa.org/well-known/v1',
			'verify_url'      => rest_url( 'c2pa-provenance/v1/verify' ),
			'site_url'        => home_url(),
			'site_name'       => get_bloginfo( 'name' ),
			'generator'       => 'WordPress/AI Content Provenance Experiment',
			'supported_tiers' => array( 'local', 'connected', 'byok' ),
			'active_tier'     => ( $this->get_signing_option( 'signing_tier' ) ? (string) $this->get_signing_option( 'signing_tier' ) : 'local' ),
		);

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		exit;
	}

	/**
	 * Handles the experiment enable/disable toggle.
	 *
	 * Generates the local keypair on first activation so it is available
	 * immediately when the first post is published.
	 *
	 * @since 0.5.0
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
	 * Uses 2048-bit RSA which balances key size with broad PHP environment
	 * compatibility. Called once on experiment activation.
	 *
	 * @since 0.5.0
	 */
	public function ensure_local_keypair(): void {
		$existing = get_option( '_c2pa_local_keypair' );

		if ( is_array( $existing ) && ! empty( $existing['private_key'] ) ) {
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
	 * @since 0.5.0
	 *
	 * @return Signing_Interface
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
	 * @since 0.5.0
	 *
	 * @return Signing_Interface
	 */
	private function get_signer(): Signing_Interface {
		$raw_tier = $this->get_signing_option( 'signing_tier' );
		$tier     = $raw_tier ? (string) $raw_tier : 'local';

		if ( 'connected' === $tier ) {
			return new Connected_Signer(
				(string) $this->get_signing_option( 'connected_service_url' ),
				(string) $this->get_signing_option( 'connected_service_api_key' )
			);
		}

		if ( 'byok' === $tier ) {
			return new BYOK_Signer(
				(string) $this->get_signing_option( 'byok_certificate' )
			);
		}

		return new Local_Signer( $this->get_local_keypair() );
	}

	/**
	 * Returns the value of an experiment setting option.
	 *
	 * Wraps get_option() with the namespaced option name produced by
	 * get_field_option_name() to reduce boilerplate at call sites.
	 *
	 * @since 0.5.0
	 *
	 * @param string $name Base option name (e.g. 'signing_tier').
	 * @return mixed Option value, or false if not set.
	 */
	private function get_signing_option( string $name ) {
		return get_option( $this->get_field_option_name( $name ) );
	}

	/**
	 * Retrieves or generates the local RSA keypair.
	 *
	 * Reads the persisted keypair from the '_c2pa_local_keypair' site option.
	 * If none exists (e.g. the option was deleted after activation), generates
	 * a new one on the fly and persists it.
	 *
	 * @since 0.5.0
	 *
	 * @return array{private_key: string, public_key: string}
	 */
	private function get_local_keypair(): array {
		$stored = get_option( '_c2pa_local_keypair' );

		if ( is_array( $stored ) && ! empty( $stored['private_key'] ) ) {
			/** @var array{private_key: string, public_key: string} $stored */
			return $stored;
		}

		$keypair = $this->generate_keypair();

		if ( is_wp_error( $keypair ) ) {
			// Return a placeholder — signing will fail gracefully downstream.
			return array(
				'private_key' => '',
				'public_key'  => '',
			);
		}

		update_option( '_c2pa_local_keypair', $keypair, false );

		return $keypair;
	}

	/**
	 * Generates a fresh RSA-2048 keypair using the PHP OpenSSL extension.
	 *
	 * @since 0.5.0
	 *
	 * @return array{private_key: string, public_key: string}|\WP_Error Keypair array or WP_Error on failure.
	 */
	private function generate_keypair() {
		$resource = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		if ( false === $resource ) {
			return new \WP_Error(
				'c2pa_keypair_gen_failed',
				esc_html__( 'Failed to generate RSA keypair via OpenSSL. Ensure the OpenSSL PHP extension is available.', 'ai' )
			);
		}

		$private_key_pem = '';
		openssl_pkey_export( $resource, $private_key_pem );

		$key_details = openssl_pkey_get_details( $resource );
		$public_key  = is_array( $key_details ) ? ( $key_details['key'] ?? '' ) : '';

		if ( empty( $private_key_pem ) || empty( $public_key ) ) {
			return new \WP_Error(
				'c2pa_keypair_export_failed',
				esc_html__( 'Failed to export RSA keypair from OpenSSL.', 'ai' )
			);
		}

		return array(
			'private_key' => $private_key_pem,
			'public_key'  => $public_key,
		);
	}
}
