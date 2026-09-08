/**
 * Text to Speech experiment plugin registration.
 */

/**
 * WordPress dependencies
 */
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import TextToSpeechPanel from './components/TextToSpeechPanel';
import './index.scss';

import type { TextToSpeechData } from './types';

const localized = ( window as any ).aiTextToSpeechData as
	| TextToSpeechData
	| undefined;

/**
 * Plugin component that renders the Text to Speech panel in the editor sidebar.
 */
const TextToSpeechPlugin = (): React.JSX.Element | null => {
	if ( ! localized?.enabled ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="ai-text-to-speech"
			title={ __( 'Text to Speech', 'ai' ) }
			className="ai-text-to-speech-panel"
		>
			<TextToSpeechPanel />
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'ai-text-to-speech', {
	render: TextToSpeechPlugin,
} );
