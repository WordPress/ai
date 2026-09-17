/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useRef } from '@wordpress/element';
import { update } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { hasMinimumContent } from '../../../utils/character-count';
import { useSlugSource } from '../hooks/useSlugGeneration';
import { getSettings } from '../settings';
import { TRIGGER_EVENT } from '../constants';

/**
 * Closes the permalink popover the button lives in.
 *
 * `useDialog()` — which `Popover` builds on — registers a native `keydown` listener on
 * the popover element and closes on `ESCAPE`. Dispatching a bubbling Escape keydown from
 * inside the popover is therefore the same path a keyboard user takes, and avoids having
 * to locate a close button whose markup differs across WordPress versions.
 *
 * @param element An element inside the popover.
 */
const closeParentPopover = ( element: HTMLElement | null ): void => {
	element?.dispatchEvent(
		new KeyboardEvent( 'keydown', {
			key: 'Escape',
			// `useDialog()` compares against the legacy `keyCode` rather than `key`.
			keyCode: 27,
			bubbles: true,
		} )
	);
};

/**
 * Renders the "Generate Slug" button inside the Block Editor permalink popover.
 *
 * @return The button component.
 */
export default function SlugGenerationButton(): React.JSX.Element {
	const buttonRef = useRef< HTMLButtonElement >( null );
	const { postId, title, content, currentSlug } = useSlugSource();

	const { minContentLength } = getSettings();
	const isContentTooShort = ! hasMinimumContent( content, minContentLength );
	const hasSlug = currentSlug.trim().length > 0;

	const handleButtonClick = () => {
		// Open the modal and start generating.
		window.dispatchEvent(
			new CustomEvent( TRIGGER_EVENT, {
				detail: { postId, title, content },
			} )
		);

		closeParentPopover( buttonRef.current );
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
			ref={ buttonRef }
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
