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
import { useEffect, useState, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { ReplyModal } from './ReplyModal';

type ModalState = {
	isOpen: boolean;
	commentId: number | null;
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
	 * Closes the modal.
	 */
	const closeModal = useCallback( () => {
		setModalState( {
			isOpen: false,
			commentId: null,
		} );
	}, [] );

	/**
	 * Handles selecting a reply suggestion.
	 */
	const handleSelectReply = useCallback( ( reply: string, commentId: number ) => {
		// Find the reply link for this comment and trigger WordPress's inline reply.
		const replyLink = document.querySelector< HTMLAnchorElement >(
			`#comment-${ commentId } .reply a`
		);

		if ( replyLink ) {
			// Click the reply link to open the inline reply form.
			replyLink.click();

			// Wait for the form to appear, then populate it.
			setTimeout( () => {
				const replyTextarea = document.querySelector< HTMLTextAreaElement >(
					'#replycontainer #replycontent'
				);

				if ( replyTextarea ) {
					replyTextarea.value = reply;
					replyTextarea.focus();

					// Trigger input event for any listeners.
					replyTextarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				}
			}, 100 );
		}

		closeModal();
	}, [ closeModal ] );

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
				/>
			) }
		</>
	);
}
