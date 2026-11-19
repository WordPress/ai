/**
 * The Wapuu that shows how a turn is going.
 */

/**
 * WordPress dependencies
 */
import { useReducedMotion } from '@wordpress/compose';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import busySprite from '../assets/wapuu/wapuu-working.svg';
import doneSprite from '../assets/wapuu/wapuu-celebrating.svg';
import errorSprite from '../assets/wapuu/wapuu-oops.svg';
import type { EntryStatus } from '../types';

/**
 * The states a turn's Wapuu can show.
 */
export type WapuuState = 'busy' | 'done' | 'error';

/**
 * How long the done sprite stays after a turn finishes, in milliseconds.
 */
const DONE_DURATION = 1800;

const SPRITES: Record< WapuuState, string > = {
	busy: busySprite,
	done: doneSprite,
	error: errorSprite,
};

const DATA_URI = 'data:image/svg+xml;base64,';

/**
 * Turns an inlined SVG sprite into its still frame.
 *
 * An `<img>` does not reliably follow the page's reduced-motion setting, so animations are disabled in the markup.
 *
 * @param src The sprite's source.
 * @return The still frame's source.
 */
function toStill( src: string ): string {
	if ( ! src.startsWith( DATA_URI ) ) {
		return src;
	}

	const markup = window
		.atob( src.slice( DATA_URI.length ) )
		.replace(
			'</svg>',
			'<style>*{animation:none!important}</style></svg>'
		);

	return 'data:image/svg+xml,' + encodeURIComponent( markup );
}

/**
 * Each sprite's still frame, built lazily.
 */
const STILLS: Partial< Record< WapuuState, string > > = {};

/**
 * Returns a sprite's still frame.
 *
 * @param state The state whose sprite to return.
 * @return The still frame's source.
 */
function stillOf( state: WapuuState ): string {
	STILLS[ state ] ??= toStill( SPRITES[ state ] );

	return STILLS[ state ];
}

/**
 * Renders one decorative Wapuu sprite.
 *
 * @param props       Component props.
 * @param props.state The state to show.
 * @return The rendered sprite.
 */
export default function WapuuStatus( { state }: { state: WapuuState } ) {
	const isReduced = useReducedMotion();
	const src = isReduced ? stillOf( state ) : SPRITES[ state ];

	return (
		<img
			className={ `ai-workspace__wapuu ai-workspace__wapuu--${ state }` }
			src={ src }
			alt=""
			width={ 48 }
			height={ 48 }
		/>
	);
}

/**
 * Briefly shows the done sprite when a turn seen running finishes.
 *
 * @param props        Component props.
 * @param props.status The turn's status.
 * @return The rendered sprite, or null.
 */
export function WapuuDone( { status }: { status: EntryStatus } ) {
	const previous = useRef( status );
	const [ isShown, setIsShown ] = useState( false );
	const isDone = 'complete' === status || 'awaiting_user' === status;

	useEffect( () => {
		if ( 'streaming' === previous.current && isDone ) {
			setIsShown( true );
		}

		previous.current = status;
	}, [ status, isDone ] );

	// Separate effect so the timer is always cleared.
	useEffect( () => {
		if ( ! isShown ) {
			return undefined;
		}

		const timer = window.setTimeout(
			() => setIsShown( false ),
			DONE_DURATION
		);

		return () => window.clearTimeout( timer );
	}, [ isShown ] );

	if ( ! isShown || ! isDone ) {
		return null;
	}

	return <WapuuStatus state="done" />;
}
