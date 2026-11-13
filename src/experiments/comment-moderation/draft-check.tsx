/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './draft-check.scss';

const DEBOUNCE_MS = 1500;
const TIMEOUT_MS = 4000;
const MIN_IDLE_LENGTH = 20;

type Verdict = 'allowed' | 'blocked' | 'unavailable';
type SubmitAttempt = { text: string; submitter: HTMLElement | null };

async function checkDraft(
	url: string,
	nonce: string,
	postId: number,
	text: string
): Promise< Verdict > {
	const controller = new AbortController();
	const timeout = window.setTimeout( () => controller.abort(), TIMEOUT_MS );

	let verdict: Verdict = 'unavailable';
	try {
		const response = await fetch( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				...( nonce ? { 'X-WP-Nonce': nonce } : {} ),
			},
			body: JSON.stringify( { post_id: postId, text } ),
			signal: controller.signal,
		} );

		if ( response.ok ) {
			const result = await response.json();
			if ( typeof result?.blocked === 'boolean' ) {
				verdict = result.blocked ? 'blocked' : 'allowed';
			}
		}
	} catch {
		// Let WordPress handle submission when the draft check is unavailable.
	} finally {
		clearTimeout( timeout );
	}

	return verdict;
}

function enhanceCommentForm( marker: HTMLElement, index: number ): void {
	const form = marker.closest( 'form' );
	const textarea = form?.querySelector< HTMLTextAreaElement >(
		'textarea[name="comment"]'
	);
	const button = form?.querySelector< HTMLButtonElement | HTMLInputElement >(
		'input[type="submit"], button[type="submit"], button:not([type])'
	);
	const postId = Number(
		form?.querySelector< HTMLInputElement >(
			'input[name="comment_post_ID"]'
		)?.value
	);
	const parentField = form?.querySelector< HTMLInputElement >(
		'input[name="comment_parent"]'
	);
	const url = marker.getAttribute( 'data-wpai-comment-draft-check' );
	const nonce = marker.getAttribute( 'data-nonce' ) ?? '';

	if ( ! form || ! textarea || ! button || ! url || ! postId ) {
		return;
	}
	const field = textarea;
	const submitButton = button;
	const endpoint = url;

	const notice = document.createElement( 'p' );
	notice.id = `wpai-comment-draft-notice-${ index }`;
	notice.className = 'wpai-comment-draft-notice';
	notice.textContent = __(
		'Your comment text is sent to this site’s AI provider for review before you post.',
		'ai'
	);

	const status = document.createElement( 'div' );
	status.id = `wpai-comment-draft-status-${ index }`;
	status.className = 'wpai-comment-draft-status';
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-atomic', 'true' );
	const statusRoot = createRoot( status );
	const checkingMessage = __( 'Checking your comment…', 'ai' );
	( textarea.closest( '.comment-form-comment' ) ?? textarea ).before(
		notice,
		status
	);
	const descriptions = textarea.getAttribute( 'aria-describedby' );
	textarea.setAttribute(
		'aria-describedby',
		[ descriptions, notice.id, status.id ].filter( Boolean ).join( ' ' )
	);

	let timer: ReturnType< typeof setTimeout >;
	let checked:
		| { text: string; verdict: Exclude< Verdict, 'unavailable' > }
		| undefined;
	let running: { text: string; result: Promise< Verdict > } | undefined;
	let submitting: SubmitAttempt | undefined;
	let composing = false;
	let locked = false;
	let resubmitting = false;

	function showVerdict( verdict: Verdict | undefined ): void {
		if ( verdict === 'blocked' ) {
			const restoreFocus =
				submitButton.ownerDocument.activeElement === submitButton;
			// Only undo a disabled state we set ourselves.
			locked ||= ! submitButton.disabled;
			submitButton.disabled = true;
			statusRoot.render(
				<Notice status="error" isDismissible={ false } spokenMessage="">
					{ __(
						'Your comment may contain abusive language. Please revise your comment and try again.',
						'ai'
					) }
				</Notice>
			);
			if ( restoreFocus ) {
				field.focus();
			}
			return;
		}
		if ( locked ) {
			submitButton.disabled = false;
			locked = false;
		}
		statusRoot.render( null );
	}

	function analyze(
		text: string,
		attempt?: SubmitAttempt
	): Promise< Verdict > {
		if ( running?.text === text ) {
			return running.result;
		}

		// A newer draft can wait for an older check, but never use its verdict.
		if ( running ) {
			return running.result.then( () => {
				if (
					field.value !== text ||
					composing ||
					( attempt && submitting !== attempt )
				) {
					return 'unavailable';
				}
				return analyze( text, attempt );
			} );
		}

		if ( checked?.text === text ) {
			return Promise.resolve( checked.verdict );
		}

		statusRoot.render( checkingMessage );
		const result = checkDraft( endpoint, nonce, postId, text ).then(
			( verdict ) => {
				running = undefined;
				if (
					field.value === text &&
					! composing &&
					( ! attempt || submitting === attempt )
				) {
					if ( verdict !== 'unavailable' ) {
						checked = { text, verdict };
					}
					showVerdict( verdict );
				}
				return verdict;
			}
		);
		running = { text, result };
		return result;
	}

	function onEdit(): void {
		clearTimeout( timer );
		const text = field.value;
		if ( submitting?.text !== text ) {
			submitting = undefined;
		}
		if ( checked?.text !== text ) {
			checked = undefined;
			showVerdict( undefined );
		}
		if ( ! composing && text.trim().length >= MIN_IDLE_LENGTH ) {
			timer = setTimeout( () => {
				if ( field.value === text ) {
					void analyze( text );
				}
			}, DEBOUNCE_MS );
		}
	}

	textarea.addEventListener( 'input', onEdit );
	textarea.addEventListener( 'compositionstart', () => {
		composing = true;
		onEdit();
	} );
	textarea.addEventListener( 'compositionend', () => {
		composing = false;
		onEdit();
	} );

	form.addEventListener( 'submit', ( event: SubmitEvent ) => {
		if ( event.defaultPrevented || resubmitting ) {
			return;
		}

		clearTimeout( timer );
		if ( composing ) {
			event.preventDefault();
			return;
		}

		const text = textarea.value;
		if (
			! text.trim() ||
			( checked?.text === text && checked.verdict !== 'blocked' )
		) {
			return;
		}

		event.preventDefault();
		if ( checked?.text === text && checked.verdict === 'blocked' ) {
			textarea.focus();
			return;
		}
		if ( submitting?.text === text ) {
			return;
		}

		const attempt = { text, submitter: event.submitter };
		const parent = parentField?.value;
		submitting = attempt;
		statusRoot.render( checkingMessage );

		let submitTimeout: ReturnType< typeof setTimeout >;
		const deadline = new Promise< Verdict >( ( resolve ) => {
			submitTimeout = setTimeout(
				() => resolve( 'unavailable' ),
				TIMEOUT_MS
			);
		} );

		void Promise.race( [ analyze( text, attempt ), deadline ] ).then(
			( verdict ) => {
				clearTimeout( submitTimeout );
				if ( submitting !== attempt ) {
					return;
				}
				submitting = undefined;

				if (
					textarea.value !== text ||
					composing ||
					parentField?.value !== parent
				) {
					if ( verdict !== 'blocked' && textarea.value === text ) {
						showVerdict( undefined );
					}
					return;
				}
				if ( verdict !== 'unavailable' ) {
					checked = { text, verdict };
				}
				showVerdict( verdict );
				if ( verdict === 'blocked' ) {
					textarea.focus();
					return;
				}

				// Use the native form flow, including validation and other submit listeners.
				resubmitting = true;
				try {
					if (
						( attempt.submitter instanceof HTMLButtonElement ||
							attempt.submitter instanceof HTMLInputElement ) &&
						attempt.submitter.form === form
					) {
						form.requestSubmit( attempt.submitter );
					} else {
						form.requestSubmit();
					}
				} finally {
					resubmitting = false;
				}
			}
		);
	} );
}

domReady( () => {
	document
		.querySelectorAll< HTMLElement >( '[data-wpai-comment-draft-check]' )
		.forEach( enhanceCommentForm );
} );
