/**
 * Wrapper component that injects AI suggestions into the Tags sidebar panel.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import SuggestionPanel from './SuggestionPanel';
import { usePanelInjection } from './usePanelInjection';

/**
 * Container ID for the tag suggestions.
 */
const CONTAINER_ID = 'ai-contextual-tagging-tags';

/**
 * TagPanelWrapper component.
 *
 * Uses DOM observation to inject the suggestion panel into the Tags
 * sidebar panel in the block editor.
 *
 * @return null - Renders via portal.
 */
export default function TagPanelWrapper(): null {
	const container = usePanelInjection( 'Tags', CONTAINER_ID );
	const rootRef = useRef< ReturnType< typeof createRoot > | null >(
		null
	);

	useEffect( () => {
		if ( ! container ) {
			if ( rootRef.current ) {
				rootRef.current.unmount();
				rootRef.current = null;
			}
			return;
		}

		if ( ! rootRef.current ) {
			rootRef.current = createRoot( container );
		}
		rootRef.current.render( <SuggestionPanel taxonomy="post_tag" /> );

		return () => {
			if ( rootRef.current ) {
				rootRef.current.unmount();
				rootRef.current = null;
			}
		};
	}, [ container ] );

	return null;
}
