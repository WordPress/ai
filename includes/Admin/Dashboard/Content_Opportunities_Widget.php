<?php
/**
 * Content Opportunities dashboard widget.
 *
 * @package WordPress\AI\Admin\Dashboard
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the mount point for the Content Opportunities React app.
 *
 * The widget itself only renders an empty root element and a no-JS
 * fallback message; all data fetching happens client-side (via the
 * `ai/content-gap-suggestions` ability) when the user clicks "Generate",
 * so simply loading the dashboard never triggers an AI request.
 *
 * @since x.x.x
 */
class Content_Opportunities_Widget {

	/**
	 * DOM ID of the React mount point.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ROOT_ID = 'ai-content-gap-suggestions-root';

	/**
	 * Renders the widget content.
	 *
	 * @since x.x.x
	 */
	public function render(): void {
		?>
		<div id="<?php echo esc_attr( self::ROOT_ID ); ?>" class="ai-content-gap-suggestions">
			<p class="ai-content-gap-suggestions__fallback">
				<?php esc_html_e( 'Loading…', 'ai' ); ?>
			</p>
		</div>
		<?php
	}
}
