/**
 * Requests to the Explorer's REST routes, and their error classification.
 *
 * Ability names go in the query string or body, never the path: an encoded
 * `/` in a path can 404 on Apache.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { getErrorMessage } from '../../utils/errors';
import { getSettings } from './settings';
import type {
	AbilitiesListResponse,
	AbilityDetailItem,
	InvokeResponse,
	RestErrorPayload,
} from './types';

interface RequestOptions {
	signal?: AbortSignal;
}

/**
 * Fetches every registered ability.
 *
 * @param options Optional request options.
 * @return The list payload.
 */
export function fetchAbilities(
	options: RequestOptions = {}
): Promise< AbilitiesListResponse > {
	return apiFetch< AbilitiesListResponse >( {
		path: getSettings().rest.routes.abilities,
		...options,
	} );
}

/**
 * Fetches one ability.
 *
 * @param name    The ability name.
 * @param options Optional request options.
 * @return The item payload.
 */
export function fetchAbility(
	name: string,
	options: RequestOptions = {}
): Promise< AbilityDetailItem > {
	return apiFetch< AbilityDetailItem >( {
		path: addQueryArgs( getSettings().rest.routes.item, { name } ),
		...options,
	} );
}

/**
 * Invokes an ability. The input is sent as typed and decoded on the server,
 * so large integers keep their precision.
 *
 * @param name     The ability name.
 * @param rawInput The raw JSON input string.
 * @param options  Optional request options.
 * @return The ability's outcome.
 */
export function invokeAbility(
	name: string,
	rawInput: string,
	options: RequestOptions = {}
): Promise< InvokeResponse > {
	return apiFetch< InvokeResponse >( {
		path: getSettings().rest.routes.invoke,
		method: 'POST',
		data: { name, input: rawInput },
		...options,
	} );
}

/**
 * How a failed request is presented. `turned-off` and `lost-permission` are fatal.
 */
export type ExplorerErrorKind =
	| 'turned-off'
	| 'lost-permission'
	| 'not-found'
	| 'aborted'
	| 'failure';

export interface ExplorerError {
	kind: ExplorerErrorKind;
	code: string;
	message: string;
	status: number | null;
	data: RestErrorPayload[ 'data' ];
}

const isRestErrorPayload = ( error: unknown ): error is RestErrorPayload =>
	!! error &&
	typeof error === 'object' &&
	typeof ( error as { code?: unknown } ).code === 'string';

/**
 * Classifies a rejected request.
 *
 * @param error The rejection value from `apiFetch`.
 * @return The classified error.
 */
export function classifyError( error: unknown ): ExplorerError {
	if (
		error &&
		typeof error === 'object' &&
		( error as { name?: unknown } ).name === 'AbortError'
	) {
		return {
			kind: 'aborted',
			code: '',
			message: '',
			status: null,
			data: null,
		};
	}

	const payload = isRestErrorPayload( error ) ? error : null;
	const code = payload?.code ?? '';
	const data = payload?.data ?? null;
	const status = typeof data?.status === 'number' ? data.status : null;

	if ( 'rest_no_route' === code && ( null === status || 404 === status ) ) {
		return {
			kind: 'turned-off',
			code,
			message: __(
				'The Abilities Explorer is turned off. Reload the page.',
				'ai'
			),
			status,
			data,
		};
	}

	if ( 401 === status || 403 === status ) {
		return {
			kind: 'lost-permission',
			code,
			message: __(
				'You no longer have permission to use the Abilities Explorer. Reload the page to sign in again.',
				'ai'
			),
			status,
			data,
		};
	}

	if ( 'ai_abilities_explorer_not_found' === code ) {
		return {
			kind: 'not-found',
			code,
			message: __( 'Ability not found.', 'ai' ),
			status,
			data,
		};
	}

	return {
		kind: 'failure',
		code,
		message: getErrorMessage( error ),
		status,
		data,
	};
}

/**
 * Reports whether an error puts the screen in its fatal state.
 *
 * @param error The classified error.
 * @return True for `turned-off` and `lost-permission`.
 */
export function isFatalError( error: ExplorerError ): boolean {
	return 'turned-off' === error.kind || 'lost-permission' === error.kind;
}
