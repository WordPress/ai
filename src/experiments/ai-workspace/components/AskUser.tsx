/**
 * A question from the assistant, with its choices as buttons.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DismissResult } from '../hooks/useTurn';
import type { Question } from '../types';

/**
 * Renders a question card; only the live card can be answered or dismissed.
 *
 * @param props             Component props.
 * @param props.question    The question and its choices.
 * @param props.isLive      Whether the question can still be answered.
 * @param props.isDismissed Whether the person closed it without answering.
 * @param props.chosen      The choice that answered it, or null.
 * @param props.onAnswer    Sends a choice as the next message.
 * @param props.onDismiss   Closes the question without sending anything to the model; absent when it cannot.
 * @return The rendered question.
 */
export default function AskUser( {
	question,
	isLive,
	isDismissed,
	chosen,
	onAnswer,
	onDismiss,
}: {
	question: Question;
	isLive: boolean;
	isDismissed: boolean;
	chosen: string | null;
	onAnswer: ( choice: string ) => void;
	onDismiss?: ( () => Promise< DismissResult > ) | undefined;
} ) {
	const instanceId = useInstanceId( AskUser, 'ai-workspace-question' );
	const labelId = `${ instanceId }-label`;
	const [ isDismissing, setIsDismissing ] = useState( false );
	const [ failed, setFailed ] = useState( false );
	const canAct = isLive && ! isDismissing;

	const dismiss = async (): Promise< void > => {
		if ( ! onDismiss ) {
			return;
		}

		setIsDismissing( true );
		setFailed( false );

		const result = await onDismiss();

		setIsDismissing( false );
		setFailed( 'failed' === result );
	};

	return (
		<div
			className={
				'ai-workspace__question' +
				( isDismissed ? ' is-dismissed' : '' )
			}
			role="group"
			aria-labelledby={ labelId }
		>
			<p id={ labelId } className="ai-workspace__question-text">
				{ question.question }
			</p>
			<div className="ai-workspace__question-choices">
				{ question.choices.map( ( choice ) => (
					<Button
						key={ choice }
						__next40pxDefaultSize
						variant={ chosen === choice ? 'primary' : 'secondary' }
						disabled={ ! canAct }
						// Only an answered card shows which choice was sent.
						aria-pressed={ isLive ? undefined : chosen === choice }
						onClick={ () => onAnswer( choice ) }
					>
						{ choice }
					</Button>
				) ) }
				{ isLive && onDismiss && (
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						isBusy={ isDismissing }
						disabled={ isDismissing }
						accessibleWhenDisabled
						onClick={ () => {
							void dismiss();
						} }
					>
						{ __( 'Dismiss question', 'ai' ) }
					</Button>
				) }
				{ isDismissed && (
					<span className="ai-workspace__question-dismissed">
						{ __( 'Dismissed', 'ai' ) }
					</span>
				) }
			</div>
			{ isLive && failed && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'The question could not be dismissed. Try again.',
						'ai'
					) }
				</Notice>
			) }
			{ isLive && (
				<p className="ai-workspace__question-hint">
					{ __( 'Choose an answer, or type your own.', 'ai' ) }
				</p>
			) }
		</div>
	);
}
