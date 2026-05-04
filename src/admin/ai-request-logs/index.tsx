/**
 * Internal dependencies
 */
import './index.scss';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Notice } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import {
	createRoot,
	useCallback,
	useDeferredValue,
	useEffect,
	useState,
} from '@wordpress/element';

/**
 * Internal dependencies
 */
import HeaderPeriodSelector from './components/HeaderPeriodSelector';
import LogDetailModal from './components/LogDetailModal';
import LogsTable from './components/LogsTable';
import {
	getDefaultLogsQuery,
	normalizeLogsQuery,
	serializeOperationSelection,
} from './query';
import SettingsPanel from './components/SettingsPanel';
import SummaryCards from './components/SummaryCards';
import type {
	FilterOptions,
	LocalizedSettings,
	LogEntry,
	LogSummary,
	LogsQuery,
	SummaryPeriod,
} from './types';

const settings: LocalizedSettings =
	window.aiRequestLogsSettings ??
	( () => {
		throw new Error( 'aiRequestLogsSettings is not defined.' );
	} )();

const providerMetadata = settings.providerMetadata ?? {};
const connectorsUrl = settings.connectorsUrl;
const LOGS_QUERY_STORAGE_KEY = 'ai.requestLogs.query';
const INITIAL_FILTERS: FilterOptions = {
	...settings.initialState.filters,
	operations: settings.initialState.filters.operations ?? [],
};

apiFetch.use( apiFetch.createNonceMiddleware( settings.rest.nonce ) );
apiFetch.use( apiFetch.createRootURLMiddleware( settings.rest.root ) );

const showNotice = (
	status: 'success' | 'error' | 'warning',
	message: string
) =>
	dispatch( noticesStore ).createNotice( status, message, {
		type: 'snackbar',
	} );

const getErrorMessage = ( error: unknown ): string => {
	if ( typeof error === 'string' ) {
		return error;
	}

	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( ( error as { message: string } ).message );
	}

	return __( 'Something went wrong. Please try again.', 'ai' );
};

const getInitialLogsQuery = (): LogsQuery => {
	try {
		const persisted = window.localStorage.getItem( LOGS_QUERY_STORAGE_KEY );

		if ( ! persisted ) {
			return getDefaultLogsQuery();
		}

		return normalizeLogsQuery(
			JSON.parse( persisted ),
			INITIAL_FILTERS.operations
		);
	} catch {
		return getDefaultLogsQuery();
	}
};

