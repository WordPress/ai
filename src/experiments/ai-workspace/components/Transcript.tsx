/**
 * The AI Workspace conversation transcript.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import AskUser from './AskUser';
import ConfirmProposal from './ConfirmProposal';
import EmptyState from './EmptyState';
import Markdown from './Markdown';
import ResultsTable from './ResultsTable';
import WapuuStatus, { WapuuDone } from './WapuuStatus';
import { toPostResultSet } from '../utils/post-results';
import { PROPOSAL_ABILITIES, toProposalId } from '../utils/proposal';
import { questionState, toQuestion } from '../utils/question';
import type { DismissResult } from '../hooks/useTurn';
import type {
	ContextScope,
	ProposalExecution,
	Question,
	RestData,
	ToolCallRecord,
	TranscriptEntry,
} from '../types';

/**
 * The ability whose successful result carries a question with choices.
 */
const QUESTION_ABILITY = 'ai/ask-user';

/**
 * Summarises what a turn retrieved, as one line.
 *
 * Counts come from the server's `retrieval` summary, not `total - rows` (which would count later pages as hidden).
 * "Searched" uses `examined` (before the permission filter), so it already includes the hidden rows.
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
			// Clamped so "searched" is never fewer than were shown.
			searched +=
				'number' === typeof summary.examined
					? Math.max( summary.examined, summary.returned )
					: summary.returned;
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
 * Collects the proposal IDs a turn produced, in order.
 *
 * @param toolCalls The turn's tool invocations.
 * @return The proposal identifiers.
 */
function proposalIds( toolCalls: ToolCallRecord[] ): string[] {
	const ids: string[] = [];

	toolCalls.forEach( ( call ) => {
		if (
			! PROPOSAL_ABILITIES.includes( call.ability ) ||
			call.status !== 'success'
		) {
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
 * Collects the questions a turn asked, in the order they were asked.
 *
 * @param toolCalls The turn's tool invocations.
 * @return The questions.
 */
function questions( toolCalls: ToolCallRecord[] ): Question[] {
	const asked: Question[] = [];

	toolCalls.forEach( ( call ) => {
		if ( call.ability !== QUESTION_ABILITY || call.status !== 'success' ) {
			return;
		}

		const question = toQuestion( call.result );

		if ( question ) {
			asked.push( question );
		}
	} );

	return asked;
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
		case 'surface_emptied':
			return __(
				'Site Context has no tools to work with: every ability the assistant would call has been removed from its surface.',
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
 * @param props           Component props.
 * @param props.toolCalls The tool invocations.
 * @return The rendered steps, or null when there were none.
 */
function ToolSteps( { toolCalls }: { toolCalls: ToolCallRecord[] } ) {
	if ( 0 === toolCalls.length ) {
		return null;
	}

	const trace = retrievalTrace( toolCalls );

	return (
		<details className="ai-workspace__tools">
			<summary>
				{ trace ??
					sprintf(
						/* translators: %d: number of tool calls. */
						_n(
							'Checked the site (%d step)',
							'Checked the site (%d steps)',
							toolCalls.length,
							'ai'
						),
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
			// A follow-up is never retried: the server owes exactly one.
			if ( entry.followUp ) {
				return (
					<p className="ai-workspace__state ai-workspace__state--error">
						<WapuuStatus state="error" />
						{ entry.detail }
					</p>
				);
			}

			return (
				<Notice status="error" isDismissible={ false }>
					<p className="ai-workspace__failure">
						<WapuuStatus state="error" />
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
		// The proposal or question card above is what the person acts on next.
		case 'awaiting_user':
		case 'complete':
		default:
			return null;
	}
}

/**
 * Renders the conversation; not a live region, since the app announces at sentence boundaries.
 *
 * @param props                Component props.
 * @param props.entries        The transcript entries.
 * @param props.onRetry        Retry handler.
 * @param props.onSuggest      Fills the composer with a suggested prompt.
 * @param props.rest           The REST transport data.
 * @param props.conversationId The conversation being rendered.
 * @param props.onFollowUp     Asks the assistant to follow up a settled proposal.
 * @param props.onAnswer       Sends a question's choice as the next message.
 * @param props.onDismiss      Closes the question a turn asked, by that turn's ID.
 * @param props.outcomes       Reported outcomes, keyed by proposal ID.
 * @return The rendered transcript.
 */
export default function Transcript( {
	entries,
	onRetry,
	onSuggest,
	rest,
	conversationId,
	onFollowUp,
	onAnswer,
	onDismiss,
	outcomes = {},
}: {
	entries: TranscriptEntry[];
	onRetry: ( id: string ) => void;
	onSuggest: ( prompt: string ) => void;
	rest: RestData;
	conversationId: string;
	onFollowUp: ( scope: ContextScope, conversationId: string ) => void;
	onAnswer: ( choice: string, scope: ContextScope ) => void;
	onDismiss: ( turnId: string ) => Promise< DismissResult >;
	outcomes?: Record< string, ProposalExecution >;
} ) {
	if ( 0 === entries.length ) {
		return <EmptyState onSelect={ onSuggest } />;
	}

	return (
		<ol className="ai-workspace__turns">
			{ entries.map( ( entry, index ) => {
				// A dismissal shows on the card it closed, not as a turn.
				if ( 'dismissed' === entry.status ) {
					return null;
				}

				const { isLive, isDismissed, reply } = questionState(
					entries,
					index
				);

				return (
					<li key={ entry.id } className="ai-workspace__turn">
						{ ! entry.followUp && (
							<article
								className="ai-workspace__message ai-workspace__message--user"
								aria-label={ __( 'Your message', 'ai' ) }
							>
								<p>{ entry.prompt }</p>
							</article>
						) }

						<article
							className="ai-workspace__message ai-workspace__message--assistant"
							aria-label={ __( 'Assistant response', 'ai' ) }
						>
							{ /* Questions render as their own card below. */ }
							<ToolSteps
								toolCalls={ entry.toolCalls.filter(
									( call ) =>
										call.ability !== QUESTION_ABILITY
								) }
							/>

							{ '' !== entry.text && (
								<Markdown source={ entry.text } />
							) }

							{ 'streaming' === entry.status && (
								<p className="ai-workspace__progress">
									<WapuuStatus state="busy" />
									{ __( 'Responding…', 'ai' ) }
								</p>
							) }

							<WapuuDone status={ entry.status } />

							{ '' !== conversationId &&
								proposalIds( entry.toolCalls ).map( ( id ) => (
									<ConfirmProposal
										key={ id }
										proposalId={ id }
										conversationId={ conversationId }
										rest={ rest }
										stored={ outcomes[ id ] }
										onSettled={ ( canFollowUp ) => {
											if ( canFollowUp ) {
												onFollowUp(
													entry.scope,
													conversationId
												);
											}
										} }
									/>
								) ) }

							{ questions( entry.toolCalls ).map(
								( question, questionIndex ) => (
									<AskUser
										key={ questionIndex }
										question={ question }
										isLive={ isLive }
										isDismissed={ isDismissed }
										chosen={
											reply &&
											question.choices.includes( reply )
												? reply
												: null
										}
										onAnswer={ ( choice ) =>
											onAnswer( choice, entry.scope )
										}
										// A question saved without its turn ID cannot be dismissed.
										onDismiss={
											'' === entry.turnId
												? undefined
												: () =>
														onDismiss(
															entry.turnId
														)
										}
									/>
								)
							) }

							<TurnState entry={ entry } onRetry={ onRetry } />
						</article>
					</li>
				);
			} ) }
		</ol>
	);
}
