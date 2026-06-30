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
import './index.scss';

type Tone = 'friendly' | 'professional' | 'casual';

const LOADING_TEXT = __( 'Generating…', 'ai' );
const ORIGINAL_LINK_TEXT = __( 'Suggest reply', 'ai' );
const SUGGEST_BTN_TEXT = __( 'Suggest Reply', 'ai' );
const LOADING_PLACEHOLDER = __( 'Generating AI reply…', 'ai' );
const GENERIC_ERROR_MESSAGE = __(
	'Failed to generate a reply suggestion. Please try again.',
	'ai'
);
const TONE_LABEL = __( 'Tone:', 'ai' );
const ERROR_NOTICE_ID = 'wpai-suggest-reply-error';
const CONTROLS_WRAPPER_ID = 'wpai-suggest-reply-controls';
const SUGGEST_BTN_ID = 'wpai-suggest-reply-btn';
const TONE_SELECT_ID = 'wpai-tone-select';
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
		document.getElementById( TONE_SELECT_ID ) as HTMLSelectElement | null,
	];

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
	notice.innerHTML = `<p>${ message }</p>`;

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
}

/** Returns the tone currently selected in the inline Tone dropdown. */
function getSelectedTone(): Tone {
	const select = document.getElementById(
		TONE_SELECT_ID
	) as HTMLSelectElement | null;

	return ( select?.value as Tone ) ?? DEFAULT_TONE;
}

/** Creates the Suggest Reply button. */
function createSuggestReplyButton(): HTMLButtonElement {
	const btn = document.createElement( 'button' );
	btn.id = SUGGEST_BTN_ID;
	btn.type = 'button';
	btn.className = 'button';
	btn.textContent = SUGGEST_BTN_TEXT;

	btn.addEventListener( 'click', () => {
		const commentIdInput = document.querySelector< HTMLInputElement >(
			'#replyrow #comment_ID'
		);
		const commentId = parseInt( commentIdInput?.value ?? '0', 10 );

		if ( commentId > 0 ) {
			void runGenerationFromEditor( commentId );
		}
	} );

	return btn;
}

/**
 * Creates a DocumentFragment containing the "Tone:" label and the tone
 * dropdown, ready to be appended together.
 */
function createToneControl(): DocumentFragment {
	const fragment = document.createDocumentFragment();

	const label = document.createElement( 'label' );
	label.htmlFor = TONE_SELECT_ID;
	label.textContent = TONE_LABEL;

	const select = document.createElement( 'select' );
	select.id = TONE_SELECT_ID;

	TONE_OPTIONS.forEach( ( { label: optLabel, value } ) => {
		const option = document.createElement( 'option' );
		option.value = value;
		option.textContent = optLabel;
		select.appendChild( option );
	} );

	fragment.appendChild( label );
	fragment.appendChild( select );

	return fragment;
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

	wrapper.appendChild( createSuggestReplyButton() );
	wrapper.appendChild( createToneControl() );

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
		const result = await runAbility< string >( 'ai/reply-suggestion', {
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
