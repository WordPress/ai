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
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { changeSurface } from '../api';
import { useExplorer } from '../context';
import type { AbilityListItem, SurfaceChange, SurfaceResponse } from '../types';
import { getWorkspaceChange } from '../workspace';

/**
 * The confirmation for each change.
 *
 * @param change The change that was applied.
 * @return The message.
 */
export function getSurfaceChangeMessage( change: SurfaceChange ): string {
	return 'remove' === change
		? __( 'Removed from AI Workspace.', 'ai' )
		: __( 'Restored to AI Workspace.', 'ai' );
}

/**
 * The inline notice for a change that failed.
 *
 * @param change  The change that failed.
 * @param message Why it failed.
 * @return The notice text.
 */
function getSurfaceFailureMessage(
	change: SurfaceChange,
	message: string
): string {
	if ( 'remove' === change ) {
		return sprintf(
			/* translators: %s: Why the request failed. */
			__( 'Could not remove this ability from AI Workspace. %s', 'ai' ),
			message
		);
	}

	return sprintf(
		/* translators: %s: Why the request failed. */
		__( 'Could not restore this ability to AI Workspace. %s', 'ai' ),
		message
	);
}

/**
 * What one row's surface control shows and does.
 */
export interface RowSurfaceAction {
	label: string;
	/** True while this row's change is pending. */
	isBusy: boolean;
	onActivate: () => void;
	/** Why the last change failed, or null. */
	error: string | null;
	onDismissError: () => void;
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
 * Returns a function that describes each row's Remove or Restore control.
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
	const [ errors, setErrors ] = useState<
		Readonly< Record< string, string > >
	>( {} );

	const setRowError = useCallback(
		( slug: string, message: string | null ) => {
			setErrors( ( current ) => {
				if ( null === message ) {
					if ( ! ( slug in current ) ) {
						return current;
					}

					const { [ slug ]: _removed, ...rest } = current;

					return rest;
				}

				return { ...current, [ slug ]: message };
			} );
		},
		[]
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
			const change = getWorkspaceChange( item );

			if ( null === change || pendingRef.current.has( item.slug ) ) {
				return;
			}

			setRowPending( item.slug, true );
			setRowError( item.slug, null );

			try {
				const response = await changeSurface( change, item.slug );

				// A stale or "no change" response is still a success.
				onSurfaceResponse( response );
				notify( 'success', getSurfaceChangeMessage( change ) );
			} catch ( error ) {
				// The row keeps its state; the failure shows under it.
				const classified = reportError( error, { notice: false } );

				if (
					'failure' === classified.kind ||
					'not-found' === classified.kind
				) {
					setRowError(
						item.slug,
						getSurfaceFailureMessage( change, classified.message )
					);
				}
			} finally {
				setRowPending( item.slug, false );
				onSettled?.( item.slug );
			}
		},
		[
			notify,
			reportError,
			onSurfaceResponse,
			onSettled,
			setRowPending,
			setRowError,
		]
	);

	return useCallback< GetRowSurfaceAction >(
		( item ) => {
			const change = getWorkspaceChange( item );

			if ( null === change ) {
				return null;
			}

			return {
				label:
					'restore' === change
						? __( 'Restore to AI Workspace', 'ai' )
						: __( 'Remove from AI Workspace', 'ai' ),
				isBusy: pending.has( item.slug ),
				onActivate: () => {
					run( item );
				},
				error: errors[ item.slug ] ?? null,
				onDismissError: () => setRowError( item.slug, null ),
			};
		},
		[ pending, errors, run, setRowError ]
	);
}
