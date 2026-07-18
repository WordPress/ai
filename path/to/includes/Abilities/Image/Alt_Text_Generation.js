# Update the HTML structure and CSS styles to reflect the new layout
(function($) {
  $(document).ready(function() {
    // Update the HTML structure and CSS styles to reflect the new layout
    $('.ai-alt-text-generation').append('<textarea id="ai-alt-text-generation-textarea" placeholder="Enter image description"></textarea>');
    $('.ai-alt-text-generation').append('<button class="button button-primary" id="ai-alt-text-generation-button">Generate Alt Text</button>');
  });
})(jQuery);