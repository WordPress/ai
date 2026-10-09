/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';

export interface LocalStoragePreference {
	enabled: boolean;
	toggle: () => void;
}

/**
 * Manages a boolean preference backed by localStorage.
 *
 * The value is read once on mount and persisted whenever it changes, surviving
 * page reloads. All localStorage access is wrapped in try/catch so the hook
 * degrades gracefully when storage is unavailable (e.g. private browsing).
 *
 * @param {string} storageKey The localStorage key used to persist the value.
 *
 * @return {LocalStoragePreference} The current value and a toggle function.
 */
export function useLocalStoragePreference(
	storageKey: string
): LocalStoragePreference {
	const [ enabled, setEnabled ] = useState< boolean >( () => {
		try {
			return localStorage.getItem( storageKey ) === 'true';
		} catch {
			return false;
		}
	} );

	useEffect( () => {
		try {
			if ( enabled ) {
				localStorage.setItem( storageKey, 'true' );
			} else {
				localStorage.removeItem( storageKey );
			}
		} catch {}
	}, [ enabled, storageKey ] );

	const toggle = useCallback( () => {
		setEnabled( ( prev ) => ! prev );
	}, [] );

	return { enabled, toggle };
}
