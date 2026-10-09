/**
 * Excerpt generation plugin registration.
 */

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import ExcerptGenerationPanel from './components/ExcerptGenerationPanel';
import './index.scss';

/**
 * Plugin component that renders the Excerpt generation panel in the editor
 * sidebar, right below the post summary.
 *
 * The panel follows core's Excerpt panel preference: when that panel is
 * turned off in Preferences the generated excerpt would not be visible
 * anywhere in the sidebar, so this panel is hidden as well.
 */
const ExcerptGenerationPlugin = (): React.JSX.Element | null => {
	const isExcerptPanelEnabled = useSelect(
		( select ) =>
			select( editorStore ).isEditorPanelEnabled( 'post-excerpt' ),
		[]
	);

	if ( ! isExcerptPanelEnabled ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="ai-excerpt-generation"
			title={ __( 'Excerpt generation', 'ai' ) }
			className="ai-excerpt-generation-settings-panel"
		>
			<ExcerptGenerationPanel />
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'excerpt-generation', {
	render: ExcerptGenerationPlugin,
} );
