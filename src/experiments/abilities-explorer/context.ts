/**
 * WordPress dependencies
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { ExplorerError } from './api';
import type { Route } from './router';

export type NoticeStatus = 'success' | 'error' | 'info' | 'warning';

export interface ReportErrorOptions {
	/** False when the caller shows the failure inline. Default true. */
	notice?: boolean;
	/** Replaces the classified message in the snackbar. */
	message?: string;
}

/**
 * Shared navigation and error reporting for every view.
 */
export interface ExplorerContextValue {
	/** Moves to a view, pushing a history entry and focusing the heading. */
	navigate: ( route: Route ) => void;
	/** The real URL for a view. */
	getHref: ( route: Route ) => string;
	/**
	 * Reports a failed request: a lost route or permission makes the screen
	 * fatal, an abort shows nothing, anything else shows a snackbar.
	 *
	 * @return The classification.
	 */
	reportError: (
		error: unknown,
		options?: ReportErrorOptions
	) => ExplorerError;
	/** Shows a snackbar. */
	notify: ( status: NoticeStatus, message: string ) => void;
	/** True once the screen is in its fatal state. */
	isFatal: boolean;
}

export const ExplorerContext = createContext< ExplorerContextValue | null >(
	null
);

/**
 * Returns the Explorer context.
 *
 * @return The context value.
 */
export function useExplorer(): ExplorerContextValue {
	const value = useContext( ExplorerContext );

	if ( ! value ) {
		throw new Error(
			'useExplorer() must be used inside the Abilities Explorer app.'
		);
	}

	return value;
}
