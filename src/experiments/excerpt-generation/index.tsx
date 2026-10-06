/**
 * Excerpt generation plugin registration.
 */

/**
 * External dependencies
 */
import React from 'react';

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
import ExcerptGeneration from './components/ExcerptGeneration';

/**
 * Plugin component that renders a dedicated sidebar panel with the generate
 * button, right below the post summary.
 *
 * The panel follows core's Excerpt panel preference: when that panel is
 * turned off in Preferences the generated excerpt would not be visible
 * anywhere in the sidebar, so this panel is hidden as well.
 */
const ExcerptPanelPlugin = (): React.JSX.Element | null => {
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
			className="ai-excerpt-generation-panel"
		>
			<ExcerptGeneration />
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'excerpt-generation', {
	render: ExcerptPanelPlugin,
} );
