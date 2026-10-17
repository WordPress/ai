/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import apiFetch from '@wordpress/api-fetch';
import {
	BlockEditorProvider,
	BlockList,
	// @ts-expect-error Public export missing from the external type package.
	BlockTools,
	WritingFlow,
	ObserveTyping,
} from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';
import {
	getBlockType,
	parse,
	rawHandler,
	serialize,
	type Block,
} from '@wordpress/blocks';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	DropdownMenu,
	Icon,
	Modal,
	Notice,
	Popover,
	SlotFillProvider,
	Spinner,
} from '@wordpress/components';
import {
	RawHTML,
	createRoot,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { external, lock, moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getErrorMessage } from '../../utils/errors';
import './index.scss';

type Section = 'summary' | 'overview' | 'timeline';
type Version = {
	content: string;
	source_ids: number[];
	generated_at?: string;
	preview_html?: string;
};
type State = {
	revision: string;
	sections: Record<
		Section,
		{ approved: Version | null; draft: Version | null; locked: boolean }
	>;
	hidden: Section[];
	stale: Section[];
	generationError: string;
};
type Settings = {
	termId: number;
	name: string;
	archiveUrl: string;
	editUrl: string;
	count: number;
	minimum: number;
	allowedBlocks: string[];
	state: State;
};
type Action = 'generate' | 'save' | 'publish' | 'lock';
type Edits = Partial< Record< Section, Block[] > >;

declare global {
	interface Window {
		aiTopicEditorData?: Settings;
	}
}

const sections: Section[] = [ 'summary', 'overview', 'timeline' ];
const labels: Record< Section, { title: string; description: string } > = {
	summary: {
		title: __( 'Summary', 'ai' ),
		description: __(
			'Where the topic stands today, in two or three sentences.',
			'ai'
		),
	},
	overview: {
		title: __( 'Narrative overview', 'ai' ),
		description: __(
			'How the story developed, with links to your reporting.',
			'ai'
		),
	},
	timeline: {
		title: __( 'Coverage timeline', 'ai' ),
		description: __(
			'Key moments, ordered by their source articles’ publication dates.',
			'ai'
		),
	},
};

// Recover earlier HTML through WordPress's own block transforms rather than a second editor.
function readBlocks( content: string ): Block[] {
	const blocks = parse( content );
	return blocks.some(
		( block ) => block.name === 'core/freeform' || block.isValid === false
	)
		? rawHandler( { HTML: content } )
		: blocks;
}

function SectionCard( {
	name,
	item,
	blocks,
	onChange,
	busy,
	dirty,
	hidden,
	stale,
	canGenerate,
	onAction,
	allowedBlocks,
}: {
	name: Section;
	item: State[ 'sections' ][ Section ];
	blocks: Block[];
	onChange: ( next: Block[] ) => void;
	busy: boolean;
	dirty: boolean;
	hidden: boolean;
	stale: boolean;
	canGenerate: boolean;
	onAction: (
		action: Action,
		section: Section,
		extra?: Record< string, unknown >
	) => void;
	allowedBlocks: string[];
} ) {
	const [ comparing, setComparing ] = useState( false );
	const hasContent = !! ( item.draft || item.approved );
	let status: string = __( 'Not generated', 'ai' );
	if ( dirty ) {
		status = __( 'Unsaved changes', 'ai' );
	} else if ( item.draft ) {
		status = __( 'Draft', 'ai' );
	} else if ( hidden ) {
		status = __( 'Hidden', 'ai' );
	} else if ( item.approved ) {
		status = __( 'Published', 'ai' );
	}

	return (
		<Card
			className="ai-topic-section"
			as="section"
			aria-labelledby={ `topic-${ name }` }
		>
			<CardHeader>
				<div className="ai-topic-section__heading">
					<div className="ai-topic-section__title">
						<h2 id={ `topic-${ name }` }>
							{ labels[ name ].title }
						</h2>
						<span className="ai-topic-status">{ status }</span>
						{ item.locked && (
							<span
								className="ai-topic-lock"
								title={ __(
									'Excluded from bulk regeneration',
									'ai'
								) }
							>
								<Icon icon={ lock } size={ 16 } />
								<span className="screen-reader-text">
									{ __(
										'Excluded from bulk regeneration',
										'ai'
									) }
								</span>
							</span>
						) }
					</div>
					<p>{ labels[ name ].description }</p>
				</div>
				<div className="ai-topic-actions">
					{ ( item.draft || dirty ) && (
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							disabled={ busy }
							onClick={ () => onAction( 'publish', name ) }
						>
							{ __( 'Publish', 'ai' ) }
						</Button>
					) }
					<DropdownMenu
						icon={ moreVertical }
						label={ sprintf(
							/* translators: %s: section name. */ __(
								'%s actions',
								'ai'
							),
							labels[ name ].title
						) }
						toggleProps={ { disabled: busy } }
						controls={ [
							{
								title: hasContent
									? __( 'Regenerate draft', 'ai' )
									: __( 'Generate draft', 'ai' ),
								isDisabled: ! canGenerate,
								onClick: () => onAction( 'generate', name ),
							},
							{
								title: __( 'Save draft', 'ai' ),
								isDisabled: ! dirty,
								onClick: () => onAction( 'save', name ),
							},
							{
								title: item.locked
									? __( 'Include in bulk regeneration', 'ai' )
									: __(
											'Exclude from bulk regeneration',
											'ai'
									  ),
								onClick: () =>
									onAction( 'lock', name, {
										locked: ! item.locked,
									} ),
							},
						] }
					/>
				</div>
			</CardHeader>
			<CardBody>
				{ hidden && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The published version is hidden because the topic or its sources are no longer eligible. Your saved content is preserved.',
							'ai'
						) }
					</Notice>
				) }
				{ stale && ! hidden && (
					<p className="ai-topic-help">
						{ __(
							'Sources have changed, or this earlier version has no source snapshot. Review or regenerate the content.',
							'ai'
						) }
					</p>
				) }
				{ ! hasContent ? (
					<div className="ai-topic-empty">
						<p>
							{ __(
								'Generate a draft to start. Nothing appears on your site until you publish.',
								'ai'
							) }
						</p>
						<Button
							__next40pxDefaultSize
							variant="secondary"
							disabled={ busy || ! canGenerate }
							onClick={ () => onAction( 'generate', name ) }
						>
							{ __( 'Generate draft', 'ai' ) }
						</Button>
					</div>
				) : (
					<div
						className="ai-topic-canvas"
						aria-busy={ busy }
						ref={ ( node ) => {
							if ( node ) {
								node.inert = busy;
							}
						} }
					>
						<SlotFillProvider>
							<BlockEditorProvider
								value={ blocks }
								onInput={ onChange }
								onChange={ onChange }
								settings={ {
									allowedBlockTypes: allowedBlocks,
									hasFixedToolbar: false,
								} }
							>
								<BlockTools>
									<div className="editor-styles-wrapper">
										<WritingFlow>
											<ObserveTyping>
												<BlockList />
											</ObserveTyping>
										</WritingFlow>
									</div>
								</BlockTools>
								<Popover.Slot />
							</BlockEditorProvider>
						</SlotFillProvider>
					</div>
				) }
				{ item.approved?.preview_html && ( item.draft || dirty ) && (
					<div className="ai-topic-comparison">
						<Button
							__next40pxDefaultSize
							variant="link"
							aria-expanded={ comparing }
							aria-controls={ `published-${ name }` }
							onClick={ () => setComparing( ! comparing ) }
						>
							{ comparing
								? __( 'Hide published version', 'ai' )
								: __( 'Compare with published version', 'ai' ) }
						</Button>
						{ comparing && (
							<div
								id={ `published-${ name }` }
								className="ai-topic-comparison__preview"
							>
								{ /* HTML comes from the source-validated public renderer. */ }
								<RawHTML>
									{ item.approved.preview_html }
								</RawHTML>
							</div>
						) }
					</div>
				) }
			</CardBody>
		</Card>
	);
}

