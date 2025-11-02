<?php
/**
 * Settings section renderer for fallback non-JS UI.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin\Settings;

/**
 * Handles rendering of settings sections in fallback mode.
 *
 * @since 0.1.0
 */
class Settings_Renderer {
	/**
	 * Renders the global toggle section for the fallback UI.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Toggle  $toggle  Toggle service.
	 * @param \WordPress\AI\Admin\Settings\Settings_Section $section Current section.
	 */
	public function render_toggle_section( Settings_Toggle $toggle, Settings_Section $section ): void {
		unset( $section ); // Section metadata currently unused by the fallback.

		$option_name = Settings_Toggle::OPTION_KEY;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( Settings_Toggle::SETTINGS_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>" value="0" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $option_name ); ?>">
							<?php esc_html_e( 'Enable Experimental Features', 'ai' ); ?>
						</label>
					</th>
					<td>
						<label for="<?php echo esc_attr( $option_name ); ?>">
							<input
								type="checkbox"
								name="<?php echo esc_attr( $option_name ); ?>"
								id="<?php echo esc_attr( $option_name ); ?>"
								value="1"
								<?php checked( $toggle->is_enabled() ); ?>
							/>
							<?php esc_html_e( 'Allow experimental AI features to run on this site.', 'ai' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}