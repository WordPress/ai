/**
 * AI Workspace admin screen entry point.
 *
 * Renders the app shell only: a transcript region, a context-scope control and
 * a multi-line prompt input, or an explanatory state when the workspace cannot
 * operate. Conversation behaviour is added by later work.
 */

/**
 * WordPress dependencies
 */
import { Notice, SelectControl, TextareaControl } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import { createRoot, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Availability, ContextScope, LocalizedData } from './types';

const CONTEXT_SCOPES: { value: ContextScope; label: string }[] = [
	{ value: 'site', label: __( 'Entire site', 'ai' ) },
	{ value: 'post-type', label: __( 'A single post type', 'ai' ) },
	{ value: 'selection', label: __( 'Selected content', 'ai' ) },
];

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
	const [ scope, setScope ] = useState< ContextScope >( 'site' );
	const [ prompt, setPrompt ] = useState( '' );

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
			<section
				className="ai-workspace__transcript"
				aria-label={ __( 'Conversation transcript', 'ai' ) }
				aria-live="polite"
				tabIndex={ 0 }
			>
				<p>{ __( 'Your conversation will appear here.', 'ai' ) }</p>
			</section>

			<form
				className="ai-workspace__composer"
				aria-label={ __( 'Send a message', 'ai' ) }
				onSubmit={ ( event ) => event.preventDefault() }
			>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Context scope', 'ai' ) }
					help={ __(
						'Choose how much of your site the assistant may read. It can only ever read content you are allowed to read.',
						'ai'
					) }
					value={ scope }
					options={ CONTEXT_SCOPES }
					onChange={ ( value ) => setScope( value as ContextScope ) }
				/>

				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Message', 'ai' ) }
					help={ __(
						'Describe what you would like help with.',
						'ai'
					) }
					rows={ 4 }
					value={ prompt }
					onChange={ setPrompt }
				/>
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
