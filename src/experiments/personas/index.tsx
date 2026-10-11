/**
 * Personas experiment plugin registration.
 */

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import PersonaDetailsPanel from './components/PersonaDetailsPanel';
import PersonaPanel from './components/PersonaPanel';

import type { PersonasData } from './types';

const localized = ( window as any ).aiPersonasData as PersonasData | undefined;

/**
 * Plugin component rendering the persona sidebar panel for the current screen.
 *
 * While editing a persona it shows that persona's role and audience fields;
 * on any other post it shows the persona picker.
 */
const PersonasPlugin = (): React.JSX.Element | null => {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType() as string,
		[]
	);

	if ( ! localized?.enabled ) {
		return null;
	}

	if ( postType === localized.personaPostType ) {
		return (
			<PluginDocumentSettingPanel
				name="ai-persona-details"
				title={ __( 'Persona details', 'ai' ) }
				className="ai-persona-details-settings-panel"
			>
				<PersonaDetailsPanel
					roleMetaKey={ localized.roleMetaKey }
					audienceMetaKey={ localized.audienceMetaKey }
				/>
			</PluginDocumentSettingPanel>
		);
	}

	if ( ! localized.personas?.length ) {
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
