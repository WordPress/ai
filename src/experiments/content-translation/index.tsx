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
import { useContentTranslation } from './hooks/useContentTranslation';
import './index.scss';

function ContentTranslationBlockEdit( { BlockEdit, props }: any ) {
	const { isLoading, translate, canTranslate } = useContentTranslation(
		props.clientId
	);

	return (
		<>
			{ props.isSelected && (
				<BlockControls>
					<ContentTranslationToolbar
						isLoading={ isLoading }
						translate={ translate }
						canTranslate={ canTranslate }
					/>
				</BlockControls>
			) }
			<div
				className={ `ai-content-translation-content ${
					isLoading
						? 'ai-content-translation-content--is-loading'
						: ''
				}` }
			>
				<BlockEdit { ...props } />
			</div>
		</>
	);
}

const withContentTranslation = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if (
			props.name !== 'core/paragraph' ||
			! window.aiContentTranslationData?.enabled
		) {
			return <BlockEdit { ...props } />;
		}

		return (
			<ContentTranslationBlockEdit
				BlockEdit={ BlockEdit }
				props={ props }
			/>
		);
	};
}, 'withContentTranslation' );

addFilter(
	'editor.BlockEdit',
	'ai/content-translation',
	withContentTranslation
);
