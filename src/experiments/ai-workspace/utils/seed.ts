/**
 * The block editor and command palette handoffs' seed, as the workspace consumes it.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { WorkspaceSeed } from '../types';

/**
 * Query argument carrying the command palette's prompt (`Admin_Page::PROMPT_QUERY_ARG`).
 */
export const PROMPT_QUERY_ARG = 'wpai-prompt';

/**
 * Builds the prompt the workspace opens with after a handoff.
 *
 * The post title is author-controlled, so it only prefills the composer and is never sent automatically.
 *
 * @param seed The seeded post, or null when there was no handoff.
 * @return The prompt to prefill, or an empty string.
 */
export function getSeedPrompt( seed: WorkspaceSeed | null ): string {
	if ( seed?.status === 'prompt' ) {
		return seed.prompt;
	}

	if ( ! seed || seed.status !== 'ready' || ! seed.title ) {
		return '';
	}

	return sprintf(
		/* translators: 1: Post title. 2: Post type slug. 3: Post ID. */
		__(
			'I am working on “%1$s” (%2$s ID %3$d) on this site. Look it up and help me improve it.',
			'ai'
		),
		seed.title,
		seed.postType,
		seed.postId
	);
}

/**
 * Builds the explanation shown when a handoff could not put its post in scope.
 *
 * @param seed The seeded post, or null when there was no handoff.
 * @return The explanation, or null when there is nothing to explain.
 */
export function getSeedNotice( seed: WorkspaceSeed | null ): string | null {
	if ( ! seed ) {
		return null;
	}

	switch ( seed.status ) {
		case 'denied':
			return __(
				'You do not have permission to read the post this workspace was opened for, so it was not put in scope.',
				'ai'
			);
		case 'not-found':
			return __(
				'The post this workspace was opened for no longer exists, so it was not put in scope.',
				'ai'
			);
		default:
			return null;
	}
}

/**
 * Removes the command palette's prompt from a workspace URL so a reload does not refill the composer.
 *
 * @param href The current URL.
 * @return The URL without the prompt.
 */
export function urlWithoutPrompt( href: string ): string {
	const url = new URL( href );

	url.searchParams.delete( PROMPT_QUERY_ARG );

	return url.toString();
}
