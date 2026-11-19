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
 * Returns the row change an ability offers, if any.
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
	label: string;
	/** True while this row's change is pending. */
	disabled: boolean;
	onActivate: () => void;
}

/**
 * Returns a row's surface control, or null when the row offers none.
 */
export type GetRowSurfaceAction = (
	item: AbilityListItem
) => RowSurfaceAction | null;

/**
 * Passes `GetRowSurfaceAction` to Name cells, since DataViews can't pass props through.
 */
export const RowSurfaceActionContext =
	createContext< GetRowSurfaceAction | null >( null );

/**
 * Returns a row's surface control from the list, or null when it has none.
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
	/** Called once a change has succeeded or failed. */
	onSettled?: ( slug: string ) => void;
}

/**
 * Returns a function that describes each row's remove/return control.
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

	// The ref blocks a double click before the disabled state renders.
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

				// A stale or "no change" response is still a success.
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
