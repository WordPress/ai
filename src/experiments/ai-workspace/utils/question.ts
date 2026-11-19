/**
 * Narrows an untrusted `ai/ask-user` tool result to the question the card renders.
 */

/**
 * Internal dependencies
 */
import type { Question } from '../types';

/**
 * Narrows a tool result to a question with at least two unique, trimmed choices.
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

	if ( unique.length < 2 ) {
		return null;
	}

	return { question: question.trim(), choices: unique };
}
