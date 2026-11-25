/**
 * The JavaScript parts of the attachment `alt_text` field: the `@ai/alt-text-field` script module.
 *
 * @see includes/Experiments/Alt_Text_Generation/Alt_Text_Generation.php
 */

/**
 * Internal dependencies
 */
import { MediaEditorAltTextControl } from '../../../src/experiments/alt-text-generation/components/MediaEditorAltTextControl';

export default {
	alt_text: {
		Edit: MediaEditorAltTextControl,
	},
};
