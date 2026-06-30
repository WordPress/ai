/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import ContentTranslationPlugin from './components/ContentTranslationPlugin';
import './index.scss';

registerPlugin( 'ai-content-translation', {
	render: () => <ContentTranslationPlugin />,
} );
