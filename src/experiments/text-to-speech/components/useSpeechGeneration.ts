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

/**
 * Hook for text to speech generation functionality.
 *
 * Starts background generation via POST /ai/v1/text-to-speech/{id} and polls
 * the same route with GET while a job is running. The job itself runs
 * server-side in WP-Cron, so it survives the editor being closed; re-opening
 * the editor resumes polling from the persisted status.
 *
 * @return {Object} Object with generation state and handlers.
 */
export function useSpeechGeneration(): {
	status: TtsStatus | null;
	isGenerating: boolean;
	isBlockedByUnsavedChanges: boolean;
	hasAudio: boolean;
	audioUrl: string;
	displayAudio: boolean;
	isDeleting: boolean;
	setDisplayAudio: ( value: boolean ) => void;
	handleGenerate: () => Promise< void >;
	handleDelete: () => Promise< void >;
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

	const isGenerating =
		isStarting ||
		status?.status === 'pending' ||
		status?.status === 'processing';

	// Poll while a background job is running. Polling also keeps WP-Cron
	// spawning on low-traffic sites.
	useEffect( () => {
		if ( ! isGenerating ) {
			return undefined;
		}

		const intervalId = window.setInterval( async () => {
			const result = await fetchStatus();

			if ( result?.status === 'error' ) {
				dispatch( noticesStore ).createErrorNotice(
					result.error || __( 'Audio generation failed.', 'ai' ),
					{ id: NOTICE_ID, isDismissible: true }
				);
			}
		}, POLL_INTERVAL );

		return () => window.clearInterval( intervalId );
	}, [ isGenerating, fetchStatus ] );

	const handleGenerate = async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsStarting( true );
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
		isBlockedByUnsavedChanges: Boolean( isDirty || isSaving ),
		hasAudio: Boolean( status?.audio_id ),
		audioUrl: status?.audio_url ?? '',
		displayAudio,
		isDeleting,
		setDisplayAudio,
		handleGenerate,
		handleDelete,
	};
}
