/**
 * WordPress dependencies
 */
import { useSyncExternalStore } from '@wordpress/element';

/**
 * Shared post translation state.
 */
let isPostTranslatingGlobal = false;
const listeners = new Set< () => void >();

/**
 * Subscribes to shared post translation state changes.
 *
 * @param callback Called whenever the shared state changes.
 * @return Function that removes the subscription.
 */
function subscribe( callback: () => void ): () => void {
	listeners.add( callback );
	return () => {
		listeners.delete( callback );
	};
}

/**
 * Reads the latest post translation state for guards inside event handlers.
 * Use `usePostTranslating()` when a component should rerender as the state changes.
 *
 * @return Whether a post is currently being translated.
 */
export function getIsPostTranslating(): boolean {
	return isPostTranslatingGlobal;
}

/**
 * Updates the shared  state and notifies every subscriber.
 *
 * @param isPostTranslating Whether a post is currently being translated.
 */
export function setIsPostTranslating( isPostTranslating: boolean ): void {
	if ( isPostTranslatingGlobal === isPostTranslating ) {
		return;
	}

	isPostTranslatingGlobal = isPostTranslating;
	listeners.forEach( ( listener ) => listener() );
}

/**
 * Shares post translation activity across components so block previews can
 * be disabled while post translation runs. Since module-level changes do not
 * trigger React renders, `useSyncExternalStore()` subscribes components and
 * rerenders them when the activity value changes.
 *
 * @return Whether a post is currently being translated.
 */
export function usePostTranslating(): boolean {
	return useSyncExternalStore( subscribe, getIsPostTranslating );
}
