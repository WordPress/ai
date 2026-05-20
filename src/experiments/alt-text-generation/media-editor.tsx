/**
 * Alt text generation integration for Gutenberg's experimental Media Editor.
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

// `registerPostTypeSchema` runs asynchronously from `usePostFields` and may
// land after our module-level dispatch, which would remove our override.
// We need to re-register on both the route-based editor and modal editor to ensure our override is applied.
let lastHandledPostType: string | undefined;
let lastMediaEditorModalOpen = false;
let scheduled: ReturnType< typeof setTimeout > | null = null;

const registerAltTextField = () => {
	if ( ! isMediaEditorEnabled ) {
		return;
	}

	registerEntityField( 'postType', 'attachment', field );
};

const scheduleRegister = () => {
	// Debounce to coalesce core's burst of `registerEntityField` dispatches from `registerPostTypeSchema`.
	if ( scheduled ) {
		clearTimeout( scheduled );
	}
	scheduled = setTimeout( registerAltTextField, 100 );
};

/**
 * Re-registers our override when the route-based Media Editor activates,
 * detected by `core/editor.getCurrentPostType()` transitioning to
 * `attachment`.
 */
const subscribeToRouteMediaEditor = () => {
	subscribe( () => {
		if ( ! isMediaEditorEnabled ) {
			return;
		}

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

		scheduleRegister();
	}, 'core/editor' );
};

/**
 * Re-registers our override when the Media Editor Modal opens.
 */
const subscribeToModalMediaEditor = () => {
	subscribe( () => {
		if ( ! isMediaEditorEnabled ) {
			return;
		}

		// `@wordpress/media-editor` doesn't publicly export its store,
		// so for now we need to use the string literal to access it.
		const mediaEditorState = select( 'core/media-editor' ) as  // eslint-disable-line @wordpress/data-no-store-string-literals
			| { isOpen?: () => boolean }
			| undefined;
		const isOpen = !! mediaEditorState?.isOpen?.();

		if ( isOpen === lastMediaEditorModalOpen ) {
			return;
		}

		lastMediaEditorModalOpen = isOpen;
		if ( ! isOpen ) {
			return;
		}

		scheduleRegister();
	}, 'core/media-editor' );
};

registerAltTextField();
subscribeToRouteMediaEditor();
subscribeToModalMediaEditor();
