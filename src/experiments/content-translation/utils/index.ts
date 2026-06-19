/**
 * WordPress dependencies
 */
import type { Block } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getBlockText } from '../../../utils/blocks';
import { runAbility } from '../../../utils/run-ability';
import type { AIContentTranslationData } from '../types/types';
import {
	TRANSLATION_LOADING_CLASSES,
	TRANSLATION_MINIMUM_CONTENT_COUNT_DEFAULT,
	TRANSLATION_SUPPORTED_BLOCK_TYPES,
} from '../constants';

/**
 * Retrieves the content translation settings from the global window object.
 *
 * @return The content translation settings.
 */
export const getSettings = (): AIContentTranslationData => {
	const settings = window?.aiContentTranslationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ??
			TRANSLATION_MINIMUM_CONTENT_COUNT_DEFAULT,
		languages: settings.languages ?? [],
	};
};

/**
 * Get the translatable block if it is supported or has non-empty text content.
 *
 * @param block The block to check.
 * @return An object containing the clientId and content of the block, or null if the block is not translatable.
 */
export function getTranslatableBlock( block: Block ) {
	const content = getBlockText( block );

	if (
		TRANSLATION_SUPPORTED_BLOCK_TYPES.includes( block.name ) &&
		content.trim().length > 0
	) {
		return {
			clientId: block.clientId,
			content,
		};
	}

	return null;
}

/**
 * Toggle the loading class used to show the translation-in-progress state.
 *
 * @param loadingClass The loading class to toggle.
 * @param isLoading    A boolean indicating whether the loading class should be toggled.
 */
export function setTranslationLoadingClass(
	loadingClass: keyof typeof TRANSLATION_LOADING_CLASSES,
	isLoading: boolean
) {
	const editorBody = document.querySelector< HTMLIFrameElement >(
		'iframe[name="editor-canvas"]'
	)?.contentDocument?.body;

	editorBody?.classList.toggle(
		TRANSLATION_LOADING_CLASSES[ loadingClass ],
		isLoading
	);
}

/**
 * Get a user-friendly error message from an unknown error object.
 *
 * @param error The unknown error object to extract the message from.
 * @return A string containing the error message to display to the user.
 */
export function getErrorMessage( error: unknown ): string {
	if ( typeof error === 'string' ) {
		return error;
	}

	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( ( error as { message: string } ).message );
	}

	return __( 'Something went wrong. Please try again.', 'ai' );
}

/**
 * Translates the content of a post using the AI API.
 *
 * @param content        The content to translate.
 * @param targetLanguage The target language to translate the content to.
 * @param postId         The ID of the post to translate the content for.
 * @return  A promise that resolves to the translated content.
 */
export function translateContent(
	content: string,
	targetLanguage: string,
	postId: number
): Promise< string > {
	return runAbility< string >( 'ai/content-translation', {
		content,
		target_language: targetLanguage,
		post_id: postId,
	} );
}
