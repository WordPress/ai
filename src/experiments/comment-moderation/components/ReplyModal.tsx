/**
 * Reply Modal component.
 *
 * Displays AI-generated reply suggestions in a modal dialog.
 */

/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import {
	Button,
	Flex,
	FlexItem,
	Modal,
	SelectControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';

type Tone = 'professional' | 'friendly' | 'casual';

type ReplySuggestionResult = {
	comment_id: number;
	replies: string[];
};

type CachedReplies = {
	replies: string[];
	tone: Tone;
};

type ReplyModalProps = {
	commentId: number;
	onClose: () => void;
	onSelectReply: ( reply: string, commentId: number ) => void;
	initialReplies?: CachedReplies;
	onRepliesChange?: ( data: CachedReplies ) => void;
};

/**
 * Single reply option component.
 */
function ReplyOption( {
	reply,
	index,
	onChange,
	onSelect,
}: {
	reply: string;
	index: number;
	onChange: ( value: string ) => void;
	onSelect: ( reply: string, index: number ) => void;
} ): React.ReactElement {
	return (
		<FlexItem className="ai-reply-option">
			<TextareaControl
				rows={ 4 }
				label={ __( 'Generated reply', 'ai' ) }
				hideLabelFromVision
				value={ reply }
				onChange={ onChange }
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				style={ { marginTop: '10px' } }
				onClick={ () => onSelect( reply, index ) }
			>
				{ __( 'Use this reply', 'ai' ) }
			</Button>
		</FlexItem>
	);
}

/**
 * ReplyModal component.
 *
 * Shows a modal with AI-generated reply suggestions.
 */
export function ReplyModal( {
	commentId,
	onClose,
	onSelectReply,
	initialReplies,
	onRepliesChange,
}: ReplyModalProps ): React.ReactElement {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ replies, setReplies ] = useState< string[] >( initialReplies?.replies ?? [] );
	const [ tone, setTone ] = useState< Tone >( initialReplies?.tone ?? 'friendly' );
	const [ error, setError ] = useState< string | null >( null );
	const [ hasGenerated, setHasGenerated ] = useState( !! initialReplies?.replies?.length );

	/**
	 * Generates reply suggestions.
	 */
	const generateReplies = useCallback( async () => {
		setIsLoading( true );
		setError( null );

		try {
			const result = await runAbility< ReplySuggestionResult >(
				'ai/reply-suggestion',
				{
					comment_id: commentId,
					tone,
					candidates: 3,
				}
			);

			setReplies( result.replies );
			setHasGenerated( true );

			// Notify parent of new replies for caching.
			onRepliesChange?.( { replies: result.replies, tone } );
		} catch ( err ) {
			const message =
				err instanceof Error ? err.message : __( 'Failed to generate replies.', 'ai' );
			setError( message );
		} finally {
			setIsLoading( false );
		}
	}, [ commentId, tone, onRepliesChange ] );

	/**
	 * Handles selecting a reply.
	 */
	const handleSelect = useCallback(
		( reply: string ) => {
			onSelectReply( reply, commentId );
		},
		[ commentId, onSelectReply ]
	);

	/**
	 * Updates a reply in the list.
	 */
	const handleReplyChange = useCallback( ( index: number, value: string ) => {
		setReplies( ( prev ) => {
			const updated = prev.map( ( r, i ) => ( i === index ? value : r ) );
			// Update cache with edited reply.
			onRepliesChange?.( { replies: updated, tone } );
			return updated;
		} );
	}, [ tone, onRepliesChange ] );

	/**
	 * Generate replies on mount only if no cached replies.
	 */
	useEffect( () => {
		if ( ! initialReplies?.replies?.length ) {
			generateReplies();
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<Modal
			title={ __( 'AI Reply Suggestions', 'ai' ) }
			onRequestClose={ onClose }
			isFullScreen={ false }
			size="large"
			className="ai-reply-modal"
		>
			<div className="ai-reply-modal__controls" style={ { marginBottom: '20px' } }>
				<Flex gap={ 4 } align="flex-end">
					<FlexItem>
						<SelectControl
							label={ __( 'Tone', 'ai' ) }
							value={ tone }
							options={ [
								{ label: __( 'Professional', 'ai' ), value: 'professional' },
								{ label: __( 'Friendly', 'ai' ), value: 'friendly' },
								{ label: __( 'Casual', 'ai' ), value: 'casual' },
							] }
							onChange={ ( value ) => setTone( value as Tone ) }
							__nextHasNoMarginBottom
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="secondary"
							onClick={ generateReplies }
							disabled={ isLoading }
							isBusy={ isLoading }
						>
							{ hasGenerated
								? __( 'Regenerate', 'ai' )
								: __( 'Generate', 'ai' ) }
						</Button>
					</FlexItem>
				</Flex>
			</div>

			<div className="ai-reply-modal__content">
				{ isLoading && (
					<div className="ai-reply-modal__loading">
						<Spinner />
						<p>{ __( 'Generating reply suggestions...', 'ai' ) }</p>
					</div>
				) }

				{ error && (
					<div className="ai-reply-modal__error">
						<p>{ error }</p>
						<Button variant="secondary" onClick={ generateReplies }>
							{ __( 'Try again', 'ai' ) }
						</Button>
					</div>
				) }

				{ ! isLoading && ! error && replies.length > 0 && (
					<Flex gap={ 4 } wrap direction="column">
						{ replies.map( ( reply, index ) => (
							<ReplyOption
								key={ `reply-${ index }` }
								reply={ reply }
								index={ index }
								onChange={ ( value ) =>
									handleReplyChange( index, value )
								}
								onSelect={ handleSelect }
							/>
						) ) }
					</Flex>
				) }

				{ ! isLoading && ! error && replies.length === 0 && hasGenerated && (
					<p className="ai-reply-modal__empty">
						{ __( 'No reply suggestions were generated. Please try again.', 'ai' ) }
					</p>
				) }
			</div>
		</Modal>
	);
}
