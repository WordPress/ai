# Update the HTML structure and CSS styles to reflect the new layout
<?php
/**
 * Alt Text Generation Ability
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Includes\Abilities\Image;

use WordPress\AI\Includes\Abilities\Image\Image_Prompt_System_Instruction;

class Alt_Text_Generation {
  public function __construct() {
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    add_action( 'admin_footer', array( $this, 'render_ability' ) );
  }

  public function enqueue_scripts() {
    wp_enqueue_script( 'ai-alt-text-generation', AI_PLUGIN_URL . 'assets/js/ai-alt-text-generation.js', array( 'jquery' ) );
  }

  public function render_ability() {
    ?>
    <div class="ai-alt-text-generation">
      <h2><?php _e( 'Alt Text Generation', 'ai' ); ?></h2>
      <div class="ai-alt-text-generation__content">
        <textarea id="ai-alt-text-generation-textarea" placeholder="<?php _e( 'Enter image description', 'ai' ); ?>"></textarea>
        <button class="button button-primary" id="ai-alt-text-generation-button"><?php _e( 'Generate Alt Text', 'ai' ); ?></button>
      </div>
    </div>
    <?php
  }
}