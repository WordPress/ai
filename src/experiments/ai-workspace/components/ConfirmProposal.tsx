/**
 * The confirmation surface for a stored AI Workspace write proposal.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ProposedChange from './ProposedChange';
import {
	readFollowUp,
	toEditError,
	toProposal,
	toProposalExecution,
} from '../utils/proposal';
import {
	applyLabel,
	canOpen,
	changeLabel,
	createLabel,
	createdLabel,
	initialSelection,
	isUpdate,
	isUpdateExecution,
	listLink,
	openChangeLabel,
	openLabel,
	reviewLabel,
	templateNotice,
	updateOutcomeLabel,
	updatedLabel,
} from '../utils/proposal-card';
import type {
	Proposal,
	ProposalExecution,
	ProposalItemOutcome,
	RestData,
} from '../types';

/**
 * How the confirmation is currently behaving.
 *
 * `waiting` is an approval another window is still writing: this card writes
 * nothing and shows that window's outcome once it is stored.
 */
type ConfirmState =
	| 'loading'
	| 'ready'
	| 'working'
	| 'waiting'
	| 'done'
	| 'error';

/**
 * How long to wait between reads of a proposal another window is writing, in
 * milliseconds.
 */
const WAIT_INTERVAL = 1000;

/**
 * Returns the stored outcome of a proposal whose approval has finished.
 *
 * @param proposal The proposal as read back from the server.
 * @return The outcome, or null while nothing is settled.
 */
function settledOutcome( proposal: Proposal ): ProposalExecution | null {
	if ( 'executed' !== proposal.status && 'failed' !== proposal.status ) {
		return null;
	}

	return proposal.results;
}

/**
 * Names an outcome other than "created" for the person reading it.
 *
 * @param outcome The server's outcome code.
 * @return The label.
 */
function outcomeLabel( outcome: string ): string {
	switch ( outcome ) {
		case 'failed':
			return __( 'Not created', 'ai' );
		case 'denied':
			return __( 'Not created: you no longer have permission', 'ai' );
		case 'duplicate':
			return __( 'Already created earlier', 'ai' );
		case 'deselected':
			return __( 'Not selected', 'ai' );
		default:
			return outcome;
	}
}

/**
 * Renders one row per item, with why an item was not created and a way to
 * open any item that points at a post.
 *
 * @param props       Component props.
 * @param props.items The outcomes to list.
 * @return The rows.
 */
