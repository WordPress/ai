/**
 * Meta description experiment plugin registration.
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
import MetaDescriptionPanel from './components/MetaDescriptionPanel';
import './index.scss';

import type { MetaDescriptionData } from './types';

const localized = ( window as any ).aiMetaDescriptionData as
	| MetaDescriptionData
	| undefined;

/**
 * Plugin component that renders the Meta Description panel in the editor sidebar.
 */
const MetaDescriptionPlugin = (): JSX.Element | null => {
	if ( ! localized?.enabled ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="ai-meta-description"
			title={ __( 'Meta Description', 'ai' ) }
			className="ai-meta-description-settings-panel"
		>
			<MetaDescriptionPanel />
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'ai-meta-description', {
	render: MetaDescriptionPlugin,
} );
