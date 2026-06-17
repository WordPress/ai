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

const STORAGE_KEY = 'ai_access_control_mode';

export const AccessControlModeContext = createContext< boolean >( false );

export function useAccessControlModeContext(): boolean {
	return useContext( AccessControlModeContext );
}

interface UseAccessControlModeReturn {
	isAccessControlMode: boolean;
	toggleAccessControlMode: () => void;
}

/**
 * useAccessControlMode hook.
 *
 * @return {UseAccessControlModeReturn} The access control mode return object.
 */
export function useAccessControlMode(): UseAccessControlModeReturn {
	const [ isAccessControlMode, setIsAccessControlMode ] = useState< boolean >( () => {
		try {
			return localStorage.getItem( STORAGE_KEY ) === 'true';
		} catch {
			return false;
		}
	} );

	useEffect( () => {
		try {
			if ( isAccessControlMode ) {
				localStorage.setItem( STORAGE_KEY, 'true' );
			} else {
				localStorage.removeItem( STORAGE_KEY );
			}
		} catch {}
	}, [ isAccessControlMode ] );

	const toggleAccessControlMode = useCallback( () => {
		setIsAccessControlMode( ( prev ) => ! prev );
	}, [] );

	return { isAccessControlMode, toggleAccessControlMode };
}