function TopicEditor( { settings }: { settings: Settings } ) {
	const [ state, setState ] = useState( settings.state );
	const [ edits, setEdits ] = useState< Edits >( {} );
	const [ busy, setBusy ] = useState< Action | null >( null );
	const pending = useRef( false );
	const [ confirmation, setConfirmation ] = useState<
		Section | 'all' | null
	>( null );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const saved = useMemo(
		() =>
			Object.fromEntries(
				sections.map( ( name ) => [
					name,
					readBlocks(
						(
							state.sections[ name ].draft ??
							state.sections[ name ].approved
						)?.content ?? ''
					),
				] )
			) as Record< Section, Block[] >,
		[ state.sections ]
	);
	const changed = Object.fromEntries(
		sections
			.filter(
				( name ) =>
					edits[ name ] &&
					serialize( edits[ name ]! ) !== serialize( saved[ name ] )
			)
			.map( ( name ) => [ name, serialize( edits[ name ]! ) ] )
	) as Partial< Record< Section, string > >;
	const dirty = Object.keys( changed ).length > 0;
	const hasDrafts = sections.some( ( name ) => state.sections[ name ].draft );
	const unlocked = sections.filter(
		( name ) => ! state.sections[ name ].locked
	);

	useEffect( () => {
		const warn = ( event: BeforeUnloadEvent ) => {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	async function perform(
		action: Action,
		section: Section | 'all',
		extra: Record< string, unknown > = {}
	) {
		if ( pending.current ) {
			return;
		}
		pending.current = true;
		setBusy( action );
		setNotice( null );
		let targets = sections;
		if ( section !== 'all' ) {
			targets = [ section ];
		} else if ( action === 'generate' ) {
			targets = unlocked;
		}

		try {
			const next = await apiFetch< State >( {
				path: `/ai/v1/topic-pages/${ settings.termId }`,
				method: 'POST',
				data: {
					action,
					section,
					revision: state.revision,
					...( action === 'save' || action === 'publish'
						? {
								edits: Object.fromEntries(
									Object.entries( changed ).filter(
										( [ name ] ) =>
											targets.includes( name as Section )
									)
								),
						  }
						: {} ),
					...extra,
				},
			} );
			setState( next );
			if ( action !== 'lock' ) {
				setEdits( ( previous ) =>
					Object.fromEntries(
						Object.entries( previous ).filter(
							( [ name ] ) =>
								! targets.includes( name as Section )
						)
					)
				);
			}
			const messages: Record< Action, string > = {
				generate: __(
					'Drafts ready. Review the text and sources before publishing.',
					'ai'
				),
				publish: __(
					'Changes published. Your Topic blocks now use this version.',
					'ai'
				),
				save: __(
					'Draft saved. The published version is unchanged.',
					'ai'
				),
				lock: __( 'Regeneration preference saved.', 'ai' ),
			};
			setNotice( { status: 'success', text: messages[ action ] } );
		} catch ( error ) {
			setNotice( { status: 'error', text: getErrorMessage( error ) } );
		} finally {
			pending.current = false;
			setBusy( null );
		}
	}

	function act(
		action: Action,
		section: Section | 'all',
		extra?: Record< string, unknown >
	) {
		const targets = section === 'all' ? unlocked : [ section ];
		if (
			action === 'generate' &&
			targets.some(
				( name ) =>
					state.sections[ name ].draft ||
					changed[ name ] !== undefined
			)
		) {
			setConfirmation( section );
			return;
		}
		void perform( action, section, extra );
	}

	return (
		<SlotFillProvider>
			<Page
				className="ai-topic-page"
				title={ decodeEntities( settings.name ) }
				subTitle={ sprintf(
					/* translators: %d: public source article count. */ __(
						'Topic page · %d public articles',
						'ai'
					),
					settings.count
				) }
				showSidebarToggle={ false }
				actions={
					<div className="ai-topic-actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							href={ settings.archiveUrl }
							target="_blank"
							rel="noreferrer"
							icon={ external }
						>
							{ __( 'View archive', 'ai' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="secondary"
							disabled={ !! busy || ! dirty }
							onClick={ () => act( 'save', 'all' ) }
						>
							{ __( 'Save draft', 'ai' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							disabled={ !! busy || ( ! dirty && ! hasDrafts ) }
							onClick={ () => act( 'publish', 'all' ) }
						>
							{ busy === 'publish'
								? __( 'Publishing…', 'ai' )
								: __( 'Publish changes', 'ai' ) }
						</Button>
					</div>
				}
			>
				<div className="ai-topic-content">
					<div className="ai-topic-toolbar">
						<Button
							__next40pxDefaultSize
							variant="link"
							href={ settings.editUrl }
						>
							{ __( 'Back to category or tag', 'ai' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="secondary"
							disabled={
								!! busy ||
								!! state.generationError ||
								! unlocked.length
							}
							onClick={ () => act( 'generate', 'all' ) }
						>
							{ __( 'Generate unlocked sections', 'ai' ) }
						</Button>
					</div>
					<p className="ai-topic-intro">
						{ __(
							'Shape your coverage into a clear guide. Edit each section, check its sources, and publish when it is ready.',
							'ai'
						) }
					</p>
					<p className="ai-topic-help">
						{ __(
							'Add the Topic Summary, Topic Overview, and Topic Timeline blocks to your archive template. Keep its Query Loop for the article list.',
							'ai'
						) }
					</p>
					{ notice && (
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
						>
							{ notice.text }
						</Notice>
					) }
					{ state.generationError && (
						<Notice status="warning" isDismissible={ false }>
							{ state.generationError }
						</Notice>
					) }
					{ busy && (
						<div className="ai-topic-progress" role="status">
							<Spinner />
							<span>
								{
									{
										generate: __(
											'Generating drafts…',
											'ai'
										),
										publish: __(
											'Publishing changes…',
											'ai'
										),
										save: __( 'Saving draft…', 'ai' ),
										lock: __( 'Saving preference…', 'ai' ),
									}[ busy ]
								}
							</span>
						</div>
					) }
					{ sections.map( ( name ) => (
						<SectionCard
							key={ name }
							name={ name }
							item={ state.sections[ name ] }
							blocks={ edits[ name ] ?? saved[ name ] }
							onChange={ ( next ) => {
								if ( ! pending.current ) {
									setEdits( ( previous ) => ( {
										...previous,
										[ name ]: next,
									} ) );
								}
							} }
							busy={ !! busy }
							dirty={ changed[ name ] !== undefined }
							hidden={ state.hidden.includes( name ) }
							stale={ state.stale.includes( name ) }
							canGenerate={ ! state.generationError }
							onAction={ act }
							allowedBlocks={ settings.allowedBlocks }
						/>
					) ) }
				</div>
			</Page>
			<Popover.Slot />
			{ confirmation && (
				<Modal
					title={ __( 'Replace existing drafts?', 'ai' ) }
					onRequestClose={ () => setConfirmation( null ) }
				>
					<p>
						{ __(
							'Regeneration will replace drafts and unsaved edits in the selected sections. Published content stays unchanged until you publish the new version.',
							'ai'
						) }
					</p>
					<div className="ai-topic-actions ai-topic-dialog-actions">
						<Button
							__next40pxDefaultSize
							variant="secondary"
							onClick={ () => setConfirmation( null ) }
						>
							{ __( 'Cancel', 'ai' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ () => {
								const target = confirmation;
								setConfirmation( null );
								void perform( 'generate', target );
							} }
						>
							{ __( 'Replace drafts', 'ai' ) }
						</Button>
					</div>
				</Modal>
			) }
		</SlotFillProvider>
	);
}

domReady( () => {
	const root = document.getElementById( 'ai-topic-editor' );
	const settings = window.aiTopicEditorData;
	if ( root && settings ) {
		if ( ! getBlockType( 'core/paragraph' ) ) {
			registerCoreBlocks();
		}
		createRoot( root ).render( <TopicEditor settings={ settings } /> );
	}
} );
