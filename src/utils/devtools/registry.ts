/**
 * Feature capability registry for Chrome DevTools 3P Tools.
 *
 * Uses window.__aiFeatures as the backing store so registrations from
 * multiple separate webpack bundles are all visible to the single DevTools
 * listener that is registered by whichever bundle loads first.
 */

export interface DevToolsRegistration {
	name: string;
	description: string;
	abilitySlug: string;
}

declare global {
	interface Window {
		__aiFeatures?: DevToolsRegistration[];
	}
}

/**
 * Returns the store of registered features.
 *
 * @since x.x.x
 * @return {DevToolsRegistration[]} List of registered feature registrations.
 */
function getStore(): DevToolsRegistration[] {
	if ( ! window.__aiFeatures ) {
		window.__aiFeatures = [];
	}
	return window.__aiFeatures;
}

/**
 * Registers an AI feature so it appears in the get_ai_capabilities DevTools tool.
 *
 * Call this once from the feature's entry point at module level.
 *
 * @since x.x.x
 * @param {DevToolsRegistration} registration The feature metadata.
 */
export function exposeToDevTools( registration: DevToolsRegistration ): void {
	const store = getStore();
	if ( ! store.find( ( e ) => e.abilitySlug === registration.abilitySlug ) ) {
		store.push( registration );
	}
}

/**
 * Returns all registered features.
 *
 * @since x.x.x
 * @return {DevToolsRegistration[]} List of registered AI feature registrations.
 */
export function getDevToolsRegistrations(): DevToolsRegistration[] {
	return getStore();
}
