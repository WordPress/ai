# Update the CSS styles to reflect the new layout
(function($) {
  $(document).ready(function() {
    // Update the CSS styles to reflect the new layout
    $('.ai-alt-text-generation').css({
      'margin-top': '20px'
    });
    $('.ai-alt-text-generation__content').css({
      'padding': '20px',
      'background-color': '#f7f7f7',
      'border': '1px solid #ddd',
      'border-radius': '10px',
      'box-shadow': '0 0 10px rgba(0, 0, 0, 0.1)'
    });
    $('#ai-alt-text-generation-textarea').css({
      'width': '100%',
      'height': '100px',
      'padding': '10px',
      'font-size': '16px',
      'border': '1px solid #ccc'
    });
    $('#ai-alt-text-generation-button').css({
      'margin-top': '10px'
    });
  });
})(jQuery);