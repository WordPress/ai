/**
 * The AI Workspace conversation transcript.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ConfirmProposal from './ConfirmProposal';
import EmptyState from './EmptyState';
import Markdown from './Markdown';
import ResultsTable from './ResultsTable';
import { toPostResultSet } from '../utils/post-results';
import { toProposalId } from '../utils/proposal';
import type { RestData, ToolCallRecord, TranscriptEntry } from '../types';

/**
 * The ability whose successful result carries a stored proposal.
 */
const PROPOSAL_ABILITY = 'ai/propose-drafts';

/**
 * Summarises what a turn retrieved, as one line (R24, R25).
 *
 * The counts come from the server's own `retrieval` summary, never from
 * arithmetic over a result set. A withheld count in particular is not the gap
 * between a search's `total` and its rows: that gap also contains everything
 * pagination left on later pages, so deriving it would announce posts "hidden
 * by your role" on an ordinary search that simply has more pages.
 *
 * `withheld` is null when the ability reports no count, which is not the same
 * as zero. Null is rendered as silence rather than "none withheld", because
 * only a counting ability can make that claim.
 *
 * @param toolCalls The turn's tool invocations.
 * @return The trace line, or null when nothing reported a retrieval.
 */
function retrievalTrace( toolCalls: ToolCallRecord[] ): string | null {
	let searched = 0;
	let read = 0;
	let withheld: number | null = null;
	let reported = false;

	toolCalls.forEach( ( call ) => {
		const summary = call.retrieval;

		if ( ! summary ) {
			return;
		}

		reported = true;

		if ( 'read' === summary.kind ) {
			read += summary.returned;
		} else {
			searched += summary.returned;
		}

		if ( 'number' === typeof summary.withheld ) {
			withheld = ( withheld ?? 0 ) + summary.withheld;
		}
	} );

	if ( ! reported ) {
		return null;
	}

	const parts: string[] = [
		sprintf(
			/* translators: %d: number of posts searched. */
			_n( 'Searched %d post', 'Searched %d posts', searched, 'ai' ),
			searched
		),
	];

	if ( read > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of posts read in full. */
				_n( 'read %d in full', 'read %d in full', read, 'ai' ),
				read
			)
		);
	}

	/*
	 * Named as a capability outcome, not as missing content: the count says how
	 * many rows the person's own permissions kept back, and deliberately never
	 * identifies them.
	 */
	if ( null !== withheld && withheld > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of posts withheld by permissions. */
				_n(
					'%d hidden by your permissions',
					'%d hidden by your permissions',
					withheld,
					'ai'
				),
				withheld
			)
		);
	}

	return parts.join( ' \u00b7 ' );
}

/**
 * Collects the proposals a turn produced, in the order they were made.
 *
 * Only the identifier is taken from the tool result. Everything the person then
 * reviews is fetched back from the server's stored copy, so the confirmation
 * cannot show one thing and write another (R16).
 *
 * @param toolCalls The turn's tool invocations.
 * @return The proposal identifiers.
 */
function proposalIds( toolCalls: ToolCallRecord[] ): string[] {
	const ids: string[] = [];

	toolCalls.forEach( ( call ) => {
		if ( call.ability !== PROPOSAL_ABILITY || call.status !== 'success' ) {
			return;
		}

		const id = toProposalId( call.result );

		if ( '' !== id && ! ids.includes( id ) ) {
			ids.push( id );
		}
	} );

	return ids;
}

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
 * Each invocation record reports which ability ran, how it ended and how long
 * it took, and carries the ability's own result. A result that narrows to a
 * post list is rendered as a table; anything else is left to the assistant's
 * prose, because the transcript can only render shapes it understands.
 *
 * @param props           Component props.
 * @param props.toolCalls The tool invocations.
 * @return The rendered steps, or null when there were none.
 */
function ToolSteps( { toolCalls }: { toolCalls: ToolCallRecord[] } ) {
	if ( 0 === toolCalls.length ) {
		return null;
	}

	/*
	 * The trace is the disclosure's summary rather than a second element above
	 * it: that keeps what the assistant retrieved on one always-visible line
	 * (R24) while the per-invocation detail stays one interaction away, which
	 * is what the plan asks for without rendering the same facts twice. An
	 * ability that reports no retrieval summary falls back to naming the steps,
	 * so a tool this component does not understand still discloses that it ran.
	 */
	const trace = retrievalTrace( toolCalls );

	return (
		<details className="ai-workspace__tools">
			<summary>
				{ trace ??
					sprintf(
						/* translators: %d: number of tool calls. */
						__( 'Looked up site content (%d steps)', 'ai' ),
						toolCalls.length
					) }
			</summary>
			<ul>
				{ toolCalls.map( ( call, index ) => {
					const posts = toPostResultSet( call.result );

					return (
						<li key={ call.call_id ?? index }>
							<code>{ call.ability }</code>{ ' ' }
							{ sprintf(
								/* translators: 1: outcome, 2: duration in milliseconds. */
								__( '— %1$s, %2$d ms', 'ai' ),
								call.status,
								call.duration_ms
							) }
							{ null !== posts && (
								<ResultsTable result={ posts } />
							) }
						</li>
					);
				} ) }
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
 * @param props                Component props.
 * @param props.entries        The transcript entries.
 * @param props.onRetry        Retry handler.
 * @param props.onSuggest      Fills the composer with a suggested prompt.
 * @param props.rest           The REST transport data.
 * @param props.conversationId The conversation being rendered.
 * @return The rendered transcript.
 */
export default function Transcript( {
	entries,
	onRetry,
	onSuggest,
	rest,
	conversationId,
}: {
	entries: TranscriptEntry[];
	onRetry: ( id: string ) => void;
	onSuggest: ( prompt: string ) => void;
	rest: RestData;
	conversationId: string;
} ) {
	if ( 0 === entries.length ) {
		return <EmptyState onSelect={ onSuggest } />;
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

						{ '' !== conversationId &&
							proposalIds( entry.toolCalls ).map( ( id ) => (
								<ConfirmProposal
									key={ id }
									proposalId={ id }
									conversationId={ conversationId }
									rest={ rest }
								/>
							) ) }

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
