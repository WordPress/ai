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
import type { Action } from '@wordpress/dataviews/wp';
import { useCallback, useMemo, useRef, useState } from '@wordpress/element';
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
 * The action ID both variants of the row action share, so DataViews keeps the
 * same button when its label flips or it turns busy, and focus stays on it.
 */
export const SURFACE_ACTION_ID = 'assistant-surface';

interface UseSurfaceActionsOptions {
	/** Applies a surface response; false when it was stale. */
	onSurfaceResponse: ( response: SurfaceResponse ) => boolean;
	/** Called once a change has settled, whether it succeeded or failed. */
	onSettled?: ( slug: string ) => void;
}

/**
 * Returns the row actions that remove an ability from the assistant or return
 * it, with the per-row pending state they need.
 *
 * DataViews has no per-row disabled state, so the action comes in two
 * variants with the same ID: one for rows with a change pending (disabled)
 * and one for the rest. Exactly one is eligible for any row.
 *
 * @param options                   Hook options.
 * @param options.onSurfaceResponse Applies a surface response to the list.
 * @param options.onSettled         Called once a change has settled.
 * @return The actions.
 */
export function useSurfaceActions( {
	onSurfaceResponse,
	onSettled,
}: UseSurfaceActionsOptions ): Action< AbilityListItem >[] {
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

	return useMemo< Action< AbilityListItem >[] >( () => {
		const label = ( items: AbilityListItem[] ) => {
			const item = items[ 0 ];

			return item && 'restore' === getRowSurfaceChange( item )
				? __( 'Return to assistant', 'ai' )
				: __( 'Remove from assistant', 'ai' );
		};

		const isApplicable = ( item: AbilityListItem ) =>
			null !== getRowSurfaceChange( item );

		return [
			{
				id: SURFACE_ACTION_ID,
				label,
				isPrimary: true,
				isEligible: ( item ) =>
					isApplicable( item ) && ! pending.has( item.slug ),
				callback: ( selected ) => {
					const item = selected[ 0 ];

					if ( item ) {
						run( item );
					}
				},
			},
			{
				id: SURFACE_ACTION_ID,
				label,
				isPrimary: true,
				disabled: true,
				isEligible: ( item ) =>
					isApplicable( item ) && pending.has( item.slug ),
				// Disabled while pending: a click sends nothing.
				callback: () => {},
			},
		];
	}, [ pending, run ] );
}
