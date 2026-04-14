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
};

interface WindowWithAbilities extends Window {
	wp?: Window[ 'wp' ] & {
		abilities?: {
			executeAbility?: (
				ability: string,
				input?: AbilityInput
			) => Promise< unknown >;
		};
	};
}

let hasShownFallbackNotice = false;

const getAbilityClient = () =>
	( window as WindowWithAbilities )?.wp?.abilities ?? null;

const logFallbackWarning = () => {
	if ( hasShownFallbackNotice ) {
		return;
	}

	// eslint-disable-next-line no-console
	console.warn(
		'[AI] wp.abilities.executeAbility is unavailable. Falling back to REST.'
	);
	hasShownFallbackNotice = true;
};

const isAbilityNotFoundError = ( error: unknown ): boolean => {
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
		code === 'ability_not_found' || message.includes( 'Ability not found' )
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
	const client = getAbilityClient();

	if ( typeof client?.executeAbility === 'function' ) {
		try {
			return ( await client.executeAbility(
				ability,
				input ?? null
			) ) as T;
		} catch ( error ) {
			if ( ! isAbilityNotFoundError( error ) ) {
				throw error;
			}
			logFallbackWarning();
		}
	} else {
		logFallbackWarning();
	}

	const method: Method = options?.method ?? 'POST';

	const response = await apiFetch(
		buildFetchOptions( ability, input, method )
	);

	return response as T;
}
