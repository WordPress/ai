/**
 * Hook that generates a summary of the changes between two revisions.
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getErrorMessage } from '../../../utils/errors';
import { isProviderAvailable } from '../../../utils/provider-status';
import { runAbility } from '../../../utils/run-ability';
import type { RevisionChangeSummaryResult } from '../types';

const ABILITY_NAME = 'ai/revision-change-summary';

interface SummaryState {
	key: string | null;
	isLoading: boolean;
	error: string | null;
	result: RevisionChangeSummaryResult | null;
}

export interface RevisionSummary {
	isLoading: boolean;
	isBusy: boolean;
	error: string | null;
	result: RevisionChangeSummaryResult | null;
	generate: () => Promise< void >;
}

/**
 * Returns the key that identifies a pair of revisions.
 *
 * @param postId         The post ID.
 * @param revisionId     The newer revision ID.
 * @param fromRevisionId The older revision ID, if one is selected.
 * @return The key.
 */
function getKey(
	postId: number,
	revisionId: number,
	fromRevisionId?: number | null
): string {
	return `${ postId }:${ fromRevisionId || 'previous' }:${ revisionId }`;
}

/**
 * Generates and tracks the summary of changes for the selected revisions.
 *
 * Generation only starts when `generate` is called, and only one request runs
 * at a time, so moving to other revisions mid-request can't start another.
 * The latest result is shown again if the user moves back to the same
 * revisions, and is never shown for other revisions.
 *
 * @param postId         The post ID.
 * @param revisionId     The newer revision ID.
 * @param fromRevisionId The older revision ID. Defaults to the previous revision.
 * @return The summary state and a function that starts generation.
 */
export function useRevisionSummary(
	postId: number,
	revisionId: number | null,
	fromRevisionId?: number | null | undefined
): RevisionSummary {
	const key = revisionId
		? getKey( postId, revisionId, fromRevisionId )
		: null;
	const latestKey = useRef( key );
	const isRunning = useRef( false );
	const [ state, setState ] = useState< SummaryState >( {
		key: null,
		isLoading: false,
		error: null,
		result: null,
	} );

	useEffect( () => {
		latestKey.current = key;
	}, [ key ] );

	const current: SummaryState =
		state.key === key
			? state
			: {
					key,
					isLoading: false,
					error: null,
					result: null,
			  };

	const generate = useCallback( async () => {
		if ( ! key || ! revisionId || isRunning.current ) {
			return;
		}

		if ( ! isProviderAvailable() ) {
			const message = __(
				'This feature requires an AI Connector to function properly.',
				'ai'
			);
			setState( { key, isLoading: false, error: message, result: null } );
			speak( message, 'assertive' );
			return;
		}

		isRunning.current = true;
		setState( { key, isLoading: true, error: null, result: null } );
		speak( __( 'Summarizing changes…', 'ai' ) );

		const input: {
			post_id: number;
			revision_id: number;
			from_revision_id?: number;
		} = {
			post_id: postId,
			revision_id: revisionId,
		};

		if ( fromRevisionId ) {
			input.from_revision_id = fromRevisionId;
		}

		try {
			const response = await runAbility< RevisionChangeSummaryResult >(
				ABILITY_NAME,
				input
			);

			if (
				! response ||
				typeof response.summary !== 'string' ||
				! Array.isArray( response.changes )
			) {
				throw new Error( __( 'Invalid response from API.', 'ai' ) );
			}

			setState( {
				key,
				isLoading: false,
				error: null,
				result: response,
			} );

			if ( latestKey.current === key ) {
				speak( __( 'Summary of changes is ready.', 'ai' ) );
			}
		} catch ( error ) {
			const message = getErrorMessage(
				error,
				__( 'Could not summarize the changes. Please try again.', 'ai' )
			);

			setState( { key, isLoading: false, error: message, result: null } );

			if ( latestKey.current === key ) {
				speak( message, 'assertive' );
			}
		} finally {
			isRunning.current = false;
		}
	}, [ key, postId, revisionId, fromRevisionId ] );

	return {
		isLoading: current.isLoading,
		isBusy: state.isLoading,
		error: current.error,
		result: current.result,
		generate,
	};
}
