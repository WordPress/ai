/**
 * Excerpt generation experiment plugin registration.
 */

/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { __experimentalPluginPostExcerpt as PluginPostExcerpt } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import ExcerptGeneration from './components/ExcerptGeneration';

/**
 * Plugin component that adds a generate button to the excerpt panel.
 *
 * @return {JSX.Element | null} The plugin component.
 */
const ExcerptGenerationPlugin = () => {
	// __experimentalPluginPostExcerpt from @wordpress/edit-post is a function
	// that returns the component (or null in site editor)
	const PluginExcerptComponent = PluginPostExcerpt();

	// If we're in the site editor, the function returns null
	if ( ! PluginExcerptComponent ) {
		return null;
	}

	const PluginExcerpt = PluginExcerptComponent as React.ComponentType< {
		children: React.ReactNode;
		className?: string;
	} >;

	return (
		<PluginExcerpt className="ai-excerpt-generation">
			<ExcerptGeneration />
		</PluginExcerpt>
	);
};

registerPlugin( 'excerpt-generation', {
	render: ExcerptGenerationPlugin,
} );
