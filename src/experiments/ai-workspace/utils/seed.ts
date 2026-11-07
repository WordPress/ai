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
 * Query argument the command palette's Ask AI row carries its prompt in, as
 * `Admin_Page::PROMPT_QUERY_ARG` names it.
 */
export const PROMPT_QUERY_ARG = 'wpai-prompt';

/**
 * Builds the prompt the workspace opens with after a handoff.
 *
 * A prompt from the command palette is used as it is: the person typed it,
 * and it lands in the composer for them to send or change.
 *
 * The seeded post's title is author-controlled text, so it is never sent on the
 * person's behalf: it lands in the composer, where the person reads it and can
 * edit or discard it before any turn is taken. The prompt names the post rather
 * than carrying it, because the assistant must read the body through the
 * permission-checked tool path like any other content.
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
 * A denial is reported as a denial. The workspace never falls back to whatever
 * the client happened to know about the post.
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
 * Removes the command palette's prompt from a workspace URL, keeping every
 * other parameter.
 *
 * The prompt is read once. Once it is in the composer it leaves the URL, so a
 * reload does not fill the composer again.
 *
 * @param href The current URL.
 * @return The URL without the prompt.
 */
export function urlWithoutPrompt( href: string ): string {
	const url = new URL( href );

	url.searchParams.delete( PROMPT_QUERY_ARG );

	return url.toString();
}
