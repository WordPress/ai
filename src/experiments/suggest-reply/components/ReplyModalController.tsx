/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { useEffect, useState, useCallback, useRef } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { ReplyModal } from './ReplyModal';
import type { CachedReply } from './ReplyModal';

type ModalState = {
	isOpen: boolean;
	commentId: number | null;
};

export function ReplyModalController(): React.ReactElement {
	const [ modalState, setModalState ] = useState< ModalState >( {
		isOpen: false,
		commentId: null,
	} );

	const repliesCache = useRef< Map< number, CachedReply > >( new Map() );

	const populateTimeoutRef = useRef< number | null >( null );

	const openModal = useCallback( ( commentId: number ) => {
		setModalState( { isOpen: true, commentId } );
	}, [] );

	const closeModal = useCallback( () => {
		setModalState( ( prev ) => ( { ...prev, isOpen: false } ) );
	}, [] );

	const getCachedReply = useCallback(
		( commentId: number ): CachedReply | undefined =>
			repliesCache.current.get( commentId ),
		[]
	);

	const setCachedReply = useCallback(
		( commentId: number, data: CachedReply ) => {
			repliesCache.current.set( commentId, data );
		},
		[]
	);

	const populateReplyTextarea = useCallback( ( reply: string ) => {
		const textarea = document.querySelector< HTMLTextAreaElement >(
			'#replycontainer #replycontent'
		);

		if ( ! textarea ) {
			return;
		}

		textarea.value = reply;
		textarea.focus();
		// Trigger any listeners bound to the input event (e.g. character counts).
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}, [] );

	const isInlineReplyOpenForComment = useCallback(
		( commentId: number ): boolean => {
			const replyRow =
				document.querySelector< HTMLElement >( '#replyrow' );
			const commentIdInput = document.querySelector< HTMLInputElement >(
				'#replyrow #comment_ID'
			);

			if ( ! replyRow || ! commentIdInput ) {
				return false;
			}

			const isVisible =
				replyRow.style.display !== 'none' &&
				replyRow.offsetParent !== null;
			const isForComment =
				parseInt( commentIdInput.value, 10 ) === commentId;

			return isVisible && isForComment;
		},
		[]
	);

	const handleSelectReply = useCallback(
		( reply: string, commentId: number ) => {
			closeModal();

			if ( isInlineReplyOpenForComment( commentId ) ) {
				populateReplyTextarea( reply );
				return;
			}

			// Find and click WordPress's own Reply button to open the form.
			const replyButton = document.querySelector< HTMLButtonElement >(
				`#comment-${ commentId } .reply button`
			);

			if ( replyButton ) {
				replyButton.click();
			}

			// Defer population to let WordPress render the inline reply row.
			if ( populateTimeoutRef.current !== null ) {
				window.clearTimeout( populateTimeoutRef.current );
			}
			populateTimeoutRef.current = window.setTimeout( () => {
				populateReplyTextarea( reply );
				populateTimeoutRef.current = null;
			}, 150 );
		},
		[ closeModal, isInlineReplyOpenForComment, populateReplyTextarea ]
	);

	useEffect( () => {
		const handleClick = ( event: MouseEvent ) => {
			const target = event.target as HTMLElement;

			if ( ! target.classList.contains( 'wpai-suggest-reply' ) ) {
				return;
			}

			event.preventDefault();

			const commentId = parseInt(
				target.dataset[ 'commentId' ] ?? '0',
				10
			);

			if ( commentId > 0 ) {
				openModal( commentId );
			}
		};

		const commentList = document.querySelector( '#the-comment-list' );

		if ( commentList ) {
			commentList.addEventListener(
				'click',
				handleClick as EventListener
			);

			return () => {
				commentList.removeEventListener(
					'click',
					handleClick as EventListener
				);
			};
		}

		return undefined;
	}, [ openModal ] );

	// Clean up any pending timeout on unmount.
	useEffect( () => {
		return () => {
			if ( populateTimeoutRef.current !== null ) {
				window.clearTimeout( populateTimeoutRef.current );
			}
		};
	}, [] );

	return (
		<>
			{ modalState.isOpen && modalState.commentId !== null && (
				<ReplyModal
					commentId={ modalState.commentId }
					onClose={ closeModal }
					onSelectReply={ handleSelectReply }
					initialReply={ getCachedReply( modalState.commentId ) }
					onReplyChange={ ( data ) => {
						if ( modalState.commentId !== null ) {
							setCachedReply( modalState.commentId, data );
						}
					} }
				/>
			) }
		</>
	);
}
