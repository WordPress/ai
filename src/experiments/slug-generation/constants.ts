/**
 * Shared constants for the slug generation experiment.
 */

/**
 * Window event dispatched by the permalink popover button to open the suggestions modal.
 *
 * The button renders into its own React root inside the popover, so a window event is
 * used to reach the plugin component that owns the modal.
 */
export const TRIGGER_EVENT = 'ai-trigger-slug-generation';

/**
 * Selector matching the permalink panel the "Generate Slug" button is attached to.
 */
export const SLUG_PANEL_SELECTOR = '.editor-post-url';

/**
 * Class name of the container the button is rendered into.
 */
export const BUTTON_CONTAINER_CLASS = 'ai-slug-generation-container';
