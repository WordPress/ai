/**
 * Text to Speech sidebar panel contents.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { audio } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useSpeechGeneration } from './useSpeechGeneration';
import type { TextToSpeechData } from '../types';

/**
 * Get the settings for the Text to Speech panel.
 *
 * @return {TextToSpeechData} The settings for the Text to Speech panel.
 */
const getSettings = (): TextToSpeechData => {
	const settings = ( window as any ).aiTextToSpeechData ?? {};

	return {
		enabled: settings.enabled ?? false,
		hasTtsSupport: settings.hasTtsSupport ?? false,
	};
};

/**
 * Panel component with the generate button, progress, preview, and the
 * front-end display toggle.
 *
 * @return {React.JSX.Element} The Text to Speech panel component.
 */
export default function TextToSpeechPanel(): React.JSX.Element {
	const { hasTtsSupport } = getSettings();
	const {
		status,
		isGenerating,
		isBlockedByUnsavedChanges,
		hasAudio,
		audioUrl,
		displayAudio,
		setDisplayAudio,
		handleGenerate,
	} = useSpeechGeneration();

	if ( isGenerating ) {
		return (
			<div className="ai-text-to-speech-panel__content">
				<div className="ai-text-to-speech-panel__loading">
					<Spinner />
					<span>
						{ status && status.total > 0
							? sprintf(
									/* translators: 1: number of chunks processed, 2: total number of chunks */
									__(
										'Generating audio… (%1$d of %2$d)',
										'ai'
									),
									status.done,
									status.total
							  )
							: __( 'Generating audio…', 'ai' ) }
					</span>
				</div>
			</div>
		);
	}

	return (
		<div className="ai-text-to-speech-panel__content">
			{ ! hasTtsSupport && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'No connected AI provider supports text to speech.',
						'ai'
					) }
				</Notice>
			) }

			{ hasAudio && audioUrl && (
				<audio
					className="ai-text-to-speech-panel__preview"
					controls
					preload="metadata"
					src={ audioUrl }
				/>
			) }

			{ hasAudio && (
				<ToggleControl
					label={ __(
						'Display audio player on the front end',
						'ai'
					) }
					checked={ displayAudio }
					onChange={ setDisplayAudio }
				/>
			) }

			<Button
				__next40pxDefaultSize
				variant="secondary"
				onClick={ handleGenerate }
				icon={ audio }
				disabled={ ! hasTtsSupport || isBlockedByUnsavedChanges }
				accessibleWhenDisabled
			>
				{ hasAudio
					? __( 'Regenerate Audio', 'ai' )
					: __( 'Generate Audio', 'ai' ) }
			</Button>

			{ isBlockedByUnsavedChanges && (
				<p className="ai-text-to-speech-panel__help">
					{ __(
						'Save your changes first — audio is generated from the saved content.',
						'ai'
					) }
				</p>
			) }

			{ hasAudio && (
				<p className="ai-text-to-speech-panel__help">
					{ __(
						'Regenerating deletes the current audio and creates a new version.',
						'ai'
					) }
				</p>
			) }
		</div>
	);
}
