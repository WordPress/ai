# Update the CSS styles to reflect the new layout
<?php
/**
 * Image Prompt System Instruction
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Includes\Abilities\Image;

class Image_Prompt_System_Instruction {
  public function __construct() {
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    add_action( 'admin_footer', array( $this, 'render_instruction' ) );
  }

  public function enqueue_scripts() {
    wp_enqueue_script( 'ai-image-prompt-system-instruction', AI_PLUGIN_URL . 'assets/js/ai-image-prompt-system-instruction.js', array( 'jquery' ) );
  }

  public function render_instruction() {
    ?>
    <style>
      .ai-alt-text-generation {
        margin-top: 20px;
      }
      .ai-alt-text-generation__content {
        padding: 20px;
        background-color: #f7f7f7;
        border: 1px solid #ddd;
        border-radius: 10px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
      }
      #ai-alt-text-generation-textarea {
        width: 100%;
        height: 100px;
        padding: 10px;
        font-size: 16px;
        border: 1px solid #ccc;
      }
      #ai-alt-text-generation-button {
        margin-top: 10px;
      }
    </style>
    <?php
  }
}