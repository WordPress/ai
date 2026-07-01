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
// @ts-expect-error - __experimentalPluginPostExcerpt is not in type definitions but exists at runtime
import { __experimentalPluginPostExcerpt as PluginPostExcerpt } from '@wordpress/edit-post'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import ExcerptGeneration from './components/ExcerptGeneration';
import ExcerptInlineWrapper from './components/ExcerptInlineWrapper';
import { exposeToDevTools } from '../../utils/devtools';

exposeToDevTools( {
	name: 'Excerpt Generation',
	description: 'Generates a post excerpt from the post body using AI.',
	abilitySlug: 'ai/excerpt-generation',
} );

/**
 * Plugin component that adds a generate button to the excerpt panel.
 */
const ExcerptGenerationPlugin = (): React.JSX.Element | null => {
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

// Register plugin for the form area (after the textarea)
registerPlugin( 'excerpt-generation', {
	render: ExcerptGenerationPlugin,
} );

// Register plugin for the inline button (next to the excerpt link)
registerPlugin( 'excerpt-generation-inline', {
	render: ExcerptInlineWrapper,
} );
