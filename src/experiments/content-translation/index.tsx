/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import './index.scss';
import TranslationToolbar from './toolbar';
import ContentTranslationPlugin from './components/ContentTranslationPlugin';

registerPlugin( 'ai-content-translation', {
	render: () => (
		<>
			<TranslationToolbar />
			<ContentTranslationPlugin />
		</>
	),
} );
