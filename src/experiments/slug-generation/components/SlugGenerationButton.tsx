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

export default function SlugGenerationButton(): React.JSX.Element {
	const { postId, title, content } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			title: ( editor.getEditedPostAttribute( 'title' ) as string ) ?? '',
			content: ( editor.getEditedPostContent() as string ) ?? '',
		};
	}, [] );

	const settings = getSettings();
	const minContentLength = settings.minContentLength;
	const isContentTooShort = ! hasMinimumContent( content, minContentLength );

	const handleButtonClick = () => {
		// Dispatch the trigger event to open the modal and start generation
		window.dispatchEvent(
			new CustomEvent( 'ai-trigger-slug-generation', {
				detail: { postId, title, content },
			} )
		);

		// Close the slug popover immediately by simulating a click on its close/toggle button
		const closeButton = document.querySelector(
			'.editor-post-url button[aria-label="Close"], .editor-post-url button[aria-label="Close popover"], .editor-post-url button[aria-label="Close dialogue"]'
		) as HTMLElement | null;

		if ( closeButton ) {
			closeButton.click();
		} else {
			const toggleButton = document.querySelector(
				'.editor-post-url__toggle, .editor-post-url__toggle-button, button.editor-post-url__hostname'
			) as HTMLElement | null;

			if ( toggleButton ) {
				toggleButton.click();
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

	const buttonLabel = __( 'Generate Slug', 'ai' );
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
