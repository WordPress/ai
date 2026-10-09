<?php
/**
 * Admin page for semantic search content indexing.
 *
 * Located at Tools → Semantic Search Index. Shows indexing status, a
 * test-connection button, and a batch indexing button with live progress.
 *
 * @package WordPress\AI\Experiments\Semantic_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Semantic_Search;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the content indexing admin page.
 *
 * Hooks into admin_menu to add a submenu under Tools and into
 * admin_enqueue_scripts to pass REST URLs and nonce to the inline JS that
 * drives the test-connection and batch-indexing buttons.
 *
 * @internal
 * @since x.x.x
 */
class Index_Page {

	/**
	 * Admin page slug used in add_submenu_page() and the load-* hook.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const PAGE_SLUG = 'wpai-semantic-search-index';

	/**
	 * Registers the admin_menu and admin_enqueue_scripts hooks.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the "Semantic Search Index" submenu under Tools.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'Semantic Search Index', 'ai' ),
			__( 'Semantic Search Index', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Localises the REST URLs and nonce for the indexing page JS.
	 *
	 * Only runs on the indexing admin page. Attaches data to jQuery (always
	 * loaded on admin pages) as the `wpaiSemanticSearchIndex` global so the
	 * inline page script can reference REST endpoints without hardcoding them.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook Hook suffix of the current admin page.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_localize_script(
			'jquery',
			'wpaiSemanticSearchIndex',
			array(
				'indexUrl'  => rest_url( 'ai/v1/semantic-search/index' ),
				'statusUrl' => rest_url( 'ai/v1/semantic-search/index/status' ),
				'testUrl'   => rest_url( 'ai/v1/semantic-search/index/test' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Renders the indexing admin page.
	 *
	 * Shows a notice with a link to Settings → AI when no embedding provider is
	 * configured. When a provider is configured, shows the current indexed/total
	 * counts, a Test Connection button, and an Index All Posts button. All button
	 * interactions are handled by the inline script at the bottom of the page.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai' ) );
		}

		$generator = new Embedding_Generator();
		$store     = new Embedding_Store();
		$stats     = $store->get_stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Semantic Search — Content Index', 'ai' ); ?></h1>

			<?php if ( ! $generator->is_available() ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						/* translators: %s: URL to the AI settings page. */
						$notice = __( 'No connector with embedding support is configured. Go to <a href="%s">Settings → AI</a> and connect a provider that supports embedding generation.', 'ai' );

						printf(
							wp_kses( $notice, array( 'a' => array( 'href' => array() ) ) ),
							esc_url( admin_url( 'options-general.php?page=ai-wp-admin' ) )
						);
						?>
					</p>
				</div>
			<?php else : ?>

				<p>
					<?php
					printf(
						/* translators: 1: indexed count, 2: total count */
						esc_html__( '%1$d of %2$d posts/pages indexed.', 'ai' ),
						(int) $stats['indexed'],
						(int) $stats['total']
					);
					?>
				</p>

				<div style="margin: 16px 0; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
					<button id="wpai-ss-test-btn" class="button">
						<?php esc_html_e( 'Test Connection', 'ai' ); ?>
					</button>
					<button id="wpai-ss-index-btn" class="button button-primary">
						<?php esc_html_e( 'Index All Posts', 'ai' ); ?>
					</button>
					<span id="wpai-ss-progress" style="display:none;">
						<span id="wpai-ss-count">0</span> / <?php echo esc_html( (string) $stats['total'] ); ?>
					</span>
				</div>

				<p id="wpai-ss-message" style="margin-top: 8px; white-space: pre-wrap;"></p>

			<?php endif; ?>
		</div>

		<script>
		( function () {
			var cfg      = window.wpaiSemanticSearchIndex || {};
			var nonce    = cfg.nonce    || '';
			var indexUrl = cfg.indexUrl || '';
			var testUrl  = cfg.testUrl  || '';

			var testBtn  = document.getElementById( 'wpai-ss-test-btn' );
			var indexBtn = document.getElementById( 'wpai-ss-index-btn' );
			var progress = document.getElementById( 'wpai-ss-progress' );
			var countEl  = document.getElementById( 'wpai-ss-count' );
			var msgEl    = document.getElementById( 'wpai-ss-message' );

			function setMsg( text, isError ) {
				if ( ! msgEl ) return;
				msgEl.textContent  = text;
				msgEl.style.color  = isError ? '#d63638' : '#3c8a3c';
			}

			function apiFetch( url, method ) {
				return fetch( url, {
					method:  method || 'GET',
					headers: {
						'X-WP-Nonce':   nonce,
						'Content-Type': 'application/json',
					},
				} ).then( function ( r ) { return r.json(); } );
			}

			if ( testBtn ) {
				testBtn.addEventListener( 'click', function () {
					testBtn.disabled = true;
					setMsg( '<?php echo esc_js( __( 'Testing connection…', 'ai' ) ); ?>', false );

					apiFetch( testUrl ).then( function ( res ) {
						if ( res.ok ) {
							setMsg(
								'<?php echo esc_js( __( 'Connection OK — model: ', 'ai' ) ); ?>' + res.model +
								', <?php echo esc_js( __( 'dimensions: ', 'ai' ) ); ?>' + res.dimensions + '.',
								false
							);
						} else {
							var msg = '<?php echo esc_js( __( 'Connection failed: ', 'ai' ) ); ?>' + res.error;
							if ( res.available_models && res.available_models.length ) {
								msg += '\n\n<?php echo esc_js( __( 'Available embedding models for your key:', 'ai' ) ); ?>\n  • '
									+ res.available_models.join( '\n  • ' )
									+ '\n\n<?php echo esc_js( __( 'Paste one of these into the Model field in Settings → AI and save.', 'ai' ) ); ?>';
							}
							setMsg( msg, true );
						}
					} ).catch( function ( err ) {
						setMsg( '<?php echo esc_js( __( 'Request error: ', 'ai' ) ); ?>' + ( err.message || 'unknown' ), true );
					} ).finally( function () {
						testBtn.disabled = false;
					} );
				} );
			}

			function runBatch() {
				apiFetch( indexUrl, 'POST' ).then( function ( res ) {
					if ( countEl ) countEl.textContent = res.indexed;

					if ( res.error ) {
						indexBtn.disabled      = false;
						progress.style.display = 'none';
						setMsg( '<?php echo esc_js( __( 'Indexing stopped: ', 'ai' ) ); ?>' + res.error, true );
						return;
					}

					if ( res.done ) {
						indexBtn.disabled      = false;
						progress.style.display = 'none';
						setMsg( '<?php echo esc_js( __( 'Done — all posts indexed.', 'ai' ) ); ?>', false );
					} else {
						setMsg(
							'<?php echo esc_js( __( 'Indexed ', 'ai' ) ); ?>' + res.indexed +
							' <?php echo esc_js( __( 'of ', 'ai' ) ); ?>' + res.total + '…',
							false
						);
						runBatch();
					}
				} ).catch( function ( err ) {
					indexBtn.disabled      = false;
					progress.style.display = 'none';
					setMsg( '<?php echo esc_js( __( 'Request error: ', 'ai' ) ); ?>' + ( err.message || 'unknown' ), true );
				} );
			}

			if ( indexBtn ) {
				indexBtn.addEventListener( 'click', function () {
					indexBtn.disabled      = true;
					progress.style.display = 'inline';
					setMsg( '', false );
					runBatch();
				} );
			}
		} )();
		</script>
		<?php
	}
}
