/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { useLocalStoragePreference } from './use-local-storage-preference';

const STORAGE_KEY = 'ai_developer_mode';

export const DeveloperModeContext = createContext< boolean >( false );

export function useDeveloperModeContext(): boolean {
	return useContext( DeveloperModeContext );
}

interface UseDeveloperModeReturn {
	isDeveloperMode: boolean;
	toggleDeveloperMode: () => void;
}

/**
 * useDeveloperMode hook.
 *
 * @return {UseDeveloperModeReturn} The developer mode return object.
 */
export function useDeveloperMode(): UseDeveloperModeReturn {
	const { enabled, toggle } = useLocalStoragePreference( STORAGE_KEY );

	return { isDeveloperMode: enabled, toggleDeveloperMode: toggle };
}
