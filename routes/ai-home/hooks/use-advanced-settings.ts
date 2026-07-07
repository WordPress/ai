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

const STORAGE_KEY = 'ai_advanced_settings';

export interface AdvancedSettingsContextValue {
	isAdvancedSettingsEnabled: boolean;
	setAdvancedSettingsEnabled: ( enabled: boolean ) => void;
}

const defaultContextValue: AdvancedSettingsContextValue = {
	isAdvancedSettingsEnabled: false,
	setAdvancedSettingsEnabled: () => {},
};

export const AdvancedSettingsContext =
	createContext< AdvancedSettingsContextValue >( defaultContextValue );

/**
 * Reads the current advanced settings state from context.
 *
 * Use this hook inside any component that needs to check whether advanced
 * configuration options should be visible.
 *
 * @return {AdvancedSettingsContextValue} The context value.
 */
export function useAdvancedSettingsContext(): AdvancedSettingsContextValue {
	return useContext( AdvancedSettingsContext );
}

/**
 * Manages the advanced settings preference.
 *
 * Persists the user's choice to localStorage so it survives page reloads.
 * Intended to be called once at the root of the AI Settings page to provide
 * the context value.
 *
 * @return {AdvancedSettingsContextValue} The context value with state and setter.
 */
export function useAdvancedSettings(): AdvancedSettingsContextValue {
	const [ isAdvancedSettingsEnabled, setEnabled ] = useState< boolean >(
		() => {
			try {
				return localStorage.getItem( STORAGE_KEY ) === 'true';
			} catch {
				return false;
			}
		}
	);

	useEffect( () => {
		try {
			if ( isAdvancedSettingsEnabled ) {
				localStorage.setItem( STORAGE_KEY, 'true' );
			} else {
				localStorage.removeItem( STORAGE_KEY );
			}
		} catch {}
	}, [ isAdvancedSettingsEnabled ] );

	const setAdvancedSettingsEnabled = useCallback( ( enabled: boolean ) => {
		setEnabled( enabled );
	}, [] );

	return {
		isAdvancedSettingsEnabled,
		setAdvancedSettingsEnabled,
	};
}
