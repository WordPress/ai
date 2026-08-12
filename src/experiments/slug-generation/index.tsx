/**
 * WordPress dependencies
 */
import { PluginPrePublishPanel } from '@wordpress/editor';
import {
	createRoot,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import SlugGenerationButton from './components/SlugGenerationButton';
import SlugGenerationModal from './components/SlugGenerationModal';
import SlugPrePublishPanel from './components/SlugPrePublishPanel';
import {
	BUTTON_CONTAINER_CLASS,
	SLUG_PANEL_SELECTOR,
	TRIGGER_EVENT,
} from './constants';
import { applySlug, useSlugGeneration } from './hooks/useSlugGeneration';
import { getSettings } from './settings';
import './index.scss';
import type { SlugSource } from './types';

const NOTICE_ID = 'ai_slug_generation_error';

// Nodes worth re-running the attach check for: the permalink panel itself, or our own container.
const RELEVANT_NODE_SELECTOR = `${ SLUG_PANEL_SELECTOR }, .${ BUTTON_CONTAINER_CLASS }`;

// Debounce window for coalescing bursts of DOM changes into a single attach check.
const ATTACH_DEBOUNCE_MS = 100;

/**
 * Checks whether a node list contains an element the button attaches to or lives in.
 *
 * @param nodes The added or removed nodes from a mutation record.
 * @return Whether any node is, or contains, a relevant element.
 */
function hasRelevantNode( nodes: NodeList ): boolean {
	for ( let i = 0; i < nodes.length; i++ ) {
		const node = nodes[ i ];

		// Text nodes make up the bulk of editor mutations; skip them before matching.
		if ( ! ( node instanceof HTMLElement ) ) {
			continue;
		}

		if (
			node.matches( RELEVANT_NODE_SELECTOR ) ||
			node.querySelector( RELEVANT_NODE_SELECTOR )
		) {
			return true;
		}
	}

	return false;
}

/**
 * Main plugin wrapper component for slug generation.
 *
 * Attaches the "Generate Slug" button to the permalink inspector popover,
 * handles the trigger event, renders the pre-publish panel, and owns the modal.
 *
 * @return The plugin components.
 */
function SlugGenerationWrapper(): React.JSX.Element {
	const [ modalSource, setModalSource ] = useState< SlugSource | null >(
		null
	);

	const closeModal = useCallback( () => setModalSource( null ), [] );

	const { suggestions, isGenerating, generate, reset } = useSlugGeneration( {
		noticeId: NOTICE_ID,
		onError: closeModal,
	} );

	useEffect( () => {
		if ( ! getSettings().enabled ) {
			return;
		}

		const handleTrigger = ( event: Event ) => {
			const source = ( event as CustomEvent< SlugSource > ).detail;

			reset();
			setModalSource( source );
			generate( source );
		};

		window.addEventListener( TRIGGER_EVENT, handleTrigger );

		let isAttached = false;
		let root: ReturnType< typeof createRoot > | null = null;
		let container: HTMLElement | null = null;
		let timeoutId: ReturnType< typeof setTimeout > | null = null;

		const detach = () => {
			root?.unmount();
			root = null;
			container?.remove();
			container = null;
			isAttached = false;
		};

		const findAndAttach = () => {
			if ( isAttached ) {
				return;
			}

			// The slug panel in WordPress 7.0+ renders inside a Dropdown popover. The
			// popover content uses the PostURL component, which wraps everything in a
			// div with class "editor-post-url".
			const slugPanel =
				document.querySelector< HTMLElement >( SLUG_PANEL_SELECTOR );

			if (
				! slugPanel ||
				slugPanel.querySelector( `.${ BUTTON_CONTAINER_CLASS }` )
			) {
				return;
			}

			// Insert the button container at the end of the slug panel.
			container = document.createElement( 'div' );
			container.className = BUTTON_CONTAINER_CLASS;
			slugPanel.appendChild( container );

			root = createRoot( container );
			root.render( <SlugGenerationButton /> );

			isAttached = true;
		};

		const checkAndAttach = () => {
			// The popover is destroyed when it closes, taking our container with it.
			if (
				isAttached &&
				! document.querySelector( `.${ BUTTON_CONTAINER_CLASS }` )
			) {
				detach();
			}

			findAndAttach();
		};

		const debouncedCheck = () => {
			if ( timeoutId ) {
				clearTimeout( timeoutId );
			}

			timeoutId = setTimeout( checkAndAttach, ATTACH_DEBOUNCE_MS );
		};

		findAndAttach();

		/*
		 * Observed at the document level on purpose: when the editor does not render a
		 * Popover.Slot, `Popover` portals into a container appended directly to
		 * `document.body` (see `getPopoverFallbackContainer()` in @wordpress/components),
		 * so a narrower root would miss the panel entirely. The cost is kept down by
		 * matching only element nodes that are, or contain, the permalink panel — the
		 * text-node churn that dominates editor mutations is discarded immediately.
		 */
		const observer = new MutationObserver( ( mutations ) => {
			const isRelevant = mutations.some(
				( mutation ) =>
					hasRelevantNode( mutation.addedNodes ) ||
					hasRelevantNode( mutation.removedNodes )
			);

			if ( isRelevant ) {
				debouncedCheck();
			}
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );

		return () => {
			window.removeEventListener( TRIGGER_EVENT, handleTrigger );

			if ( timeoutId ) {
				clearTimeout( timeoutId );
			}

			observer.disconnect();
			detach();
		};
	}, [ generate, reset ] );

	if ( ! getSettings().enabled ) {
		return <></>;
	}

	return (
		<>
			<PluginPrePublishPanel
				title={ __( 'Suggest Slugs', 'ai' ) }
				initialOpen={ false }
			>
				<SlugPrePublishPanel />
			</PluginPrePublishPanel>

			{ modalSource && (
				<SlugGenerationModal
					suggestions={ suggestions }
					onClose={ closeModal }
					onSelect={ ( selectedSlug ) => {
						applySlug( selectedSlug );
						closeModal();
					} }
					onRegenerate={ () => generate( modalSource ) }
					isRegenerating={ isGenerating }
				/>
			) }
		</>
	);
}

registerPlugin( 'ai-slug-generation', {
	render: SlugGenerationWrapper,
} );
