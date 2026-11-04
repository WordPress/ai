<?php
/**
 * AI experiment links on the Available Tools screen.
 *
 * @package WordPress\AI\Admin
 * @since 1.7.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Tools submenu pages and lists them on Available Tools.
 * @since 1.7.0
 */
final class Tools_Page {

	/**
	 * Experiments whose submenu pages were registered for this request.
	 *
	 * @var array<string, array{label: string, description: string}>
	 */
	private static array $entries = array();

	/**
	 * Registers an experiment's Tools submenu page and remembers it for the card.
	 * @since 1.7.0
	 * @param string   $slug        The submenu page slug.
	 * @param string   $label       The page title and link label.
	 * @param string   $description A short description of the tool.
	 * @param callable $callback    Renders the submenu page.
	 * @return string|false The page hook, or false if the user cannot access the page.
	 */
	public static function add_experiment( string $slug, string $label, string $description, callable $callback ) {
		$hook = add_submenu_page( 'tools.php', $label, $label, 'manage_options', $slug, $callback );

		if ( $hook ) {
			self::$entries[ $slug ] = array(
				'label'       => $label,
				'description' => $description,
			);
		}

		return $hook;
	}

	/**
	 * Renders the AI experiments card on the main Tools page.
	 * @since 1.7.0
	 */
	public static function render(): void {
		if ( empty( self::$entries ) ) {
			return;
		}
		?>
		<section class="card" aria-labelledby="wpai-tools-heading">
			<h2 class="title" id="wpai-tools-heading"><?php esc_html_e( 'AI experiments', 'ai' ); ?></h2>
			<ul role="list">
				<?php foreach ( self::$entries as $slug => $entry ) : ?>
					<li>
						<p>
							<a href="<?php echo esc_url( menu_page_url( $slug, false ) ); ?>"><?php echo esc_html( $entry['label'] ); ?></a><br />
							<?php echo esc_html( $entry['description'] ); ?>
						</p>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}
}
