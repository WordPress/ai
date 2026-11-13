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
import { getErrorMessage } from '../../utils/errors';
import {
	generateAltText,
	type AltTextGenerationResult,
} from '../../utils/generate-alt-text';
import { isProviderAvailable } from '../../utils/provider-status';

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

const GENERATE_BUTTON_SELECTOR = '#ai-alt-text-generate-button';
const MODAL_ALT_TEXT_SELECTOR =
	'#attachment-details-two-column-alt-text, #attachment-details-alt-text';
const EDIT_SCREEN_ALT_TEXT_SELECTOR = '#attachment_alt';

/**
 * Finds the alt text field that belongs to a Generate button.
 *
 * Closed media modals can stay in the DOM, so the lookup is limited to the
 * button's own modal.
 *
 * @since x.x.x
 *
 * @param button The Generate button.
 * @return The alt text field, or null if none is found.
 */
function getAltTextField(
	button: HTMLButtonElement
): HTMLTextAreaElement | null {
	const modal = button.closest( '.media-modal' );

	return modal
		? modal.querySelector< HTMLTextAreaElement >( MODAL_ALT_TEXT_SELECTOR )
		: document.querySelector< HTMLTextAreaElement >(
				EDIT_SCREEN_ALT_TEXT_SELECTOR
		  );
}

/**
 * Updates the button label based on the alt text field value.
 *
 * @since 0.3.0
 *
 * @param button The Generate button.
 * @param field  The alt text field.
 */
function updateButtonLabel(
	button: HTMLButtonElement,
	field: HTMLTextAreaElement
): void {
	button.textContent =
		field.value.trim().length > 0
			? __( 'Regenerate', 'ai' )
			: __( 'Generate', 'ai' );
}

class AltTextMediaControls {
	private context: FieldContext;
	private textarea: HTMLTextAreaElement | null = null;
	private button: HTMLButtonElement | null = null;
	private spinner: HTMLSpanElement | null = null;
	private status: HTMLParagraphElement | null = null;

	/**
	 * Constructs a new AltTextMediaControls instance.
	 *
	 * @since 0.3.0
	 *
	 * @param button The Generate button that was clicked.
	 */
	public constructor( button: HTMLButtonElement ) {
		const postID = button.getAttribute( 'data-attachment-id' );

		this.context = {
			getAttachmentId: () => ( postID ? parseInt( postID, 10 ) : null ),
			getImageUrl: () => null,
		};

		const textarea = getAltTextField( button );
		const container = button.closest< HTMLDivElement >(
			'.ai-alt-text-media-actions'
		);

		if ( ! textarea || ! container ) {
			return;
		}

		this.textarea = textarea;
		this.button = button;
		this.spinner = container.querySelector< HTMLSpanElement >( '.spinner' );
		this.status =
			container.querySelector< HTMLParagraphElement >( '.description' );
	}

	/**
	 * Handles the generate button click.
	 *
	 * The button stays disabled until the request finishes, which blocks
	 * repeat clicks.
	 *
	 * @since 0.3.0
	 */
	public async handleGenerate(): Promise< void > {
		if ( ! this.textarea || ! this.button || ! this.spinner ) {
			return;
		}

		if ( ! isProviderAvailable() ) {
			this.setStatus(
				__(
					'This feature requires a valid AI Connector to function properly. Please set up a provider to use this feature in Settings → Connectors.',
					'ai'
				),
				true
			);
			return;
		}

		this.button.disabled = true;
		this.spinner.classList.add( 'is-active' );
		this.setStatus( __( 'Generating alt text…', 'ai' ) );

		try {
			const result = await requestAltText( this.context );
			this.textarea.value = result.alt_text;
			this.textarea.dispatchEvent(
				new Event( 'input', { bubbles: true } )
			);
			this.textarea.dispatchEvent(
				new Event( 'change', { bubbles: true } )
			);

			const message = result.is_decorative
				? __(
						'Image identified as decorative. Alt text cleared.',
						'ai'
				  )
				: __( 'Alt text generated and applied.', 'ai' );

			this.setStatus( message );
		} catch ( error ) {
			const message = getErrorMessage(
				error,
				__(
					'An unexpected error occurred while generating alt text.',
					'ai'
				)
			);
			this.setStatus( message, true );
		} finally {
			this.button.disabled = false;
			this.spinner.classList.remove( 'is-active' );
			updateButtonLabel( this.button, this.textarea );
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
 * @return The generated alt text result.
 */
async function requestAltText(
	context: FieldContext
): Promise< AltTextGenerationResult > {
	const attachmentId = context.getAttachmentId();
	const imageUrl = context.getImageUrl();

	return await generateAltText(
		attachmentId ?? undefined,
		imageUrl ?? undefined
	);
}

if ( window.aiAltTextGenerationMediaData?.enabled ) {
	// One listener on the document handles every Generate button, on the
	// attachment edit screen and in any media modal, including buttons
	// rendered later. Binding per modal stacked handlers when a modal was
	// reused, so one click sent several requests.
	document.addEventListener( 'click', ( event ) => {
		const button =
			event.target instanceof Element
				? event.target.closest< HTMLButtonElement >(
						GENERATE_BUTTON_SELECTOR
				  )
				: null;

		if ( button ) {
			void new AltTextMediaControls( button ).handleGenerate();
		}
	} );

	// Keep the button label in sync when the alt text is edited by hand.
	document.addEventListener( 'input', ( event ) => {
		const field = event.target;

		if (
			! ( field instanceof HTMLTextAreaElement ) ||
			! field.matches(
				`${ MODAL_ALT_TEXT_SELECTOR }, ${ EDIT_SCREEN_ALT_TEXT_SELECTOR }`
			)
		) {
			return;
		}

		const scope: ParentNode = field.closest( '.media-modal' ) ?? document;
		const button = scope.querySelector< HTMLButtonElement >(
			GENERATE_BUTTON_SELECTOR
		);

		if ( button ) {
			updateButtonLabel( button, field );
		}
	} );
}
