/**
 * Title generation utility functions.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../utils/run-ability';
import type {
	TitleGenerationAbilityInput,
	GeneratedTitleData,
	TitleGenerationData,
} from './types';

export const NOTICE_ID = 'ai_title_generation_error';
export const MINIMUM_CONTENT_COUNT_DEFAULT = 250;

/**
 * Retrieves title generation experiment settings.
 *
 * @return Title generation settings.
 */
export const getSettings = (): TitleGenerationData => {
	const settings = ( window as any ).aiTitleGenerationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ?? MINIMUM_CONTENT_COUNT_DEFAULT,
	};
};

/**
 * Generates a title for the given post ID and content.
 *
 * @param postId  The ID of the post to generate a title for.
 * @param content The content of the post to generate a title for.
 * @return A promise that resolves to the generated title.
 */
export async function generateTitle(
	postId: number,
	content: string
): Promise< string > {
	const params: TitleGenerationAbilityInput = {
		context: postId.toString(),
		content,
	};

	const response = await runAbility< GeneratedTitleData >(
		'ai/title-generation',
		params
	);

	if (
		response &&
		typeof response === 'object' &&
		'title' in response &&
		typeof response.title === 'string' &&
		response.title.length > 0
	) {
		return response.title;
	}

	throw new Error( __( 'No title suggestion was generated.', 'ai' ) );
}

/**
 * Programmatically updates the value of a React-controlled input element
 * and dispatches necessary events for React state listeners to pick up the change.
 *
 * @param input The input element to update.
 * @param value The new string value.
 */
export function setInputValue( input: HTMLInputElement, value: string ): void {
	const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	)?.set;

	if ( nativeInputValueSetter ) {
		nativeInputValueSetter.call( input, value );
	} else {
		input.value = value;
	}

	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}
