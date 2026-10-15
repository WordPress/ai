/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import {
	Disabled,
	Notice,
	Spinner,
	VisuallyHidden,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import {
	classifyError,
	fetchAbilities,
	fetchAbility,
	isFatalError,
	type ExplorerError,
} from '../api';
import {
	ExplorerContext,
	type ExplorerContextValue,
	type NoticeStatus,
	type ReportErrorOptions,
} from '../context';
import { routeToUrl, useRouter } from '../router';
import type { AbilityDetailItem, AbilityListItem } from '../types';
import DetailView from './DetailView';
import ListView from './ListView';
import LoadError from './LoadError';
import NotFound from './NotFound';
import Snackbars from './Snackbars';
import TestRunner from './TestRunner';

export const ROOT_ID = 'ai-abilities-explorer-root';

interface ListState {
	status: 'idle' | 'loading' | 'ready' | 'error';
	items: AbilityListItem[];
	/** The first-load failure, shown inline with Retry. */
	error: string | null;
}

interface ItemState {
	/** The ability this state belongs to; '' before any item is requested. */
	name: string;
	status: 'loading' | 'ready' | 'missing' | 'error';
	item: AbilityDetailItem | null;
	error: string | null;
}

const INITIAL_LIST: ListState = {
	status: 'idle',
	items: [],
	error: null,
};

const INITIAL_ITEM: ItemState = {
	name: '',
	status: 'loading',
	item: null,
	error: null,
};

/**
 * Moves focus to the view's heading after in-app navigation.
 */
const focusHeading = () => {
	const heading = document.getElementById( ROOT_ID )?.querySelector( 'h1' );

	if ( ! heading ) {
		return;
	}

	heading.setAttribute( 'tabindex', '-1' );
	heading.focus();
};

/**
 * The Abilities Explorer app shell: routes between views and owns their data.
 *
 * @return The app.
 */
export default function App() {
	const { route, navigate, navigationCount } = useRouter();
	const { createNotice } = useDispatch( noticesStore );

	const [ fatal, setFatal ] = useState< ExplorerError | null >( null );
	const fatalRef = useRef( false );

	const [ list, setList ] = useState< ListState >( INITIAL_LIST );
	const [ itemState, setItemState ] = useState< ItemState >( INITIAL_ITEM );
	const itemRequest = useRef( 0 );

	const notify = useCallback(
		( status: NoticeStatus, message: string ) => {
			if ( fatalRef.current ) {
				return;
			}

			createNotice( status, message, {
				type: 'snackbar',
				explicitDismiss: 'error' === status,
			} );
		},
		[ createNotice ]
	);

	const reportError = useCallback(
		( error: unknown, options: ReportErrorOptions = {} ) => {
			const classified = classifyError( error );

			if ( isFatalError( classified ) ) {
				if ( ! fatalRef.current ) {
					fatalRef.current = true;
					setFatal( classified );
				}

				return classified;
			}

			if ( 'aborted' !== classified.kind && false !== options.notice ) {
				notify( 'error', options.message ?? classified.message );
			}

			return classified;
		},
		[ notify ]
	);

	const loadList = useCallback( async () => {
		setList( ( previous ) => ( {
			...previous,
			status: 'loading',
			error: null,
		} ) );

		try {
			const response = await fetchAbilities();

			setList( {
				status: 'ready',
				items: response.items,
				error: null,
			} );
		} catch ( error ) {
			const classified = reportError( error, { notice: false } );

			setList( ( previous ) => ( {
				...previous,
				status: 'error',
				error: classified.message,
			} ) );
		}
	}, [ reportError ] );

	const loadItem = useCallback(
		async ( name: string ) => {
			const requestId = ++itemRequest.current;

			setItemState( {
				name,
				status: 'loading',
				item: null,
				error: null,
			} );

			try {
				const item = await fetchAbility( name );

				if ( requestId === itemRequest.current ) {
					setItemState( {
						name,
						status: 'ready',
						item,
						error: null,
					} );
				}
			} catch ( error ) {
				if ( requestId !== itemRequest.current ) {
					return;
				}

				const classified = reportError( error, { notice: false } );

				setItemState( {
					name,
					status:
						'not-found' === classified.kind ? 'missing' : 'error',
					item: null,
					error: classified.message,
				} );
			}
		},
		[ reportError ]
	);

	useEffect( () => {
		if ( 'list' === route.view && 'idle' === list.status ) {
			loadList();
		}
	}, [ route.view, list.status, loadList ] );

	// Returning to the list drops the held item, so the next entry refetches it.
	const requestedAbility = 'list' === route.view ? '' : route.ability;

	useEffect( () => {
		if ( 'list' === route.view ) {
			if ( '' !== itemState.name ) {
				itemRequest.current++;
				setItemState( INITIAL_ITEM );
			}

			return;
		}

		if ( '' === requestedAbility || requestedAbility === itemState.name ) {
			return;
		}

		loadItem( requestedAbility );
	}, [ route.view, requestedAbility, itemState.name, loadItem ] );

	useEffect( () => {
		if ( navigationCount > 0 ) {
			focusHeading();
		}
	}, [ navigationCount ] );

	const isFatal = null !== fatal;

	const context = useMemo< ExplorerContextValue >(
		() => ( {
			navigate,
			getHref: routeToUrl,
			reportError,
			notify,
			isFatal,
		} ),
		[ navigate, reportError, notify, isFatal ]
	);

	const renderView = () => {
		if ( 'list' === route.view ) {
			if ( 'error' === list.status && null !== list.error ) {
				return (
					<LoadError message={ list.error } onRetry={ loadList } />
				);
			}

			return (
				<ListView
					items={ list.items }
					isLoading={ 'ready' !== list.status }
				/>
			);
		}

		if ( '' === route.ability ) {
			return <NotFound reason="unspecified" />;
		}

		if (
			itemState.name !== route.ability ||
			'loading' === itemState.status
		) {
			return (
				<div className="ai-abilities-explorer__loading">
					<Spinner />
					<VisuallyHidden>
						{ __( 'Loading ability…', 'ai' ) }
					</VisuallyHidden>
				</div>
			);
		}

		if ( 'missing' === itemState.status ) {
			return <NotFound reason="missing" />;
		}

		if ( 'error' === itemState.status || null === itemState.item ) {
			const name = route.ability;

			return (
				<LoadError
					message={
						itemState.error ?? __( 'Something went wrong.', 'ai' )
					}
					onRetry={ () => loadItem( name ) }
				/>
			);
		}

		if ( 'detail' === route.view ) {
			return <DetailView item={ itemState.item } />;
		}

		return (
			<TestRunner key={ itemState.item.slug } item={ itemState.item } />
		);
	};

	return (
		<ExplorerContext.Provider value={ context }>
			<Page
				className="ai-abilities-explorer__page"
				title={ __( 'Abilities Explorer', 'ai' ) }
				subTitle={ __(
					'Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.',
					'ai'
				) }
			>
				<div className="ai-abilities-explorer__app">
					{ fatal && (
						<Notice status="error" isDismissible={ false }>
							{ fatal.message }
						</Notice>
					) }
					<Disabled isDisabled={ isFatal }>{ renderView() }</Disabled>
				</div>
			</Page>
			<Snackbars />
		</ExplorerContext.Provider>
	);
}
