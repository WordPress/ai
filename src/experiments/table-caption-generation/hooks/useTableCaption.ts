/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { getErrorMessage } from '../../../utils/errors';
import { isProviderAvailable } from '../../../utils/provider-status';
import { runAbility } from '../../../utils/run-ability';
import { escapeCaption, getSnapshot, isSameSource } from '../utils';
import type { CaptionProposal, TableData } from '../types';

/**
 * Generates and applies one table's proposal, guarding request and editor state.
 *
 * A failed regeneration keeps the previous proposal. Unmounted controls ignore
 * late responses, and applying always rechecks the latest source and editability.
 *
 * @param clientId Table client ID.
 * @return Current source, proposal, request state, and review actions.
 */
export function useTableCaption( clientId: string ) {
	const source = useSelect(
		( select ) => getSnapshot( clientId, select ),
		[ clientId ]
	);
	const [ proposal, setProposal ] = useState< CaptionProposal | null >(
		null
	);
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const running = useRef( false );
	const mounted = useRef( true );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const isStale =
		proposal !== null && ! isSameSource( source, proposal.source );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const generate = async () => {
		if ( running.current ) {
			return;
		}
		const current = getSnapshot( clientId );
		if ( ! current ) {
			return;
		}
		if ( ! current.postId ) {
			setError(
				__(
					'Save the post as a draft before generating a caption.',
					'ai'
				)
			);
			return;
		}
		if ( ! isProviderAvailable() ) {
			setError(
				__(
					'This feature requires an AI Connector to function properly.',
					'ai'
				)
			);
			return;
		}
		running.current = true;
		setIsLoading( true );
		setError( '' );
		try {
			const result = await runAbility< string >(
				'ai/table-caption-generation',
				{
					post_id: current.postId,
					table: JSON.parse( current.table ) as TableData,
					title: current.title,
					context: current.context,
				}
			);
			if ( ! mounted.current ) {
				return;
			}
			if ( typeof result !== 'string' || ! result.trim() ) {
				throw new Error(
					__(
						'The provider returned an invalid caption. Please try again.',
						'ai'
					)
				);
			}
			setProposal( { text: result.trim(), source: current } );
		} catch ( caught ) {
			if ( mounted.current ) {
				const message = getErrorMessage(
					caught,
					__(
						'Could not generate a caption. Please try again.',
						'ai'
					)
				);
				setError( message );
			}
		} finally {
			running.current = false;
			if ( mounted.current ) {
				setIsLoading( false );
			}
		}
	};

	const edit = ( text: string ) => {
		setProposal( ( previous ) =>
			previous ? { ...previous, text } : null
		);
	};

	const discard = () => {
		setProposal( null );
		setError( '' );
	};

	const apply = () => {
		if ( running.current || ! proposal?.text.trim() ) {
			return;
		}
		if ( ! isSameSource( getSnapshot( clientId ), proposal.source ) ) {
			const message = __(
				'The table, caption, or surrounding context changed. Generate a new suggestion before applying it.',
				'ai'
			);
			setError( message );
			return;
		}
		updateBlockAttributes( clientId, {
			caption: escapeCaption( proposal.text.trim() ),
		} );
		setProposal( null );
		setError( '' );
		createSuccessNotice(
			__(
				'Table caption applied. Save the post to keep your changes.',
				'ai'
			),
			{ type: 'snackbar' }
		);
	};

	return {
		source,
		proposal,
		isLoading,
		error,
		isStale,
		generate,
		edit,
		discard,
		apply,
	};
}
