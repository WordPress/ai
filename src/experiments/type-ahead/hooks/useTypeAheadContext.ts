/**
 * Hooks for type-ahead context.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { htmlToPlainText } from '../utils/text';

type TypeAheadContext = {
	selectedClientId: string | null;
	siblingContext: string;
	postId: number;
	plainContent: string;
};

/**
 * Collects editor context used by type-ahead requests.
 *
 * @param {string} clientId    Current block client ID.
 * @param {string} htmlContent Current block HTML content.
 * @return {TypeAheadContext} Block selection state and neighboring text context.
 */
export const useTypeAheadContext = (
	clientId: string,
	htmlContent: string
): TypeAheadContext => {
	const { selectedClientId, siblingContext, postId } = useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore );
			const editor = select( editorStore );
			const selected = blockEditor.getSelectedBlockClientId();
			const rootClientId = blockEditor.getBlockRootClientId( clientId );
			const order = blockEditor.getBlockOrder(
				rootClientId || undefined
			);
			const index = blockEditor.getBlockIndex( clientId );
			const hasOrder = Array.isArray( order ) && order.length > 0;
			const previousId =
				hasOrder && index > 0 ? order[ index - 1 ] : null;
			const nextId =
				hasOrder && index !== -1 && index < order.length - 1
					? order[ index + 1 ]
					: null;
			const previous = previousId
				? blockEditor.getBlockAttributes( previousId )
				: null;
			const next = nextId
				? blockEditor.getBlockAttributes( nextId )
				: null;

			const neighborText = [
				previous?.[ 'content' ], // eslint-disable-line dot-notation
				next?.[ 'content' ], // eslint-disable-line dot-notation
			]
				.filter( Boolean )
				.map( ( value ) => htmlToPlainText( value as string ) )
				.join( '\n\n' )
				.trim();

			return {
				selectedClientId: selected,
				siblingContext: neighborText,
				postId: Number( editor.getCurrentPostId() || 0 ),
			};
		},
		[ clientId ]
	);

	const plainContent = useMemo(
		() => htmlToPlainText( htmlContent || '' ),
		[ htmlContent ]
	);

	return {
		selectedClientId,
		siblingContext,
		postId,
		plainContent,
	};
};
