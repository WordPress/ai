/**
 * WordPress dependencies
 */
import { createContext, useContext, useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { useLocalStoragePreference } from './use-local-storage-preference';

const STORAGE_KEY = 'ai_advanced_settings';

export interface AdvancedSettingsContextValue {
	isAdvancedSettingsEnabled: boolean;
	toggleAdvancedSettings: () => void;
}

const defaultContextValue: AdvancedSettingsContextValue = {
	isAdvancedSettingsEnabled: false,
	toggleAdvancedSettings: () => {},
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
	const { enabled, toggle } = useLocalStoragePreference( STORAGE_KEY );

	return useMemo(
		() => ( {
			isAdvancedSettingsEnabled: enabled,
			toggleAdvancedSettings: toggle,
		} ),
		[ enabled, toggle ]
	);
}
