/**
 * Typed requests to the Explorer's `ai/v1/abilities` routes, and the one
 * place their failures are classified.
 *
 * Ability names always travel in the query string or the body, never the
 * path (KTD3): every name contains `/`, and a percent-encoded slash in a
 * path can 404 on Apache before WordPress sees it.
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
	SurfaceChange,
	SurfaceResponse,
} from './types';

interface RequestOptions {
	signal?: AbortSignal;
}

/**
 * Fetches every registered ability with the assistant policy state.
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
 * Fetches one ability with its schemas, raw data and example input.
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
 * Invokes an ability.
 *
 * The input is sent as the raw string the user typed, and decoded on the
 * server, so large integers keep their precision and scalar input works. An
 * empty string means "no input".
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
 * Changes the AI Workspace assistant's tool surface.
 *
 * @param change The change.
 * @param name   The ability name; required for `remove` and `restore`.
 * @return The updated row or list, with the policy state and a sequence.
 */
export function changeSurface(
	change: SurfaceChange,
	name = ''
): Promise< SurfaceResponse > {
	return apiFetch< SurfaceResponse >( {
		path: getSettings().rest.routes.surface,
		method: 'POST',
		data: { change, name },
	} );
}

/*
 * KTD8. The highest response sequence applied so far. A list or surface
 * response carrying a lower number was read before a change the screen has
 * already shown, so applying it would undo that change on screen.
 */
let highestSequence = 0;

/**
 * Claims a response's sequence number before its state is applied.
 *
 * @param sequence The response's `sequence`.
 * @return True when the response is current and may be applied; false when a
 *         newer response has already been applied.
 */
export function claimSequence( sequence: number ): boolean {
	if ( sequence < highestSequence ) {
		return false;
	}

	highestSequence = sequence;

	return true;
}

/**
 * Returns the highest response sequence applied so far.
 *
 * @return The sequence, or 0 before any response has been applied.
 */
export function getHighestSequence(): number {
	return highestSequence;
}

/**
 * How a failed request should be presented.
 *
 * - `turned-off`: the experiment was switched off mid-session (the routes are gone).
 * - `lost-permission`: the user was logged out or lost `manage_options`.
 * - `not-found`: the named ability is not registered.
 * - `aborted`: the request was cancelled by the screen itself; show nothing.
 * - `failure`: anything else, including network errors and 5xx responses.
 *
 * `turned-off` and `lost-permission` are fatal (R17): the screen shows one
 * explanation and disables its controls.
 */
export type ExplorerErrorKind =
	| 'turned-off'
	| 'lost-permission'
	| 'not-found'
	| 'aborted'
	| 'failure';

export interface ExplorerError {
	kind: ExplorerErrorKind;
	/** The REST error code, or '' when there was none. */
	code: string;
	/** A message ready to show. */
	message: string;
	/** The HTTP status, when the error carried one. */
	status: number | null;
	/** The error's `data`, for example the invoke route's `errors` list. */
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
 * Reports whether a classified error should put the screen in its fatal state.
 *
 * @param error The classified error.
 * @return True for `turned-off` and `lost-permission`.
 */
export function isFatalError( error: ExplorerError ): boolean {
	return 'turned-off' === error.kind || 'lost-permission' === error.kind;
}
