/**
 * Shared hook for text to speech generation logic.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { ensureProvider } from '../../../utils/provider-status';
import type { TtsStatus } from '../types';

const NOTICE_ID = 'ai_text_to_speech_error';
const POLL_INTERVAL = 5000;
const MAX_POLL_FAILURES = 3;

/**
 * Hook for text to speech generation functionality.
 *
 * Starts background generation via POST /ai/v1/text-to-speech/{id} and polls
 * the same route with GET while a job is running. The job itself runs
 * server-side in WP-Cron, so it survives the editor being closed; re-opening
 * the editor resumes polling from the persisted status. Polling stops after
 * several failed requests in a row, so a lost connection does not leave the
 * panel spinning forever.
 *
 * @return {Object} Object with generation state and handlers.
 */
export function useSpeechGeneration(): {
	status: TtsStatus | null;
	isGenerating: boolean;
	hasLostContact: boolean;
	isBlockedByUnsavedChanges: boolean;
	hasAudio: boolean;
	audioUrl: string;
	displayAudio: boolean;
	isDeleting: boolean;
	setDisplayAudio: ( value: boolean ) => void;
	handleGenerate: () => Promise< void >;
	handleDelete: () => Promise< void >;
	handleCheckAgain: () => void;
} {
	const { postId, isDirty, isSaving, meta } = useSelect( ( select ) => {
		return {
			postId: select( editorStore ).getCurrentPostId(),
			isDirty: select( editorStore ).isEditedPostDirty(),
			isSaving: select( editorStore ).isSavingPost(),
			meta: select( editorStore ).getEditedPostAttribute( 'meta' ) as
				| { wpai_tts_display_audio?: boolean }
				| undefined,
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );
	const [ status, setStatus ] = useState< TtsStatus | null >( null );
	const [ isStarting, setIsStarting ] = useState< boolean >( false );
	const [ isDeleting, setIsDeleting ] = useState< boolean >( false );
	const [ hasLostContact, setHasLostContact ] = useState< boolean >( false );

	const fetchStatus = useCallback( async (): Promise< TtsStatus | null > => {
		if ( ! postId ) {
			return null;
		}

		try {
			const result = await apiFetch< TtsStatus >( {
				path: `/ai/v1/text-to-speech/${ postId }`,
			} );
			setStatus( result );
			return result;
		} catch {
			return null;
		}
	}, [ postId ] );

	// Load persisted status when the editor opens.
	useEffect( () => {
		fetchStatus();
	}, [ fetchStatus ] );

	const isJobRunning =
		status?.status === 'pending' || status?.status === 'processing';
	const isGenerating = isStarting || ( isJobRunning && ! hasLostContact );

	// Poll while a background job is running. Polling also keeps WP-Cron
	// spawning on low-traffic sites. Each poll is scheduled only after the
	// previous request settles, so slow responses never overlap.
	useEffect( () => {
		if ( ! isJobRunning || hasLostContact ) {
			return undefined;
		}

		let isCancelled = false;
		let failures = 0;
		let timeoutId = 0;

		const poll = async () => {
			const result = await fetchStatus();

			if ( isCancelled ) {
				return;
			}

			if ( ! result ) {
				failures++;

				if ( failures >= MAX_POLL_FAILURES ) {
					setHasLostContact( true );
					return;
				}
			} else {
				failures = 0;

				if ( result.status === 'error' ) {
					dispatch( noticesStore ).createErrorNotice(
						result.error || __( 'Audio generation failed.', 'ai' ),
						{ id: NOTICE_ID, isDismissible: true }
					);
				}
			}

			timeoutId = window.setTimeout( poll, POLL_INTERVAL );
		};

		timeoutId = window.setTimeout( poll, POLL_INTERVAL );

		return () => {
			isCancelled = true;
			window.clearTimeout( timeoutId );
		};
	}, [ isJobRunning, hasLostContact, fetchStatus ] );

	const handleCheckAgain = () => {
		setHasLostContact( false );
		fetchStatus();
	};

	const handleGenerate = async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsStarting( true );
		setHasLostContact( false );
		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const result = await apiFetch< TtsStatus >( {
				path: `/ai/v1/text-to-speech/${ postId }`,
				method: 'POST',
			} );
			setStatus( result );
		} catch ( error: any ) {
			dispatch( noticesStore ).createErrorNotice(
				error?.message ??
					__( 'Failed to start audio generation.', 'ai' ),
				{ id: NOTICE_ID, isDismissible: true }
			);
		} finally {
			setIsStarting( false );
		}
	};

	const handleDelete = async () => {
		setIsDeleting( true );
		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const result = await apiFetch< TtsStatus >( {
				path: `/ai/v1/text-to-speech/${ postId }`,
				method: 'DELETE',
			} );
			setStatus( result );
		} catch ( error: any ) {
			dispatch( noticesStore ).createErrorNotice(
				error?.message ?? __( 'Failed to delete audio.', 'ai' ),
				{ id: NOTICE_ID, isDismissible: true }
			);
		} finally {
			setIsDeleting( false );
		}
	};

	const displayAudio = meta?.wpai_tts_display_audio ?? true;

	const setDisplayAudio = ( value: boolean ) => {
		editPost( { meta: { wpai_tts_display_audio: value } } );
	};

	return {
		status,
		isGenerating,
		hasLostContact: hasLostContact && isJobRunning,
		isBlockedByUnsavedChanges: Boolean( isDirty || isSaving ),
		hasAudio: Boolean( status?.audio_id ),
		audioUrl: status?.audio_url ?? '',
		displayAudio,
		isDeleting,
		setDisplayAudio,
		handleGenerate,
		handleDelete,
		handleCheckAgain,
	};
}
