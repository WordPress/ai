/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import {
	Button,
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
	createResponseSequencer,
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
import type {
	AbilityDetailItem,
	AbilityListItem,
	PolicyState,
	SurfaceResponse,
} from '../types';
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
	policy: PolicyState | null;
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
	policy: null,
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
 * The Abilities Explorer app shell: routes between the list, detail, runner
 * and not-found views, and owns the list and item data and the error states.
 *
 * @return The app.
 */
export default function App() {
	const { route, navigate, navigationCount } = useRouter();
	const { createNotice } = useDispatch( noticesStore );

	const [ fatal, setFatal ] = useState< ExplorerError | null >( null );
	const fatalRef = useRef( false );

	const [ list, setList ] = useState< ListState >( INITIAL_LIST );
	const sequencer = useRef( createResponseSequencer() );
	const [ refreshing, setRefreshing ] = useState( false );
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

	const loadList = useCallback(
		async ( mode: 'initial' | 'refresh' ) => {
			if ( 'initial' === mode ) {
				setList( ( previous ) => ( {
					...previous,
					status: 'loading',
					error: null,
				} ) );
			} else {
				setRefreshing( true );
			}

			try {
				const response = await fetchAbilities();

				if ( sequencer.current.claimList( response.sequence ) ) {
					setList( ( previous ) => ( {
						status: 'ready',
						items: sequencer.current.mergeRows(
							response.sequence,
							response.items,
							previous.items
						),
						policy: sequencer.current.claimPolicy(
							response.sequence
						)
							? response.policy
							: previous.policy,
						error: null,
					} ) );
				}
			} catch ( error ) {
				if ( 'refresh' === mode ) {
					// A failed refresh keeps the last good rows.
					reportError( error, {
						message: __(
							'The abilities list could not be refreshed. The last loaded list is still shown.',
							'ai'
						),
					} );
				} else {
					const classified = reportError( error, { notice: false } );

					setList( ( previous ) => ( {
						...previous,
						status: 'error',
						error: classified.message,
					} ) );
				}
			} finally {
				setRefreshing( false );
			}
		},
		[ reportError ]
	);

	const applySurfaceResponse = useCallback(
		( response: SurfaceResponse ): boolean => {
			const seq = sequencer.current;

			if ( 'items' in response ) {
				if ( ! seq.claimList( response.sequence ) ) {
					return false;
				}

				setList( ( previous ) => ( {
					...previous,
					items: seq.mergeRows(
						response.sequence,
						response.items,
						previous.items
					),
					policy: seq.claimPolicy( response.sequence )
						? response.policy
						: previous.policy,
				} ) );

				return true;
			}

			if ( ! seq.claimRow( response.item.slug, response.sequence ) ) {
				return false;
			}

			setList( ( previous ) => ( {
				...previous,
				items: previous.items.map( ( row ) =>
					row.slug === response.item.slug ? response.item : row
				),
				policy: seq.claimPolicy( response.sequence )
					? response.policy
					: previous.policy,
			} ) );

			return true;
		},
		[]
	);

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

	// The list loads the first time it is shown, and is kept across views.
	useEffect( () => {
		if ( 'list' === route.view && 'idle' === list.status ) {
			loadList( 'initial' );
		}
	}, [ route.view, list.status, loadList ] );

	/*
	 * A detail or runner view loads its ability unless it is already the one
	 * held, so moving between the detail view and the runner does not refetch.
	 * Returning to the list drops the held item and ignores any response still
	 * in flight, so every entry from the list fetches the ability again rather
	 * than serving a failed, missing or stale one.
	 */
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
					<LoadError
						message={ list.error }
						onRetry={ () => loadList( 'initial' ) }
					/>
				);
			}

			return (
				<ListView
					items={ list.items }
					policy={ list.policy }
					isLoading={ 'ready' !== list.status || refreshing }
					onSurfaceResponse={ applySurfaceResponse }
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
				actions={
					'list' === route.view ? (
						<Button
							__next40pxDefaultSize
							variant="secondary"
							onClick={ () => loadList( 'refresh' ) }
							isBusy={ refreshing }
							disabled={
								isFatal || refreshing || 'ready' !== list.status
							}
							accessibleWhenDisabled
						>
							{ __( 'Refresh', 'ai' ) }
						</Button>
					) : undefined
				}
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
