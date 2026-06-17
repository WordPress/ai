/**
 * WordPress dependencies
 */
import { BlockControls } from '@wordpress/block-editor';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import ContentTranslationToolbar from './components/ContentTranslationToolbar';
import './index.scss';

const withContentTranslation = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if (
			props.name !== 'core/paragraph' ||
			! window.aiContentTranslationData?.enabled
		) {
			return <BlockEdit { ...props } />;
		}

		return (
			<>
				{ props.isSelected && (
					<BlockControls>
						<ContentTranslationToolbar
							clientId={ props.clientId }
						/>
					</BlockControls>
				) }
				<BlockEdit { ...props } />
			</>
		);
	};
}, 'withContentTranslation' );

addFilter(
	'editor.BlockEdit',
	'ai/content-translation',
	withContentTranslation
);
