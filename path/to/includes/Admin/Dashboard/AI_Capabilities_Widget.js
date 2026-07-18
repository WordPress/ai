# Update the layout of the Alt Text generation button on the Edit Media page
# Remove the standalone Alt Text metabox on the Edit Media page
# Update the layout of the Alt Text generation button on the Attachment details view
(function($) {
  $(document).ready(function() {
    // Update the layout of the Alt Text generation button on the Edit Media page
    $('#ai-alt-text-generation-button').appendTo('.ai-capabilities-widget__content');

    // Remove the standalone Alt Text metabox on the Edit Media page
    $('#ai-alt-text-metabox').remove();

    // Update the layout of the Alt Text generation button on the Attachment details view
    $('#ai-attachment-details-button').appendTo('.ai-capabilities-widget__content');
  });
})(jQuery);