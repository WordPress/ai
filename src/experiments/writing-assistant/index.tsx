/**
 * Writing Assistant Gutenberg sidebar experiment.
 */

/**
 * WordPress dependencies
 */
import {
	useState,
	useMemo,
	useEffect,
	useCallback,
	useRef,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import {
	Button,
	CheckboxControl,
	Notice,
	Popover,
	Spinner,
	TextControl,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import {
	Icon,
	arrowRight,
	closeSmall,
	cog,
	timeToRead,
} from '@wordpress/icons';
import { DataViews } from '@wordpress/dataviews/wp';
import type { DataViewField, View } from '@wordpress/dataviews';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { runAbility } from '../../utils/run-ability';
import './style.scss';

type Suggestion = {
	id: string;
	type: string;
	priority: 'high' | 'medium' | 'low';
	summary: string;
	details: string;
	context?: string;
	action?: {
		type: string;
		payload?: Record< string, unknown >;
	} | null;
	timestamp: string;
	status: 'pending' | 'applied' | 'dismissed';
};

type AbilityResponse = {
	session_id?: string;
	meta?: {
		analyzed_at?: string;
		word_count?: number;
	};
	suggestions?: Array< Omit< Suggestion, 'status' > >;
};

type SuggestionTypeConfig = {
	slug: string;
	label: string;
	description: string;
	icon: string;
};

type LocalizedData = {
	enabled: boolean;
	ability: string;
	timer: {
		defaultSeconds: number;
		presets: number[];
	};
	wordTrigger: number;
	suggestionTypes: SuggestionTypeConfig[];
};

type SessionStats = {
	suggestionsReceived: number;
	suggestionsApplied: number;
	ghostAccepts: number;
};

declare global {
	interface Window {
		aiWritingAssistantData?: LocalizedData;
	}
}

const formatTime = ( totalSeconds: number ): string => {
	const safeSeconds = Math.max( 0, totalSeconds );
	const minutes = Math.floor( safeSeconds / 60 );
	const seconds = safeSeconds % 60;
	return `${ minutes.toString().padStart( 2, '0' ) }:${ seconds
		.toString()
		.padStart( 2, '0' ) }`;
};

const countWords = ( content: string ): number => {
	if ( ! content ) {
		return 0;
	}
	const text = content.replace( /<\/?[^>]+(>|$)/g, ' ' );
	const tokens = text
		.replace( /&nbsp;/g, ' ' )
		.trim()
		.split( /\s+/ );
	return tokens.filter( Boolean ).length;
};

const formatTimestamp = ( value: string ): string => {
	const date = new Date( value );
	if ( Number.isNaN( date.getTime() ) ) {
		return value;
	}
	return date.toLocaleString();
};

const createSessionId = (): string => {
	if ( typeof window !== 'undefined' && window.crypto?.randomUUID ) {
		return window.crypto.randomUUID();
	}
	return `session-${ Date.now().toString( 36 ) }-${ Math.random()
		.toString( 36 )
		.slice( 2, 8 ) }`;
};

const WritingAssistantApp: React.FC< { data: LocalizedData } > = ( {
	data,
} ) => {
	const { content, postId } = useSelect( ( select ) => {
		const editor = select( editorStore ) as {
			getEditedPostContent?: () => string;
			getCurrentPostId?: () => number;
		};

		return {
			content: editor?.getEditedPostContent?.() ?? '',
			postId: editor?.getCurrentPostId?.() ?? 0,
		};
	}, [] );

	const wordCount = useMemo( () => countWords( content ?? '' ), [ content ] );
	const [ timerSeconds, setTimerSeconds ] = useState(
		data.timer.defaultSeconds
	);
	const [ timeRemaining, setTimeRemaining ] = useState(
		data.timer.defaultSeconds
	);
	const [ customMinutes, setCustomMinutes ] = useState( '' );
	const [ sessionActive, setSessionActive ] = useState( false );
	const [ sessionId, setSessionId ] = useState( '' );
	const [ suggestions, setSuggestions ] = useState< Suggestion[] >( [] );
	const [ stats, setStats ] = useState< SessionStats >( {
		suggestionsReceived: 0,
		suggestionsApplied: 0,
		ghostAccepts: 0,
	} );
	const [ wordsAtStart, setWordsAtStart ] = useState( wordCount );
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const [ isFetching, setIsFetching ] = useState( false );
	const [ selectedTypes, setSelectedTypes ] = useState< string[] >(
		data.suggestionTypes.map( ( type ) => type.slug )
	);

	const typeLookup = useMemo( () => {
		const map = new Map< string, SuggestionTypeConfig >();
		data.suggestionTypes.forEach( ( type ) => map.set( type.slug, type ) );
		return map;
	}, [ data.suggestionTypes ] );

	const [ view, setView ] = useState< View >( {
		type: 'grid',
		perPage: 8,
		page: 1,
		search: '',
		fields: [ 'priority', 'timestamp', 'status', 'actions' ],
		titleField: 'summary',
		descriptionField: 'details',
		showTitle: true,
		showDescription: true,
		filters: [],
		sort: {
			field: 'timestamp',
			direction: 'desc',
		},
		layout: {
			previewSize: 360,
			badgeFields: [ 'priority', 'status' ],
		},
	} );

	const lastAutoWordCount = useRef( wordCount );
	const [ isControlsOpen, setIsControlsOpen ] = useState( false );
	const [ isSettingsOpen, setIsSettingsOpen ] = useState( false );
	const controlsButtonRef = useRef< HTMLButtonElement | null >( null );
	const settingsButtonRef = useRef< HTMLButtonElement | null >( null );

	const priorityLabels = useMemo(
		() => ( {
			high: __( 'High', 'ai' ),
			medium: __( 'Medium', 'ai' ),
			low: __( 'Low', 'ai' ),
		} ),
		[]
	);

	const priorityElements = useMemo(
		() => [
			{ label: __( 'High', 'ai' ), value: 'high' },
			{ label: __( 'Medium', 'ai' ), value: 'medium' },
			{ label: __( 'Low', 'ai' ), value: 'low' },
		],
		[]
	);

	const typeElements = useMemo(
		() =>
			data.suggestionTypes.map( ( type ) => ( {
				label: type.label,
				value: type.slug,
			} ) ),
		[ data.suggestionTypes ]
	);

	const statusElements = useMemo(
		() => [
			{ label: __( 'Pending', 'ai' ), value: 'pending' },
			{ label: __( 'Applied', 'ai' ), value: 'applied' },
			{ label: __( 'Dismissed', 'ai' ), value: 'dismissed' },
		],
		[]
	);

	const statusLabels = useMemo(
		() => ( {
			pending: __( 'Pending', 'ai' ),
			applied: __( 'Applied', 'ai' ),
			dismissed: __( 'Dismissed', 'ai' ),
		} ),
		[]
	);

	const wordsWritten = Math.max( 0, wordCount - wordsAtStart );
	const hasContent = wordCount > 0;

	const updateTimer = useCallback( ( seconds: number ) => {
		setTimerSeconds( seconds );
		setTimeRemaining( seconds );
	}, [] );

	const startSession = () => {
		setSessionActive( true );
		setSessionId( createSessionId() );
		setSuggestions( [] );
		setStats( {
			suggestionsReceived: 0,
			suggestionsApplied: 0,
			ghostAccepts: 0,
		} );
		setWordsAtStart( wordCount );
		updateTimer( timerSeconds );
		lastAutoWordCount.current = wordCount;
		setErrorMessage( null );
	};

	const stopSession = useCallback( () => {
		setSessionActive( false );
		updateTimer( timerSeconds );
	}, [ timerSeconds, updateTimer ] );

	useEffect( () => {
		if ( ! sessionActive || timerSeconds === 0 ) {
			return;
		}

		const interval = window.setInterval( () => {
			setTimeRemaining( ( current ) => {
				if ( current <= 1 ) {
					window.clearInterval( interval );
					stopSession();
					return 0;
				}
				return current - 1;
			} );
		}, 1000 );

		return () => window.clearInterval( interval );
	}, [ sessionActive, timerSeconds, stopSession ] );

	const handleApply = useCallback( ( id: string ) => {
		setSuggestions( ( current ) =>
			current.map( ( suggestion ) =>
				suggestion.id === id
					? { ...suggestion, status: 'applied' }
					: suggestion
			)
		);
		setStats( ( current ) => ( {
			...current,
			suggestionsApplied: current.suggestionsApplied + 1,
		} ) );
	}, [] );

	const handleDismiss = useCallback( ( id: string ) => {
		setSuggestions( ( current ) =>
			current.map( ( suggestion ) =>
				suggestion.id === id
					? { ...suggestion, status: 'dismissed' }
					: suggestion
			)
		);
	}, [] );

	const suggestionFields = useMemo< DataViewField< Suggestion >[] >(
		() => [
			{
				id: 'summary',
				label: __( 'Suggestion', 'ai' ),
				type: 'text',
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.summary,
				render: ( { item } ) => {
					const typeConfig = typeLookup.get( item.type );
					return (
						<div className="ai-writing-assistant__suggestion">
							<div className="ai-writing-assistant__suggestion-meta">
								{ typeConfig && (
									<span className="ai-writing-assistant__type-pill">
										<span
											className={ `dashicons dashicons-${ typeConfig.icon }` }
											aria-hidden="true"
										/>
										{ typeConfig.label }
									</span>
								) }
							</div>
							<strong>{ item.summary }</strong>
							{ item.context && (
								<p className="ai-writing-assistant__context">
									{ item.context }
								</p>
							) }
						</div>
					);
				},
			},
			{
				id: 'type',
				label: __( 'Type', 'ai' ),
				type: 'text',
				getValue: ( { item } ) => item.type,
				elements: typeElements,
				filterBy: { operators: [ 'isAny' ] },
				enableHiding: false,
				isVisible: () => false,
			},
			{
				id: 'details',
				label: __( 'Details', 'ai' ),
				type: 'text',
				getValue: ( { item } ) => item.details,
				render: ( { item } ) => (
					<p className="ai-writing-assistant__details">
						{ item.details }
					</p>
				),
				enableHiding: false,
				isVisible: () => false,
			},
			{
				id: 'priority',
				label: __( 'Priority', 'ai' ),
				type: 'text',
				getValue: ( { item } ) => item.priority,
				elements: priorityElements,
				filterBy: { operators: [ 'is' ] },
				enableHiding: false,
				isVisible: () => false,
			},
			{
				id: 'timestamp',
				label: __( 'Timestamp', 'ai' ),
				type: 'datetime',
				getValue: ( { item } ) => item.timestamp,
				render: ( { item } ) => formatTimestamp( item.timestamp ),
			},
			{
				id: 'status',
				label: __( 'Status', 'ai' ),
				type: 'text',
				getValue: ( { item } ) => item.status,
				elements: statusElements,
				filterBy: { operators: [ 'isAny' ] },
				render: ( { item } ) => (
					<span
						className={ `ai-writing-assistant__status ${ item.status }` }
					>
						{ statusLabels[ item.status ] }
					</span>
				),
			},
			{
				id: 'actions',
				label: __( 'Actions', 'ai' ),
				type: 'text',
				enableSorting: false,
				enableHiding: false,
				filterBy: false,
				render: ( { item } ) => (
					<div className="ai-writing-assistant__action-buttons">
						<Button
							variant="primary"
							size="small"
							onClick={ () => handleApply( item.id ) }
							disabled={ item.status === 'applied' }
						>
							{ item.status === 'applied'
								? __( 'Applied', 'ai' )
								: __( 'Apply', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							size="small"
							onClick={ () => handleDismiss( item.id ) }
							disabled={ item.status === 'dismissed' }
						>
							{ item.status === 'dismissed'
								? __( 'Dismissed', 'ai' )
								: __( 'Dismiss', 'ai' ) }
						</Button>
					</div>
				),
			},
		],
		[
			handleApply,
			handleDismiss,
			priorityElements,
			priorityLabels,
			statusLabels,
			statusElements,
			typeElements,
			typeLookup,
		]
	);

	const mapResponseToSuggestions = useCallback(
		( response?: AbilityResponse ): Suggestion[] => {
			if ( ! response?.suggestions?.length ) {
				return [];
			}

			return response.suggestions.map(
				( suggestion ) =>
					( {
						...suggestion,
						status: 'pending',
						timestamp:
							suggestion.timestamp ?? new Date().toISOString(),
					} ) as Suggestion
			);
		},
		[]
	);

	const triggerSuggestions = useCallback(
		async ( trigger: string, wordDelta = 0 ) => {
			if ( selectedTypes.length === 0 ) {
				setErrorMessage(
					__( 'Select at least one suggestion type.', 'ai' )
				);
				return;
			}

			setIsFetching( true );
			setErrorMessage( null );

			try {
				const payload = {
					post_id: postId || undefined,
					content,
					requested_types: selectedTypes,
					session: {
						id: sessionId,
						words_written: wordsWritten,
						suggestions_received: stats.suggestionsReceived,
						suggestions_applied: stats.suggestionsApplied,
						ghost_accepts: stats.ghostAccepts,
						timer_duration: timerSeconds,
						timer_remaining: timeRemaining,
					},
					trigger,
					word_delta: wordDelta,
				};

				const result = await runAbility< AbilityResponse >(
					data.ability,
					payload
				);
				const incoming = mapResponseToSuggestions( result );

				if ( result?.session_id && sessionId !== result.session_id ) {
					setSessionId( result.session_id );
				}

				if ( incoming.length > 0 ) {
					setSuggestions( ( current ) => {
						const map = new Map(
							current.map( ( item ) => [ item.id, item ] )
						);
						incoming.forEach( ( item ) => {
							const existing = map.get( item.id );
							map.set(
								item.id,
								existing
									? { ...item, status: existing.status }
									: item
							);
						} );

						return Array.from( map.values() ).sort( ( a, b ) => {
							return (
								new Date( b.timestamp ).getTime() -
								new Date( a.timestamp ).getTime()
							);
						} );
					} );

					setStats( ( current ) => ( {
						...current,
						suggestionsReceived:
							current.suggestionsReceived + incoming.length,
					} ) );
				}
			} catch ( error ) {
				const message =
					error instanceof Error
						? error.message
						: __( 'Unable to fetch suggestions.', 'ai' );
				setErrorMessage( message );
			} finally {
				setIsFetching( false );
			}
		},
		[
			content,
			data.ability,
			postId,
			selectedTypes,
			sessionId,
			stats.ghostAccepts,
			stats.suggestionsApplied,
			stats.suggestionsReceived,
			timeRemaining,
			timerSeconds,
			wordsWritten,
			mapResponseToSuggestions,
		]
	);

	useEffect( () => {
		if ( ! sessionActive ) {
			lastAutoWordCount.current = wordCount;
			return;
		}

		const delta = wordCount - lastAutoWordCount.current;
		if ( delta >= data.wordTrigger && ! isFetching ) {
			lastAutoWordCount.current = wordCount;
			triggerSuggestions( 'word-delta', delta );
		}
	}, [
		data.wordTrigger,
		isFetching,
		sessionActive,
		triggerSuggestions,
		wordCount,
	] );

	const timerOptions = useMemo( () => {
		const unique = Array.from( new Set( data.timer.presets ) );
		return unique.sort( ( a, b ) => a - b );
	}, [ data.timer.presets ] );

	const typeSummary = useMemo( () => {
		if ( selectedTypes.length === 0 ) {
			return __( 'No categories selected', 'ai' );
		}
		if ( selectedTypes.length === data.suggestionTypes.length ) {
			return __( 'All categories selected', 'ai' );
		}
		return sprintf(
			/* translators: %d: number of selected categories. */
			_n(
				'%d category selected',
				'%d categories selected',
				selectedTypes.length,
				'ai'
			),
			selectedTypes.length
		);
	}, [ selectedTypes, data.suggestionTypes.length ] );

	const toggleType = ( slug: string, next: boolean ) => {
		setSelectedTypes( ( current ) => {
			if ( next ) {
				return Array.from( new Set( [ ...current, slug ] ) );
			}
			const filtered = current.filter( ( item ) => item !== slug );
			return filtered.length === 0 ? current : filtered;
		} );
	};

	const handleCustomTimerChange = ( value: string ) => {
		setCustomMinutes( value );
		const numeric = parseInt( value, 10 );
		if ( Number.isNaN( numeric ) || numeric < 0 ) {
			return;
		}
		updateTimer( numeric * 60 );
	};

	return (
		<div className="ai-writing-assistant">
			<div className="ai-writing-assistant__header">
				<div className="ai-writing-assistant__status-bar">
					<span>
						<strong>{ __( 'Timer', 'ai' ) }:</strong>
						{ timerSeconds === 0
							? __( 'No limit', 'ai' )
							: formatTime( timeRemaining ) }
					</span>
					<span>
						<strong>{ __( 'Words', 'ai' ) }:</strong>
						{ wordsWritten.toLocaleString() }
					</span>
					<span>
						<strong>{ __( 'Status', 'ai' ) }:</strong>
						{ sessionActive
							? __( 'Active', 'ai' )
							: __( 'Idle', 'ai' ) }
					</span>
				</div>
				<div className="ai-writing-assistant__session-row">
					<Button
						variant="secondary"
						onClick={ sessionActive ? stopSession : startSession }
						disabled={ isFetching && ! sessionActive }
						className="ai-writing-assistant__session-toggle"
					>
						<Icon
							icon={ sessionActive ? closeSmall : arrowRight }
						/>
						<span>
							{ sessionActive
								? __( 'End session', 'ai' )
								: __( 'Start session', 'ai' ) }
						</span>
					</Button>
					<div className="ai-writing-assistant__header-actions">
						<Button
							variant="tertiary"
							className="ai-writing-assistant__icon-button"
							icon={ <Icon icon={ timeToRead } /> }
							label={ __( 'Session controls', 'ai' ) }
							ref={ controlsButtonRef }
							onClick={ () =>
								setIsControlsOpen( ( prev ) => ! prev )
							}
							aria-expanded={ isControlsOpen }
						/>
						<Button
							variant="tertiary"
							className="ai-writing-assistant__icon-button"
							icon={ <Icon icon={ cog } /> }
							label={ __( 'Settings', 'ai' ) }
							ref={ settingsButtonRef }
							onClick={ () =>
								setIsSettingsOpen( ( prev ) => ! prev )
							}
							aria-expanded={ isSettingsOpen }
						/>
					</div>
				</div>
			</div>

			<div className="ai-writing-assistant__content">
				<div className="ai-writing-assistant__panel ai-writing-assistant__panel--stream">
					{ errorMessage && (
						<Notice
							status="error"
							isDismissible
							onRemove={ () => setErrorMessage( null ) }
						>
							{ errorMessage }
						</Notice>
					) }
					<div className="ai-writing-assistant__stream">
						<DataViews
							data={ suggestions }
							fields={ suggestionFields }
							view={ view }
							onChangeView={ setView }
							isLoading={ isFetching }
							searchLabel={ __( 'Search suggestions', 'ai' ) }
							getItemId={ ( item ) => item.id }
							paginationInfo={ {
								totalItems: suggestions.length,
								totalPages: Math.max(
									1,
									Math.ceil(
										suggestions.length /
											( view.perPage ?? 8 )
									)
								),
							} }
							defaultLayouts={ {
								table: {
									layout: {
										density: 'comfortable',
										enableMoving: false,
									},
								},
							} }
							config={ {
								perPageSizes: [ 8, 16, 32 ],
							} }
							empty={
								<div className="ai-writing-assistant__empty">
									<strong>
										{ __( 'No suggestions yet', 'ai' ) }
									</strong>
									<p>
										{ __(
											'Generate suggestions to fill this stream.',
											'ai'
										) }
									</p>
								</div>
							}
						/>
					</div>
				</div>
			</div>

			<div className="ai-writing-assistant__footer">
				<Button
					variant="primary"
					size="large"
					className="ai-writing-assistant__footer-button"
					onClick={ () => triggerSuggestions( 'manual' ) }
					disabled={ ! hasContent || isFetching }
				>
					{ isFetching ? (
						<>
							<Spinner />
							{ __( 'Working…', 'ai' ) }
						</>
					) : (
						__( 'Generate suggestions', 'ai' )
					) }
				</Button>
			</div>

			{ isControlsOpen && controlsButtonRef.current && (
				<Popover
					anchor={ controlsButtonRef.current }
					onClose={ () => setIsControlsOpen( false ) }
					placement="bottom-start"
					className="ai-writing-assistant__controls-popover"
				>
					<div className="ai-writing-assistant__controls">
						<ToggleGroupControl
							label={ __( 'Timer presets', 'ai' ) }
							value={ String( timerSeconds ) }
							isBlock
							onChange={ ( value ) => {
								const seconds = Number( value );
								if ( ! Number.isNaN( seconds ) ) {
									updateTimer( seconds );
								}
							} }
						>
							{ timerOptions.map( ( seconds ) => (
								<ToggleGroupControlOption
									key={ seconds }
									value={ String( seconds ) }
									label={
										seconds === 0
											? __( 'No timer', 'ai' )
											: sprintf(
													/* translators: %d: number of minutes. */
													_n(
														'%d min',
														'%d mins',
														seconds / 60,
														'ai'
													),
													Math.round( seconds / 60 )
											  )
									}
								/>
							) ) }
						</ToggleGroupControl>
						<TextControl
							label={ __( 'Custom timer (minutes)', 'ai' ) }
							type="number"
							min={ 0 }
							value={ customMinutes }
							onChange={ handleCustomTimerChange }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<Button
							variant="secondary"
							onClick={ () => triggerSuggestions( 'manual' ) }
							disabled={ ! sessionActive || isFetching }
						>
							{ isFetching
								? __( 'Working…', 'ai' )
								: __( 'Generate suggestions', 'ai' ) }
						</Button>
					</div>
				</Popover>
			) }

			{ isSettingsOpen && settingsButtonRef.current && (
				<Popover
					anchor={ settingsButtonRef.current }
					onClose={ () => setIsSettingsOpen( false ) }
					placement="bottom-end"
					className="ai-writing-assistant__settings-popover"
				>
					<div className="ai-writing-assistant__type-dropdown">
						<strong>
							{ __( 'Suggestion categories', 'ai' ) }
						</strong>
						<span className="ai-writing-assistant__type-summary">
							{ typeSummary }
						</span>
						{ data.suggestionTypes.map( ( type ) => (
							<CheckboxControl
								key={ type.slug }
								label={ type.label }
								checked={ selectedTypes.includes( type.slug ) }
								onChange={ ( value: boolean ) =>
									toggleType( type.slug, value )
								}
								__nextHasNoMarginBottom
							/>
						) ) }
						<p className="ai-writing-assistant__settings-help">
							{ __(
								'Selected categories inform which insight types get requested from the AI service.',
								'ai'
							) }
						</p>
					</div>
				</Popover>
			) }
		</div>
	);
};

const WritingAssistantSidebar: React.FC = () => {
	const data = window.aiWritingAssistantData;

	if ( ! data ) {
		return null;
	}

	if ( ! data.enabled ) {
		return (
			<>
				<PluginSidebarMoreMenuItem target="ai-writing-assistant-sidebar">
					{ __( 'AI Writing Assistant', 'ai' ) }
				</PluginSidebarMoreMenuItem>
				<PluginSidebar
					name="ai-writing-assistant-sidebar"
					title={ __( 'AI Writing Assistant', 'ai' ) }
					icon="welcome-write-blog"
				>
					<div className="ai-writing-assistant ai-writing-assistant__card">
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This experiment is currently disabled.',
								'ai'
							) }
						</Notice>
					</div>
				</PluginSidebar>
			</>
		);
	}

	return (
		<>
			<PluginSidebarMoreMenuItem target="ai-writing-assistant-sidebar">
				{ __( 'AI Writing Assistant', 'ai' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="ai-writing-assistant-sidebar"
				title={ __( 'AI Writing Assistant', 'ai' ) }
				icon="welcome-write-blog"
			>
				<WritingAssistantApp data={ data } />
			</PluginSidebar>
		</>
	);
};

if ( window.aiWritingAssistantData ) {
	registerPlugin( 'ai-writing-assistant', {
		render: WritingAssistantSidebar,
		icon: 'welcome-write-blog',
	} );
}
