/**
 * The Wapuu that shows how a turn is going (R17).
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

/**
 * The prefix of the data URI the bundler inlines each sprite as.
 */
const DATA_URI = 'data:image/svg+xml;base64,';

/**
 * Turns a sprite into its still frame.
 *
 * Each sprite keeps its motion inside its own reduced-motion query, but an
 * `<img>` evaluates that query in a separate document, which does not reliably
 * follow the page's setting. Switching every animation off in the markup is the
 * still pose the query would have shown. A source that is not an inlined SVG is
 * returned as it is, so this can only ever fall back to the moving sprite.
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
 * Each sprite's still frame, built once per page the first time it is needed.
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
 * Renders one Wapuu sprite.
 *
 * The sprite is decorative: the text beside it is what says how the turn is
 * going, so it has an empty alternative and is never announced on its own.
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
 * Shows the done sprite for a moment after a turn finishes.
 *
 * Only a turn seen running gets one, so a chat reopened from history does not
 * celebrate every turn it already had. A turn that ends any other way, stopped,
 * capped or failed, shows nothing here.
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

	// The timer has its own effect so that re-running the one above can never
	// leave the sprite up without something to take it down.
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
