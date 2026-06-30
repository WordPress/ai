/**
 * Suggest reply experiment
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';

type Tone = 'friendly' | 'professional' | 'casual';

const LOADING_TEXT = __( 'Generating…', 'ai' );
const ORIGINAL_LINK_TEXT = __( 'Suggest reply', 'ai' );
const SUGGEST_BTN_TEXT = __( 'Suggest Reply', 'ai' );
const LOADING_PLACEHOLDER = __( 'Generating AI reply…', 'ai' );
const GENERIC_ERROR_MESSAGE = __(
	'Failed to generate a reply suggestion. Please try again.',
	'ai'
);
const ERROR_NOTICE_ID = 'wpai-suggest-reply-error';
const CONTROLS_WRAPPER_ID = 'wpai-suggest-reply-controls';
const SUGGEST_BTN_ID = 'wpai-suggest-reply-btn';
const DEFAULT_TONE: Tone = 'friendly';
const REPLY_FORM_POLL_INTERVAL = 30;
const REPLY_FORM_POLL_TIMEOUT = 1000;
const INIT_FLAG_ATTR = 'data-wpai-suggest-reply-initialized';

const TONE_OPTIONS: { label: string; value: Tone }[] = [
	{ label: __( 'Friendly', 'ai' ), value: 'friendly' },
	{ label: __( 'Professional', 'ai' ), value: 'professional' },
	{ label: __( 'Casual', 'ai' ), value: 'casual' },
];

/** Writes text into the inline reply textarea and focuses it. */
function populateReplyTextarea( text: string ): void {
	const textarea = document.querySelector< HTMLTextAreaElement >(
		'#replycontainer #replycontent'
	);

	if ( ! textarea ) {
		return;
	}

	textarea.value = text;
	textarea.focus();
	textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

/** Sets or clears a placeholder on the inline reply textarea. */
function setTextareaPlaceholder( message: string ): void {
	const textarea = document.querySelector< HTMLTextAreaElement >(
		'#replycontainer #replycontent'
	);

	if ( textarea ) {
		textarea.placeholder = message;
	}
}

/**
 * Disables or re-enables the textarea, Reply button, Cancel button, and
 * in-editor Suggest Reply button + Tone select during generation.
 */
function setReplyFormDisabled( disabled: boolean ): void {
	const elements = [
		document.querySelector< HTMLTextAreaElement >( '#replycontent' ),
		document.querySelector< HTMLButtonElement >( '#replysubmit .save' ),
		document.querySelector< HTMLButtonElement >( '#replysubmit .cancel' ),
		document.getElementById( SUGGEST_BTN_ID ) as HTMLButtonElement | null,
		document.querySelector< HTMLButtonElement >(
			'.wpai-split-button__toggle'
		),
	];

	document
		.querySelectorAll< HTMLButtonElement >( '.wpai-dropdown-item' )
		.forEach( ( item ) => elements.push( item ) );

	elements.forEach( ( el ) => {
		if ( el ) {
			( el as HTMLInputElement ).disabled = disabled;
		}
	} );
}

/** Removes any previously injected error notice. */
function clearErrorNotice(): void {
	document.getElementById( ERROR_NOTICE_ID )?.remove();
}

/** Shows an error notice. */
function showErrorNotice( message: string ): void {
	clearErrorNotice();

	const container = document.querySelector( '.reply-submit-buttons' );

	if ( ! container ) {
		return;
	}

	const notice = document.createElement( 'div' );
	notice.id = ERROR_NOTICE_ID;
	notice.className =
		'notice notice-error notice-alt inline wpai-suggest-reply-error';
	
	const p = document.createElement( 'p' );
	p.textContent = message;
	notice.appendChild( p );

	container.appendChild( notice );
}

/** Sets the row-action link into a loading/idle state. */
function setLinkLoading( link: HTMLElement, loading: boolean ): void {
	if ( loading ) {
		link.textContent = LOADING_TEXT;
		link.setAttribute( 'aria-disabled', 'true' );
		link.classList.add( 'wpai-suggest-reply-link-loading' );
	} else {
		link.textContent = ORIGINAL_LINK_TEXT;
		link.removeAttribute( 'aria-disabled' );
		link.classList.remove( 'wpai-suggest-reply-link-loading' );
	}
}

/** Sets the in-editor Suggest Reply button into a loading/idle state. */
function setSuggestBtnLoading( loading: boolean ): void {
	const btn = document.getElementById(
		SUGGEST_BTN_ID
	) as HTMLButtonElement | null;

	if ( btn ) {
		btn.disabled = loading;
		btn.textContent = loading ? LOADING_TEXT : SUGGEST_BTN_TEXT;
	}

	if ( loading ) {
		// Close the dropdown menu when generation starts
		const dropdownMenu = document.querySelector< HTMLElement >(
			'.wpai-split-button__dropdown'
		);
		const toggleBtn = document.querySelector< HTMLButtonElement >(
			'.wpai-split-button__toggle'
		);
		
		if ( dropdownMenu ) {
			dropdownMenu.hidden = true;
		}
		if ( toggleBtn ) {
			toggleBtn.setAttribute( 'aria-expanded', 'false' );
		}
	}
}

/** Returns the tone currently selected in the button dropdown. */
function getSelectedTone(): Tone {
	const container =
		document.querySelector< HTMLElement >( '.wpai-split-button' );

	return ( container?.dataset['tone'] as Tone ) ?? DEFAULT_TONE;
}

/** Creates the Suggest Reply Button. */
function createSplitButtonControls(): HTMLElement {
	let currentTone: Tone = DEFAULT_TONE;

	const container = document.createElement( 'div' );
	container.className = 'wpai-split-button';
	container.dataset['tone'] = currentTone;

	const actionBtn = document.createElement( 'button' );
	actionBtn.id = SUGGEST_BTN_ID;
	actionBtn.type = 'button';
	actionBtn.className = 'button wpai-split-button__action';
	actionBtn.textContent = SUGGEST_BTN_TEXT;

	actionBtn.addEventListener( 'click', () => {
		const commentIdInput = document.querySelector< HTMLInputElement >(
			'#replyrow #comment_ID'
		);
		const commentId = parseInt( commentIdInput?.value ?? '0', 10 );

		if ( commentId > 0 ) {
			void runGenerationFromEditor( commentId );
		}
	} );

	const toggleBtn = document.createElement( 'button' );
	toggleBtn.type = 'button';
	toggleBtn.className = 'button wpai-split-button__toggle';
	toggleBtn.setAttribute( 'aria-expanded', 'false' );
	toggleBtn.setAttribute( 'aria-haspopup', 'true' );
	toggleBtn.setAttribute( 'aria-label', __( 'Change reply tone', 'ai' ) );
	toggleBtn.innerHTML =
		'<span class="dashicons dashicons-arrow-down-alt2"></span>';

	const dropdownMenu = document.createElement( 'div' );
	dropdownMenu.className = 'wpai-split-button__dropdown';
	dropdownMenu.hidden = true;

	const updateSelectionUI = () => {
		dropdownMenu
			.querySelectorAll( '.wpai-dropdown-item' )
			.forEach( ( item ) => {
				if ( ( item as HTMLElement ).dataset['tone'] === currentTone ) {
					item.classList.add( 'is-selected' );
					item.setAttribute( 'aria-selected', 'true' );
				} else {
					item.classList.remove( 'is-selected' );
					item.setAttribute( 'aria-selected', 'false' );
				}
			} );

		container.dataset['tone'] = currentTone;
	};

	TONE_OPTIONS.forEach( ( { label, value } ) => {
		const itemBtn = document.createElement( 'button' );
		itemBtn.type = 'button';
		itemBtn.className = 'wpai-dropdown-item';
		itemBtn.dataset['tone'] = value;
		itemBtn.innerHTML = `<span class="dashicons dashicons-yes wpai-selected-icon"></span> ${ label }`;

		itemBtn.addEventListener( 'click', () => {
			currentTone = value as Tone;
			updateSelectionUI();
			dropdownMenu.hidden = true;
			toggleBtn.setAttribute( 'aria-expanded', 'false' );
		} );

		dropdownMenu.appendChild( itemBtn );
	} );

	updateSelectionUI();

	// Open and Close the dropdown menu
	toggleBtn.addEventListener( 'click', () => {
		const isExpanded = toggleBtn.getAttribute( 'aria-expanded' ) === 'true';
		toggleBtn.setAttribute(
			'aria-expanded',
			isExpanded ? 'false' : 'true'
		);

		dropdownMenu.hidden = isExpanded;
	} );

	// Close dropdown when clicking outside
	document.addEventListener( 'click', ( e ) => {
		if ( ! container.contains( e.target as Node ) ) {
			dropdownMenu.hidden = true;
			toggleBtn.setAttribute( 'aria-expanded', 'false' );
		}
	} );

	container.appendChild( actionBtn );
	container.appendChild( toggleBtn );
	container.appendChild( dropdownMenu );

	return container;
}

/**
 * Injects the Suggest Reply button and Tone dropdown into the WP inline reply
 * form as a dedicated row below the native Reply / Cancel buttons.
 */
function injectSuggestReplyControls(): void {
	if ( document.getElementById( CONTROLS_WRAPPER_ID ) ) {
		return;
	}

	const buttonArea = document.querySelector< HTMLElement >(
		'#replysubmit .reply-submit-buttons'
	);

	if ( ! buttonArea ) {
		return;
	}

	const wrapper = document.createElement( 'span' );
	wrapper.id = CONTROLS_WRAPPER_ID;
	wrapper.className = 'wpai-suggest-reply-controls';

	wrapper.appendChild( createSplitButtonControls() );

	const spinner = buttonArea.querySelector( '.waiting.spinner' );

	if ( spinner ) {
		buttonArea.insertBefore( wrapper, spinner );
	} else {
		buttonArea.appendChild( wrapper );
	}
}

/**
 * Shared generation logic for both entry points: clears any previous error,
 * shows a loading placeholder, disables the form, calls the REST ability,
 * then populates the textarea with the result (or shows an error notice).
 */
async function runGeneration( commentId: number, tone: Tone ): Promise< void > {
	clearErrorNotice();
	setTextareaPlaceholder( LOADING_PLACEHOLDER );
	setReplyFormDisabled( true );

	try {
		const result = await runAbility< string >( 'ai/suggest-reply', {
			comment_id: commentId,
			tone,
		} );

		setTextareaPlaceholder( '' );
		populateReplyTextarea( result ?? '' );
	} catch ( err: any ) {
		setTextareaPlaceholder( '' );

		const message = err.message ? err.message : GENERIC_ERROR_MESSAGE;

		showErrorNotice( message );
	} finally {
		setReplyFormDisabled( false );
	}
}

/**
 * Triggered by the in-editor Suggest Reply button.
 * Uses the tone currently selected in the inline Tone dropdown.
 */
async function runGenerationFromEditor( commentId: number ): Promise< void > {
	const tone = getSelectedTone();

	setSuggestBtnLoading( true );

	await runGeneration( commentId, tone );

	setSuggestBtnLoading( false );
}

/**
 * Triggered by the "Suggest reply" row action link.
 * Opens the inline reply form (if not already open) for the given comment,
 * then generates a reply using the currently selected Tone.
 */
async function generateAndInsertReply(
	commentId: number,
	link: HTMLElement
): Promise< void > {
	const tone = getSelectedTone();

	setLinkLoading( link, true );

	openReplyFormThen( commentId, async () => {
		await runGeneration( commentId, tone );

		setLinkLoading( link, false );
	} );
}

/**
 * Returns true when the WordPress inline reply form is already open for the
 * given comment.
 */
function isInlineReplyOpenForComment( commentId: number ): boolean {
	const replyRow = document.querySelector< HTMLElement >( '#replyrow' );
	const commentIdInput = document.querySelector< HTMLInputElement >(
		'#replyrow #comment_ID'
	);

	if ( ! replyRow || ! commentIdInput ) {
		return false;
	}

	const isVisible =
		replyRow.style.display !== 'none' && replyRow.offsetParent !== null;
	const isForComment = parseInt( commentIdInput.value, 10 ) === commentId;

	return isVisible && isForComment;
}

/**
 * Opens the WP inline reply form for the given comment and calls the callback
 * once it is visible.
 */
function openReplyFormThen( commentId: number, callback: () => void ): void {
	if ( isInlineReplyOpenForComment( commentId ) ) {
		callback();
		return;
	}

	const replyButton = document.querySelector< HTMLButtonElement >(
		`#comment-${ commentId } .reply button`
	);

	if ( replyButton ) {
		replyButton.click();
	}

	const startTime = Date.now();

	const poll = () => {
		if ( isInlineReplyOpenForComment( commentId ) ) {
			callback();
			return;
		}

		if ( Date.now() - startTime >= REPLY_FORM_POLL_TIMEOUT ) {
			showErrorNotice( GENERIC_ERROR_MESSAGE );
			return;
		}

		window.setTimeout( poll, REPLY_FORM_POLL_INTERVAL );
	};

	window.setTimeout( poll, REPLY_FORM_POLL_INTERVAL );
}

/**
 * Attaches a delegated click listener on the comment list so that clicking a
 * "Suggest reply" row action link immediately opens the reply form and starts
 * AI generation.
 */
export function init(): void {
	const commentList = document.querySelector( '#the-comment-list' );

	if ( ! commentList ) {
		return;
	}

	injectSuggestReplyControls();

	if ( commentList.hasAttribute( INIT_FLAG_ATTR ) ) {
		return;
	}

	commentList.setAttribute( INIT_FLAG_ATTR, 'true' );

	commentList.addEventListener( 'click', ( event: Event ) => {
		const target = event.target as HTMLElement;

		if ( ! target.classList.contains( 'wpai-suggest-reply' ) ) {
			return;
		}

		if ( target.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}

		event.preventDefault();

		const commentId = parseInt(
			target.getAttribute( 'data-comment-id' ) ?? '0',
			10
		);

		if ( commentId > 0 ) {
			void generateAndInsertReply( commentId, target );
		}
	} );
}
