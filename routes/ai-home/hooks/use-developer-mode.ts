/**
 * WordPress dependencies
 */
import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useState,
} from '@wordpress/element';

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
	const [ isDeveloperMode, setIsDeveloperMode ] = useState< boolean >( () => {
		try {
			return localStorage.getItem( STORAGE_KEY ) === 'true';
		} catch {
			return false;
		}
	} );

	useEffect( () => {
		try {
			if ( isDeveloperMode ) {
				localStorage.setItem( STORAGE_KEY, 'true' );
			} else {
				localStorage.removeItem( STORAGE_KEY );
			}
		} catch {}
	}, [ isDeveloperMode ] );

	const toggleDeveloperMode = useCallback( () => {
		setIsDeveloperMode( ( prev ) => ! prev );
	}, [] );

	return { isDeveloperMode, toggleDeveloperMode };
}
