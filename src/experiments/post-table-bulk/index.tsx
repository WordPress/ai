/**
 * Entry point for the post table bulk experiment.
 */

import domReady from '@wordpress/dom-ready';
import { createRoot } from 'react-dom/client';
import Assistant from './components/Assistant';

declare global {
	interface Window {
		PostTableBulkData?: {
			enabled: boolean;
			ability: string;
			postType: string;
			taxonomies: Array< {
				name: string;
				label: string;
				hierarchical: boolean;
			} >;
			maxBatchSize: number;
			suggestionLimit?: number;
		};
	}
}

domReady( () => {
	const data = window.PostTableBulkData;

	if ( ! data?.enabled ) {
		return;
	}

	document
		.querySelectorAll< HTMLElement >( '.wp-ai-taxonomy-suggestions' )
		.forEach( ( node ) => {
			const mode =
				( node.dataset.mode as 'bulk' | 'quick' | undefined ) ?? 'quick';

			const root = createRoot( node );
			root.render(
				<Assistant
					mode={ mode }
					ability={ data.ability }
					taxonomies={ data.taxonomies }
					maxBatchSize={ data.maxBatchSize }
					suggestionLimit={ data.suggestionLimit ?? 5 }
				/>
			);
		} );
} );
