/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useExplorer } from '../context';
import { LIST_ROUTE, type Route } from '../router';

interface RouterLinkProps {
	route: Route;
	children: React.ReactNode;
	/** Renders a button-styled link. Omit for a plain text link. */
	variant?: 'primary' | 'secondary' | 'tertiary' | 'link';
	className?: string;
}

/**
 * Reports whether a click should be left to the browser: anything but a
 * plain left-click, so modified clicks still open a new tab or window.
 *
 * @param event The click event.
 * @return True when the browser should handle the click.
 */
const isModifiedClick = ( event: React.MouseEvent< HTMLElement > ): boolean =>
	event.defaultPrevented ||
	event.button !== 0 ||
	event.metaKey ||
	event.ctrlKey ||
	event.shiftKey ||
	event.altKey;

/**
 * A real link to a view that routes in-app on a plain left-click.
 *
 * @param props           Component props.
 * @param props.route     The view to link to.
 * @param props.children  The link content.
 * @param props.variant   Optional button variant.
 * @param props.className Optional class name.
 * @return The link.
 */
export default function RouterLink( {
	route,
	children,
	variant,
	className,
}: RouterLinkProps ) {
	const { navigate, getHref } = useExplorer();
	const href = getHref( route );

	const onClick = ( event: React.MouseEvent< HTMLElement > ) => {
		if ( isModifiedClick( event ) ) {
			return;
		}

		event.preventDefault();
		navigate( route );
	};

	if ( variant ) {
		return (
			<Button
				__next40pxDefaultSize
				variant={ variant }
				href={ href }
				onClick={ onClick }
				{ ...( className ? { className } : {} ) }
			>
				{ children }
			</Button>
		);
	}

	return (
		<a href={ href } onClick={ onClick } className={ className }>
			{ children }
		</a>
	);
}

/**
 * The "Back to List" link every non-list view shows.
 *
 * @return The link.
 */
export function BackToListLink() {
	return (
		<RouterLink route={ LIST_ROUTE } variant="secondary">
			{ __( '← Back to List', 'ai' ) }
		</RouterLink>
	);
}
