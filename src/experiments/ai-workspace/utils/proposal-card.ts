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
import type {
	Proposal,
	ProposalExecution,
	ProposalItem,
	ProposalItemOutcome,
} from '../types';

/**
 * The proposal kinds that change something that exists rather than create it.
 */
const UPDATE_KINDS = [ 'post-field', 'site-identity' ];

/**
 * The site settings a `site-identity` item can change.
 */
const SITE_FIELDS = [ 'blogname', 'blogdescription' ];

/**
 * Reports whether a field is a site setting rather than a post field.
 *
 * @param field The field.
 * @return Whether it is a site setting.
 */
function isSiteField( field: string ): boolean {
	return SITE_FIELDS.includes( field );
}

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

	if ( 'pending' !== proposal.status ) {
		return [];
	}

	/*
	 * Every change of an update card starts checked: each one sits beside the
	 * value it replaces, so the card is read item by item before anything is
	 * applied, and the person unchecks what they do not want.
	 */
	if ( isUpdate( proposal ) ) {
		return proposal.items.map( ( item ) => item.key );
	}

	if ( 1 !== proposal.items.length || ! only ) {
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

/**
 * Reports whether a proposal changes existing content rather than creating it.
 *
 * @param proposal The stored proposal.
 * @return Whether it is an update.
 */
export function isUpdate( proposal: Proposal ): boolean {
	return UPDATE_KINDS.includes( proposal.kind );
}

/**
 * Reports whether an execution result is the outcome of an update.
 *
 * An update item names the field it changed. A discarded proposal names none,
 * and is told apart by its `discarded` flag instead.
 *
 * @param execution The execution result.
 * @return Whether it reports changes rather than creations.
 */
export function isUpdateExecution( execution: ProposalExecution ): boolean {
	return (
		execution.items.some( ( item ) => '' !== item.field ) ||
		execution.updated +
			execution.unchanged +
			execution.stale +
			execution.locked >
			0
	);
}

/**
 * Names each field an update item can change.
 *
 * `name` is how the editor names a post field; a site setting has none, since
 * its card is named by the label the server sends. `edit` labels the field that
 * edits the new value.
 *
 * Built on each call, so the strings are translated in the current locale.
 *
 * @param field The field.
 * @return The labels, or undefined for a field this card does not know.
 */
function fieldLabels(
	field: string
): { name?: string; edit: string } | undefined {
	const labels: Record< string, { name?: string; edit: string } > = {
		title: { name: __( 'Title', 'ai' ), edit: __( 'New title', 'ai' ) },
		excerpt: {
			name: __( 'Excerpt', 'ai' ),
			edit: __( 'New excerpt', 'ai' ),
		},
		content: {
			name: __( 'Content', 'ai' ),
			edit: __( 'New content', 'ai' ),
		},
		blogname: { edit: __( 'New site title', 'ai' ) },
		blogdescription: { edit: __( 'New tagline', 'ai' ) },
	};

	return Object.prototype.hasOwnProperty.call( labels, field )
		? labels[ field ]
		: undefined;
}

/**
 * Names a post field the way the editor does.
 *
 * @param field The field.
 * @return The label, or the field itself for one this card does not know.
 */
function postFieldName( field: string ): string {
	return fieldLabels( field )?.name ?? field;
}

/**
 * Names what one update item changes, such as `Title of “Hello world!”`.
 *
 * A site setting is named by its own label, which the server sends as the
 * item's title.
 *
 * @param item The proposed change, or the outcome of one.
 * @return The label.
 */
export function changeLabel(
	item: Pick< ProposalItem, 'field' | 'title' >
): string {
	if ( '' === item.field ) {
		return item.title;
	}

	if ( isSiteField( item.field ) ) {
		return item.title;
	}

	return sprintf(
		/* translators: 1: a post field, such as Title. 2: the post's title. */
		__( '%1$s of “%2$s”', 'ai' ),
		postFieldName( item.field ),
		item.title
	);
}

/**
 * Names the field that edits an item's new value.
 *
 * @param item The proposed change.
 * @return The label.
 */
export function editFieldLabel( item: ProposalItem ): string {
	return fieldLabels( item.field )?.edit ?? __( 'New value', 'ai' );
}

/**
 * Says that a change goes live as soon as it is applied, when it does.
 *
 * @param item The proposed change.
 * @return The notice, or an empty string when the change is not live at once.
 */
export function liveNotice( item: ProposalItem ): string {
	if ( ! item.live ) {
		return '';
	}

	if ( isSiteField( item.field ) ) {
		return __(
			'Shown across the site, so the change is live for visitors as soon as you apply it.',
			'ai'
		);
	}

	return __(
		'This post is published, so the change is live for visitors as soon as you apply it.',
		'ai'
	);
}

/**
 * Names an update card, with how many changes it holds.
 *
 * @param count The number of changes.
 * @return The heading.
 */
export function reviewLabel( count: number ): string {
	return sprintf(
		/* translators: %d: number of changes. */
		_n( 'Review %d change', 'Review %d changes', count, 'ai' ),
		count
	);
}

/**
 * Names the button that applies the selected changes.
 *
 * @param count The number of selected changes.
 * @return The label.
 */
export function applyLabel( count: number ): string {
	return sprintf(
		/* translators: %d: number of changes. */
		_n( 'Apply %d change', 'Apply %d changes', count, 'ai' ),
		count
	);
}

/**
 * Names what an update changed.
 *
 * @param items Every item's outcome.
 * @return The heading.
 */
export function updatedLabel( items: ProposalItemOutcome[] ): string {
	const updated = items.filter( ( item ) => 'updated' === item.outcome );

	if ( 0 === updated.length ) {
		return __( 'Nothing was changed', 'ai' );
	}

	return sprintf(
		/* translators: %d: number of changes. */
		_n( 'Updated %d change', 'Updated %d changes', updated.length, 'ai' ),
		updated.length
	);
}

/**
 * Names what happened to one change.
 *
 * @param outcome The server's outcome code.
 * @return The label.
 */
export function updateOutcomeLabel( outcome: string ): string {
	switch ( outcome ) {
		case 'updated':
			return __( 'Updated', 'ai' );
		case 'unchanged':
			return __( 'Unchanged: it already had this value', 'ai' );
		case 'stale':
			return __(
				'Changed since proposed, so it was left as it is',
				'ai'
			);
		case 'locked':
			return __(
				'Someone else is editing it, so it was left as it is',
				'ai'
			);
		case 'failed':
			return __( 'Not changed', 'ai' );
		case 'denied':
			return __( 'Not changed: you no longer have permission', 'ai' );
		case 'deselected':
			return __( 'Not selected', 'ai' );
		case 'discarded':
			return __( 'Discarded', 'ai' );
		default:
			return outcome;
	}
}

/**
 * Names the link that opens what a change was made to.
 *
 * @param item The outcome.
 * @return The label.
 */
export function openChangeLabel( item: ProposalItemOutcome ): string {
	return 'option' === item.post_type || isSiteField( item.field )
		? __( 'Open settings', 'ai' )
		: __( 'Open in the editor', 'ai' );
}
