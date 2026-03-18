/**
 * Hook for meta description generation logic.
 */

/**
 * WordPress dependencies
 */
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState, useCallback } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import type {
	MetaDescriptionAbilityInput,
	MetaDescriptionAbilityResponse,
	MetaDescriptionSuggestion,
	MetaDescriptionData,
} from '../types';

const NOTICE_ID = 'ai_meta_description_error';

const getLocalized = (): MetaDescriptionData | undefined =>
	( window as any ).aiMetaDescriptionData as MetaDescriptionData | undefined;

interface UseMetaDescriptionReturn {
	isGenerating: boolean;
	suggestions: MetaDescriptionSuggestion[];
	currentDescription: string;
	metaKey: string;
	hasSeoPlugin: boolean;
	generateDescriptions: () => Promise< void >;
	applyDescription: ( text: string ) => void;
}

/**
 * Hook providing meta description generation state and actions.
 *
 * @return Object with generation state, suggestions, and handlers.
 */
export function useMetaDescription(): UseMetaDescriptionReturn {
	const localized = getLocalized();
	const metaKey = localized?.metaKey ?? '_meta_description';
	const hasSeoPlugin = Boolean( localized?.seoPlugin );

	const { editPost } = useDispatch( editorStore );
	const { removeNotice, createErrorNotice } = dispatch( noticesStore );

	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ suggestions, setSuggestions ] = useState<
		MetaDescriptionSuggestion[]
	>( [] );

	const { postId, content, title, currentDescription } = useSelect(
		( select ) => {
			const editor = select( editorStore );
			const meta = editor.getEditedPostAttribute( 'meta' ) as
				| Record< string, string >
				| undefined;

			return {
				postId: editor.getCurrentPostId() as number,
				content: editor.getEditedPostContent(),
				title: editor.getEditedPostAttribute( 'title' ) as string,
				currentDescription: meta?.[ metaKey ] ?? '',
			};
		},
		[ metaKey ]
	);

	const generateDescriptions = useCallback( async () => {
		setIsGenerating( true );
		setSuggestions( [] );

		// Clear any existing notices.
		removeNotice( NOTICE_ID );

		try {
			// Generate the meta descriptions.
			const params: MetaDescriptionAbilityInput = {
				content,
				title,
				post_id: postId,
			};

			const response = await runAbility< MetaDescriptionAbilityResponse >(
				'ai/meta-description',
				params
			);

			if ( response?.descriptions && response.descriptions.length > 0 ) {
				setSuggestions( response.descriptions );
			} else {
				createErrorNotice(
					'No meta description suggestions were generated.',
					{ id: NOTICE_ID, isDismissible: true }
				);
			}
		} catch ( error: any ) {
			const message =
				typeof error === 'string'
					? error
					: error?.message ?? 'Failed to generate meta descriptions.';

			createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	}, [ content, title, postId, removeNotice, createErrorNotice ] );

	const applyDescription = useCallback(
		( text: string ) => {
			editPost( {
				meta: {
					[ metaKey ]: text,
				},
			} );
		},
		[ editPost, metaKey ]
	);

	return {
		isGenerating,
		suggestions,
		currentDescription,
		metaKey,
		hasSeoPlugin,
		generateDescriptions,
		applyDescription,
	};
}
