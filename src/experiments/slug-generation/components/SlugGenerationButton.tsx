/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { update } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { hasMinimumContent } from '../../../utils/character-count';
import type { SlugGenerationData } from '../types';

const MINIMUM_CONTENT_COUNT_DEFAULT = 250;
const NUMBER_OF_SUGGESTIONS_DEFAULT = 3;

/**
 * Helper to fetch localized settings passed from PHP to the global window object.
 */
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
 * Renders the "Generate Slug" button inside the Block Editor permalink popover.
 *
 * @return The button component.
 */
export default function SlugGenerationButton(): React.JSX.Element {
	// Retrieve post ID, title, content, and current slug from the block editor store.
	const { postId, title, content, currentSlug } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			title: ( editor.getEditedPostAttribute( 'title' ) as string ) ?? '',
			content: ( editor.getEditedPostContent() as string ) ?? '',
			currentSlug:
				( editor.getEditedPostAttribute( 'slug' ) as string ) ?? '',
		};
	}, [] );

	const settings = getSettings();
	const minContentLength = settings.minContentLength;
	const isContentTooShort = ! hasMinimumContent( content, minContentLength );
	const hasSlug = Boolean( currentSlug && currentSlug.trim().length > 0 );

	const handleButtonClick = () => {
		// Dispatch the trigger event to open the modal and start generation
		window.dispatchEvent(
			new CustomEvent( 'ai-trigger-slug-generation', {
				detail: { postId, title, content },
			} )
		);

		// Close the slug popover immediately in a language-agnostic way
		const popover = document
			.querySelector( '.editor-post-url' )
			?.closest( '.components-popover, .components-dropdown__content' );

		const closeButton = popover?.querySelector< HTMLElement >(
			'.components-popover__header button, button.components-popover__close-button'
		);

		if ( closeButton ) {
			closeButton.click();
		} else {
			const toggleButton = document.querySelector< HTMLElement >(
				'.editor-post-url__toggle[aria-expanded="true"], button.editor-post-url__hostname[aria-expanded="true"], .editor-post-url__toggle, .editor-post-url__toggle-button, button.editor-post-url__hostname'
			);

			if ( toggleButton ) {
				toggleButton.click();
			} else {
				document.activeElement?.dispatchEvent(
					new KeyboardEvent( 'keydown', {
						key: 'Escape',
						keyCode: 27,
						bubbles: true,
					} )
				);
			}
		}
	};

	const tooShortLabel = sprintf(
		/* translators: %d: minimum number of characters required. */
		__(
			'Slug suggestions will be available when the post content has at least %d characters.',
			'ai'
		),
		minContentLength
	);

	const buttonLabel = hasSlug
		? __( 'Regenerate Slug', 'ai' )
		: __( 'Generate Slug', 'ai' );
	const buttonTooltip = isContentTooShort ? tooShortLabel : buttonLabel;

	return (
		<Button
			variant="secondary"
			icon={ update }
			onClick={ handleButtonClick }
			disabled={ isContentTooShort }
			label={ buttonTooltip }
			showTooltip
			accessibleWhenDisabled
			__next40pxDefaultSize
		>
			{ buttonLabel }
		</Button>
	);
}
