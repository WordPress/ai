/**
 * Alt text generation integration for Gutenberg's experimental Media Editor.
 */

/**
 * WordPress dependencies
 */
import { registerEntityField } from '@wordpress/editor';
import { addAction } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import type { Field } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import { MediaEditorAltTextControl } from './components/MediaEditorAltTextControl';
import type { MediaEditorAttachment } from './types';

interface MediaEditorWindow extends Window {
	__experimentalMediaEditor?: boolean;
	__experimentalMediaEditorModal?: boolean;
}

const { __experimentalMediaEditor, __experimentalMediaEditorModal } =
	window as MediaEditorWindow;

const isMediaEditorEnabled =
	!! __experimentalMediaEditor || !! __experimentalMediaEditorModal;

const field: Field< MediaEditorAttachment > = {
	id: 'alt_text',
	type: 'text',
	label: __( 'Alt text', 'ai' ),
	isVisible: ( item ) => item?.media_type === 'image',
	render: ( { item } ) => item?.alt_text || '-',
	Edit: MediaEditorAltTextControl,
	enableSorting: false,
};

const registerAltTextField = () => {
	if ( ! isMediaEditorEnabled ) {
		return;
	}

	registerEntityField( 'postType', 'attachment', field );
};

// Register after core's attachment fields so ours replaces core's `alt_text` field.
addAction(
	'core.registerPostTypeSchema',
	'ai/alt-text-generation',
	( postType: string ) => {
		if ( 'attachment' === postType ) {
			registerAltTextField();
		}
	}
);

registerAltTextField();
