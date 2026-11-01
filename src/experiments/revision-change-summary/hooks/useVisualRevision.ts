/**
 * Hook that follows the visual revisions screen in the block editor.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { getQueryArg } from '@wordpress/url';

const HEADER_SELECTOR = '.editor-revisions-header';
const SUMMARY_SELECTOR = '.editor-sidebar__panel .editor-post-summary';
const REVISION_LINK_SELECTOR = 'a[href*="revision.php"]';
const ANCHOR_CLASS = 'ai-revision-change-summary-sidebar-anchor';
const CHECK_INTERVAL_MS = 500;

interface VisualRevision {
	anchor: HTMLElement | null;
	revisionId: number | null;
}

const EMPTY: VisualRevision = { anchor: null, revisionId: null };

/**
 * Returns the revision linked from the revision summary in the sidebar.
 *
 * Core keeps the selected revision ID in a private store. While revisions
 * are shown, the sidebar links to the classic revisions screen for that
 * revision, so the ID is read from that link.
 *
 * @param summary The revision summary section of the sidebar.
 * @return The revision ID, or null if none is linked.
 */
function getRevisionId( summary: HTMLElement ): number | null {
	const link = summary.querySelector< HTMLAnchorElement >(
		REVISION_LINK_SELECTOR
	);

	if ( ! link ) {
		return null;
	}

	const value = getQueryArg( link.href, 'revision' );
	const revisionId = Number( Array.isArray( value ) ? value[ 0 ] : value );

	return Number.isInteger( revisionId ) && revisionId > 0 ? revisionId : null;
}

/**
 * Removes the elements our panel renders into, once revisions aren't shown.
 */
function removeAnchors() {
	document
		.querySelectorAll( `.${ ANCHOR_CLASS }` )
		.forEach( ( element ) => element.remove() );
}

/**
 * Finds the selected revision and the element our panel renders into.
 *
 * Core has no extension point in the revisions sidebar, so an element is
 * added right after its revision summary section. If core changes that
 * markup, nothing is found and the panel is simply not shown.
 *
 * @return The anchor element and the revision ID.
 */
function findVisualRevision(): VisualRevision {
	if ( ! document.querySelector( HEADER_SELECTOR ) ) {
		return EMPTY;
	}

	const summary = document.querySelector< HTMLElement >( SUMMARY_SELECTOR );
	const revisionId = summary ? getRevisionId( summary ) : null;

	if ( ! summary?.parentElement || ! revisionId ) {
		return EMPTY;
	}

	let anchor = summary.parentElement.querySelector< HTMLElement >(
		`:scope > .${ ANCHOR_CLASS }`
	);

	if ( ! anchor ) {
		anchor = document.createElement( 'div' );
		anchor.className = ANCHOR_CLASS;
		summary.after( anchor );
	}

	return { anchor, revisionId };
}

/**
 * Tracks the visual revisions screen while the editor is in preview mode.
 *
 * The block editor switches to preview mode while revisions are shown. Only
 * then does this check, on a short interval, for the revisions sidebar and
 * the selected revision, because the sidebar can be opened, closed, or
 * switched to another tab at any time.
 *
 * @param isPreviewMode Whether the block editor is in preview mode.
 * @return The anchor element and the selected revision ID.
 */
export function useVisualRevision( isPreviewMode: boolean ): VisualRevision {
	const [ state, setState ] = useState< VisualRevision >( EMPTY );

	useEffect( () => {
		if ( ! isPreviewMode ) {
			removeAnchors();
			setState( EMPTY );
			return undefined;
		}

		const check = () => {
			const { anchor, revisionId } = findVisualRevision();

			if ( ! anchor ) {
				removeAnchors();
			}

			setState( ( previous ) =>
				previous.anchor === anchor && previous.revisionId === revisionId
					? previous
					: { anchor, revisionId }
			);
		};

		check();
		const interval = window.setInterval( check, CHECK_INTERVAL_MS );

		return () => {
			window.clearInterval( interval );
		};
	}, [ isPreviewMode ] );

	return state;
}
