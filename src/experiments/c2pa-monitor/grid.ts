/**
 * Media Library grid indicator for the C2PA Monitor experiment.
 *
 * Adds a neutral badge to attachment thumbnails whose `wpaiC2pa.present`
 * flag (added server-side via `wp_prepare_attachment_for_js`) is true.
 *
 * The badge deliberately avoids the Content Credentials "CR" trustmark: that
 * mark is reserved for C2PA conforming products and implies validated
 * credentials, whereas this experiment only detects their presence.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './grid.scss';

type C2paData = {
	present?: boolean;
};

type AttachmentView = {
	cid: string;
	el: HTMLElement;
	model: {
		get: ( key: string ) => unknown;
	};
};

type AttachmentViewClass = {
	prototype: {
		render: ( this: AttachmentView, ...args: unknown[] ) => AttachmentView;
	};
};

type MediaWindow = {
	wp?: {
		media?: {
			view?: {
				Attachment?: AttachmentViewClass;
			};
		};
	};
};

const BADGE_CLASS = 'wpai-c2pa-grid-badge';

/**
 * Appends the detection badge to a rendered attachment view.
 *
 * @since x.x.x
 *
 * @param view The attachment view that has just rendered.
 */
const addBadge = ( view: AttachmentView ): void => {
	// Skip the 40px thumbnails in the modal's selection strip.
	if ( view.el.classList.contains( 'selection' ) ) {
		return;
	}

	const data = view.model.get( 'wpaiC2pa' ) as C2paData | undefined;
	if ( ! data?.present ) {
		return;
	}

	const preview = view.el.querySelector( '.attachment-preview' );
	if ( ! preview || preview.querySelector( `.${ BADGE_CLASS }` ) ) {
		return;
	}

	const label = __(
		'C2PA Content Credentials detected (not verified)',
		'ai'
	);

	const badge = document.createElement( 'span' );
	badge.className = `${ BADGE_CLASS } dashicons dashicons-id`;
	badge.title = label;
	badge.setAttribute( 'aria-hidden', 'true' );

	// The grid item's aria-label replaces its content for assistive tech, and
	// core also shows that label as the visible caption in media modals, so
	// the status is attached as a description instead.
	const descriptionId = `${ BADGE_CLASS }-${ view.cid }`;
	const description = document.createElement( 'span' );
	description.id = descriptionId;
	description.className = 'screen-reader-text';
	description.textContent = label;

	preview.append( badge, description );

	const describedBy = view.el.getAttribute( 'aria-describedby' ) ?? '';
	if ( ! describedBy.split( ' ' ).includes( descriptionId ) ) {
		view.el.setAttribute(
			'aria-describedby',
			`${ describedBy } ${ descriptionId }`.trim()
		);
	}
};

/**
 * Wraps the core attachment view's render() so every thumbnail re-render
 * gets the badge.
 *
 * @since x.x.x
 */
const init = (): void => {
	const Attachment = ( window as unknown as MediaWindow ).wp?.media?.view
		?.Attachment;
	if ( ! Attachment ) {
		return;
	}

	const originalRender = Attachment.prototype.render;

	Attachment.prototype.render = function ( ...args: unknown[] ) {
		const result = originalRender.apply( this, args );
		addBadge( this );
		return result;
	};
};

init();
