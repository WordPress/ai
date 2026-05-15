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
import { ensureProvider } from '../../../utils/provider-status';
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
	suggestion: MetaDescriptionSuggestion | null;
	currentDescription: string;
	metaKey: string;
	hasSeoPlugin: boolean;
	ensureProviderAvailable: () => boolean;
	generateDescription: () => Promise< void >;
	applyDescription: ( text: string ) => void;
}

/**
 * Hook providing meta description generation state and actions.
 *
 * @return Object with generation state, suggestion, and handlers.
 */
export function useMetaDescription(): UseMetaDescriptionReturn {
	const localized = getLocalized();
	const metaKey = localized?.metaKey ?? 'wpai_meta_description';
	const hasSeoPlugin = Boolean( localized?.seoPlugin );

	const { editPost } = useDispatch( editorStore );
	const { removeNotice, createErrorNotice } = dispatch( noticesStore );

	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ suggestion, setSuggestion ] =
		useState< MetaDescriptionSuggestion | null >( null );

	const ensureProviderAvailable = useCallback(
		() => ensureProvider( NOTICE_ID ),
		[]
	);

	const { postId, content, title, meta } = useSelect( ( select ) => {
		const editor = select( editorStore );
		const currentMeta = editor.getEditedPostAttribute( 'meta' ) as
			| Record< string, string >
			| undefined;

		return {
			postId: editor.getCurrentPostId() as number,
			content: editor.getEditedPostContent(),
			title: editor.getEditedPostAttribute( 'title' ) as string,
			meta: currentMeta,
		};
	}, [] );

	const generateDescription = useCallback( async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsGenerating( true );
		setSuggestion( null );

		// Clear any existing notices.
		removeNotice( NOTICE_ID );

		try {
			// Generate the meta description.
			const params: MetaDescriptionAbilityInput = {
				content,
				title,
				post_id: postId,
			};

			const response = await runAbility< MetaDescriptionAbilityResponse >(
				'ai/meta-description',
				params
			);

			if ( response?.description ) {
				setSuggestion( response.description );
			} else {
				createErrorNotice(
					'No meta description suggestion was generated.',
					{ id: NOTICE_ID, isDismissible: true }
				);
			}
		} catch ( error: any ) {
			const message =
				typeof error === 'string'
					? error
					: error?.message ?? 'Failed to generate meta description.';

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
					...meta,
					[ metaKey ]: text,
				},
			} );
		},
		[ editPost, metaKey, meta ]
	);

	return {
		isGenerating,
		suggestion,
		currentDescription: meta?.[ metaKey ] ?? '',
		metaKey,
		hasSeoPlugin,
		ensureProviderAvailable,
		generateDescription,
		applyDescription,
	};
}
