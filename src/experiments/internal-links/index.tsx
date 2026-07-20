/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import InternalLinksPlugin from './components/InternalLinksPlugin';

declare global {
	interface Window {
		aiInternalLinksData?: {
			enabled: boolean;
			minContentLength: number;
			maxSuggestions: number;
		};
	}
}

if ( ( window as any ).aiInternalLinksData?.enabled ) {
	registerPlugin( 'ai-internal-links', {
		render: () => <InternalLinksPlugin />,
	} );
}
