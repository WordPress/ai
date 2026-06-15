/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { useEffect, useState, useRef } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { ReplyModal } from './ReplyModal';

type ModalState = {
	isOpen: boolean;
	commentId: number | null;
};

export function ReplyModalController(): React.ReactElement {
	const [ modalState, setModalState ] = useState< ModalState >( {
		isOpen: false,
		commentId: null,
	} );

	const populateTimeoutRef = useRef< number | null >( null );

	const populateReplyTextarea = ( reply: string ) => {
		const textarea = document.querySelector< HTMLTextAreaElement >(
			'#replycontainer #replycontent'
		);
		if ( ! textarea ) {
			return;
		}
		textarea.value = reply;
		textarea.focus();
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	};

	const isInlineReplyOpenForComment = ( commentId: number ): boolean => {
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
	};

	const closeModal = () =>
		setModalState( ( prev ) => ( { ...prev, isOpen: false } ) );

	const handleSelectReply = ( reply: string, commentId: number ) => {
		closeModal();

		if ( isInlineReplyOpenForComment( commentId ) ) {
			populateReplyTextarea( reply );
			return;
		}

		const replyButton = document.querySelector< HTMLButtonElement >(
			`#comment-${ commentId } .reply button`
		);
		if ( replyButton ) {
			replyButton.click();
		}

		if ( populateTimeoutRef.current !== null ) {
			window.clearTimeout( populateTimeoutRef.current );
		}
		populateTimeoutRef.current = window.setTimeout( () => {
			populateReplyTextarea( reply );
			populateTimeoutRef.current = null;
		}, 150 );
	};

	useEffect( () => {
		const handleClick = ( event: MouseEvent ) => {
			const target = event.target as HTMLElement;
			if ( ! target.classList.contains( 'wpai-suggest-reply' ) ) {
				return;
			}
			event.preventDefault();
			const commentId = parseInt( target.dataset.commentId ?? '0', 10 );
			if ( commentId > 0 ) {
				setModalState( { isOpen: true, commentId } );
			}
		};

		const commentList = document.querySelector( '#the-comment-list' );

		if ( commentList ) {
			commentList.addEventListener(
				'click',
				handleClick as EventListener
			);
			return () =>
				commentList.removeEventListener(
					'click',
					handleClick as EventListener
				);
		}

		return undefined;
	}, [] );

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
				/>
			) }
		</>
	);
}
