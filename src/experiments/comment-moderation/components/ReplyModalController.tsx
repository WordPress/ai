/**
 * Reply Modal Controller component.
 *
 * Manages the AI Reply modal for generating reply suggestions.
 */

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

type ModalState = {
	isOpen: boolean;
	commentId: number | null;
};

type CachedReplies = {
	replies: string[];
	tone: 'professional' | 'friendly' | 'casual';
};

/**
 * ReplyModalController component.
 *
 * Listens for clicks on AI Reply links and opens the modal.
 */
export function ReplyModalController(): React.ReactElement {
	const [ modalState, setModalState ] = useState< ModalState >( {
		isOpen: false,
		commentId: null,
	} );

	// Cache replies per comment ID so reopening shows previous results.
	const repliesCache = useRef< Map< number, CachedReplies > >( new Map() );

	/**
	 * Opens the modal for a specific comment.
	 */
	const openModal = useCallback( ( commentId: number ) => {
		setModalState( {
			isOpen: true,
			commentId,
		} );
	}, [] );

	/**
	 * Closes the modal without clearing cache.
	 */
	const closeModal = useCallback( () => {
		setModalState( ( prev ) => ( {
			...prev,
			isOpen: false,
		} ) );
	}, [] );

	/**
	 * Gets cached replies for a comment.
	 */
	const getCachedReplies = useCallback( ( commentId: number ): CachedReplies | undefined => {
		return repliesCache.current.get( commentId );
	}, [] );

	/**
	 * Caches replies for a comment.
	 */
	const setCachedReplies = useCallback( ( commentId: number, data: CachedReplies ) => {
		repliesCache.current.set( commentId, data );
	}, [] );

	/**
	 * Populates the reply textarea with the selected reply.
	 */
	const populateReplyTextarea = useCallback( ( reply: string ) => {
		const replyTextarea = document.querySelector< HTMLTextAreaElement >(
			'#replycontainer #replycontent'
		);

		if ( replyTextarea ) {
			replyTextarea.value = reply;
			replyTextarea.focus();

			// Trigger input event for any listeners.
			replyTextarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	}, [] );

	/**
	 * Checks if the inline reply form is currently open for a specific comment.
	 */
	const isReplyFormOpenForComment = useCallback( ( commentId: number ): boolean => {
		const replyRow = document.querySelector< HTMLElement >( '#replyrow' );
		const commentIdInput = document.querySelector< HTMLInputElement >( '#replyrow #comment_ID' );

		if ( ! replyRow || ! commentIdInput ) {
			return false;
		}

		// Check if the reply row is visible and for the correct comment.
		const isVisible = replyRow.style.display !== 'none' && replyRow.offsetParent !== null;
		const isForComment = parseInt( commentIdInput.value, 10 ) === commentId;

		return isVisible && isForComment;
	}, [] );

	/**
	 * Handles selecting a reply suggestion.
	 */
	const handleSelectReply = useCallback( ( reply: string, commentId: number ) => {
		// Check if the reply form is already open for this comment.
		if ( isReplyFormOpenForComment( commentId ) ) {
			// Just populate the existing textarea.
			populateReplyTextarea( reply );
			closeModal();
			return;
		}

		// Find the reply button for this comment and trigger WordPress's inline reply.
		const replyButton = document.querySelector< HTMLButtonElement >(
			`#comment-${ commentId } .reply button`
		);

		if ( replyButton ) {
			// Click the reply button to open the inline reply form.
			replyButton.click();

			// Wait for the form to appear, then populate it.
			setTimeout( () => {
				populateReplyTextarea( reply );
			}, 100 );
		}

		closeModal();
	}, [ closeModal, isReplyFormOpenForComment, populateReplyTextarea ] );

	/**
	 * Sets up click handlers for AI Reply links.
	 */
	useEffect( () => {
		const handleClick = ( event: MouseEvent ) => {
			const target = event.target as HTMLElement;

			if ( ! target.classList.contains( 'ai-suggest-reply' ) ) {
				return;
			}

			event.preventDefault();

			const commentId = parseInt( target.dataset.commentId || '0', 10 );

			if ( commentId ) {
				openModal( commentId );
			}
		};

		// Use event delegation on the comments table.
		const commentsTable = document.querySelector( '#the-comment-list' );

		if ( commentsTable ) {
			commentsTable.addEventListener( 'click', handleClick as EventListener );

			return () => {
				commentsTable.removeEventListener( 'click', handleClick as EventListener );
			};
		}

		return undefined;
	}, [ openModal ] );

	return (
		<>
			{ modalState.isOpen && modalState.commentId && (
				<ReplyModal
					commentId={ modalState.commentId }
					onClose={ closeModal }
					onSelectReply={ handleSelectReply }
					initialReplies={ getCachedReplies( modalState.commentId ) }
					onRepliesChange={ ( data ) => setCachedReplies( modalState.commentId!, data ) }
				/>
			) }
		</>
	);
}
