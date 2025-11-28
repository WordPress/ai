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
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import {
	Button,
	ButtonGroup,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import type { DataViewField, View } from '@wordpress/dataviews';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

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
		type: 'table',
		perPage: 8,
		page: 1,
		search: '',
		fields: [ 'summary', 'timestamp', 'status', 'actions' ],
		sort: {
			field: 'timestamp',
			direction: 'desc',
		},
		layout: {
			density: 'comfortable',
		},
	} );

	const lastAutoWordCount = useRef( wordCount );

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
								<span
									className={ `ai-writing-assistant__priority ai-writing-assistant__priority--${ item.priority }` }
								>
									{ priorityLabels[ item.priority ] }
								</span>
								<span className="ai-writing-assistant__status-pill">
									{ __( 'Status', 'ai' ) }:{ ' ' }
									{ statusLabels[ item.status ] }
								</span>
							</div>
							<strong>{ item.summary }</strong>
							<p>{ item.details }</p>
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
					<ButtonGroup>
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
					</ButtonGroup>
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
			<Card className="ai-writing-assistant__card">
				<CardHeader>
					<strong>{ __( 'Session Controls', 'ai' ) }</strong>
				</CardHeader>
				<CardBody>
					<div className="ai-writing-assistant__timer-row">
						<div>
							<span className="ai-writing-assistant__timer-label">
								{ __( 'Timer', 'ai' ) }
							</span>
							<div className="ai-writing-assistant__timer-value">
								{ timerSeconds === 0
									? __( 'No limit', 'ai' )
									: formatTime( timeRemaining ) }
							</div>
						</div>
						<div className="ai-writing-assistant__timer-actions">
							<Button
								variant={
									sessionActive ? 'secondary' : 'primary'
								}
								onClick={
									sessionActive ? stopSession : startSession
								}
								disabled={ isFetching && ! sessionActive }
							>
								{ sessionActive
									? __( 'End Session', 'ai' )
									: __( 'Start Session', 'ai' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => triggerSuggestions( 'manual' ) }
								disabled={ ! sessionActive || isFetching }
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
					</div>

					<div className="ai-writing-assistant__stats-grid">
						<div>
							<span>{ __( 'Words this session', 'ai' ) }</span>
							<strong>{ wordsWritten.toLocaleString() }</strong>
						</div>
						<div>
							<span>{ __( 'Suggestions received', 'ai' ) }</span>
							<strong>{ stats.suggestionsReceived }</strong>
						</div>
						<div>
							<span>{ __( 'Suggestions applied', 'ai' ) }</span>
							<strong>{ stats.suggestionsApplied }</strong>
						</div>
					</div>

					<div className="ai-writing-assistant__timer-options">
						<span>{ __( 'Timer presets', 'ai' ) }</span>
						<ButtonGroup>
							{ timerOptions.map( ( seconds ) => (
								<Button
									key={ seconds }
									variant={
										timerSeconds === seconds
											? 'primary'
											: 'secondary'
									}
									onClick={ () => updateTimer( seconds ) }
								>
									{ seconds === 0
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
										  ) }
								</Button>
							) ) }
						</ButtonGroup>
						<TextControl
							label={ __( 'Custom timer (minutes)', 'ai' ) }
							type="number"
							min={ 0 }
							value={ customMinutes }
							onChange={ handleCustomTimerChange }
						/>
					</div>
				</CardBody>
			</Card>

			<Card className="ai-writing-assistant__card">
				<CardHeader>
					<strong>{ __( 'Suggestion Categories', 'ai' ) }</strong>
				</CardHeader>
				<CardBody>
					<div className="ai-writing-assistant__type-grid">
						{ data.suggestionTypes.map( ( type ) => (
							<div
								key={ type.slug }
								className="ai-writing-assistant__type"
							>
								<CheckboxControl
									label={
										<span className="ai-writing-assistant__type-label">
											<span
												className={ `dashicons dashicons-${ type.icon }` }
												aria-hidden="true"
											/>
											{ type.label }
										</span>
									}
									checked={ selectedTypes.includes(
										type.slug
									) }
									onChange={ ( value: boolean ) =>
										toggleType( type.slug, value )
									}
								/>
								<p>{ type.description }</p>
							</div>
						) ) }
					</div>
				</CardBody>
			</Card>

			<Card className="ai-writing-assistant__card">
				<CardHeader>
					<strong>{ __( 'Suggestion Stream', 'ai' ) }</strong>
				</CardHeader>
				<CardBody>
					{ errorMessage && (
						<Notice
							status="error"
							isDismissible
							onRemove={ () => setErrorMessage( null ) }
						>
							{ errorMessage }
						</Notice>
					) }
					{ ! sessionActive && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Start a session to collect fresh suggestions.',
								'ai'
							) }
						</Notice>
					) }
					<DataViews
						records={ suggestions }
						fields={ suggestionFields }
						view={ view }
						onChangeView={ setView }
						isLoading={ isFetching }
						searchLabel={ __( 'Search suggestions', 'ai' ) }
						getItemId={ ( item ) => item.id }
						emptyState={ {
							title: __( 'No suggestions yet', 'ai' ),
							description: __(
								'Generate suggestions to fill this stream.',
								'ai'
							),
						} }
					/>
				</CardBody>
			</Card>
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
