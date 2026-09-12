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
import { toProposal, toProposalExecution } from '../utils/proposal';
import type {
	Proposal,
	ProposalExecution,
	ProposalItemOutcome,
	RestData,
} from '../types';

/**
 * How the confirmation is currently behaving.
 */
type ConfirmState = 'loading' | 'ready' | 'working' | 'done' | 'error';

const PANEL_STYLE = {
	border: '1px solid #949494',
	borderRadius: '4px',
	padding: '12px 16px',
	margin: '12px 0',
};

const ITEM_STYLE = {
	borderTop: '1px solid #ddd',
	padding: '12px 0',
};

const VALUE_STYLE = {
	margin: '4px 0 0',
	whiteSpace: 'pre-wrap' as const,
	fontFamily: 'inherit',
};

/**
 * Names an outcome for the person reading it.
 *
 * @param outcome The server's outcome code.
 * @return The label.
 */
function outcomeLabel( outcome: string ): string {
	switch ( outcome ) {
		case 'created':
			return __( 'Created', 'ai' );
		case 'failed':
			return __( 'Failed', 'ai' );
		case 'denied':
			return __( 'Refused: you no longer have permission', 'ai' );
		case 'duplicate':
			return __( 'Already created earlier', 'ai' );
		case 'deselected':
			return __( 'Not selected, so nothing was written', 'ai' );
		default:
			return outcome;
	}
}

/**
 * Renders what happened to each item after execution.
 *
 * @param props           Component props.
 * @param props.execution The execution result.
 * @return The outcome list.
 */
function Outcomes( { execution }: { execution: ProposalExecution } ) {
	return (
		<div>
			<p>
				{ sprintf(
					/* translators: 1: number created, 2: number not created. */
					__( '%1$d created, %2$d not created.', 'ai' ),
					execution.created,
					execution.failed +
						execution.denied +
						execution.duplicate +
						execution.deselected
				) }
			</p>
			<ul>
				{ execution.items.map( ( item: ProposalItemOutcome ) => (
					<li key={ item.key }>
						<strong>{ item.title }</strong>
						{ ' — ' }
						{ outcomeLabel( item.outcome ) }
						{ '' !== item.error_message && (
							<span>{ ' — ' + item.error_message }</span>
						) }
						{ item.post_id > 0 && '' !== item.edit_link && (
							<span>
								{ ' ' }
								<a href={ item.edit_link }>
									{ __( 'Open in the editor', 'ai' ) }
								</a>
							</span>
						) }
					</li>
				) ) }
			</ul>
			<p>
				{ __(
					'Nothing is retried automatically. Ask the assistant again if you want another attempt.',
					'ai'
				) }
			</p>
		</div>
	);
}

/**
 * Renders the stored values of a proposal and the controls that approve them.
 *
 * The values shown are read back from the server's stored copy of the proposal,
 * never from the assistant's message: R16 exists because the model's summary of
 * what it will write is influenced by the same untrusted content the assistant
 * read. Items are individually selectable and start deselected, so an item
 * appended to an otherwise legitimate batch has to be chosen deliberately before
 * it can be written.
 *
 * @param props                Component props.
 * @param props.proposalId     The proposal to confirm.
 * @param props.conversationId The conversation the proposal belongs to.
 * @param props.rest           The REST transport data.
 * @return The confirmation surface.
 */
export default function ConfirmProposal( {
	proposalId,
	conversationId,
	rest,
}: {
	proposalId: string;
	conversationId: string;
	rest: RestData;
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
	}, [ base, conversationId, request, selected ] );

	const decline = useCallback( async (): Promise< void > => {
		setState( 'working' );

		const { ok } = await request( base, 'DELETE' );

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
	}, [ base, request ] );

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
		return (
			<div style={ PANEL_STYLE }>
				<Outcomes execution={ execution } />
			</div>
		);
	}

	if ( ! proposal ) {
		return null;
	}

	return (
		<section
			style={ PANEL_STYLE }
			aria-label={ __( 'Review what will be created', 'ai' ) }
		>
			<h3>{ __( 'Review what will be created', 'ai' ) }</h3>
			<p>
				{ __(
					'These are the exact values that will be written. Select the ones you want.',
					'ai'
				) }
			</p>

			{ proposal.items.map( ( item ) => (
				<div key={ item.key } style={ ITEM_STYLE }>
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
						<p style={ VALUE_STYLE }>{ item.excerpt }</p>
					) }
					{ '' !== item.content && (
						<details>
							<summary>
								{ __( 'Show the full body', 'ai' ) }
							</summary>
							<p style={ VALUE_STYLE }>{ item.content }</p>
						</details>
					) }
				</div>
			) ) }

			<p>
				<Button
					__next40pxDefaultSize
					variant="primary"
					disabled={ 0 === selected.length || 'working' === state }
					onClick={ () => {
						void approve();
					} }
				>
					{ sprintf(
						/* translators: %d: number of selected items. */
						_n(
							'Create %d selected item',
							'Create %d selected items',
							selected.length,
							'ai'
						),
						selected.length
					) }
				</Button>{ ' ' }
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
			</p>
		</section>
	);
}
