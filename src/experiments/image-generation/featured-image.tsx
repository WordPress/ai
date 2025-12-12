/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import GenerateFeaturedImage from './components/GenerateFeaturedImage';
import AILabel from './components/AILabel';

const { aiImageGenerationData } = window as any;

/**
 * Wraps the PostFeaturedImage component to add a generate featured image button.
 *
 * @param OriginalComponent - The original PostFeaturedImage component.
 * @return The wrapped component.
 */
function wrapPostFeaturedImage(
	OriginalComponent: React.ComponentType< any >
) {
	if ( ! aiImageGenerationData.enabled ) {
		return OriginalComponent;
	}

	return function ( props: any ) {
		return createElement(
			React.Fragment,
			{},
			<GenerateFeaturedImage />,
			createElement( OriginalComponent, props ),
			<AILabel label={ __( 'AI Generated Featured Image', 'ai' ) } />
		);
	};
}

addFilter(
	'editor.PostFeaturedImage',
	'ai/image-generation',
	wrapPostFeaturedImage
);
