/**
 * Hook that loads and mutates the connector approval state.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { deletePending, fetchApprovalState, postApproval } from './api';
import type { ApprovalState } from '../types';

/**
 * Returns the message from an error.
 *
 * @param {unknown} err      Error.
 * @param {string}  fallback Fallback message.
 * @return {string} Message from the error.
 */
function messageFromError( err: unknown, fallback: string ): string {
	return err instanceof Error ? err.message : fallback;
}

export interface UseApprovalState {
	state: ApprovalState | null;
	error: string | null;
	isSaving: boolean;
	clearError: () => void;
	setApproval: (
		pluginBasename: string,
		connectorId: string,
		approved: boolean
	) => Promise< void >;
	dismissPending: ( key: string ) => Promise< void >;
}

/**
 * Returns the approval state.
 *
 * @return {UseApprovalState} Approval state.
 */
export function useApprovalState(): UseApprovalState {
	const [ state, setState ] = useState< ApprovalState | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ isSaving, setIsSaving ] = useState( false );

	const load = useCallback( async () => {
		setError( null );
		try {
			setState( await fetchApprovalState() );
		} catch ( err ) {
			setError(
				messageFromError(
					err,
					__( 'Failed to load approval data.', 'ai' )
				)
			);
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const setApproval = useCallback(
		async (
			pluginBasename: string,
			connectorId: string,
			approved: boolean
		) => {
			setIsSaving( true );
			setError( null );
			try {
				setState(
					await postApproval( pluginBasename, connectorId, approved )
				);
			} catch ( err ) {
				setError(
					messageFromError(
						err,
						__( 'Failed to update approval.', 'ai' )
					)
				);
			} finally {
				setIsSaving( false );
			}
		},
		[]
	);

	const dismissPending = useCallback( async ( key: string ) => {
		setIsSaving( true );
		setError( null );
		try {
			setState( await deletePending( key ) );
		} catch ( err ) {
			setError(
				messageFromError(
					err,
					__( 'Failed to dismiss request.', 'ai' )
				)
			);
		} finally {
			setIsSaving( false );
		}
	}, [] );

	const clearError = useCallback( () => setError( null ), [] );

	return {
		state,
		error,
		isSaving,
		clearError,
		setApproval,
		dismissPending,
	};
}