function OutcomeRows( { items }: { items: ProposalItemOutcome[] } ) {
	return (
		<ul className="ai-workspace__proposal-outcomes">
			{ items.map( ( item ) => (
				<li key={ item.key } className="ai-workspace__proposal-outcome">
					<span className="ai-workspace__proposal-outcome-text">
						<strong>{ item.title }</strong>
						{ 'created' !== item.outcome && (
							<span className="ai-workspace__proposal-outcome-reason">
								{ outcomeLabel( item.outcome ) }
								{ '' !== item.error_message &&
									': ' + item.error_message }
							</span>
						) }
					</span>
					{ canOpen( item ) && (
						<Button
							size="compact"
							variant="secondary"
							href={ item.edit_link }
							label={ sprintf(
								/* translators: %s: the item's title. */
								__( 'Open “%s”', 'ai' ),
								item.title
							) }
							showTooltip={ false }
						>
							{ __( 'Open', 'ai' ) }
						</Button>
					) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * Renders what an update changed, one row per change, each with a way to the
 * post or settings screen it changed.
 *
 * @param props             Component props.
 * @param props.execution   The execution result.
 * @param props.interrupted True when the approval stopped part way.
 * @return The outcome.
 */
function UpdateOutcomes( {
	execution,
	interrupted,
}: {
	execution: ProposalExecution;
	interrupted: boolean;
} ) {
	const heading = updatedLabel( execution.items );

	return (
		<section className="ai-workspace__proposal" aria-label={ heading }>
			<h3 className="ai-workspace__proposal-title">{ heading }</h3>
			{ interrupted && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This approval stopped before it finished. These are the changes it got to; nothing else was changed.',
						'ai'
					) }
				</Notice>
			) }
			<ul className="ai-workspace__proposal-outcomes">
				{ execution.items.map( ( item ) => (
					<li
						key={ item.key }
						className="ai-workspace__proposal-outcome"
					>
						<span className="ai-workspace__proposal-outcome-text">
							<strong>{ changeLabel( item ) }</strong>
							<span className="ai-workspace__proposal-outcome-reason">
								{ updateOutcomeLabel( item.outcome ) }
								{ 'failed' === item.outcome &&
									'' !== item.error_message &&
									': ' + item.error_message }
							</span>
							{ /*
							 * What the field now holds, so the card keeps the
							 * change once the side-by-side view is gone. Content
							 * is reported by length only, so it shows nothing here.
							 */ }
							{ ( 'updated' === item.outcome ||
								'unchanged' === item.outcome ) &&
								'' !== item.value && (
									<span className="ai-workspace__proposal-outcome-value">
										{ sprintf(
											/* translators: %s: the value the field now holds. */
											__( 'Now: %s', 'ai' ),
											item.value
										) }
									</span>
								) }
						</span>
						{ '' !== item.edit_link && (
							<Button
								size="compact"
								variant="secondary"
								href={ item.edit_link }
							>
								{ openChangeLabel( item ) }
							</Button>
						) }
					</li>
				) ) }
			</ul>
		</section>
	);
}

/**
 * Renders what happened after approval, with the way into the result.
 *
 * One created item gets one prominent button to open it, with any other item's
 * outcome listed under it. Several get a row each with its own "Open", and a
 * link to the list that holds them all. An item that was not created says why,
 * in its own row.
 *
 * @param props             Component props.
 * @param props.execution   The execution result.
 * @param props.interrupted True when the approval stopped part way.
 * @return The outcome.
 */
function Outcomes( {
	execution,
	interrupted = false,
}: {
	execution: ProposalExecution;
	interrupted?: boolean;
} ) {
	if ( execution.discarded ) {
		return (
			<section
				className="ai-workspace__proposal"
				aria-label={ __( 'Discarded', 'ai' ) }
			>
				<h3 className="ai-workspace__proposal-title">
					{ __( 'Discarded', 'ai' ) }
				</h3>
				<p className="ai-workspace__proposal-hint">
					{ __( 'Nothing was written.', 'ai' ) }
				</p>
			</section>
		);
	}

	if ( isUpdateExecution( execution ) ) {
		return (
			<UpdateOutcomes
				execution={ execution }
				interrupted={ interrupted }
			/>
		);
	}

	const created = execution.items.filter(
		( item ) => 'created' === item.outcome
	);
	const heading = createdLabel( created );
	const [ only ] = created;

	if ( 1 === created.length && only && canOpen( only ) ) {
		const others = execution.items.filter( ( item ) => item !== only );

		return (
			<section className="ai-workspace__proposal" aria-label={ heading }>
				<h3 className="ai-workspace__proposal-title">{ heading }</h3>
				<p className="ai-workspace__proposal-created">{ only.title }</p>
				<div className="ai-workspace__proposal-actions">
					<Button
						__next40pxDefaultSize
						variant="primary"
						href={ only.edit_link }
					>
						{ openLabel( only ) }
					</Button>
				</div>
				{ others.length > 0 && <OutcomeRows items={ others } /> }
			</section>
		);
	}

	const openable = created.filter( canOpen );
	const list = openable.length > 1 ? listLink( openable ) : null;

	return (
		<section className="ai-workspace__proposal" aria-label={ heading }>
			<h3 className="ai-workspace__proposal-title">{ heading }</h3>
			<OutcomeRows items={ execution.items } />
			{ list && (
				<Button variant="link" href={ list.url }>
					{ list.label }
				</Button>
			) }
		</section>
	);
}

/**
 * Renders the stored values of a proposal and the controls that approve them.
 *
 * The values shown are read back from the server's stored copy of the proposal,
 * never from the assistant's message: R16 exists because the model's summary of
 * what it will write is influenced by the same untrusted content the assistant
 * read. A single draft starts selected, so the card's main button does what was
 * asked for; a batch starts unchecked, so an item appended to an otherwise
 * legitimate batch has to be chosen deliberately ({@link initialSelection}).
 *
 * @param props                Component props.
 * @param props.proposalId     The proposal to confirm.
 * @param props.conversationId The conversation the proposal belongs to.
 * @param props.rest           The REST transport data.
 * @param props.onSettled      Called once the proposal is approved or discarded,
 *                             with whether the assistant may follow up.
 * @param props.stored         The outcome already reported into a reopened
 *                             chat, shown as it is with nothing fetched.
 * @return The confirmation surface.
 */
export default function ConfirmProposal( {
	proposalId,
	conversationId,
	rest,
	onSettled,
	stored,
}: {
	proposalId: string;
	conversationId: string;
	rest: RestData;
	onSettled: ( canFollowUp: boolean ) => void;
	stored?: ProposalExecution | undefined;
} ) {
	const [ proposal, setProposal ] = useState< Proposal | null >( null );
	const [ selected, setSelected ] = useState< string[] >( [] );
	const [ state, setState ] = useState< ConfirmState >(
		stored ? 'done' : 'loading'
	);
	const [ detail, setDetail ] = useState( '' );
	const [ execution, setExecution ] = useState< ProposalExecution | null >(
		stored ?? null
	);
	// True when the approval that wrote the outcome stopped part way.
	const [ interrupted, setInterrupted ] = useState( false );
	// The person's edits to proposed values, keyed by item, while open.
	const [ edits, setEdits ] = useState< Record< string, string > >( {} );
	// The server's message about a refused edit, keyed by item.
	const [ editErrors, setEditErrors ] = useState< Record< string, string > >(
		{}
	);

	const { proposals } = rest.routes;
	const base = ( proposals ?? '' ) + '/' + proposalId;

	// Set false once this confirmation leaves the transcript -- clearing the
	// conversation unmounts it mid-request -- so a reply that arrives afterwards
	// updates nothing.
	const mounted = useRef( true );

	useEffect( () => {
		mounted.current = true;

		return () => {
			mounted.current = false;
		};
	}, [] );

	/*
	 * Total by construction: every caller drives a dialog whose controls are
	 * already disabled while the request is out, so a rejection that escaped
	 * here would strand the dialog on `loading` or `working` with no way back
	 * short of a reload. A transport that never reached the server is reported
	 * as its own failure rather than as a refusal, because the two differ in
	 * what the person can conclude about the server's state.
	 */
	const request = useCallback(
		async ( url: string, method: string, body?: string ) => {
			let response: Response;

			try {
				response = await window.fetch( rest.root + url, {
					method,
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': rest.nonce,
					},
					...( undefined === body ? {} : { body } ),
				} );
			} catch {
				return { ok: false, status: 0, payload: null, sent: false };
			}

			let payload: unknown = null;

			try {
				payload = await response.json();
			} catch {
				payload = null;
			}

			return {
				ok: response.ok,
				status: response.status,
				payload,
				sent: true,
			};
		},
		[ rest.nonce, rest.root ]
	);

	// The waiting loop calls the latest `settle` without depending on itself.
	const settleRef = useRef< ( narrowed: Proposal ) => void >( () => {} );

	/*
	 * Shows a stored outcome, or waits for one while another window writes.
	 * Nothing here writes: it only reads the proposal back until its approval
	 * has finished, which the server bounds by failing an approval that runs
	 * too long.
	 */
	const settle = useCallback(
		( narrowed: Proposal ): void => {
			const outcome = settledOutcome( narrowed );

			if ( outcome ) {
				setExecution( outcome );
				setInterrupted( 'failed' === narrowed.status );
				setState( 'done' );
				return;
			}

			if ( 'executing' !== narrowed.status ) {
				// The other window let go without writing; this one may approve.
				setProposal( narrowed );
				setSelected( initialSelection( narrowed ) );
				setState( 'ready' );
				return;
			}

			setState( 'waiting' );

			window.setTimeout( () => {
				void ( async () => {
					if ( ! mounted.current ) {
						return;
					}

					const { ok, payload } = await request( base, 'GET' );

					if ( ! mounted.current ) {
						return;
					}

					const next = ok ? toProposal( payload ) : null;

					if ( ! next ) {
						setState( 'error' );
						setDetail(
							__(
								'This proposal is being applied in another window, and its outcome could not be read here. Reload the page to see it.',
								'ai'
							)
						);
						return;
					}

					settleRef.current( next );
				} )();
			}, WAIT_INTERVAL );
		},
		[ base, request ]
	);

	settleRef.current = settle;

	useEffect( () => {
		// A reopened chat already knows how this proposal ended.
		if ( stored ) {
			return;
		}

		let cancelled = false;

		void ( async () => {
			const { ok, payload, sent } = await request( base, 'GET' );

			if ( cancelled ) {
				return;
			}

			const narrowed = ok ? toProposal( payload ) : null;

			if ( ! narrowed ) {
				setState( 'error' );
				setDetail(
					sent
						? __(
								'This proposal is no longer available. Ask the assistant to propose it again.',
								'ai'
						  )
						: __(
								'This proposal could not be loaded. Check your connection and reload the page.',
								'ai'
						  )
				);
				return;
			}

			if ( 'pending' !== narrowed.status ) {
				settle( narrowed );
				return;
			}

			setProposal( narrowed );
			setSelected( initialSelection( narrowed ) );
			setState( 'ready' );
		} )();

		return () => {
			cancelled = true;
		};
	}, [ base, request, settle, stored ] );

	const editItem = useCallback(
		( key: string, value: string | null ): void => {
			setEdits( ( current ) => {
				const next = { ...current };

				if ( null === value ) {
					delete next[ key ];
				} else {
					next[ key ] = value;
				}

				return next;
			} );
			setEditErrors( ( current ) => {
				const next = { ...current };
				delete next[ key ];

				return next;
			} );
		},
		[]
	);

	const toggle = useCallback( ( key: string, isChecked: boolean ): void => {
		setSelected( ( current ) =>
			isChecked
				? [ ...current, key ]
				: current.filter( ( item ) => item !== key )
		);
	}, [] );

	const approve = useCallback( async (): Promise< void > => {
		setState( 'working' );

		const updating = proposal ? isUpdate( proposal ) : false;

		// Only an edit that changes a selected item's value is sent.
		const changed: Record< string, { value: string } > = {};

		proposal?.items.forEach( ( item ) => {
			const edit = edits[ item.key ];

			if (
				undefined !== edit &&
				edit !== item.value &&
				selected.includes( item.key )
			) {
				changed[ item.key ] = { value: edit };
			}
		} );

		const { ok, status, payload, sent } = await request(
			base + '/execute',
			'POST',
			JSON.stringify( {
				conversation_id: conversationId,
				selected,
				...( Object.keys( changed ).length > 0
					? { edits: changed }
					: {} ),
			} )
		);

		if ( ! mounted.current ) {
			return;
		}

		// A refused edit leaves the proposal as it was, so the card stays open.
		const refused = 400 === status ? toEditError( payload ) : null;

		if ( refused && undefined !== edits[ refused.key ] ) {
			setEditErrors( { [ refused.key ]: refused.message } );
			setState( 'ready' );
			return;
		}

		const narrowed = ok ? toProposalExecution( payload ) : null;

		if ( ! narrowed ) {
			setState( 'error' );

			if ( updating ) {
				setDetail(
					sent
						? __(
								'Nothing was changed. The request was refused.',
								'ai'
						  )
						: __(
								'The request could not be sent, so nothing was changed. Ask the assistant to propose this again.',
								'ai'
						  )
				);
				return;
			}

			setDetail(
				sent
					? __(
							'Nothing was created. The request was refused.',
							'ai'
					  )
					: __(
							'The request could not be sent, so nothing was created. Ask the assistant to propose this again.',
							'ai'
					  )
			);
			return;
		}

		/*
		 * An approval that wrote owes the assistant a follow-up. One that owes
		 * none found the proposal already approved, perhaps still being written
		 * in another window, so its stored outcome is read back instead.
		 */
		if ( ! narrowed.follow_up ) {
			const current = await request( base, 'GET' );

			if ( ! mounted.current ) {
				return;
			}

			const reread = current.ok ? toProposal( current.payload ) : null;

			/*
			 * Still pending means another window holds the approval but has not
			 * started writing yet, so this one waits for it as for one that has.
			 */
			if ( reread ) {
				settle(
					'pending' === reread.status
						? { ...reread, status: 'executing' }
						: reread
				);
				return;
			}
		}

		setExecution( narrowed );
		setState( 'done' );
		onSettled( narrowed.follow_up );
	}, [
		base,
		conversationId,
		edits,
		onSettled,
		proposal,
		request,
		selected,
		settle,
	] );

	const decline = useCallback( async (): Promise< void > => {
		setState( 'working' );

		const { ok, payload } = await request( base, 'DELETE' );

		if ( ! mounted.current ) {
			return;
		}

		setProposal( null );
		setState( 'error' );
		// A failed delete leaves the proposal stored and still approvable, so
		// claiming it was discarded would be a claim about the server that this
		// request never established.
		const updating = proposal ? isUpdate( proposal ) : false;

		if ( updating ) {
			setDetail(
				ok
					? __( 'Discarded. Nothing was changed.', 'ai' )
					: __(
							'This proposal could not be discarded, so it is still stored. Nothing was changed.',
							'ai'
					  )
			);
		} else {
			setDetail(
				ok
					? __( 'Discarded. Nothing was created.', 'ai' )
					: __(
							'This proposal could not be discarded, so it is still stored. Nothing was created.',
							'ai'
					  )
			);
		}

		if ( ok ) {
			onSettled( readFollowUp( payload ) );
		}
	}, [ base, onSettled, proposal, request ] );

	if ( 'loading' === state ) {
		return (
			<p>
				<Spinner />
				{ __( 'Loading what the assistant proposes to write…', 'ai' ) }
			</p>
		);
	}

	if ( 'error' === state ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ detail }
			</Notice>
		);
	}

	if ( 'waiting' === state ) {
		return (
			<p className="ai-workspace__progress" role="status">
				<Spinner />
				{ __( 'Applying in another window…', 'ai' ) }
			</p>
		);
	}

	if ( 'done' === state && execution ) {
		return <Outcomes execution={ execution } interrupted={ interrupted } />;
	}

	if ( ! proposal ) {
		return null;
	}

	if ( isUpdate( proposal ) ) {
		const heading = reviewLabel( proposal.items.length );

		return (
			<section className="ai-workspace__proposal" aria-label={ heading }>
				<h3 className="ai-workspace__proposal-title">{ heading }</h3>
				<p className="ai-workspace__proposal-hint">
					{ __(
						'Check each change against what is there now. Edit a new value if you like, then apply.',
						'ai'
					) }
				</p>

				{ proposal.items.map( ( item ) => (
					<ProposedChange
						key={ item.key }
						item={ item }
						isSelected={ selected.includes( item.key ) }
						onSelect={ toggle }
						edit={ edits[ item.key ] ?? null }
						onEdit={ editItem }
						error={ editErrors[ item.key ] ?? '' }
						disabled={ 'working' === state }
					/>
				) ) }

				<div className="ai-workspace__proposal-actions">
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={
							0 === selected.length || 'working' === state
						}
						accessibleWhenDisabled
						isBusy={ 'working' === state }
						onClick={ () => {
							void approve();
						} }
					>
						{ applyLabel( selected.length ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						disabled={ 'working' === state }
						onClick={ () => {
							void decline();
						} }
					>
						{ __( 'Discard', 'ai' ) }
					</Button>
				</div>
			</section>
		);
	}

	if ( 'template' === proposal.kind ) {
		const [ template ] = proposal.items;

		if ( ! template ) {
			return null;
		}

		const heading = sprintf(
			/* translators: %s: the template's title. */
			__( 'Create template: %s', 'ai' ),
			template.title
		);

		return (
			<section className="ai-workspace__proposal" aria-label={ heading }>
				<h3 className="ai-workspace__proposal-title">{ heading }</h3>
				<p className="ai-workspace__proposal-hint">
					{ sprintf(
						/* translators: 1: the template slug, 2: the theme name. */
						__( 'Template %1$s, for the theme %2$s.', 'ai' ),
						template.slug,
						template.theme
					) }
				</p>
				<p className="ai-workspace__proposal-value">
					{ templateNotice( template ) }
				</p>
				{ /* Shown open, not behind a toggle: this markup goes live as it is. */ }
				<div className="ai-workspace__code ai-workspace__proposal-code">
					<pre>
						<code>{ template.content }</code>
					</pre>
				</div>
				<div className="ai-workspace__proposal-actions">
					<Button
						__next40pxDefaultSize
						variant="primary"
						// Nothing is selected once the proposal is no longer pending.
						disabled={
							0 === selected.length || 'working' === state
						}
						accessibleWhenDisabled
						isBusy={ 'working' === state }
						onClick={ () => {
							void approve();
						} }
					>
						{ __( 'Create template', 'ai' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						disabled={ 'working' === state }
						onClick={ () => {
							void decline();
						} }
					>
						{ __( 'Discard', 'ai' ) }
					</Button>
				</div>
			</section>
		);
	}

	const title = createLabel( proposal.items, proposal.items.length );
	const chosen = proposal.items.filter( ( item ) =>
		selected.includes( item.key )
	);

	return (
		<section className="ai-workspace__proposal" aria-label={ title }>
			<h3 className="ai-workspace__proposal-title">{ title }</h3>
			<p className="ai-workspace__proposal-hint">
				{ 1 === proposal.items.length
					? __( 'Check it over, then create it.', 'ai' )
					: __( 'Check the ones you want.', 'ai' ) }
			</p>

			{ proposal.items.map( ( item ) => (
				<div key={ item.key } className="ai-workspace__proposal-item">
					<CheckboxControl
						__nextHasNoMarginBottom
						checked={ selected.includes( item.key ) }
						onChange={ ( isChecked: boolean ) =>
							toggle( item.key, isChecked )
						}
						label={ item.title }
						help={ sprintf(
							/* translators: 1: post type, 2: post status. */
							__( '%1$s — will be saved as %2$s', 'ai' ),
							item.post_type,
							item.status
						) }
					/>
					{ '' !== item.excerpt && (
						<p className="ai-workspace__proposal-value">
							{ item.excerpt }
						</p>
					) }
					{ '' !== item.content && (
						<details className="ai-workspace__proposal-preview">
							<summary>{ __( 'Preview', 'ai' ) }</summary>
							<p className="ai-workspace__proposal-value">
								{ item.content }
							</p>
						</details>
					) }
				</div>
			) ) }

			<div className="ai-workspace__proposal-actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					disabled={ 0 === selected.length || 'working' === state }
					accessibleWhenDisabled
					isBusy={ 'working' === state }
					onClick={ () => {
						void approve();
					} }
				>
					{ createLabel(
						// With nothing checked, the label keeps naming what the
						// card holds, so it reads "Create 0 drafts".
						chosen.length > 0 ? chosen : proposal.items,
						selected.length
					) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					disabled={ 'working' === state }
					onClick={ () => {
						void decline();
					} }
				>
					{ __( 'Discard', 'ai' ) }
				</Button>
			</div>
		</section>
	);
}
