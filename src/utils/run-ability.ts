/**
 * Safe ability execution helper.
 *
 * Uses the Abilities API client when it's available and falls back to REST calls
 * when the client script hasn't been enqueued yet.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

type AbilityInput =
	| Record< string, unknown >
	| Array< unknown >
	| string
	| number
	| boolean
	| null
	| undefined;

type Method = 'GET' | 'POST' | 'DELETE';

type RunAbilityOptions = {
	method?: Method;
	signal?: AbortSignal;
};

type AbilitiesModule = {
	executeAbility?: (
		ability: string,
		input?: AbilityInput
	) => Promise< unknown >;
};

let hasShownFallbackNotice = false;

const importAbilitiesModule = async (): Promise< AbilitiesModule | null > => {
	try {
		// Keep these as runtime imports so webpack does not emit a hard
		// "wp-abilities" script dependency for classic enqueued bundles.
		const { ready } = await import(
			/* webpackIgnore: true */ '@wordpress/core-abilities'
		);
		await ready;
		return ( await import(
			/* webpackIgnore: true */ '@wordpress/abilities'
		) ) as AbilitiesModule;
	} catch {
		return null;
	}
};

const logFallbackWarning = () => {
	if ( hasShownFallbackNotice ) {
		return;
	}

	// eslint-disable-next-line no-console
	console.warn(
		'[AI] Client ability execution is unavailable. Falling back to REST.'
	);
	hasShownFallbackNotice = true;
};

const shouldFallbackToRest = ( error: unknown ): boolean => {
	if ( ! error || typeof error !== 'object' ) {
		return false;
	}

	const message =
		'message' in error && typeof ( error as any ).message === 'string'
			? ( error as any ).message
			: '';
	const code =
		'code' in error && typeof ( error as any ).code === 'string'
			? ( error as any ).code
			: '';

	return (
		// Ability is not registered in the client store yet.
		code === 'ability_not_found' ||
		message.includes( 'Ability not found' ) ||
		// Ability exists but has no callback in client store (server-only ability
		// not yet bridged to a callback), so use REST instead.
		message.includes( 'missing callback' ) ||
		// Temporary compatibility fallback: some server schemas include fields not
		// currently accepted by client-side schema validation.
		code === 'ability_invalid_input'
	);
};

const buildFetchOptions = (
	ability: string,
	input: AbilityInput,
	method: Method
) => {
	const normalizedInput = input ?? null;

	if ( method === 'GET' || method === 'DELETE' ) {
		return {
			path:
				normalizedInput === null
					? `/wp-abilities/v1/abilities/${ ability }/run`
					: addQueryArgs(
							`/wp-abilities/v1/abilities/${ ability }/run`,
							{
								input: normalizedInput,
							}
					  ),
			method,
		};
	}

	return {
		path: `/wp-abilities/v1/abilities/${ ability }/run`,
		method: 'POST' as const,
		data: {
			input: normalizedInput,
		},
	};
};

export async function runAbility< T = unknown >(
	ability: string,
	input?: AbilityInput,
	options?: RunAbilityOptions
): Promise< T > {
	try {
		const abilitiesModule = await importAbilitiesModule();
		if ( typeof abilitiesModule?.executeAbility === 'function' ) {
			return ( await abilitiesModule.executeAbility(
				ability,
				input ?? null
			) ) as T;
		}

		logFallbackWarning();
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error( error );

		if ( ! shouldFallbackToRest( error ) ) {
			throw error;
		}

		logFallbackWarning();
	}

	const method: Method = options?.method ?? 'POST';

	const response = await apiFetch( {
		...buildFetchOptions( ability, input, method ),
		...( options?.signal ? { signal: options.signal } : {} ),
	} );

	return response as T;
}
