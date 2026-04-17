/**
 * Alt text generation integration for Gutenberg's experimental Media Editor.
 *
 * Re-registers the core `alt_text` entity field on the `attachment` post type
 * to render an AI generate/regenerate control next to the standard textarea.
 */

/**
 * WordPress dependencies
 */
import { registerEntityField, store as editorStore } from '@wordpress/editor';
import { subscribe, select } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import type { Field } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { MediaEditorAltTextControl } from './components/MediaEditorAltTextControl';
import type { MediaEditorAttachment } from './types';

interface MediaEditorWindow extends Window {
	__experimentalMediaEditor?: boolean;
}

const { __experimentalMediaEditor } = window as MediaEditorWindow;

if ( __experimentalMediaEditor ) {
	const field: Field< MediaEditorAttachment > = {
		id: 'alt_text',
		type: 'text',
		label: __( 'Alt text', 'ai' ),
		isVisible: ( item ) => item?.media_type === 'image',
		render: ( { item } ) => item?.alt_text || '-',
		Edit: MediaEditorAltTextControl,
		enableSorting: false,
	};

	// `registerPostTypeSchema` runs asynchronously from `usePostFields` and may
	// land after our module-level dispatch, which would remove our override.
	// Re-register once whenever the current post type transitions into `attachment`.
	let lastHandledPostType: string | undefined;
	let scheduled: ReturnType< typeof setTimeout > | null = null;

	const doRegister = () => {
		registerEntityField( 'postType', 'attachment', field );
	};

	doRegister();

	subscribe( () => {
		const postType = select( editorStore ).getCurrentPostType() as
			| string
			| undefined;

		if ( postType === lastHandledPostType ) {
			return;
		}

		lastHandledPostType = postType;
		if ( postType !== 'attachment' ) {
			return;
		}

		// Debounce to coalesce core's burst of `registerEntityField` dispatches from `registerPostTypeSchema`.
		if ( scheduled ) {
			clearTimeout( scheduled );
		}
		scheduled = setTimeout( doRegister, 100 );
	}, 'core/editor' );
}
