/**
 * Entry point for the post table bulk experiment.
 */

import domReady from '@wordpress/dom-ready';
import { createRoot } from 'react-dom/client';
import Assistant from './components/Assistant';

type MountMode = 'bulk' | 'quick';

type MountSelectors = {
	bulk: string[];
	quick: string[];
};

type LocalizedData = {
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

declare global {
	interface Window {
		aiPostTableBulkData?: LocalizedData;
	}
}

const MOUNT_SELECTOR = '.wp-ai-taxonomy-suggestions';

const ensureMountPoint = (
	selectors: string[],
	mode: MountMode
): HTMLElement | null => {
	let node = document.querySelector< HTMLElement >(
		`${ MOUNT_SELECTOR }[data-mode="${ mode }"]`
	);

	if ( node ) {
		return node;
	}

	let target: HTMLElement | null = null;

	for ( const selector of selectors ) {
		target = document.querySelector< HTMLElement >( selector );
		if ( target ) {
			break;
		}
	}

	if ( ! target ) {
		return null;
	}

	node = document.createElement( 'div' );
	node.className = 'wp-ai-taxonomy-suggestions';
	node.dataset.mode = mode;
	node.setAttribute( 'aria-live', 'polite' );
	target.appendChild( node );

	return node;
};

const DEFAULT_SELECTORS: MountSelectors = {
	bulk: [
		'#bulk-edit fieldset.inline-edit-categories .inline-edit-col',
		'#bulk-edit fieldset.inline-edit-categories',
	],
	quick: [
		'#edit fieldset.inline-edit-categories .inline-edit-col',
		'#edit fieldset.inline-edit-categories',
	],
};

const ensureDefaultMountPoints = () => {
	ensureMountPoint( DEFAULT_SELECTORS.bulk, 'bulk' );
	ensureMountPoint( DEFAULT_SELECTORS.quick, 'quick' );
};

const processedMounts = new WeakSet< HTMLElement >();

const mountAssistants = ( data: LocalizedData ) => {
	document
		.querySelectorAll< HTMLElement >( MOUNT_SELECTOR )
		.forEach( ( node ) => {
			if ( processedMounts.has( node ) ) {
				return;
			}

			processedMounts.add( node );
			const mode = ( node.dataset.mode as MountMode | undefined ) ?? 'quick';
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
};

const observeInlineEditRows = ( data: LocalizedData ) => {
	if ( typeof MutationObserver === 'undefined' ) {
		return;
	}

	const observer = new MutationObserver( () => {
		ensureDefaultMountPoints();
		mountAssistants( data );
	} );

	observer.observe( document.body, {
		childList: true,
		subtree: true,
	} );
};

domReady( () => {
	const { aiPostTableBulkData } = window as any;
	const data = aiPostTableBulkData as LocalizedData | undefined;

	if ( ! data || ! data.enabled ) {
		return;
	}

	ensureDefaultMountPoints();
	mountAssistants( data );
	observeInlineEditRows( data );
} );
