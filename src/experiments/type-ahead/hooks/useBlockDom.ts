/**
 * Hooks for block DOM.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

type BlockDomState = {
	block: HTMLElement | null;
	editable: HTMLElement | null;
};

/**
 * Locates the rendered block element and rich text editable element.
 *
 * @param clientId Block client ID.
 * @return Current block and editable nodes.
 */
export const useBlockDom = ( clientId: string ): BlockDomState => {
	const [ state, setState ] = useState< BlockDomState >( {
		block: null,
		editable: null,
	} );

	useEffect( () => {
		let cancelled = false;

		const queryDocuments = (): Document[] => {
			const docs: Document[] = [ document ];

			document
				.querySelectorAll(
					'iframe[name="editor-canvas"], iframe.wp-block-editor-iframe__iframe'
				)
				.forEach( ( frame ) => {
					if (
						frame instanceof HTMLIFrameElement &&
						frame.contentDocument
					) {
						docs.push( frame.contentDocument );
					}
				} );

			return docs;
		};

		const findEditable = ( blockEl: HTMLElement ): HTMLElement | null => {
			if (
				blockEl.getAttribute( 'contenteditable' ) === 'true' ||
				blockEl.hasAttribute( 'data-rich-text-editable' )
			) {
				return blockEl;
			}

			const candidates = Array.from(
				blockEl.querySelectorAll< HTMLElement >(
					'[data-rich-text-editable], [contenteditable]'
				)
			);

			return (
				candidates.find(
					( candidate ) =>
						candidate.getAttribute( 'contenteditable' ) !== 'false'
				) ?? null
			);
		};

		const lookup = () => {
			const selector = `[data-block="${ clientId }"]`;
			for ( const doc of queryDocuments() ) {
				const block = doc.querySelector< HTMLElement >( selector );
				if ( block ) {
					const editable = findEditable( block );
					if ( ! cancelled ) {
						setState( { block, editable } );
					}
					return;
				}
			}

			if ( ! cancelled ) {
				setState( { block: null, editable: null } );
			}
		};

		lookup();
		const interval = window.setInterval( lookup, 750 );

		return () => {
			cancelled = true;
			window.clearInterval( interval );
		};
	}, [ clientId ] );

	return state;
};
