# Update the layout of the Alt Text generation button on the Edit Media page
# Remove the standalone Alt Text metabox on the Edit Media page
# Update the layout of the Alt Text generation button on the Attachment details view
<?php
/**
 * AI Capabilities Widget
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Includes\Admin\Dashboard;

use WordPress\AI\Includes\Abilities\Image\Alt_Text_Generation;

class AI_Capabilities_Widget {
  public function __construct() {
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    add_action( 'admin_footer', array( $this, 'render_widget' ) );
  }

  public function enqueue_scripts() {
    wp_enqueue_script( 'ai-capabilities-widget', AI_PLUGIN_URL . 'assets/js/ai-capabilities-widget.js', array( 'jquery' ) );
  }

  public function render_widget() {
    ?>
    <div class="ai-capabilities-widget">
      <h2><?php _e( 'AI Capabilities', 'ai' ); ?></h2>
      <div class="ai-capabilities-widget__content">
        <div class="ai-capabilities-widget__section">
          <h3><?php _e( 'Alt Text Generation', 'ai' ); ?></h3>
          <button class="button button-primary" id="ai-alt-text-generation-button"><?php _e( 'Generate Alt Text', 'ai' ); ?></button>
        </div>
        <div class="ai-capabilities-widget__section">
          <h3><?php _e( 'Attachment Details', 'ai' ); ?></h3>
          <button class="button button-primary" id="ai-attachment-details-button"><?php _e( 'View Attachment Details', 'ai' ); ?></button>
        </div>
      </div>
    </div>
    <?php
  }
}