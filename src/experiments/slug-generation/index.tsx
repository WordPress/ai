/**
 * WordPress dependencies
 */
import { PluginPrePublishPanel, store as editorStore } from '@wordpress/editor';
import {
	createRoot,
	useEffect,
	useState,
	useCallback,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import SlugGenerationButton from './components/SlugGenerationButton';
import SlugPrePublishPanel from './components/SlugPrePublishPanel';
import SlugGenerationModal from './components/SlugGenerationModal';
import { runAbility } from '../../utils/run-ability';
import { ensureProvider } from '../../utils/provider-status';
import './index.scss';
import type {
	SlugGenerationAbilityInput,
	GeneratedSlugData,
	SlugGenerationData,
} from './types';

const NOTICE_ID = 'ai_slug_generation_error';
const MINIMUM_CONTENT_COUNT_DEFAULT = 250;
const NUMBER_OF_SUGGESTIONS_DEFAULT = 3;

const getSettings = (): SlugGenerationData => {
	const settings = ( window as any ).aiSlugGenerationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ?? MINIMUM_CONTENT_COUNT_DEFAULT,
		numberOfSuggestions:
			settings.numberOfSuggestions ?? NUMBER_OF_SUGGESTIONS_DEFAULT,
	};
};

/**
 * Main plugin wrapper component for slug generation.
 *
 * Attaches the "Generate Slug" button to the permalink inspector popover,
 * handles custom events, renders the pre-publish panel, and manages modal state.
 *
 * @return The plugin components.
 */
function SlugGenerationWrapper(): React.JSX.Element {
	const [ modalState, setModalState ] = useState< {
		isOpen: boolean;
		suggestions: string[];
		isRegenerating: boolean;
		title: string;
		content: string;
		postId: number | null;
	} >( {
		isOpen: false,
		suggestions: [],
		isRegenerating: false,
		title: '',
		content: '',
		postId: null,
	} );

	const generateSlugs = useCallback(
		async ( title: string, content: string, postId: number | null ) => {
			if ( ! ensureProvider( NOTICE_ID ) ) {
				setModalState( ( prev ) => ( {
					...prev,
					isOpen: false,
					isRegenerating: false,
				} ) );
				return;
			}

			setModalState( ( prev ) => ( { ...prev, isRegenerating: true } ) );
			dispatch( noticesStore ).removeNotice( NOTICE_ID );

			try {
				const params: SlugGenerationAbilityInput = {
					title,
					content,
					context: postId ? postId.toString() : '',
					number_of_suggestions: getSettings().numberOfSuggestions,
				};

				const response = await runAbility< GeneratedSlugData >(
					'ai/slug-generation',
					params
				);

				if (
					response &&
					typeof response === 'object' &&
					'slugs' in response &&
					Array.isArray( response.slugs ) &&
					response.slugs.length > 0
				) {
					setModalState( ( prev ) => ( {
						...prev,
						suggestions: response.slugs,
						isRegenerating: false,
					} ) );
				} else {
					throw new Error(
						__( 'No slug suggestion was generated.', 'ai' )
					);
				}
			} catch ( error: any ) {
				const message =
					typeof error === 'string'
						? error
						: error?.message ??
						  __( 'Failed to generate slug.', 'ai' );
				dispatch( noticesStore ).createErrorNotice( message, {
					id: NOTICE_ID,
					isDismissible: true,
				} );
				setModalState( ( prev ) => ( {
					...prev,
					isOpen: false,
					isRegenerating: false,
				} ) );
			}
		},
		[]
	);

	useEffect( () => {
		if ( ! getSettings().enabled ) {
			return;
		}

		// Listen for the trigger event from the button
		const handleTrigger = ( e: Event ) => {
			const customEvent = e as CustomEvent;
			const { title, content, postId } = customEvent.detail;
			setModalState( {
				isOpen: true,
				suggestions: [],
				isRegenerating: true,
				title,
				content,
				postId,
			} );
			generateSlugs( title, content, postId );
		};

		window.addEventListener( 'ai-trigger-slug-generation', handleTrigger );

		let isAttached = false;
		let root: ReturnType< typeof createRoot > | null = null;
		let observer: MutationObserver | null = null;
		let container: HTMLElement | null = null;

		const findAndAttach = () => {
			if ( isAttached ) {
				return;
			}

			// The slug panel in WordPress 7.0+ renders inside a Dropdown
			// popover. The popover content uses the PostURL component which
			// wraps everything in a div with class "editor-post-url".
			const slugPanel = document.querySelector(
				'.editor-post-url'
			) as HTMLElement | null;

			if ( ! slugPanel ) {
				return;
			}

			// Ensure we don't double attach
			if ( slugPanel.querySelector( '.ai-slug-generation-container' ) ) {
				isAttached = true;
				return;
			}

			// Create wrapper container for the Generate button
			container = document.createElement( 'div' );
			container.className = 'ai-slug-generation-container';

			// Insert the button container at the end of the slug panel,
			// after the permalink section.
			slugPanel.appendChild( container );

			root = createRoot( container );
			root.render( <SlugGenerationButton /> );

			isAttached = true;
		};

		// Run initial check
		findAndAttach();

		// Create observer to listen for sidebar renders/toggles and popover display.
		observer = new MutationObserver( () => {
			const containerExists = !! document.querySelector(
				'.ai-slug-generation-container'
			);
			if ( isAttached && ! containerExists ) {
				if ( root ) {
					root.unmount();
					root = null;
				}
				if ( container ) {
					container.remove();
					container = null;
				}
				isAttached = false;
			}

			if ( ! isAttached ) {
				findAndAttach();
			}
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );

		return () => {
			window.removeEventListener(
				'ai-trigger-slug-generation',
				handleTrigger
			);
			if ( observer ) {
				observer.disconnect();
			}
			if ( root ) {
				root.unmount();
			}
			if ( container ) {
				container.remove();
			}
		};
	}, [ generateSlugs ] );

	if ( ! getSettings().enabled ) {
		return <></>;
	}

	return (
		<>
			<PluginPrePublishPanel
				title={ __( 'Suggested Slugs', 'ai' ) }
				initialOpen={ false }
			>
				<SlugPrePublishPanel />
			</PluginPrePublishPanel>

			{ modalState.isOpen && (
				<SlugGenerationModal
					suggestions={ modalState.suggestions }
					onClose={ () =>
						setModalState( ( prev ) => ( {
							...prev,
							isOpen: false,
						} ) )
					}
					onSelect={ ( selectedSlug ) => {
						dispatch( editorStore ).editPost( {
							slug: selectedSlug,
						} );
						setModalState( ( prev ) => ( {
							...prev,
							isOpen: false,
						} ) );
					} }
					onRegenerate={ () =>
						generateSlugs(
							modalState.title,
							modalState.content,
							modalState.postId
						)
					}
					isRegenerating={ modalState.isRegenerating }
				/>
			) }
		</>
	);
}

registerPlugin( 'ai-slug-generation', {
	render: SlugGenerationWrapper,
} );
