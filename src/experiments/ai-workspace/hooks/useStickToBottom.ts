/**
 * Keeps a scrolling transcript pinned to its newest content while the person is reading there.
 */

/**
 * WordPress dependencies
 */
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';

/**
 * How close to the bottom, in pixels, still counts as reading the newest content.
 */
export const NEAR_BOTTOM_THRESHOLD = 48;

/**
 * Reports whether a scroll container is scrolled to within the threshold of its bottom.
 *
 * @param metrics              The container's scroll metrics.
 * @param metrics.scrollTop    Distance scrolled from the top.
 * @param metrics.scrollHeight Height of the scrolled content.
 * @param metrics.clientHeight Height of the visible area.
 * @param threshold            Allowed distance from the bottom, in pixels.
 * @return Whether it is near the bottom.
 */
export function isNearBottom(
	metrics: { scrollTop: number; scrollHeight: number; clientHeight: number },
	threshold: number = NEAR_BOTTOM_THRESHOLD
): boolean {
	return (
		metrics.scrollHeight - metrics.scrollTop - metrics.clientHeight <=
		threshold
	);
}

/**
 * Jumps a container to its bottom, instantly so streaming and reduced motion behave alike.
 *
 * @param node The scroll container.
 */
function scrollToBottom( node: HTMLElement ): void {
	node.scrollTo( { top: node.scrollHeight, behavior: 'instant' } );
}

/**
 * Follows new content in a scroll container while the person is near its bottom. Never moves focus.
 *
 * @param content A value that changes whenever the container's content does.
 * @return A callback ref for the container, and `stick` to start following again (e.g. when a chat opens).
 */
export function useStickToBottom< T extends HTMLElement >(
	content: unknown
): { ref: ( node: T | null ) => void; stick: () => void } {
	const [ node, setNode ] = useState< T | null >( null );
	const stuck = useRef( true );

	useEffect( () => {
		if ( ! node ) {
			return undefined;
		}

		const onScroll = (): void => {
			stuck.current = isNearBottom( node );
		};

		node.addEventListener( 'scroll', onScroll, { passive: true } );

		return () => node.removeEventListener( 'scroll', onScroll );
	}, [ node ] );

	useLayoutEffect( () => {
		if ( node && stuck.current ) {
			scrollToBottom( node );
		}
	}, [ content, node ] );

	const stick = useCallback( (): void => {
		stuck.current = true;
	}, [] );

	return { ref: setNode, stick };
}
