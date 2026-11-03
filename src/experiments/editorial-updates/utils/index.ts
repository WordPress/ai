/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Updates a Note status to approved.
 *
 * @param noteId Note ID.
 */
export async function resolveNote( noteId: number ): Promise< void > {
	return apiFetch< void >( {
		path: `/wp/v2/comments/${ noteId }`,
		method: 'PUT',
		data: { status: 'approve' },
	} );
}

/**
 * Navigates to the classic revisions screen.
 *
 * @param adminUrl       Site admin URL, or undefined if unavailable.
 * @param lastRevisionId The revision ID to open.
 */
export function navigateToClassicRevisions(
	adminUrl: string | undefined,
	lastRevisionId: number
): void {
	if ( adminUrl ) {
		window.location.href = `${ adminUrl }revision.php?revision=${ lastRevisionId }`;
	}
}

/**
 * Opens WordPress's in-editor visual revisions UI by clicking the
 * "Revisions" button Core already renders in the Document Settings
 * sidebar (`PrivatePostLastRevision` in `@wordpress/editor`).
 *
 * There is no public JS API for this in WP 7.0 — the underlying store
 * action (`setCurrentRevisionId`) is registered as a private action and
 * is gated behind `@wordpress/private-apis`, which only allows a hardcoded
 * list of core module names to opt in. Calling it directly from a plugin
 * throws, since the action is never attached to the public dispatch
 * object. Simulating a real click on Core's own rendered button is the
 * supported workaround.
 *
 * Elements are located by stable, locale-independent attributes rather
 * than translated accessible names: the sidebar toggle uses
 * `aria-controls="edit-post:document"`, which comes from the
 * complementary area identifier `edit-post/document` registered by
 * the WordPress editor package for the Document Settings sidebar, and
 * is the same regardless of site language.
 *
 * Falls back to the classic revisions screen if the button can't be
 * found within a short polling window (e.g. markup changes upstream, or
 * the post genuinely has fewer than two revisions so Core doesn't render
 * the button at all).
 *
 * @param root0                Options.
 * @param root0.adminUrl       Site admin URL, used for the classic
 *                             revisions fallback.
 * @param root0.lastRevisionId The revision ID to open.
 */
export function openVisualRevisions( {
	adminUrl,
	lastRevisionId,
}: {
	adminUrl: string | undefined;
	lastRevisionId: number;
} ): void {
	const MAX_ATTEMPTS = 20;
	const INTERVAL_MS = 100;

	// The revisions button only exists in the DOM once the Document
	// Settings sidebar is mounted, so open it first if it's collapsed.
	const settingsToggle = document.querySelector< HTMLButtonElement >(
		'button[aria-controls="edit-post:document"]'
	);
	if ( settingsToggle?.getAttribute( 'aria-expanded' ) === 'false' ) {
		settingsToggle.click();
	}

	let attempts = 0;
	const tryClick = () => {
		const revisionsButton = document.querySelector< HTMLButtonElement >(
			'.editor-private-post-last-revision__button, .editor-post-last-revision__title'
		);
		if ( revisionsButton ) {
			revisionsButton.click();
			return;
		}
		attempts++;
		if ( attempts >= MAX_ATTEMPTS ) {
			navigateToClassicRevisions( adminUrl, lastRevisionId );
			return;
		}
		setTimeout( tryClick, INTERVAL_MS );
	};
	tryClick();
}

/**
 * Builds the snackbar action for reviewing changes in the revisions UI.
 *
 * Uses the in-editor visual revisions path (WP 7.0+ default) via
 * openVisualRevisions(). Falls back to the classic revisions screen when
 * visual revisions are disabled, e.g. when classic metaboxes are active
 * on the post.
 *
 * @param root0                        Options.
 * @param root0.lastRevisionId         The revision ID to open.
 * @param root0.adminUrl               Site admin URL, used for the
 *                                     classic revisions fallback.
 * @param root0.disableVisualRevisions Whether Core has disabled visual
 *                                     revisions for this post.
 * @return Snackbar notice actions, or an empty array when neither path
 *         is available.
 */
export function getRevisionReviewAction( {
	lastRevisionId,
	adminUrl,
	disableVisualRevisions,
}: {
	lastRevisionId: number;
	adminUrl: string | undefined;
	disableVisualRevisions: boolean;
} ): Array< { label: string; url?: string; onClick?: () => void } > {
	if ( ! disableVisualRevisions ) {
		return [
			{
				label: __( 'Review in Revisions', 'ai' ),
				onClick: () =>
					openVisualRevisions( { adminUrl, lastRevisionId } ),
			},
		];
	}

	if ( adminUrl ) {
		return [
			{
				label: __( 'Review in Revisions', 'ai' ),
				url: `${ adminUrl }revision.php?revision=${ lastRevisionId }`,
			},
		];
	}

	return [];
}
