<?php
/**
 * AI tool links on the Available Tools screen.
 *
 * @package WordPress\AI\Admin
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Tools submenu pages and lists them on Available Tools.
 *
 * @since x.x.x
 */
final class Tools_Page {

	/**
	 * Pages whose submenu items were registered for this request.
	 *
	 * @var array<string, array{label: string, description: string, capability: string}>
	 */
	private static array $entries = array();

	/**
	 * Initializes the Tools page integrations.
	 *
	 * @since x.x.x
	 */
	public static function init(): void {
		add_action( 'tool_box', array( self::class, 'render' ) );
	}

	/**
	 * Registers a Tools submenu page and remembers it for the card.
	 *
	 * @since x.x.x
	 *
	 * @param string   $slug        The submenu page slug.
	 * @param string   $label       The page title and link label.
	 * @param string   $description A short description of the tool.
	 * @param callable $callback    Renders the submenu page.
	 * @param string   $capability  The capability required to access the tool.
	 * @return string|false The page hook, or false if the user cannot access the page.
	 */
	public static function add_page( string $slug, string $label, string $description, callable $callback, string $capability = 'manage_options' ) {
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The caller supplies the capability for this submenu.
		$hook = add_submenu_page( 'tools.php', $label, $label, $capability, $slug, $callback );

		if ( $hook ) {
			self::$entries[ $slug ] = array(
				'label'       => $label,
				'description' => $description,
				'capability'  => $capability,
			);
		}

		return $hook;
	}

	/**
	 * Renders the AI tools card on the main Tools page.
	 *
	 * @since x.x.x
	 */
	public static function render(): void {
		if ( empty( self::$entries ) ) {
			return;
		}
		?>
		<div class="card">
			<h2 class="title"><?php esc_html_e( 'AI Tools', 'ai' ); ?></h2>
			<ul role="list">
				<?php foreach ( self::$entries as $slug => $entry ) : ?>
					<li>
						<p>
							<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . $slug ) ); ?>"><?php echo esc_html( $entry['label'] ); ?></a><br />
							<?php echo esc_html( $entry['description'] ); ?>
						</p>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
