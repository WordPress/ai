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
import { useState, useCallback } from '@wordpress/element';
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

export type ReplyModalProps = {
	commentId: number;
	onClose: () => void;
	onSelectReply: ( reply: string, commentId: number ) => void;
};

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
		} catch ( err: any ) {
			setError(
				Boolean(err.message)
					? err.message
					: __( 'Failed to generate reply suggestion.', 'ai' )
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
							'e.g. Always mention our support email, keep it under 3 sentences…',
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
								borderLeft: '3px solid var(--wp-admin-theme-color)',
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
					{ reply && (
						<Button
							variant="primary"
							onClick={ () => onSelectReply( reply, commentId ) }
							disabled={ isLoading }
						>
							{ __( 'Use this reply', 'ai' ) }
						</Button>
					) }
					<Button
						variant="secondary"
						onClick={ generateReply }
						disabled={ isLoading }
						isBusy={ isLoading }
					>
						{ reply
							? __( 'Regenerate', 'ai' )
							: __( 'Generate', 'ai' ) }
					</Button>
				</Flex>
			</Flex>
		</Modal>
	);
}
