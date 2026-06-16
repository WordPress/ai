/**
 * Modal component for the Suggest Reply experiment.
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
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { useCopyToClipboardFeedback } from '../../../hooks/use-copy-to-clipboard-feedback';

type Tone = 'professional' | 'friendly' | 'casual';

type ReplySuggestionResult = {
	comment_id: number;
	reply: string;
};

export type ReplyModalProps = {
	commentId: number;
	onClose: () => void;
	onSelectReply: ( reply: string, commentId: number ) => void;
};

/**
 * Renders the AI reply suggestion modal, allowing the moderator to choose
 * a tone, provide optional guidelines, generate a reply, and insert it.
 */
export function ReplyModal( {
	commentId,
	onClose,
	onSelectReply,
}: ReplyModalProps ): React.ReactElement {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ reply, setReply ] = useState< string >( '' );
	const [ tone, setTone ] = useState< Tone >( 'friendly' );
	const [ guidelines, setGuidelines ] = useState< string >( '' );
	const [ error, setError ] = useState< string | null >( null );

	// Refs for focus management.
	const useThisReplyRef = useRef< HTMLButtonElement >( null );
	const generateRegenerateRef = useRef< HTMLButtonElement >( null );
	const focusTimeoutRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);

	// Cancel any pending focus timeout when the modal unmounts.
	useEffect( () => {
		return () => {
			if ( focusTimeoutRef.current !== null ) {
				clearTimeout( focusTimeoutRef.current );
			}
		};
	}, [] );

	const { ref: copyRef, hasCopied } =
		useCopyToClipboardFeedback< HTMLButtonElement >( {
			text: reply,
			announcement: __( 'Reply copied to clipboard.', 'ai' ),
		} );

	const generateReply = useCallback( async () => {
		setIsLoading( true );
		setError( null );

		try {
			const result = await runAbility< ReplySuggestionResult >(
				'ai/reply-suggestion',
				{
					comment_id: commentId,
					tone,
					guidelines,
				}
			);

			setReply( result.reply ?? '' );

			// Defer focus so the button has time to mount after the reply renders.
			focusTimeoutRef.current = setTimeout(
				() => useThisReplyRef.current?.focus(),
				0
			);
		} catch ( err: any ) {
			setError(
				Boolean( err.message )
					? err.message
					: __( 'Failed to generate reply suggestion.', 'ai' )
			);

			// Defer focus so the button has time to mount after the error notice renders.
			focusTimeoutRef.current = setTimeout(
				() => generateRegenerateRef.current?.focus(),
				0
			);
		} finally {
			setIsLoading( false );
		}
	}, [ commentId, tone, guidelines ] );

	const toneOptions = [
		{ label: __( 'Friendly', 'ai' ), value: 'friendly' },
		{ label: __( 'Professional', 'ai' ), value: 'professional' },
		{ label: __( 'Casual', 'ai' ), value: 'casual' },
	];

	const getGenerateText = useCallback( () => {
		if ( isLoading ) {
			return __( 'Generating…', 'ai' );
		}

		if ( error ) {
			return __( 'Retry', 'ai' );
		}

		if ( reply ) {
			return __( 'Regenerate', 'ai' );
		}

		return __( 'Generate', 'ai' );
	}, [ error, reply, isLoading ] );

	return (
		<Modal
			title={ __( 'Suggest Reply', 'ai' ) }
			onRequestClose={ onClose }
			size="large"
			className="wpai-reply-modal"
		>
			<Flex direction="column" gap={ 4 } align="stretch">
				<FlexItem>
					<SelectControl
						label={ __( 'Tone', 'ai' ) }
						value={ tone }
						options={ toneOptions }
						onChange={ ( value ) => setTone( value as Tone ) }
					/>
				</FlexItem>

				{ /* Guidelines */ }
				<FlexItem>
					<TextareaControl
						label={ __( 'Guidelines (optional)', 'ai' ) }
						placeholder={ __(
							'Add any instructions you want the AI to follow when generating responses…',
							'ai'
						) }
						rows={ 2 }
						value={ guidelines }
						onChange={ setGuidelines }
					/>
				</FlexItem>

				{ /* Loading spinner */ }
				{ isLoading && (
					<Flex align="center" justify="flex-start" gap={ 1 }>
						<Spinner />
						<span>
							{ __( 'Generating reply suggestion…', 'ai' ) }
						</span>
					</Flex>
				) }

				{ /* Error notice */ }
				{ ! isLoading && error && (
					<Flex direction="column" gap={ 3 } align="flex-start">
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					</Flex>
				) }

				{ /* Generated reply */ }
				{ ! isLoading && ! error && reply && (
					<FlexItem>
						<p
							style={ {
								borderLeft:
									'3px solid var(--wp-admin-theme-color)',
								padding: '12px 16px',
								fontSize: '14px',
								lineHeight: '1.6',
							} }
						>
							{ reply }
						</p>
					</FlexItem>
				) }

				{ /* Action buttons */ }
				<Flex direction="row" gap={ 2 } justify="flex-start">
					{ reply && ! error && (
						<Button
							ref={ useThisReplyRef }
							variant="primary"
							onClick={ () => onSelectReply( reply, commentId ) }
							disabled={ isLoading }
						>
							{ __( 'Use this reply', 'ai' ) }
						</Button>
					) }
					<Button
						ref={ generateRegenerateRef }
						variant="secondary"
						onClick={ generateReply }
						disabled={ isLoading }
						isBusy={ isLoading }
					>
						{ getGenerateText() }
					</Button>
					{ reply && ! error && (
						<Button
							ref={ copyRef }
							variant="tertiary"
							disabled={ isLoading }
						>
							{ hasCopied
								? __( 'Copied!', 'ai' )
								: __( 'Copy', 'ai' ) }
						</Button>
					) }
				</Flex>
			</Flex>
		</Modal>
	);
}
