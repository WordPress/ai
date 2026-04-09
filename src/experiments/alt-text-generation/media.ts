/**
 * Media library integrations for alt text generation.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AltTextGenerationAbilityInput } from './types';
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

type WordPressMedia = {
	media?: {
		view?: {
			Modal: {
				prototype: {
					on: ( event: string, cb: () => void ) => void;
				};
			};
		};
		frame?: { on: ( event: string, cb: unknown ) => void };
	};
	Uploader?: { queue?: { on: ( event: string, cb: unknown ) => void } };
};

declare global {
	interface Window {
		aiAltTextGenerationMediaData?: MediaData;
		wp?: WordPressMedia;
		jQuery?: ( selector: Document | Element | string ) => {
			ready: ( fn: () => void ) => void;
		};
	}
}

const ABILITY_NAME = 'ai/alt-text-generation';

class AltTextMediaControls {
	private context: FieldContext;
	private textarea: HTMLTextAreaElement | null = null;
	private button: HTMLButtonElement | null = null;
	private status: HTMLParagraphElement | null = null;
	private isGenerating = false;
	private static stylesInjected = false;

	/**
	 * Constructs a new AltTextMediaControls instance.
	 *
	 * @since 0.3.0
	 */
	public constructor() {
		AltTextMediaControls.injectStyles();

		this.context = {
			getAttachmentId: () => null,
			getImageUrl: () => null,
		};
		const textarea =
			document.querySelector< HTMLTextAreaElement >(
				'#attachment-details-two-column-alt-text'
			) ??
			document.querySelector< HTMLTextAreaElement >(
				'#attachment-details-alt-text'
			) ??
			document.querySelector< HTMLTextAreaElement >( '#attachment_alt' );
		const container = document.querySelector< HTMLDivElement >(
			'.ai-alt-text-media-actions'
		);
		const button = document.querySelector< HTMLButtonElement >(
			'#ai-alt-text-generate-button'
		);

		if ( ! textarea || ! container || ! button ) {
			return;
		}

		this.textarea = textarea;
		this.button = button;
		this.status =
			container.querySelector< HTMLParagraphElement >( '.description' );

		button.addEventListener( 'click', ( e ) => {
			const postID = ( e.target as HTMLButtonElement ).getAttribute(
				'data-attachment-id'
			);

			if ( postID ) {
				this.context = {
					getAttachmentId: () => parseInt( postID, 10 ),
					getImageUrl: () => null,
				};
			}

			void this.handleGenerate();
		} );

		textarea.addEventListener( 'input', () => {
			this.updateButtonLabel();
		} );

		this.syncInitialButtonLabel();
	}

	/**
	 * Updates the button label based on the textarea value.
	 *
	 * @since 0.3.0
	 */
	private updateButtonLabel(): void {
		if ( ! this.textarea || ! this.button ) {
			return;
		}

		const hasAlt = this.textarea.value.trim().length > 0;
		this.button.textContent = hasAlt
			? __( 'Regenerate', 'ai' )
			: __( 'Generate', 'ai' );
	}

	/**
	 * Syncs the button label after the media UI finishes populating the field.
	 *
	 * The attachment alt-text field can be hydrated asynchronously in the media
	 * modal, so we re-check it on the next paint and shortly after mount.
	 *
	 * @since 0.6.0
	 */
	private syncInitialButtonLabel(): void {
		this.updateButtonLabel();

		window.requestAnimationFrame( () => {
			this.updateButtonLabel();
		} );

		window.setTimeout( () => {
			this.updateButtonLabel();
		}, 50 );
	}

	/**
	 * Updates the visual busy state to match Gutenberg buttons.
	 *
	 * @since 0.6.0
	 *
	 * @param isBusy Whether the button is busy.
	 */
	private setBusyState( isBusy: boolean ): void {
		if ( ! this.button ) {
			return;
		}

		this.button.disabled = isBusy;
		this.button.classList.toggle( 'is-busy', isBusy );
		this.button.classList.toggle( 'ai-alt-text-generate-button--busy', isBusy );
		this.button.setAttribute( 'aria-disabled', isBusy ? 'true' : 'false' );

		if ( isBusy ) {
			this.button.innerHTML = `
				<span class="ai-alt-text-generate-button__content">
					<span class="dashicons dashicons-update ai-alt-text-generate-button__spinner" aria-hidden="true"></span>
					<span class="ai-alt-text-generate-button__label">${ __(
						'Generating…',
						'ai'
					) }</span>
				</span>
			`;
		}
	}

	/**
	 * Injects the admin-side styles for the custom busy button state.
	 *
	 * @since 0.6.0
	 */
	private static injectStyles(): void {
		if ( AltTextMediaControls.stylesInjected ) {
			return;
		}

		const style = document.createElement( 'style' );
		style.textContent = `
			.ai-alt-text-media-actions .ai-alt-text-generate-button--busy {
				background: repeating-linear-gradient(
					-45deg,
					#f6f7f7,
					#f6f7f7 16px,
					#eef0f1 16px,
					#eef0f1 32px
				);
				border-color: #dcdcde;
				color: #50575e;
			}

			.ai-alt-text-media-actions .ai-alt-text-generate-button__content {
				align-items: center;
				display: inline-flex;
				gap: 8px;
			}

			.ai-alt-text-media-actions .ai-alt-text-generate-button__spinner {
				animation: ai-alt-text-generate-spin 0.8s linear infinite;
				display: inline-flex;
				flex: 0 0 auto;
				font-size: 16px;
				height: 16px;
				width: 16px;
			}

			@keyframes ai-alt-text-generate-spin {
				from {
					transform: rotate( 0deg );
				}

				to {
					transform: rotate( 360deg );
				}
			}
		`;
		document.head.appendChild( style );
		AltTextMediaControls.stylesInjected = true;
	}

	/**
	 * Handles the generate button click.
	 *
	 * @since 0.3.0
	 *
	 * @return The generated alt text.
	 */
	private async handleGenerate(): Promise< void > {
		if ( this.isGenerating || ! this.textarea || ! this.button ) {
			return;
		}

		this.isGenerating = true;
		this.setBusyState( true );
		this.setStatus( __( 'Generating alt text…', 'ai' ) );

		try {
			const generated = await requestAltText( this.context );
			this.textarea.value = generated;
			this.textarea.dispatchEvent(
				new Event( 'input', { bubbles: true } )
			);
			this.textarea.dispatchEvent(
				new Event( 'change', { bubbles: true } )
			);
			this.setStatus( __( 'Alt text generated and applied.', 'ai' ) );
		} catch ( error ) {
			const message = getErrorMessage( error );
			this.setStatus( message, true );
		} finally {
			this.isGenerating = false;
			this.setBusyState( false );
			this.updateButtonLabel();
		}
	}

	/**
	 * Sets the status message.
	 *
	 * @since 0.3.0
	 *
	 * @param message The message to set.
	 * @param isError Whether the message is an error.
	 */
	private setStatus( message: string, isError = false ): void {
		if ( ! this.status ) {
			return;
		}

		this.status.textContent = message;
		this.status.style.color = isError ? '#b32d2e' : '#646970';
	}
}

