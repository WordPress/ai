/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import InternalLinksPlugin from './components/InternalLinksPlugin';
import './index.scss';
import './types.d.ts';

if ( window.aiInternalLinksData?.enabled ) {
	registerPlugin( 'ai-internal-links', {
		render: () => <InternalLinksPlugin />,
	} );
}
