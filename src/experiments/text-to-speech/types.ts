/**
 * Types for the Text to Speech experiment.
 */

export interface TextToSpeechData {
	enabled: boolean;
	hasTtsSupport: boolean;
}

export interface TtsStatus {
	status: 'idle' | 'pending' | 'processing' | 'complete' | 'error';
	done: number;
	total: number;
	error: string;
	audio_id: number;
	audio_url: string;
	display_audio: boolean;
}
