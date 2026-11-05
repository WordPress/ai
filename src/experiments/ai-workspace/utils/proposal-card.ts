/**
 * What the proposal card says and links to, kept apart from the component so
 * each rule can be tested on its own.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Proposal, ProposalItem, ProposalItemOutcome } from '../types';

/**
 * Statuses an item may be preselected with. Neither puts anything on the site.
 */
const PRESELECTABLE_STATUSES = [ 'draft', 'pending' ];

/**
 * Returns the item keys the card starts with checked.
 *
 * A single draft or pending item starts checked, so "write a post about X" is
 * one click to create and one to open. A batch starts unchecked: an instruction
 * planted in content the assistant read wins by appending an item to an
 * otherwise legitimate batch, so each item in one is chosen deliberately. An
 * item that would be published or made private is never preselected, and
 * neither is anything in a proposal that has already been executed.
 *
 * A template proposal holds one template and has no checkbox, so its one item
 * is the selection; its card says plainly that it goes live when created.
 *
 * @param proposal The stored proposal.
 * @return The keys to start with checked.
 */
export function initialSelection( proposal: Proposal ): string[] {
	const [ only ] = proposal.items;

	if (
		'pending' !== proposal.status ||
		1 !== proposal.items.length ||
		! only
	) {
		return [];
	}

	if (
		'template' !== proposal.kind &&
		! PRESELECTABLE_STATUSES.includes( only.status )
	) {
		return [];
	}

	return [ only.key ];
}

/**
 * Says what creating a template changes for visitors.
 *
 * A template is live as soon as it is created, so the card says so before
 * anyone approves it, and names the theme's own template when it replaces one.
 *
 * @param item The proposed template.
 * @return The notice.
 */
export function templateNotice( item: ProposalItem ): string {
	if ( item.replaces_theme_template ) {
		return sprintf(
			/* translators: %s: the template slug, such as 404. */
			__(
				'Replaces your theme’s %s template. The change is live for visitors as soon as you create it, and Reset in the Site Editor brings the theme’s version back.',
				'ai'
			),
			item.slug
		);
	}

	return __(
		'The template is live for visitors as soon as you create it.',
		'ai'
	);
}

/**
 * Names the button that opens a created item.
 *
 * @param item The outcome.
 * @return The label.
 */
export function openLabel( item: ProposalItemOutcome ): string {
	if ( 'wp_template' === item.post_type ) {
		return __( 'Open template', 'ai' );
	}

	return 'draft' === item.status
		? __( 'Open draft', 'ai' )
		: __( 'Open in the editor', 'ai' );
}

/**
 * Names what approving the given items will do, with the count.
 *
 * Publishing is named whenever it is part of it, because that puts something
 * on the site. Drafts are named as drafts, because that is what a person asked
 * for; any other mix falls back to "items", and each row still says how it is
 * saved.
 *
 * @param items The items the label describes.
 * @param count How many will be written.
 * @return The label.
 */
export function createLabel( items: ProposalItem[], count: number ): string {
	if ( items.some( ( item ) => 'publish' === item.status ) ) {
		return sprintf(
			/* translators: %d: number of items. */
			_n(
				'Create and publish %d item',
				'Create and publish %d items',
				count,
				'ai'
			),
			count
		);
	}

	if (
		items.length > 0 &&
		items.every( ( item ) => 'draft' === item.status )
	) {
		return sprintf(
			/* translators: %d: number of drafts. */
			_n( 'Create %d draft', 'Create %d drafts', count, 'ai' ),
			count
		);
	}

	return sprintf(
		/* translators: %d: number of items. */
		_n( 'Create %d item', 'Create %d items', count, 'ai' ),
		count
	);
}

/**
 * Names what was created.
 *
 * @param created The items whose outcome is "created".
 * @return The heading.
 */
export function createdLabel( created: ProposalItemOutcome[] ): string {
	if ( 0 === created.length ) {
		return __( 'Nothing was created', 'ai' );
	}

	if ( created.every( ( item ) => 'wp_template' === item.post_type ) ) {
		return sprintf(
			/* translators: %d: number of templates. */
			_n(
				'Created %d template',
				'Created %d templates',
				created.length,
				'ai'
			),
			created.length
		);
	}

	if ( created.every( ( item ) => 'draft' === item.status ) ) {
		return sprintf(
			/* translators: %d: number of drafts. */
			_n( 'Created %d draft', 'Created %d drafts', created.length, 'ai' ),
			created.length
		);
	}

	return sprintf(
		/* translators: %d: number of items. */
		_n( 'Created %d item', 'Created %d items', created.length, 'ai' ),
		created.length
	);
}

/**
 * Reports whether an outcome points at a post that can be opened.
 *
 * True for a created item and for a duplicate, which points at the post an
 * earlier approval created.
 *
 * @param item The outcome.
 * @return Whether it can be opened.
 */
export function canOpen( item: ProposalItemOutcome ): boolean {
	return item.post_id > 0 && '' !== item.edit_link;
}

/**
 * Builds a link to the admin list that holds the given items.
 *
 * The list is the post type's own screen, narrowed to the status they were
 * saved with when they share one, and newest first, so they are at the top.
 * The admin URL is resolved from an item's edit link rather than assumed. Items
 * of different post types have no single list, so they get no link.
 *
 * @param items The items to list, each one that can be opened.
 * @return The list's URL and label, or null.
 */
export function listLink(
	items: ProposalItemOutcome[]
): { url: string; label: string } | null {
	const [ first ] = items;

	if (
		! first ||
		items.some( ( item ) => item.post_type !== first.post_type )
	) {
		return null;
	}

	let url: URL;

	try {
		url = new URL( 'edit.php', first.edit_link );
	} catch {
		return null;
	}

	url.searchParams.set( 'post_type', first.post_type );

	if ( items.every( ( item ) => item.status === first.status ) ) {
		url.searchParams.set( 'post_status', first.status );
	}

	url.searchParams.set( 'orderby', 'date' );
	url.searchParams.set( 'order', 'desc' );

	let label: string = __( 'Open all in the list', 'ai' );

	if ( 'post' === first.post_type ) {
		label = __( 'Open all in Posts', 'ai' );
	} else if ( 'page' === first.post_type ) {
		label = __( 'Open all in Pages', 'ai' );
	}

	return { url: url.href, label };
}
