/**
 * Media library integrations for the alt text generation experiment.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../utils/run-ability';

type AbilityResponse = {
	alt_text?: string;
};

type FieldContext = {
	getAttachmentId: () => number | null;
	getImageUrl: () => string | null;
};

type MediaData = {
	enabled: boolean;
};

declare global {
	interface Window {
		aiAltTextGenerationMediaData?: MediaData;
	}
}

const ABILITY_NAME = 'ai/alt-text-generation';
const MEDIA_MODAL_SELECTOR = '.media-sidebar .setting[data-setting="alt"] textarea';
const ATTACHMENT_EDIT_SELECTOR = '#attachment_alt';

class AltTextFieldObserver {
	private processed = new WeakSet< HTMLTextAreaElement >();
	private observer?: MutationObserver;

	public start(): void {
		this.scan( document );
		this.observe();
	}

	private observe(): void {
		if ( this.observer ) {
			return;
		}

		if ( typeof MutationObserver === 'undefined' ) {
			return;
		}

		this.observer = new MutationObserver( ( mutations ) => {
			for ( const mutation of mutations ) {
				mutation.addedNodes.forEach( ( node ) => {
					if ( node instanceof HTMLElement ) {
						this.scan( node );
					}
				} );
			}
		} );

		this.observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
	}

	private scan( root: ParentNode ): void {
		this.queryAltTextFields( root ).forEach( ( textarea ) => {
			if ( this.processed.has( textarea ) ) {
				return;
			}

			const context = createFieldContext( textarea );

			if ( ! context ) {
				return;
			}

			this.processed.add( textarea );
			new AltTextMediaControls( textarea, context );
		} );
	}

	private queryAltTextFields( root: ParentNode ): Array< HTMLTextAreaElement > {
		const fields: Array< HTMLTextAreaElement > = [];
		const selectors = [ MEDIA_MODAL_SELECTOR, ATTACHMENT_EDIT_SELECTOR ];

		selectors.forEach( ( selector ) => {
			root.querySelectorAll< HTMLTextAreaElement >( selector ).forEach( ( field ) => {
				fields.push( field );
			} );
		} );

		return fields;
	}
}

class AltTextMediaControls {
	private textarea: HTMLTextAreaElement;
	private context: FieldContext;
	private container: HTMLDivElement;
	private button: HTMLButtonElement;
	private status: HTMLParagraphElement;
	private spinner: HTMLSpanElement;
	private isGenerating = false;

	public constructor( textarea: HTMLTextAreaElement, context: FieldContext ) {
		this.textarea = textarea;
		this.context = context;
		this.container = document.createElement( 'div' );
		this.container.className = 'ai-alt-text-media-actions';
		this.container.style.marginTop = '8px';

		this.button = document.createElement( 'button' );
		this.button.type = 'button';
		this.button.className = 'button button-secondary';
		this.button.addEventListener( 'click', () => {
			void this.handleGenerate();
		} );
		this.container.appendChild( this.button );

		this.spinner = document.createElement( 'span' );
		this.spinner.className = 'spinner';
		this.spinner.setAttribute( 'aria-hidden', 'true' );
		this.spinner.style.marginLeft = '8px';
		this.container.appendChild( this.spinner );

		this.status = document.createElement( 'p' );
		this.status.className = 'description';
		this.status.style.marginTop = '6px';
		this.status.style.fontSize = '12px';
		this.status.setAttribute( 'aria-live', 'polite' );
		this.container.appendChild( this.status );

		const description = getDescriptionElement( textarea );

		if ( description ) {
			description.insertAdjacentElement( 'beforebegin', this.container );
		} else {
			textarea.insertAdjacentElement( 'afterend', this.container );
		}

		this.updateButtonLabel();

		textarea.addEventListener( 'input', () => {
			this.updateButtonLabel();
		} );
	}

	private updateButtonLabel(): void {
		const hasAlt = this.textarea.value.trim().length > 0;
		this.button.textContent = hasAlt
			? __( 'Regenerate Alt Text', 'ai' )
			: __( 'Generate Alt Text', 'ai' );
	}

	private async handleGenerate(): Promise< void > {
		if ( this.isGenerating ) {
			return;
		}

		this.isGenerating = true;
		this.button.disabled = true;
		this.spinner.classList.add( 'is-active' );
		this.setStatus( __( 'Generating alt text...', 'ai' ) );

		try {
			const generated = await requestAltText( this.context );
			this.textarea.value = generated;
			this.textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			this.textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			this.setStatus( __( 'Alt text generated and applied.', 'ai' ) );
		} catch ( error ) {
			const message = getErrorMessage( error );
			this.setStatus( message, true );
		} finally {
			this.isGenerating = false;
			this.button.disabled = false;
			this.spinner.classList.remove( 'is-active' );
			this.updateButtonLabel();
		}
	}

	private setStatus( message: string, isError = false ): void {
		this.status.textContent = message;
		this.status.style.color = isError ? '#b32d2e' : '#646970';
	}
}

function getDescriptionElement( textarea: HTMLTextAreaElement ): HTMLElement | null {
	const describedBy = textarea.getAttribute( 'aria-describedby' );

	if ( describedBy ) {
		const firstId = describedBy.split( /\s+/ ).find( Boolean );
		if ( firstId ) {
			const described = document.getElementById( firstId );
			if ( described ) {
				return described;
			}
		}
	}

	return textarea.closest( '.setting' )?.querySelector( '.description' ) ?? null;
}

function createFieldContext( textarea: HTMLTextAreaElement ): FieldContext | null {
	const mediaSidebar = textarea.closest< HTMLElement >( '.media-sidebar' );

	if ( mediaSidebar ) {
		const fieldNameId = getAttachmentIdFromFieldName( textarea );

		return {
			getAttachmentId: () => fieldNameId ?? getAttachmentIdFromDetails( mediaSidebar ),
			getImageUrl: () => getFileUrlFromDetails( mediaSidebar ),
		};
	}

	if ( textarea.matches( ATTACHMENT_EDIT_SELECTOR ) ) {
		return {
			getAttachmentId: () => getAttachmentIdFromEditScreen(),
			getImageUrl: () => getAttachmentUrlFromEditScreen(),
		};
	}

	return null;
}

function getAttachmentIdFromDetails( sidebar: HTMLElement ): number | null {
	const datasetId = sidebar.dataset?.id ?? sidebar.dataset?.attachmentId ?? sidebar.getAttribute( 'data-id' ) ?? sidebar.getAttribute( 'data-attachment-id' );
	const parsedDataset = parseNumeric( datasetId );

	if ( parsedDataset ) {
		return parsedDataset;
	}

	const details = sidebar.querySelector< HTMLElement >( '.attachment-details' ) ?? sidebar;
	const editLink = details.querySelector< HTMLAnchorElement >( '.edit-attachment' );
	const idFromLink = editLink ? getIdFromUrl( editLink.href ) : null;

	if ( idFromLink ) {
		return idFromLink;
	}

	const deleteButton = details.querySelector< HTMLElement >( '.delete-attachment' );
	const deleteId = deleteButton?.getAttribute( 'data-id' );

	if ( deleteId ) {
		const parsedDelete = parseNumeric( deleteId );
		if ( parsedDelete ) {
			return parsedDelete;
		}
	}

	const hiddenId = sidebar.querySelector< HTMLInputElement >( 'input[name="id"], input[name="attachment[id]"]' );
	const parsedHidden = parseNumeric( hiddenId?.value ?? null );

	if ( parsedHidden ) {
		return parsedHidden;
	}

	const compatField = sidebar.querySelector< HTMLInputElement >( 'input[name^="attachments["]' );

	if ( compatField ) {
		const match = compatField.name.match( /^attachments\[(\d+)\]/ );
		if ( match ) {
			return parseNumeric( match[1] );
		}
	}

	return null;
}

function getFileUrlFromDetails( sidebar: HTMLElement ): string | null {
	const copyField = sidebar.querySelector< HTMLInputElement >( '.attachment-details-copy-link' );
	if ( copyField?.value ) {
		return copyField.value;
	}

	const thumbnail = sidebar.querySelector< HTMLImageElement >( '.thumbnail img, .thumbnail-image img' );
	if ( thumbnail?.src && ! thumbnail.src.startsWith( 'blob:' ) ) {
		return thumbnail.src;
	}

	return null;
}

function getAttachmentIdFromEditScreen(): number | null {
	const postIdInput = document.querySelector< HTMLInputElement >( '#post_ID, input[name="post_ID"]' );
	return parseNumeric( postIdInput?.value ?? null );
}

function getAttachmentUrlFromEditScreen(): string | null {
	const urlInput = document.querySelector< HTMLInputElement >( '#attachment_url input[type="text"], #attachment_url textarea' );
	if ( urlInput?.value ) {
		return urlInput.value;
	}

	const urlAnchor = document.querySelector< HTMLAnchorElement >( '#attachment_url a' );
	if ( urlAnchor?.href ) {
		return urlAnchor.href;
	}

	return null;
}

function parseNumeric( value: string | null | undefined ): number | null {
	if ( ! value ) {
		return null;
	}

	const int = parseInt( value, 10 );

	return Number.isFinite( int ) ? int : null;
}

function getAttachmentIdFromFieldName( field: HTMLTextAreaElement ): number | null {
	const nameAttr = field.getAttribute( 'name' );

	if ( ! nameAttr ) {
		return null;
	}

	const match = nameAttr.match( /^attachments\[(\d+)\]/ );

	return match ? parseNumeric( match[1] ) : null;
}

function getIdFromUrl( url: string ): number | null {
	try {
		const parsed = new URL( url, window.location.origin );
		const postId = parsed.searchParams.get( 'post' ) || parsed.searchParams.get( 'item' );
		return parseNumeric( postId );
	} catch ( error ) {
		return null;
	}
}

async function requestAltText( context: FieldContext ): Promise< string > {
	const params: Record< string, unknown > = {};
	const attachmentId = context.getAttachmentId();

	if ( attachmentId ) {
		params.attachment_id = attachmentId;
	} else {
		const imageUrl = context.getImageUrl();
		if ( imageUrl ) {
			params.image_url = imageUrl;
		}
	}

	if ( Object.keys( params ).length === 0 ) {
		throw new Error( __( 'Unable to determine which image to describe.', 'ai' ) );
	}

	const response = await runAbility< AbilityResponse >( ABILITY_NAME, params );

	if ( response?.alt_text ) {
		return response.alt_text;
	}

	throw new Error( __( 'Failed to generate alt text.', 'ai' ) );
}

function getErrorMessage( error: unknown ): string {
	if ( error && typeof error === 'object' && 'message' in error && typeof ( error as any ).message === 'string' ) {
		return ( error as any ).message;
	}

	return __( 'An unexpected error occurred while generating alt text.', 'ai' );
}

domReady( () => {
	const data = window.aiAltTextGenerationMediaData;

	if ( ! data?.enabled ) {
		return;
	}

	const observer = new AltTextFieldObserver();
	observer.start();
} );
