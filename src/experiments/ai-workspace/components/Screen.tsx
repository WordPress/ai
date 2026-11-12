/**
 * A screen from the assistant, drawn from A2UI messages. Experimental.
 */

/**
 * External dependencies
 */
import { createFunctionImplementation } from '@a2ui/web_core/v0_9';
import { OpenUrlApi } from '@a2ui/web_core/v0_9/basic_catalog';
import {
	A2UIProcessor,
	A2UIRenderer,
	wordPressCatalog,
	type ActionMessage,
	type ComponentCatalog,
} from '@swissspidy/a2ui-wp';

/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { describeAction } from '../utils/screen';

/**
 * Component types the server refuses, left out here as well. Their URLs would
 * let the model make the browser fetch any address.
 */
const MEDIA_COMPONENTS = new Set( [ 'Image', 'Video', 'AudioPlayer' ] );

/**
 * The basic catalog without its media components.
 */
const CATALOG: ComponentCatalog = Object.fromEntries(
	Object.entries( wordPressCatalog ).filter(
		( [ type ] ) => ! MEDIA_COMPONENTS.has( type )
	)
);

/**
 * `openUrl`, limited to this site.
 *
 * The basic catalog's own opens any web address in a new tab, which would let a
 * screen send the person anywhere. A screen may only link back into the site.
 */
const OPEN_URL = createFunctionImplementation( OpenUrlApi, ( args ) => {
	const url = new URL( String( args.url ), window.location.href );

	if ( url.origin !== window.location.origin ) {
		throw new Error( 'A screen may only link to this site.' );
	}

	window.open( url.href, '_blank', 'noopener,noreferrer' );
} );

/**
 * Reads the text of the button that sent an event, when it is plain text.
 *
 * @param processor         The screen's processor.
 * @param surfaceId         The surface the button is on.
 * @param sourceComponentId The button's component ID.
 * @return The button's text, or an empty string.
 */
function buttonLabel(
	processor: A2UIProcessor,
	surfaceId: string,
	sourceComponentId: string
): string {
	const components = processor.getSurface( surfaceId )?.componentsModel;
	const button = components?.get( sourceComponentId )?.properties as
		| { child?: unknown }
		| undefined;
	const label =
		'string' === typeof button?.child
			? ( components?.get( button.child )?.properties as
					| { text?: unknown }
					| undefined )
			: undefined;
	const text = label?.text;

	return 'string' === typeof text ? text : '';
}

/**
 * Renders a screen card.
 *
 * Each card has its own processor, so one screen cannot reach into another's
 * surfaces or data. A button event sends a line describing it as the person's
 * next message. Only the screen at the end of the conversation can be used:
 * once anything has been sent after it, the screen is made inert.
 *
 * @param props          Component props.
 * @param props.messages The A2UI messages.
 * @param props.isLive   Whether the screen can still be used.
 * @param props.onAction Sends a button press as the next message.
 * @return The rendered screen.
 */
export default function Screen( {
	messages,
	isLive,
	onAction,
}: {
	messages: object[];
	isLive: boolean;
	onAction: ( text: string ) => void;
} ) {
	const ref = useRef< HTMLDivElement >( null );

	/*
	 * The transcript narrows the tool result again on every render, so the
	 * array is new each time even when nothing changed. The processor holds
	 * what the person has typed and picked, so it is kept for as long as the
	 * messages are the same, not the same array.
	 */
	const source = JSON.stringify( messages );

	const { processor, error } = useMemo( () => {
		const instance = new A2UIProcessor( { functions: [ OPEN_URL ] } );

		try {
			instance.processMessages( JSON.parse( source ) as object[] );

			return { processor: instance, error: null };
		} catch ( caught ) {
			return {
				processor: instance,
				error:
					caught instanceof Error ? caught.message : String( caught ),
			};
		}
	}, [ source ] );

	// React 18 does not pass `inert` through, so it is set on the element.
	useEffect( () => {
		ref.current?.toggleAttribute( 'inert', ! isLive );
	}, [ isLive ] );

	const handleAction = useCallback(
		( message: ActionMessage ) => {
			if ( ! isLive ) {
				return;
			}

			onAction(
				describeAction(
					message.action.name,
					buttonLabel(
						processor,
						message.action.surfaceId,
						message.action.sourceComponentId
					),
					message.action.context as Record< string, unknown >
				)
			);
		},
		[ isLive, onAction, processor ]
	);

	if ( error ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'The assistant sent a screen that could not be shown.',
					'ai'
				) }
			</Notice>
		);
	}

	return (
		<div
			ref={ ref }
			className="ai-workspace__screen"
			role="group"
			aria-label={ __( 'Screen from the assistant', 'ai' ) }
		>
			<A2UIRenderer
				processor={ processor }
				catalog={ CATALOG }
				onAction={ handleAction }
			/>
		</div>
	);
}
