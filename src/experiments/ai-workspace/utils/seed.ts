/**
 * The block editor handoff's seed, as the workspace consumes it.
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
 * Builds the prompt the workspace opens with after a handoff.
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