const App: React.FC = () => {
	const [ summary, setSummary ] = useState< LogSummary >(
		settings.initialState.summary
	);
	const [ summaryPeriod, setSummaryPeriod ] =
		useState< SummaryPeriod >( 'day' );
	const [ summaryLoading, setSummaryLoading ] = useState( false );

	const [ logs, setLogs ] = useState< LogEntry[] >( [] );
	const [ logsLoading, setLogsLoading ] = useState( true );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );
	const [ logsQuery, setLogsQuery ] =
		useState< LogsQuery >( getInitialLogsQuery );
	const serializedOperationSelection = serializeOperationSelection(
		logsQuery.operation
	);
	const hasOperationSelection = logsQuery.operation.length > 0;
	const deferredSearch = useDeferredValue( logsQuery.search );

	const [ filterOptions, setFilterOptions ] =
		useState< FilterOptions >( INITIAL_FILTERS );

	const [ selectedLog, setSelectedLog ] = useState< LogEntry | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ purging, setPurging ] = useState( false );

	useEffect( () => {
		try {
			window.localStorage.setItem(
				LOGS_QUERY_STORAGE_KEY,
				JSON.stringify( logsQuery )
			);
		} catch {
			// Ignore localStorage persistence failures.
		}
	}, [ logsQuery ] );

	const fetchSummary = useCallback( async ( period: SummaryPeriod ) => {
		setSummaryLoading( true );
		try {
			const response = await apiFetch< LogSummary >( {
				path: `${ settings.rest.routes.summary }?period=${ period }`,
			} );
			setSummary( response );
		} catch {
			showNotice( 'error', __( 'Unable to load summary data.', 'ai' ) );
		} finally {
			setSummaryLoading( false );
		}
	}, [] );

	const fetchLogs = useCallback( async () => {
		setLogsLoading( true );

		try {
			const params = new URLSearchParams( {
				page: String( logsQuery.page ),
				per_page: String( logsQuery.perPage ),
			} );

			if ( logsQuery.type ) {
				params.append( 'type', logsQuery.type );
			}
			if ( logsQuery.status ) {
				params.append( 'status', logsQuery.status );
			}
			if ( logsQuery.provider ) {
				params.append( 'provider', logsQuery.provider );
			}
			if ( hasOperationSelection ) {
				params.append( 'operation', serializedOperationSelection );
			}
			if ( logsQuery.tokensFilter ) {
				params.append( 'tokens_filter', logsQuery.tokensFilter );
			}
			if ( logsQuery.userId ) {
				params.append( 'user_id', logsQuery.userId );
			}
			if ( deferredSearch ) {
				params.append( 'search', deferredSearch );
			}

			params.append( 'orderby', logsQuery.orderby );
			params.append( 'order', logsQuery.order.toUpperCase() );

			const response = await apiFetch< LogEntry[], false >( {
				path: `${ settings.rest.routes.logs }?${ params.toString() }`,
				parse: false,
			} );

			const data = ( await response.json() ) as LogEntry[];

			setLogs( data );
			setTotal(
				parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 )
			);
			setTotalPages(
				parseInt( response.headers.get( 'X-WP-TotalPages' ) || '1', 10 )
			);
			setError( null );
		} catch ( apiError ) {
			setError( getErrorMessage( apiError ) );
		} finally {
			setLogsLoading( false );
		}
	}, [
		deferredSearch,
		logsQuery.page,
		logsQuery.perPage,
		logsQuery.provider,
		logsQuery.status,
		logsQuery.tokensFilter,
		logsQuery.type,
		logsQuery.userId,
		logsQuery.orderby,
		logsQuery.order,
		hasOperationSelection,
		serializedOperationSelection,
	] );

	const fetchFilters = useCallback( async () => {
		try {
			const response = await apiFetch< FilterOptions >( {
				path: settings.rest.routes.filters,
			} );

			const nextFilters = {
				...response,
				operations: response.operations ?? [],
			};

			setFilterOptions( nextFilters );
			setLogsQuery( ( previous ) =>
				normalizeLogsQuery( previous, nextFilters.operations )
			);
		} catch {
			showNotice(
				'error',
				__( 'Unable to load filter metadata.', 'ai' )
			);
		}
	}, [] );

	useEffect( () => {
		fetchFilters();
	}, [ fetchFilters ] );

	useEffect( () => {
		fetchLogs();
	}, [ fetchLogs ] );

	const handlePeriodChange = ( period: SummaryPeriod ) => {
		setSummaryPeriod( period );
		fetchSummary( period );
	};

	const handlePurge = async () => {
		setPurging( true );

		try {
			await apiFetch( {
				path: settings.rest.routes.logs,
				method: 'DELETE',
			} );

			setLogs( [] );
			setTotal( 0 );
			setTotalPages( 1 );
			setLogsQuery( ( previous ) => ( {
				...previous,
				page: 1,
			} ) );

			showNotice( 'success', __( 'All logs have been purged.', 'ai' ) );
			fetchSummary( summaryPeriod );
			fetchFilters();
		} catch ( apiError ) {
			showNotice( 'error', getErrorMessage( apiError ) );
		} finally {
			setPurging( false );
		}
	};

	return (
		<div className="ai-request-logs__app">
			<HeaderPeriodSelector
				period={ summaryPeriod }
				onPeriodChange={ handlePeriodChange }
				loading={ summaryLoading }
			/>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<SummaryCards summary={ summary } loading={ summaryLoading } />

			<div className="ai-request-logs__main">
				<LogsTable
					logs={ logs }
					filterOptions={ filterOptions }
					onViewLog={ setSelectedLog }
					loading={ logsLoading }
					totalPages={ totalPages }
					total={ total }
					query={ logsQuery }
					setQuery={ setLogsQuery }
					providerMetadata={ providerMetadata }
					connectorsUrl={ connectorsUrl }
				/>

				<SettingsPanel
					onPurgeLogs={ handlePurge }
					purging={ purging }
				/>
			</div>

			{ selectedLog && (
				<LogDetailModal
					log={ selectedLog }
					onClose={ () => setSelectedLog( null ) }
				/>
			) }
		</div>
	);
};

const mountNode = document.getElementById( 'ai-request-logs-root' );

if ( mountNode ) {
	const root = createRoot( mountNode );
	root.render( <App /> );
}
