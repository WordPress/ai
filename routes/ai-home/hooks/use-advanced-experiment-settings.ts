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

const STORAGE_KEY = 'ai_advanced_experiment_settings';

export interface AdvancedExperimentSettingsContextValue {
	isAdvancedExperimentSettingsEnabled: boolean;
	setAdvancedExperimentSettingsEnabled: ( enabled: boolean ) => void;
}

const defaultContextValue: AdvancedExperimentSettingsContextValue = {
	isAdvancedExperimentSettingsEnabled: false,
	setAdvancedExperimentSettingsEnabled: () => {},
};

export const AdvancedExperimentSettingsContext =
	createContext< AdvancedExperimentSettingsContextValue >(
		defaultContextValue
	);

/**
 * Reads the current advanced experiment settings state from context.
 *
 * Use this hook inside any component that needs to check whether advanced
 * experiment configuration options should be visible.
 *
 * @return {AdvancedExperimentSettingsContextValue} The context value.
 */
export function useAdvancedExperimentSettingsContext(): AdvancedExperimentSettingsContextValue {
	return useContext( AdvancedExperimentSettingsContext );
}

/**
 * Manages the advanced experiment settings preference.
 *
 * Persists the user's choice to localStorage so it survives page reloads.
 * Intended to be called once at the root of the AI Settings page to provide
 * the context value.
 *
 * @return {AdvancedExperimentSettingsContextValue} The context value with state and setter.
 */
export function useAdvancedExperimentSettings(): AdvancedExperimentSettingsContextValue {
	const [ isAdvancedExperimentSettingsEnabled, setEnabled ] =
		useState< boolean >( () => {
			try {
				return localStorage.getItem( STORAGE_KEY ) === 'true';
			} catch {
				return false;
			}
		} );

	useEffect( () => {
		try {
			if ( isAdvancedExperimentSettingsEnabled ) {
				localStorage.setItem( STORAGE_KEY, 'true' );
			} else {
				localStorage.removeItem( STORAGE_KEY );
			}
		} catch {}
	}, [ isAdvancedExperimentSettingsEnabled ] );

	const setAdvancedExperimentSettingsEnabled = useCallback(
		( enabled: boolean ) => {
			setEnabled( enabled );
		},
		[]
	);

	return {
		isAdvancedExperimentSettingsEnabled,
		setAdvancedExperimentSettingsEnabled,
	};
}