/**
 * Requests alt text from the AI ability.
 *
 * @since 0.3.0
 *
 * @param context The field context.
 * @return The generated alt text.
 */
async function requestAltText( context: FieldContext ): Promise< string > {
	const params: AltTextGenerationAbilityInput = {};
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
		throw new Error(
			__( 'Unable to determine which image to describe.', 'ai' )
		);
	}

	const response = await runAbility< AbilityResponse >(
		ABILITY_NAME,
		params
	);

	if ( response?.alt_text ) {
		return response.alt_text;
	}

	throw new Error( __( 'Failed to generate alt text.', 'ai' ) );
}

/**
 * Gets the error message from the error object.
 *
 * @since 0.3.0
 *
 * @param error The error object.
 * @return The error message.
 */
function getErrorMessage( error: unknown ): string {
	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof ( error as any ).message === 'string'
	) {
		return ( error as any ).message;
	}

	return __(
		'An unexpected error occurred while generating alt text.',
		'ai'
	);
}

/**
 * Initializes the AltTextMediaControls instance.
 *
 * @since 0.3.0
 */
function initAltTextMediaControls(): void {
	new AltTextMediaControls();
}

// Purposely using document.ready here as domReady fires before wp.media is fully loaded.
window.jQuery?.( document ).ready( function () {
	const data = window.aiAltTextGenerationMediaData;

	if ( ! data?.enabled ) {
		return;
	}

	// When on the attachment edit screen.
	if ( document.querySelector( '#ai_alt_text_generation' ) ) {
		initAltTextMediaControls();
	}

	const { wp: wpMedia } = window;

	if ( ! wpMedia?.media ) {
		return;
	}

	// When selecting an image in the media modal.
	wpMedia.media?.view?.Modal?.prototype?.on( 'open', function () {
		wpMedia?.media?.frame?.on(
			'selection:toggle',
			initAltTextMediaControls
		);
	} );

	// When editing an attachment in the media library.
	wpMedia.media?.frame?.on( 'edit:attachment', initAltTextMediaControls );

	// For newly uploaded media.
	wpMedia.Uploader?.queue?.on( 'reset', initAltTextMediaControls );
} );
