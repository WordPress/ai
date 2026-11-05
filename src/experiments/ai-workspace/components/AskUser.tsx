/**
 * A question from the assistant, with its choices as buttons.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Question } from '../types';

/**
 * Renders a question card.
 *
 * Clicking a choice sends its text as the person's next message, the same as
 * typing it. Only the card at the end of the conversation can be answered:
 * once anything has been sent after it, by a click or by typing, its choices
 * are disabled so an old question cannot be answered twice. The choice that was
 * sent, if any, stays marked.
 *
 * @param props          Component props.
 * @param props.question The question and its choices.
 * @param props.isLive   Whether the question can still be answered.
 * @param props.chosen   The choice that answered it, or null.
 * @param props.onAnswer Sends a choice as the next message.
 * @return The rendered question.
 */
export default function AskUser( {
	question,
	isLive,
	chosen,
	onAnswer,
}: {
	question: Question;
	isLive: boolean;
	chosen: string | null;
	onAnswer: ( choice: string ) => void;
} ) {
	const instanceId = useInstanceId( AskUser, 'ai-workspace-question' );
	const labelId = `${ instanceId }-label`;

	return (
		<div
			className="ai-workspace__question"
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
						disabled={ ! isLive }
						// Only an answered card is a set of toggles: it shows
						// which one was sent.
						aria-pressed={ isLive ? undefined : chosen === choice }
						onClick={ () => onAnswer( choice ) }
					>
						{ choice }
					</Button>
				) ) }
			</div>
			{ isLive && (
				<p className="ai-workspace__question-hint">
					{ __( 'Choose an answer, or type your own.', 'ai' ) }
				</p>
			) }
		</div>
	);
}
