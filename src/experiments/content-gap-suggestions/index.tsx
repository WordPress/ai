/**
 * Content Gap Suggestions dashboard widget entry point.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ContentOpportunitiesWidget from './components/ContentOpportunitiesWidget';
import type { ContentGapSuggestionsData } from './types';
import './index.scss';

declare global {
	interface Window {
		aiContentGapSuggestionsData?: ContentGapSuggestionsData;
	}
}

domReady( () => {
	const rootId =
		window.aiContentGapSuggestionsData?.widgetRoot ??
		'ai-content-gap-suggestions-root';
	const root = document.getElementById( rootId );

	if ( ! root ) {
		return;
	}

	createRoot( root ).render( <ContentOpportunitiesWidget /> );
} );
