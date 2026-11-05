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
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	readFollowUp,
	toProposal,
	toProposalExecution,
} from '../utils/proposal';
import type {
	Proposal,
	ProposalExecution,
	ProposalItem,
	ProposalItemOutcome,
	RestData,
} from '../types';

/**
 * How the confirmation is currently behaving.
 */
type ConfirmState = 'loading' | 'ready' | 'working' | 'done' | 'error';

/**
 * Names what approving the selected items will do, with the live count.
 *
 * Drafts are named as drafts, because that is what a person asked for; a mix
 * of statuses falls back to "items", and each row still says how it is saved.
 *
 * @param items The items the label describes.
 * @param count How many of them.
 * @return The label.
 */
function createLabel( items: ProposalItem[], count: number ): string {
	if (
		items.length > 0 &&
		items.every( ( item ) => 'draft' === item.status )
	) {
		return sprintf(
			/* translators: %d: number of drafts. */
			_n( 'Create %d draft', 'Create %d drafts', count, 'ai' ),
			count
		);
	}

	return sprintf(
		/* translators: %d: number of items. */
		_n( 'Create %d item', 'Create %d items', count, 'ai' ),
		count
	);
}

/**
 * Names what was created.
 *
 * @param created The items that were created.
 * @return The heading.
 */
