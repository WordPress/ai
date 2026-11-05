/**
 * Narrows an untrusted `ai/ask-user` tool result to the question the card renders.
 */

/**
 * Internal dependencies
 */
import type { Question } from '../types';

/**
 * Narrows a tool result to a question with choices.
 *
 * The server validated the input before it ran, but the result still arrives
 * over the wire, so anything that is not a non-empty question with at least
 * two string choices is treated as no question at all. Duplicate and empty
 * choices are dropped rather than rendered as buttons that send nothing.
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
		if (
			typeof choice === 'string' &&
			'' !== choice.trim() &&
			! unique.includes( choice )
		) {
			unique.push( choice );
		}
	} );

	if ( unique.length < 2 ) {
		return null;
	}

	return { question, choices: unique };
}
