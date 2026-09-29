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
	/**
	 * Whether a non-fatal failure shows an error snackbar. Pass false when the
	 * caller shows the failure inline itself. Default true.
	 */
	notice?: boolean;
	/** Replaces the classified message in the snackbar. */
	message?: string;
}

/**
 * What every view can reach without prop drilling.
 */
export interface ExplorerContextValue {
	/** Moves to a view, pushing a history entry and focusing the heading. */
	navigate: ( route: Route ) => void;
	/** The real URL for a view, for links that work without JavaScript routing. */
	getHref: ( route: Route ) => string;
	/**
	 * Reports a failed request so it follows the screen's error states.
	 *
	 * A lost route or lost permission puts the whole screen in its fatal state
	 * (one explanation, controls disabled) and shows nothing else. An aborted
	 * request shows nothing. Any other failure shows an error snackbar unless
	 * `options.notice` is false. Once the screen is fatal, nothing more is shown.
	 *
	 * @return The classification, so the caller can also react inline.
	 */
	reportError: (
		error: unknown,
		options?: ReportErrorOptions
	) => ExplorerError;
	/** Shows a snackbar that is both visible and announced. */
	notify: ( status: NoticeStatus, message: string ) => void;
	/** True once the screen is in its fatal state; controls must stay disabled. */
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
