/**
 * Type-ahead inline ghost text experiment.
 */

/**
 * External dependencies
 */
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import './style.scss';
import TypeAheadBlock from './components/TypeAheadBlock';
import { ALLOWED_BLOCKS } from './constants';
import type { TypeAheadSettings } from './types';

/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';

/**
 * Registers the editor block wrapper for type-ahead support.
 *
 * @param {TypeAheadSettings} settings Experiment settings.
 */
const bootstrap = ( settings: TypeAheadSettings ) => {
	if ( ! settings.enabled ) {
		return;
	}

	const allowedBlocks = new Set( ALLOWED_BLOCKS );
	if ( settings.showHeadings ) {
		allowedBlocks.add( 'core/heading' );
	}

	const withTypeAhead = createHigherOrderComponent(
		( BlockEdit: ComponentType< any > ) => {
			return ( props: any ) => (
				<TypeAheadBlock
					BlockEdit={ BlockEdit }
					blockProps={ props }
					settings={ settings }
					allowedBlocks={ allowedBlocks }
				/>
			);
		},
		'withAITypeAhead'
	);

	addFilter( 'editor.BlockEdit', 'ai/type-ahead', withTypeAhead );
};

/**
 * Waits for localized settings and initializes the experiment once available.
 *
 * @param {number} attempts Retry count.
 */
const waitForSettings = ( attempts = 0 ) => {
	const settings = window.aiTypeAheadData;

	if ( settings ) {
		bootstrap( settings );
		return;
	}

	if ( attempts > 200 ) {
		return;
	}

	window.setTimeout( () => waitForSettings( attempts + 1 ), 25 );
};

waitForSettings();
