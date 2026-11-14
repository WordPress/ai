/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import {
	RawHTML,
	createRoot,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies
 */
import { getErrorMessage } from '../../utils/errors';
import './index.scss';

type Section = 'summary' | 'overview';

type Version = { preview_html?: string };

type State = {
	revision: string;
	sections: Record<
		Section,
		{ approved: Version | null; draft: Version | null }
	>;
	hidden: Section[];
	stale: Section[];
	generationError: string;
};

type Settings = {
	termId: number;
	archiveUrl: string;
	state: State;
};

declare global {
	interface Window {
		aiTopicReviewData?: Settings;
	}
}

const labels: Record< Section, string > = {
	summary: __( 'Summary', 'ai' ),
	overview: __( 'Narrative overview', 'ai' ),
};

function TopicReview( { settings }: { settings: Settings } ) {
	const [ state, setState ] = useState( settings.state );
	const [ busy, setBusy ] = useState( '' );
	const pending = useRef( false );
	const regenerateButtons = useRef<
		Record< Section, HTMLButtonElement | null >
	>( {
		summary: null,
		overview: null,
	} );
	const focusAfterAction = useRef< Section | null >( null );

	useEffect( () => {
		if ( ! busy && focusAfterAction.current ) {
			regenerateButtons.current[ focusAfterAction.current ]?.focus();
			focusAfterAction.current = null;
		}
	}, [ busy ] );

	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const [ conflict, setConflict ] = useState( false );

	async function act(
		action: 'generate' | 'save' | 'discard',
		section: Section
	) {
		if ( pending.current ) {
			return;
		}

		pending.current = true;
		setBusy( `${ action }-${ section }` );
		setNotice( null );

		try {
			const next = await apiFetch< State >( {
				path: `/ai/v1/topic-pages/${ settings.termId }`,
				method: 'POST',
				data: { action, section, revision: state.revision },
			} );

			if ( action !== 'generate' ) {
				focusAfterAction.current = section;
			}

			setState( next );
			setConflict( false );
			const text = {
				generate: __(
					'Preview ready. Save to publish it, or discard it to keep the current text.',
					'ai'
				),
				save: __( 'Saved. The new text is now public.', 'ai' ),
				discard: __(
					'Preview discarded. The published text has not changed.',
					'ai'
				),
			}[ action ];
			setNotice( { status: 'success', text } );
			speak( text );
		} catch ( error ) {
			const text = getErrorMessage( error );
			setNotice( { status: 'error', text } );
			setConflict(
				( error as { code?: string } )?.code === 'topic_conflict'
			);
			speak( text, 'assertive' );
		} finally {
			pending.current = false;
			setBusy( '' );
		}
	}

	async function reload() {
		if ( pending.current ) {
			return;
		}

		pending.current = true;
		setBusy( 'reload' );

		try {
			setState(
				await apiFetch< State >( {
					path: `/ai/v1/topic-pages/${ settings.termId }`,
				} )
			);
			setConflict( false );
			setNotice( null );
		} catch ( error ) {
			setNotice( { status: 'error', text: getErrorMessage( error ) } );
		} finally {
			pending.current = false;
			setBusy( '' );
		}
	}

	return (
		<div className="ai-topic-review">
			<p>
				{ __(
					'Generate a section to preview it. Visitors see the current text until you choose Save.',
					'ai'
				) }
			</p>
			<p>
				<a
					href={ settings.archiveUrl }
					target="_blank"
					rel="noreferrer"
				>
					{ __( 'View topic page', 'ai' ) }
				</a>
			</p>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			{ conflict && (
				<Button
					__next40pxDefaultSize
					type="button"
					variant="secondary"
					disabled={ !! busy }
					onClick={ reload }
				>
					{ __( 'Load latest version', 'ai' ) }
				</Button>
			) }
			{ state.generationError && (
				<Notice status="warning" isDismissible={ false }>
					{ state.generationError }
				</Notice>
			) }
			{ ( Object.keys( labels ) as Section[] ).map( ( section ) => {
				const item = state.sections[ section ];
				const version = item.draft ?? item.approved;
				const generationText = version
					? __( 'Regenerate', 'ai' )
					: __( 'Generate', 'ai' );
				const generationLabel = version
					? /* translators: %s: section name. */ __(
							'Regenerate %s',
							'ai'
					  )
					: /* translators: %s: section name. */ __(
							'Generate %s',
							'ai'
					  );

				let status: string = __( 'Not generated yet', 'ai' );

				if ( item.draft ) {
					status = __( 'Unsaved preview', 'ai' );
				} else if ( state.hidden.includes( section ) ) {
					status = __( 'Hidden', 'ai' );
				} else if ( item.approved ) {
					status = __( 'Published', 'ai' );
				}

				return (
					<section
						key={ section }
						aria-labelledby={ `ai-topic-${ section }` }
						className="ai-topic-review__section"
						aria-busy={ busy.endsWith( section ) }
					>
						<div className="ai-topic-review__heading">
							<h3 id={ `ai-topic-${ section }` }>
								{ labels[ section ] }
							</h3>
							<span>{ status }</span>
						</div>
						{ state.hidden.includes( section ) && (
							<p className="description">
								{ __(
									'The published text is hidden because this category or its source articles no longer qualify.',
									'ai'
								) }
							</p>
						) }
						{ state.stale.includes( section ) && (
							<p className="description">
								{ __(
									'Source articles have changed. Regenerate when you are ready to update this section.',
									'ai'
								) }
							</p>
						) }
						{ section === 'overview' && (
							<p className="description">
								{ __(
									'Uses selected article excerpts across the category’s history.',
									'ai'
								) }
							</p>
						) }
						{ version?.preview_html ? (
							<RawHTML className="ai-topic-review__preview">
								{ version.preview_html }
							</RawHTML>
						) : (
							<p>
								{ __(
									'There is no readable text to preview yet.',
									'ai'
								) }
							</p>
						) }
						<div className="ai-topic-review__actions">
							<Button
								__next40pxDefaultSize
								type="button"
								variant="secondary"
								disabled={
									!! busy ||
									!! state.generationError ||
									conflict
								}
								ref={ ( button ) => {
									regenerateButtons.current[ section ] =
										button;
								} }
								onClick={ () => act( 'generate', section ) }
								aria-label={ sprintf(
									generationLabel,
									labels[ section ]
								) }
							>
								{ busy === `generate-${ section }`
									? __( 'Generating…', 'ai' )
									: generationText }
							</Button>
							{ item.draft && (
								<>
									<Button
										__next40pxDefaultSize
										type="button"
										variant="primary"
										disabled={
											!! busy ||
											!! state.generationError ||
											conflict
										}
										onClick={ () => act( 'save', section ) }
										aria-label={ sprintf(
											/* translators: %s: section name. */ __(
												'Save %s',
												'ai'
											),
											labels[ section ]
										) }
									>
										{ __( 'Save', 'ai' ) }
									</Button>
									<Button
										__next40pxDefaultSize
										type="button"
										variant="tertiary"
										disabled={ !! busy || conflict }
										onClick={ () =>
											act( 'discard', section )
										}
										aria-label={ sprintf(
											/* translators: %s: section name. */ __(
												'Discard %s preview',
												'ai'
											),
											labels[ section ]
										) }
									>
										{ __( 'Discard', 'ai' ) }
									</Button>
								</>
							) }
							{ busy.endsWith( section ) && (
								<span role="status">
									<Spinner />
									<span className="screen-reader-text">
										{ __(
											'Working on this section.',
											'ai'
										) }
									</span>
								</span>
							) }
						</div>
						{ item.draft && item.approved?.preview_html && (
							<details>
								<summary>
									{ __( 'Current published version', 'ai' ) }
								</summary>
								<RawHTML className="ai-topic-review__preview">
									{ item.approved.preview_html }
								</RawHTML>
							</details>
						) }
					</section>
				);
			} ) }
		</div>
	);
}

domReady( () => {
	const element = document.getElementById( 'ai-topic-review' );

	if ( element && window.aiTopicReviewData ) {
		createRoot( element ).render(
			<TopicReview settings={ window.aiTopicReviewData } />
		);
	}
} );