function createdLabel( created: ProposalItemOutcome[] ): string {
	if ( 0 === created.length ) {
		return __( 'Nothing was created', 'ai' );
	}

	if ( created.every( ( item ) => 'draft' === item.status ) ) {
		return sprintf(
			/* translators: %d: number of drafts. */
			_n( 'Created %d draft', 'Created %d drafts', created.length, 'ai' ),
			created.length
		);
	}

	return sprintf(
		/* translators: %d: number of items. */
		_n( 'Created %d item', 'Created %d items', created.length, 'ai' ),
		created.length
	);
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
 * Builds a link to the admin list that holds the created items.
 *
 * The list is the post type's own screen, narrowed to the status they were
 * saved with and newest first, so they are at the top. The admin URL is taken
 * from an item's edit link rather than assumed. Items of different post types
 * have no single list, so they get no link.
 *
 * @param created The items that were created.
 * @return The list URL and its post type, or null.
 */
function listLink(
	created: ProposalItemOutcome[]
): { url: string; postType: string } | null {
	const [ first ] = created;

	if (
		! first ||
		created.some( ( item ) => item.post_type !== first.post_type )
	) {
		return null;
	}

	try {
		const url = new URL( 'edit.php', first.edit_link );

		url.searchParams.set( 'post_type', first.post_type );

		if ( created.every( ( item ) => item.status === first.status ) ) {
			url.searchParams.set( 'post_status', first.status );
		}

		url.searchParams.set( 'orderby', 'date' );
		url.searchParams.set( 'order', 'desc' );

		return { url: url.href, postType: first.post_type };
	} catch {
		return null;
	}
}

/**
 * Renders what happened after approval, with the way into the result.
 *
 * One created item gets one prominent button to open it. Several get a row
 * each with its own "Open", and a link to the list that holds them all. An item
 * that was not created says why, in its own row.
 *
 * @param props           Component props.
 * @param props.execution The execution result.
 * @return The outcome.
 */
function Outcomes( { execution }: { execution: ProposalExecution } ) {
	const created = execution.items.filter(
		( item ) =>
			'created' === item.outcome &&
			item.post_id > 0 &&
			'' !== item.edit_link
	);
	const heading = createdLabel( created );
	const [ only ] = created;

	if ( 1 === execution.items.length && only ) {
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
						{ 'draft' === only.status
							? __( 'Open draft', 'ai' )
							: __( 'Open in the editor', 'ai' ) }
					</Button>
				</div>
			</section>
		);
	}

	const list = created.length > 1 ? listLink( created ) : null;

	return (
		<section className="ai-workspace__proposal" aria-label={ heading }>
			<h3 className="ai-workspace__proposal-title">{ heading }</h3>
			<ul className="ai-workspace__proposal-outcomes">
				{ execution.items.map( ( item ) => {
					const isCreated = created.includes( item );

					return (
						<li
							key={ item.key }
							className="ai-workspace__proposal-outcome"
						>
							<span className="ai-workspace__proposal-outcome-text">
								<strong>{ item.title }</strong>
								{ ! isCreated && (
									<span className="ai-workspace__proposal-outcome-reason">
										{ outcomeLabel( item.outcome ) }
										{ '' !== item.error_message &&
											': ' + item.error_message }
									</span>
								) }
							</span>
							{ isCreated && (
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
					);
				} ) }
			</ul>
			{ list && (
				<Button variant="link" href={ list.url }>
					{ 'page' === list.postType
						? __( 'Open all in Pages', 'ai' )
						: __( 'Open all in Posts', 'ai' ) }
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
 * read. Every item starts selected, so the card's one main button does what was
 * asked for. Nothing is written until that button is pressed, every stored value
 * is on the card first, and any item can be unchecked.
 *
 * @param props                Component props.
 * @param props.proposalId     The proposal to confirm.
 * @param props.conversationId The conversation the proposal belongs to.
 * @param props.rest           The REST transport data.
 * @param props.onSettled      Called once the proposal is approved or discarded,
 *                             with whether the assistant may follow up.
 * @return The confirmation surface.
 */
export default function ConfirmProposal( {
	proposalId,
	conversationId,
	rest,
	onSettled,
}: {
	proposalId: string;
	conversationId: string;
	rest: RestData;
	onSettled: ( canFollowUp: boolean ) => void;
} ) {
	const [ proposal, setProposal ] = useState< Proposal | null >( null );
	const [ selected, setSelected ] = useState< string[] >( [] );
	const [ state, setState ] = useState< ConfirmState >( 'loading' );
	const [ detail, setDetail ] = useState( '' );
	const [ execution, setExecution ] = useState< ProposalExecution | null >(
		null
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
				return { ok: false, payload: null, sent: false };
			}

			let payload: unknown = null;

			try {
				payload = await response.json();
			} catch {
				payload = null;
			}

			return { ok: response.ok, payload, sent: true };
		},
		[ rest.nonce, rest.root ]
	);

	useEffect( () => {
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

			setProposal( narrowed );
			setSelected( narrowed.items.map( ( item ) => item.key ) );
			setState( 'ready' );
		} )();

		return () => {
			cancelled = true;
		};
	}, [ base, request ] );

	const toggle = useCallback( ( key: string, isChecked: boolean ): void => {
		setSelected( ( current ) =>
			isChecked
				? [ ...current, key ]
				: current.filter( ( item ) => item !== key )
		);
	}, [] );

	const approve = useCallback( async (): Promise< void > => {
		setState( 'working' );

		const { ok, payload, sent } = await request(
			base + '/execute',
			'POST',
			JSON.stringify( {
				conversation_id: conversationId,
				selected,
			} )
		);

		if ( ! mounted.current ) {
			return;
		}

		const narrowed = ok ? toProposalExecution( payload ) : null;

		if ( ! narrowed ) {
			setState( 'error' );
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

		setExecution( narrowed );
		setState( 'done' );
		onSettled( narrowed.follow_up );
	}, [ base, conversationId, onSettled, request, selected ] );

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
		setDetail(
			ok
				? __( 'Discarded. Nothing was created.', 'ai' )
				: __(
						'This proposal could not be discarded, so it is still stored. Nothing was created.',
						'ai'
				  )
		);

		if ( ok ) {
			onSettled( readFollowUp( payload ) );
		}
	}, [ base, onSettled, request ] );

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

	if ( 'done' === state && execution ) {
		return <Outcomes execution={ execution } />;
	}

	if ( ! proposal ) {
		return null;
	}

	const title = createLabel( proposal.items, proposal.items.length );
	const chosen = proposal.items.filter( ( item ) =>
		selected.includes( item.key )
	);

	return (
		<section className="ai-workspace__proposal" aria-label={ title }>
			<h3 className="ai-workspace__proposal-title">{ title }</h3>
			<p className="ai-workspace__proposal-hint">
				{ __( 'Uncheck anything you don’t want.', 'ai' ) }
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
