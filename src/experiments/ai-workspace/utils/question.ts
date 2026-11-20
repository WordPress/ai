/**
 * Narrows an untrusted `ai/ask-user` tool result to the question the card renders.
 */

/**
 * Internal dependencies
 */
import type { Question, TranscriptEntry } from '../types';

/**
 * Narrows a tool result to a question with at least one unique, trimmed choice.
 *
 * Choices are trimmed because the composer trims what it sends.
 *
 * @param value The raw tool result.
 * @return The question, or null when the result is not one.
 */
export function toQuestion( value: unknown ): Question | null {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return null;
	}

	const { question, choices } = value as Record< string, unknown >;

	if ( typeof question !== 'string' || '' === question.trim() ) {
		return null;
	}

	if ( ! Array.isArray( choices ) ) {
		return null;
	}

	const unique: string[] = [];

	choices.forEach( ( choice ) => {
		if ( typeof choice !== 'string' ) {
			return;
		}

		const trimmed = choice.trim();

		if ( '' !== trimmed && ! unique.includes( trimmed ) ) {
			unique.push( trimmed );
		}
	} );

	if ( 0 === unique.length ) {
		return null;
	}

	return { question: question.trim(), choices: unique };
}

/**
 * Where a turn's question stands.
 */
export interface QuestionState {
	isLive: boolean;
	isDismissed: boolean;
	/** The message that answered it, or null. */
	reply: string | null;
}

/**
 * Works out whether a turn's question is open, answered or dismissed.
 *
 * The next entry that is not a follow-up closes it: a dismissal, or the person's message, which is then its answer.
 *
 * @param entries The transcript entries.
 * @param index   The index of the entry that asked.
 * @return The question's state.
 */
export function questionState(
	entries: TranscriptEntry[],
	index: number
): QuestionState {
	const later = entries.slice( index + 1 );
	const next = later.find( ( item ) => ! item.followUp );
	const isDismissed = 'dismissed' === next?.status;

	return {
		isLive:
			'awaiting_user' === entries[ index ]?.status &&
			undefined === next &&
			later.every( ( item ) => 'streaming' !== item.status ),
		isDismissed,
		reply: next && ! isDismissed ? next.prompt : null,
	};
}
