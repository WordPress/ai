/**
 * The row actions that change the AI Workspace assistant's tool surface:
 * "Remove from assistant" and "Return to assistant".
 *
 * Changes are pessimistic: the row's control is disabled until the
 * server answers, a second click while it is pending sends nothing, and the
 * row only changes when the surface route's response is applied.
 */

/**
 * WordPress dependencies
 */
import {
	createContext,
	useCallback,
	useContext,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { changeSurface } from '../api';
import { useExplorer } from '../context';
import type { AbilityListItem, SurfaceChange, SurfaceResponse } from '../types';

/**
 * The confirmation for each surface change.
 *
 * @param change The change that was applied.
 * @return The message.
 */
export function getSurfaceChangeMessage( change: SurfaceChange ): string {
	switch ( change ) {
		case 'remove':
			return __( 'Ability removed from the assistant.', 'ai' );
		case 'restore':
			return __( 'Ability returned to the assistant.', 'ai' );
		case 'disable_policy':
			return __(
				'Assistant admission policy switched off. Only the built-in abilities are offered.',
				'ai'
			);
		case 'enable_policy':
			return __( 'Assistant admission policy switched on.', 'ai' );
	}
}

/**
 * The row change an ability offers, if any: return an owner-excluded ability,
 * remove one the assistant holds, and nothing otherwise. An owner exclusion
 * wins.
 *
 * @param item The ability.
 * @return The change, or null when the row offers none.
 */
export function getRowSurfaceChange(
	item: AbilityListItem
): Extract< SurfaceChange, 'remove' | 'restore' > | null {
	if ( item.owner_excluded ) {
		return 'restore';
	}

	if ( item.conversational_surface ) {
		return 'remove';
	}

	return null;
}

/**
 * What one row's surface control shows and does.
 */
export interface RowSurfaceAction {
	/** "Remove from assistant" or "Return to assistant". */
	label: string;
	/** True while this row's change is pending; a click then sends nothing. */
	disabled: boolean;
	/** Starts the change. Does nothing while one is pending. */
	onActivate: () => void;
}

/**
 * Returns a row's surface control, or null when the row offers none.
 */
export type GetRowSurfaceAction = (
	item: AbilityListItem
) => RowSurfaceAction | null;

/**
 * Carries the list's `GetRowSurfaceAction` to the Name cells, which DataViews
 * renders without a way to pass props through. Null outside the list.
 */
export const RowSurfaceActionContext =
	createContext< GetRowSurfaceAction | null >( null );

/**
 * Returns a row's surface control from the list, or null when the row offers
 * none or there is no list around it.
 *
 * @param item The ability.
 * @return The control, or null.
 */
export function useRowSurfaceAction(
	item: AbilityListItem
): RowSurfaceAction | null {
	const getRowAction = useContext( RowSurfaceActionContext );

	return getRowAction ? getRowAction( item ) : null;
}

interface UseSurfaceActionsOptions {
	/** Applies a surface response; false when it was stale. */
	onSurfaceResponse: ( response: SurfaceResponse ) => boolean;
	/** Called once a change has settled, whether it succeeded or failed. */
	onSettled?: ( slug: string ) => void;
}

/**
 * Returns a function that describes each row's control for removing an
 * ability from the assistant or returning it, with the per-row pending state
 * it needs.
 *
 * @param options                   Hook options.
 * @param options.onSurfaceResponse Applies a surface response to the list.
 * @param options.onSettled         Called once a change has settled.
 * @return The per-row descriptor.
 */
export function useSurfaceActions( {
	onSurfaceResponse,
	onSettled,
}: UseSurfaceActionsOptions ): GetRowSurfaceAction {
	const { notify, reportError } = useExplorer();

	/*
	 * The ref guards against a second click in the same tick, before the
	 * disabled state has rendered; the state drives the rendering.
	 */
	const pendingRef = useRef< Set< string > >( new Set() );
	const [ pending, setPending ] = useState< ReadonlySet< string > >(
		() => new Set()
	);

	const setRowPending = useCallback( ( slug: string, isPending: boolean ) => {
		if ( isPending ) {
			pendingRef.current.add( slug );
		} else {
			pendingRef.current.delete( slug );
		}

		setPending( new Set( pendingRef.current ) );
	}, [] );

	const run = useCallback(
		async ( item: AbilityListItem ) => {
			const change = getRowSurfaceChange( item );

			if ( null === change || pendingRef.current.has( item.slug ) ) {
				return;
			}

			setRowPending( item.slug, true );

			try {
				const response = await changeSurface( change, item.slug );

				/*
				 * A stale response is dropped by `onSurfaceResponse`. The change
				 * itself still happened, and "no change" (already applied, for
				 * example from another tab) is a success too.
				 */
				onSurfaceResponse( response );
				notify( 'success', getSurfaceChangeMessage( change ) );
			} catch ( error ) {
				reportError( error );
			} finally {
				setRowPending( item.slug, false );
				onSettled?.( item.slug );
			}
		},
		[ notify, reportError, onSurfaceResponse, onSettled, setRowPending ]
	);

	return useCallback< GetRowSurfaceAction >(
		( item ) => {
			const change = getRowSurfaceChange( item );

			if ( null === change ) {
				return null;
			}

			return {
				label:
					'restore' === change
						? __( 'Return to assistant', 'ai' )
						: __( 'Remove from assistant', 'ai' ),
				disabled: pending.has( item.slug ),
				onActivate: () => {
					run( item );
				},
			};
		},
		[ pending, run ]
	);
}
