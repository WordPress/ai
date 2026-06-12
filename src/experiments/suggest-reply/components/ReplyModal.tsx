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
import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';

type Tone = 'professional' | 'friendly' | 'casual';

type ReplySuggestionResult = {
	comment_id: number;
	reply: string;
};

export type CachedReply = {
	reply: string;
	tone: Tone;
};

export type ReplyModalProps = {
	commentId: number;
	onClose: () => void;
	onSelectReply: ( reply: string, commentId: number ) => void;
	initialReply?: CachedReply | undefined;
	onReplyChange?: ( ( data: CachedReply ) => void ) | undefined;
};

export function ReplyModal( {
	commentId,
	onClose,
	onSelectReply,
	initialReply,
	onReplyChange,
}: ReplyModalProps ): React.ReactElement {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ reply, setReply ] = useState< string >( initialReply?.reply ?? '' );
	const [ tone, setTone ] = useState< Tone >(
		initialReply?.tone ?? 'friendly'
	);

	const [ error, setError ] = useState< string | null >( null );
	const [ hasGenerated, setHasGenerated ] = useState(
		!! initialReply?.reply
	);

	const generateReply = useCallback( async () => {
		setIsLoading( true );
		setError( null );

		try {
			const result = await runAbility< ReplySuggestionResult >(
				'ai/reply-suggestion',
				{
					comment_id: commentId,
					tone,
				}
			);

			const freshReply = result.reply ?? '';
			setReply( freshReply );
			setHasGenerated( true );
			onReplyChange?.( { reply: freshReply, tone } );
		} catch ( err ) {
			setError(
				err instanceof Error
					? err.message
					: __( 'Failed to generate reply suggestion.', 'ai' )
			);
		} finally {
			setIsLoading( false );
		}
	}, [ commentId, tone, onReplyChange ] );

	const handleSelect = useCallback( () => {
		onSelectReply( reply, commentId );
	}, [ reply, commentId, onSelectReply ] );

	const handleReplyChange = useCallback(
		( value: string ) => {
			setReply( value );
			onReplyChange?.( { reply: value, tone } );
		},
		[ tone, onReplyChange ]
	);

	useEffect( () => {
		if ( ! initialReply?.reply ) {
			generateReply();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const toneOptions = [
		{ label: __( 'Friendly', 'ai' ), value: 'friendly' },
		{ label: __( 'Professional', 'ai' ), value: 'professional' },
		{ label: __( 'Casual', 'ai' ), value: 'casual' },
	];

	return (
		<Modal
			title={ __( 'Suggest Reply', 'ai' ) }
			onRequestClose={ onClose }
			size="large"
			className="wpai-reply-modal"
		>
			{ /* Controls row: tone selector + generate button */ }
			<div style={ { marginBottom: '8px' } }>
				<Flex gap={ 4 } align="flex-end" wrap>
					<FlexItem>
						<SelectControl
							label={ __( 'Tone', 'ai' ) }
							value={ tone }
							options={ toneOptions }
							onChange={ ( value ) => setTone( value as Tone ) }
							__nextHasNoMarginBottom
						/>
					</FlexItem>
				</Flex>
			</div>

			{ /* Content area */ }
			<div className="wpai-reply-modal__content">
				{ isLoading && (
					<Flex align="center" justify="flex-start" gap={ 1 }>
						<Spinner />
						<span>
							{ __( 'Generating reply suggestion…', 'ai' ) }
						</span>
					</Flex>
				) }

				{ ! isLoading && error && (
					<Flex direction="column" gap={ 3 } align="flex-start">
						<Notice status="error" isDismissible={ false }>
							{ error ||
								__(
									'An error occurred while generating the reply suggestion.',
									'ai'
								) }
						</Notice>
						<Button variant="secondary" onClick={ generateReply }>
							{ __( 'Try again', 'ai' ) }
						</Button>
					</Flex>
				) }

				{ ! isLoading && ! error && reply && (
					<Flex direction="column" gap={ 4 }>
						<FlexItem style={ { width: '100%' } }>
							<TextareaControl
								rows={ 6 }
								label={ __( 'Suggested reply', 'ai' ) }
								hideLabelFromVision
								disabled
								value={ reply }
								onChange={ handleReplyChange }
								__nextHasNoMarginBottom
							/>
							<Flex
								justify="flex-start"
								gap={ 2 }
								style={ { marginTop: '8px' } }
							>
								<Button
									variant="secondary"
									onClick={ generateReply }
									disabled={ isLoading }
									isBusy={ isLoading }
								>
									{ hasGenerated
										? __( 'Regenerate', 'ai' )
										: __( 'Generate', 'ai' ) }
								</Button>
								<Button
									variant="primary"
									onClick={ handleSelect }
									disabled={ isLoading }
								>
									{ __( 'Use this reply', 'ai' ) }
								</Button>
							</Flex>
						</FlexItem>
					</Flex>
				) }

				{ ! isLoading && ! error && ! reply && hasGenerated && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The AI was unable to generate a reply suggestion for this comment.',
							'ai'
						) }
					</Notice>
				) }
			</div>
		</Modal>
	);
}
