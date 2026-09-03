/**
 * The AI Workspace conversation transcript.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Markdown from './Markdown';
import type { ToolCallRecord, TranscriptEntry } from '../types';

/**
 * Explains why the workspace could not run a turn.
 *
 * @param reason The reason code reported by the server.
 * @return The explanation.
 */
function explainReason( reason: string ): string {
	switch ( reason ) {
		case 'no_tools_registered':
			return __(
				'Site Context has no tools to work with: this site registers no abilities the assistant can call.',
				'ai'
			);
		case 'insufficient_capabilities':
			return __(
				'Site Context has no tools to work with: your account cannot run any of the abilities the assistant would use.',
				'ai'
			);
		case 'general_knowledge_scope':
			return __( 'No tools are declared in General Knowledge.', 'ai' );
		case 'no_function_calling':
			return __(
				'No configured model supports function calling, which Site Context needs.',
				'ai'
			);
		case 'no_text_generation':
			return __( 'No configured model can generate text.', 'ai' );
		default:
			return __( 'The assistant could not answer this turn.', 'ai' );
	}
}

/**
 * Renders the tool activity for one turn as a labelled, collapsible step.
 *
 * Only the invocation record is available here — the turn route reports which
 * ability ran, how it ended and how long it took. Rendering the results
 * themselves, including post lists as a DataViews table, is U6's work and
 * belongs inside this component.
 *
 * @param props           Component props.
 * @param props.toolCalls The tool invocations.
 * @return The rendered steps, or null when there were none.
 */
function ToolSteps( { toolCalls }: { toolCalls: ToolCallRecord[] } ) {
	if ( 0 === toolCalls.length ) {
		return null;
	}

	return (
		<details className="ai-workspace__tools">
			<summary>
				{ sprintf(
					/* translators: %d: number of tool calls. */
					__( 'Looked up site content (%d steps)', 'ai' ),
					toolCalls.length
				) }
			</summary>
			<ul>
				{ toolCalls.map( ( call, index ) => (
					<li key={ call.call_id ?? index }>
						<code>{ call.ability }</code>{ ' ' }
						{ sprintf(
							/* translators: 1: outcome, 2: duration in milliseconds. */
							__( '— %1$s, %2$d ms', 'ai' ),
							call.status,
							call.duration_ms
						) }
					</li>
				) ) }
			</ul>
		</details>
	);
}

/**
 * Renders the terminal state of one turn.
 *
 * The round cap is its own state: the model ended the turn, so it is neither a
 * success nor a transport failure and it offers no retry (R10).
 *
 * @param props         Component props.
 * @param props.entry   The transcript entry.
 * @param props.onRetry Retry handler.
 * @return The rendered state, or null while the turn is still running.
 */
function TurnState( {
	entry,
	onRetry,
}: {
	entry: TranscriptEntry;
	onRetry: ( id: string ) => void;
} ) {
	switch ( entry.status ) {
		case 'streaming':
			return null;
		case 'cancelled':
			return (
				<p className="ai-workspace__state ai-workspace__state--stopped">
					{ __(
						'You stopped this response. It is incomplete.',
						'ai'
					) }
				</p>
			);
		case 'max_rounds':
			return (
				<p className="ai-workspace__state ai-workspace__state--capped">
					{ sprintf(
						/* translators: 1: rounds used, 2: round limit. */
						__(
							'The assistant reached its step limit (%1$d of %2$d) and ended this turn. Ask a narrower follow-up to continue.',
							'ai'
						),
						entry.rounds,
						entry.maxRounds
					) }
				</p>
			);
		case 'tools_unavailable':
		case 'model_unavailable':
			return (
				<Notice status="warning" isDismissible={ false }>
					{ explainReason( entry.detail ) }
				</Notice>
			);
		case 'error':
			return (
				<Notice status="error" isDismissible={ false }>
					<p>
						{ '' === entry.detail
							? __(
									'The assistant could not complete this turn.',
									'ai'
							  )
							: entry.detail }
					</p>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ () => onRetry( entry.id ) }
					>
						{ __( 'Send this message again', 'ai' ) }
					</Button>
				</Notice>
			);
		case 'complete':
		default:
			return null;
	}
}

/**
 * Renders the conversation.
 *
 * The transcript itself is not a live region: it updates on every chunk, and
 * announcing that would flood assistive technology. Announcements come from the
 * single polite region the app renders, at sentence boundaries.
 *
 * @param props         Component props.
 * @param props.entries The transcript entries.
 * @param props.onRetry Retry handler.
 * @return The rendered transcript.
 */
export default function Transcript( {
	entries,
	onRetry,
}: {
	entries: TranscriptEntry[];
	onRetry: ( id: string ) => void;
} ) {
	if ( 0 === entries.length ) {
		return (
			<div className="ai-workspace__empty">
				<p>
					{ __(
						'Ask about your site to get started. In Site Context the assistant can look up content you are allowed to read; in General Knowledge it answers without touching your site.',
						'ai'
					) }
				</p>
			</div>
		);
	}

	return (
		<ol className="ai-workspace__turns">
			{ entries.map( ( entry ) => (
				<li key={ entry.id } className="ai-workspace__turn">
					<article
						className="ai-workspace__message ai-workspace__message--user"
						aria-label={ __( 'Your message', 'ai' ) }
					>
						<p>{ entry.prompt }</p>
					</article>

					<article
						className="ai-workspace__message ai-workspace__message--assistant"
						aria-label={ __( 'Assistant response', 'ai' ) }
					>
						<ToolSteps toolCalls={ entry.toolCalls } />

						{ '' !== entry.text && (
							<Markdown source={ entry.text } />
						) }

						{ 'streaming' === entry.status && (
							<p className="ai-workspace__progress">
								<Spinner />
								{ __( 'Responding…', 'ai' ) }
							</p>
						) }

						<TurnState entry={ entry } onRetry={ onRetry } />

						{ 'streaming' !== entry.status &&
							! entry.streamed &&
							'' !== entry.text && (
								<p className="ai-workspace__state ai-workspace__state--buffered">
									{ __(
										'This site cannot stream responses, so this one arrived all at once.',
										'ai'
									) }
								</p>
							) }
					</article>
				</li>
			) ) }
		</ol>
	);
}
