/**
 * Type-ahead inline ghost text experiment.
 */

/**
 * External dependencies
 */
import type { ComponentType } from 'react';

/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { dispatch } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { store as keyboardShortcutsStore } from '@wordpress/keyboard-shortcuts';

/**
 * Internal dependencies
 */
import TypeAheadBlock from './components/TypeAheadBlock';
import { ALLOWED_BLOCKS } from './constants';
import type { TypeAheadSettings } from './types';
import { normalizeTypeAheadSettings } from './utils/settings';
import './index.scss';

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
			// Only wrap allowed blocks, so other blocks (such as Columns or
			// Group) don't attach listeners or send requests.
			return ( props: any ) =>
				allowedBlocks.has( props.name ) ? (
					<TypeAheadBlock
						BlockEdit={ BlockEdit }
						blockProps={ props }
						settings={ settings }
					/>
				) : (
					<BlockEdit { ...props } />
				);
		},
		'withAITypeAhead'
	);

	addFilter( 'editor.BlockEdit', 'ai/type-ahead', withTypeAhead );

	// List the manual trigger in the editor's Keyboard shortcuts modal. The
	// key press itself is handled in TypeAheadBlock. The cast is needed because
	// `@wordpress/keyboard-shortcuts` is typed against a newer `@wordpress/data`
	// than the plugin uses.
	(
		dispatch( keyboardShortcutsStore ) as unknown as {
			registerShortcut: ( shortcut: object ) => void;
		}
	 ).registerShortcut( {
		name: 'ai/type-ahead-suggestion',
		category: 'block',
		description: __( 'Ask for a type-ahead suggestion.', 'ai' ),
		keyCombination: {
			modifier: 'access',
			character: 'g',
		},
	} );
};

const settings = window.aiTypeAheadData;
if ( settings ) {
	bootstrap( normalizeTypeAheadSettings( settings ) );
}
