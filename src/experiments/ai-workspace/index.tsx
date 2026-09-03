/**
 * AI Workspace admin screen entry point.
 *
 * Renders the app shell — a transcript region, a context-scope control and a
 * multi-line prompt input — and drives one conversation through the turn route,
 * or shows an explanatory state when the workspace cannot operate.
 */

/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ContextScope from './components/ContextScope';
import PromptInput from './components/PromptInput';
import Transcript from './components/Transcript';
import { useTurn } from './hooks/useTurn';
import type { Availability, LocalizedData } from './types';

/**
 * Returns the explanatory message for a workspace that cannot operate.
 *
 * @param availability Availability reported by the server.
 * @param settingsUrl  URL of the plugin settings screen.
 * @return The explanation, or null when the workspace is ready.
 */
function getUnavailableMessage(
	availability: Availability,
	settingsUrl: string
): { title: string; body: string; actionUrl: string } | null {
	switch ( availability.status ) {
		case 'no-credentials':
			return {
				title: __( 'No AI credentials configured', 'ai' ),
				body: __(
					'The AI Workspace needs valid AI provider credentials before it can hold a conversation. Add a provider connection to continue.',
					'ai'
				),
				actionUrl: settingsUrl,
			};
		case 'no-function-calling':
			return {
				title: __( 'No compatible model available', 'ai' ),
				body: __(
					'The AI Workspace needs a model that supports function calling so the assistant can read your content and propose changes. None of the configured providers offer one.',
					'ai'
				),
				actionUrl: settingsUrl,
			};
		case 'ready':
		default:
			return null;
	}
}

/**
 * Renders the explanatory state shown when the workspace cannot operate.
 *
 * @param props              Component props.
 * @param props.availability Availability reported by the server.
 * @param props.settingsUrl  URL of the plugin settings screen.
 * @return The unavailable state, or null when the workspace is ready.
 */
function UnavailableState( {
	availability,
	settingsUrl,
}: {
	availability: Availability;
	settingsUrl: string;
} ) {
	const message = getUnavailableMessage( availability, settingsUrl );

	if ( ! message ) {
		return null;
	}

	return (
		<Notice status="warning" isDismissible={ false }>
			<h2>{ message.title }</h2>
			<p>{ message.body }</p>
			<p>
				<a href={ message.actionUrl }>
					{ __( 'Open AI settings', 'ai' ) }
				</a>
			</p>
		</Notice>
	);
}

/**
 * Renders the AI Workspace app shell.
 *
 * @param props      Component props.
 * @param props.data Localized data provided by the server.
 * @return The workspace app shell.
 */
function WorkspaceApp( { data }: { data: LocalizedData } ) {
	const [ prompt, setPrompt ] = useState( '' );
	const inputRef = useRef< HTMLTextAreaElement | null >( null );
	const hasRun = useRef( false );
	const {
		announcement,
		clear,
		entries,
		isRunning,
		isStopping,
		retry,
		scope,
		send,
		setScope,
		stop,
		summary,
	} = useTurn( data );

	// Focus returns to the input when a turn finishes, so a keyboard user is
	// never left with focus on a control that has just been disabled.
	useEffect( () => {
		if ( isRunning ) {
			hasRun.current = true;
			return;
		}

		// Only after a turn: focus is not stolen when the screen first loads.
		if ( hasRun.current ) {
			inputRef.current?.focus();
		}
	}, [ isRunning ] );

	if ( data.availability.status !== 'ready' ) {
		return (
			<div className="ai-workspace__app">
				<UnavailableState
					availability={ data.availability }
					settingsUrl={ data.settingsUrl }
				/>
			</div>
		);
	}

	return (
		<div className="ai-workspace__app">
			{ /*
			 * The one polite live region for this screen. The transcript itself
			 * updates on every streamed chunk and is deliberately not live;
			 * this region is updated at sentence boundaries and on completion.
			 */ }
			<div
				className="screen-reader-text"
				aria-live="polite"
				aria-atomic="true"
			>
				{ announcement }
			</div>

			<section
				className="ai-workspace__transcript"
				aria-label={ __( 'Conversation transcript', 'ai' ) }
				tabIndex={ 0 }
			>
				<Transcript entries={ entries } onRetry={ retry } />
			</section>

			<form
				className="ai-workspace__composer"
				aria-label={ __( 'Send a message', 'ai' ) }
				onSubmit={ ( event ) => event.preventDefault() }
			>
				<ContextScope
					value={ scope }
					onChange={ setScope }
					disabled={ isRunning }
				/>

				<PromptInput
					value={ prompt }
					onChange={ setPrompt }
					onSubmit={ () => {
						void send( prompt, scope );
						setPrompt( '' );
					} }
					onStop={ () => {
						void stop();
					} }
					onClear={ clear }
					isRunning={ isRunning }
					isStopping={ isStopping }
					canClear={ entries.length > 0 }
					inputRef={ inputRef }
				/>

				<p className="screen-reader-text">{ summary }</p>
			</form>
		</div>
	);
}

domReady( () => {
	const container = document.getElementById( 'ai-workspace-root' );

	if ( ! container ) {
		return;
	}

	const data = window.aiWorkspace;

	if ( ! data ) {
		return;
	}

	createRoot( container ).render( <WorkspaceApp data={ data } /> );
} );
