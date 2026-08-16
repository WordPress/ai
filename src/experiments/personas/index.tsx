/**
 * Personas experiment plugin registration.
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
import PersonaPanel from './components/PersonaPanel';

import type { PersonasData } from './types';

const localized = ( window as any ).aiPersonasData as PersonasData | undefined;

/**
 * Plugin component that renders the Persona panel in the editor sidebar.
 */
const PersonasPlugin = (): React.JSX.Element | null => {
	if ( ! localized?.enabled || ! localized.personas?.length ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="ai-personas"
			title={ __( 'Persona', 'ai' ) }
			className="ai-personas-settings-panel"
		>
			<PersonaPanel
				metaKey={ localized.metaKey }
				personas={ localized.personas }
				defaultPersona={ localized.defaultPersona }
				manageUrl={ localized.manageUrl }
			/>
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'ai-personas', {
	render: PersonasPlugin,
} );
