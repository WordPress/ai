/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import { Button, Spinner } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import type { DataFormControlProps } from '@wordpress/dataviews/wp';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import type { RefObject } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { AlertDialog, Notice, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useProviders } from '../hooks/use-providers';
import {
	EMBEDDING_CAPABILITY,
	isSameEmbeddingModel,
	normalizeEmbeddingModel,
} from './EmbeddingModelField';

type Settings = Record< string, unknown >;

interface EmbeddingStatus {
	target: { provider: string; model: string; dimensions: number | null };
	backfill:
		| ( {
				status: 'running' | 'complete' | 'cancelled';
				started_at?: number;
		  } & Record< string, unknown > )
		| null;
	backoff: number | null;
	provider_error: string | null;
	last_run: number | null;
	totals: { indexed: number; indexable: number };
	cron_disabled: boolean;
}

type EmbeddingSyncPanelProps = DataFormControlProps< Settings > & {
	consumer: string;
	methodField: string;
	modelField: string;
};

const EMBEDDINGS_METHOD = 'embeddings';
const POLL_INTERVAL_MS = 10_000;
const STALE_AFTER_SECONDS = 5 * 60;
const ACTION_RESOLVED = -1;

/**
 * Formats a unix timestamp as a relative time, e.g. "in 5 minutes".
 *
 * @param {number|null} timestamp Unix timestamp in seconds.
 * @return {string} The relative time.
 */
function formatRelativeTime( timestamp: number | null ): string {
	const seconds = Math.max(
		0,
		Math.round( ( timestamp ?? 0 ) - Date.now() / 1000 )
	);
	const locale = document.documentElement.lang || undefined;
	const rtf = new Intl.RelativeTimeFormat( locale, { numeric: 'auto' } );

	if ( seconds < 60 ) {
		return rtf.format( seconds, 'second' );
	}
	if ( seconds < 3600 ) {
		return rtf.format( Math.round( seconds / 60 ), 'minute' );
	}
	if ( seconds < 86400 ) {
		return rtf.format( Math.round( seconds / 3600 ), 'hour' );
	}
	return rtf.format( Math.round( seconds / 86400 ), 'day' );
}

/**
 * Checks whether background processing looks stalled for a running backfill.
 *
 * @param {EmbeddingStatus} status The sync status.
 * @return {boolean} True when nothing has run for longer than the threshold.
 */
function isProcessingStale( status: EmbeddingStatus ): boolean {
	if ( status.cron_disabled ) {
		return true;
	}

	const startedAt =
		typeof status.backfill?.started_at === 'number'
			? status.backfill.started_at
			: 0;
	const lastActivity = Math.max( status.last_run ?? 0, startedAt );

	return Date.now() / 1000 - lastActivity > STALE_AFTER_SECONDS;
}

type BackfillMethod = 'POST' | 'DELETE';

/**
 * EmbeddingSyncPanel component.
 *
 * @param {EmbeddingSyncPanelProps} props             The component props.
 * @param {Settings}                props.data        The edited settings.
 * @param {Object}                  props.field       The display-only field.
 * @param {string}                  props.consumer    The embedding consumer ID.
 * @param {string}                  props.methodField Option name of the suggestion method field.
 * @param {string}                  props.modelField  Option name of the embedding model field.
 * @return {React.JSX.Element} The component.
 */
export function EmbeddingSyncPanel( {
	data,
	field,
	consumer,
	methodField,
	modelField,
}: EmbeddingSyncPanelProps ): React.JSX.Element {
	const { providers } = useProviders( EMBEDDING_CAPABILITY );

	const { savedMethod, savedModelRaw } = useSelect(
		( select ) => {
			const record = ( select( coreStore ) as any ).getEntityRecord(
				'root',
				'site'
			) as Settings | undefined;
			return {
				savedMethod: record?.[ methodField ],
				savedModelRaw: record?.[ modelField ],
			};
		},
		[ methodField, modelField ]
	);

	const savedModel = normalizeEmbeddingModel( savedModelRaw );
	const editedModel = normalizeEmbeddingModel( data[ modelField ] );
	const isDirty =
		data[ methodField ] !== savedMethod ||
		! isSameEmbeddingModel( editedModel, savedModel );
	const isSavedReady =
		savedMethod === EMBEDDINGS_METHOD && savedModel.model !== '';

	const [ status, setStatus ] = useState< EmbeddingStatus | null >( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ fetchError, setFetchError ] = useState< string | null >( null );
	const [ isActing, setIsActing ] = useState( false );
	const [ isConfirmOpen, setIsConfirmOpen ] = useState( false );
	// Bumped every time a request settles, so the next poll is scheduled
	// only after the previous one has finished.
	const [ settledCount, setSettledCount ] = useState( 0 );

	// Only the latest request may update state; older responses are dropped.
	const requestIdRef = useRef( 0 );
	const isActingRef = useRef( false );
	const primaryActionRef = useRef< HTMLButtonElement | null >( null );
	const bodyRef = useRef< HTMLDivElement | null >( null );
	const pendingFocusRef = useRef( false );
	const pendingAnnounceRef = useRef( false );
	// ID of the user action whose response the flags above belong to, or
	// ACTION_RESOLVED once that response has been applied.
	const pendingActionIdRef = useRef< number | null >( null );
	const previousProviderErrorRef = useRef< string | null >( null );

	const statusPath = `/ai/v1/embeddings/consumers/${ encodeURIComponent(
		consumer
	) }`;

	/**
	 * Runs a status request and applies the result if it's still the latest.
	 *
	 * @param {Object}  options         apiFetch options.
	 * @param {Object}  follow          What to do once the new state renders.
	 * @param {boolean} follow.focus    Move focus to the new primary action.
	 * @param {boolean} follow.announce Announce the new state.
	 * @return {Promise<string|null>} The error message, or null on success or when dropped.
	 */
	const request = useCallback(
		(
			options: { path: string; method?: BackfillMethod },
			follow: { focus?: boolean; announce?: boolean } = {}
		) => {
			const id = ++requestIdRef.current;

			if ( follow.focus || follow.announce ) {
				pendingActionIdRef.current = id;
				pendingFocusRef.current = !! follow.focus;
				pendingAnnounceRef.current = !! follow.announce;
			}

			const clearPendingFor = ( requestId: number ) => {
				if ( pendingActionIdRef.current === requestId ) {
					pendingActionIdRef.current = null;
					pendingFocusRef.current = false;
					pendingAnnounceRef.current = false;
				}
			};

			return apiFetch< EmbeddingStatus >( options ).then(
				( response ) => {
					if ( id !== requestIdRef.current ) {
						clearPendingFor( id );
						return null;
					}
					if ( pendingActionIdRef.current === id ) {
						pendingActionIdRef.current = ACTION_RESOLVED;
					}
					setStatus( response );
					setFetchError( null );
					setSettledCount( ( count ) => count + 1 );
					return null;
				},
				( error: unknown ) => {
					// On failure the busy button stays in place and keeps focus.
					clearPendingFor( id );
					if ( id !== requestIdRef.current ) {
						return null;
					}
					const { code, message } = ( error ?? {} ) as {
						code?: string;
						message?: string;
					};
					setSettledCount( ( count ) => count + 1 );
					if ( code === 'ai_embeddings_unknown_consumer' ) {
						setStatus( null );
						setFetchError( null );
						return null;
					}
					const errorMessage =
						message ||
						__( 'Failed to load embedding status.', 'ai' );
					setFetchError( errorMessage );
					return errorMessage;
				}
			);
		},
		[]
	);

	const fetchStatus = useCallback(
		() => request( { path: statusPath } ),
		[ request, statusPath ]
	);

	// (Re)load whenever the saved model changes, since that changes the target.
	useEffect( () => {
		if ( ! isSavedReady || ! consumer ) {
			setStatus( null );
			setFetchError( null );
			return undefined;
		}

		setIsLoading( true );
		fetchStatus().finally( () => setIsLoading( false ) );

		return () => {
			// Drop responses for the previous target. The ref is a request
			// counter, not a DOM node, so reading it at cleanup time is intended.
			// eslint-disable-next-line react-hooks/exhaustive-deps
			requestIdRef.current++;
		};
	}, [
		isSavedReady,
		consumer,
		savedModel.provider,
		savedModel.model,
		fetchStatus,
	] );

	const isRunning = status?.backfill?.status === 'running';

	// Poll while the last known status is running, one request at a time.
	useEffect( () => {
		if ( ! isRunning ) {
			return undefined;
		}

		const timeout = window.setTimeout( () => {
			if ( ! isActingRef.current ) {
				void fetchStatus();
			}
		}, POLL_INTERVAL_MS );

		return () => window.clearTimeout( timeout );
	}, [ isRunning, settledCount, fetchStatus ] );

	const runBackfill = useCallback(
		( method: BackfillMethod, follow: { focus: boolean } ) => {
			isActingRef.current = true;
			setIsActing( true );

			return request(
				{ path: `${ statusPath }/backfill`, method },
				{ focus: follow.focus, announce: true }
			).finally( () => {
				isActingRef.current = false;
				setIsActing( false );
			} );
		},
		[ request, statusPath ]
	);

	// Started from a button that the new state replaces, so focus and an
	// announcement follow once the response is rendered.
	const runBackfillFromButton = ( method: BackfillMethod ) => {
		void runBackfill( method, { focus: true } ).then( ( error ) => {
			if ( error ) {
				speak( error, 'assertive' );
			}
		} );
	};

	const remaining = status
		? Math.max( 0, status.totals.indexable - status.totals.indexed )
		: 0;
	const providerName = status
		? providers.find( ( p ) => p.id === status.target.provider )?.name ??
		  status.target.provider
		: '';

	let message = '';
	let content: React.JSX.Element | null = null;

	if ( editedModel.model === '' ) {
		message = __( 'Choose an embedding model to continue.', 'ai' );
		content = <PanelButton label={ __( 'Generate embeddings', 'ai' ) } />;
	} else if ( isDirty || ! isSavedReady ) {
		message = __(
			'Save your settings before generating embeddings.',
			'ai'
		);
		content = <PanelButton label={ __( 'Generate embeddings', 'ai' ) } />;
	} else if ( isLoading && ! status ) {
		content = <Spinner />;
	} else if ( ! status ) {
		if ( ! fetchError ) {
			message = __(
				'Save your settings before generating embeddings.',
				'ai'
			);
			content = (
				<PanelButton label={ __( 'Generate embeddings', 'ai' ) } />
			);
		}
	} else if ( status.provider_error !== null ) {
		message = sprintf(
			// translators: 1: Error message returned by the embedding provider. 2: Relative time of the next retry, e.g. "in 5 minutes".
			__( 'Your provider returned an error: %1$s. Retrying %2$s.', 'ai' ),
			status.provider_error,
			formatRelativeTime( status.backoff )
		);
		content = isRunning ? (
			<PanelButton
				label={ __( 'Cancel', 'ai' ) }
				variant="secondary"
				buttonRef={ primaryActionRef }
				isBusy={ isActing }
				onClick={ () => runBackfillFromButton( 'DELETE' ) }
			/>
		) : null;
	} else if ( isRunning ) {
		message = sprintf(
			// translators: 1: Number of terms embedded so far. 2: Total number of terms.
			_n(
				'Generating embeddings: %1$d of %2$d term done.',
				'Generating embeddings: %1$d of %2$d terms done.',
				status.totals.indexable,
				'ai'
			),
			status.totals.indexed,
			status.totals.indexable
		);
		content = (
			<>
				{ isProcessingStale( status ) && (
					<Notice.Root intent="warning">
						<Notice.Description>
							{ __(
								'Background processing hasn\'t run recently. If WP-Cron is disabled on this site, run "wp ai embeddings sync run" from WP-CLI.',
								'ai'
							) }
						</Notice.Description>
					</Notice.Root>
				) }
				<PanelButton
					label={ __( 'Cancel', 'ai' ) }
					variant="secondary"
					buttonRef={ primaryActionRef }
					isBusy={ isActing }
					onClick={ () => runBackfillFromButton( 'DELETE' ) }
				/>
			</>
		);
	} else if (
		status.totals.indexable > 0 &&
		status.totals.indexed >= status.totals.indexable
	) {
		message = sprintf(
			// translators: %d: Number of terms.
			_n(
				'All %d term is embedded.',
				'All %d terms are embedded.',
				status.totals.indexable,
				'ai'
			),
			status.totals.indexable
		);
		content = (
			<PanelButton
				label={ __( 'Re-run', 'ai' ) }
				variant="secondary"
				buttonRef={ primaryActionRef }
				isBusy={ isActing }
				onClick={ () => runBackfillFromButton( 'POST' ) }
			/>
		);
	} else {
		message = sprintf(
			// translators: 1: Number of terms embedded. 2: Total number of terms.
			_n(
				'%1$d of %2$d term embedded.',
				'%1$d of %2$d terms embedded.',
				status.totals.indexable,
				'ai'
			),
			status.totals.indexed,
			status.totals.indexable
		);
		content = (
			<>
				<p className="ai-embedding-sync-panel__help">
					{ __(
						'This runs in the background and sends each category and tag to your embedding provider, which may cost money. On sites with many terms it can take a while.',
						'ai'
					) }
				</p>
				<PanelButton
					label={ __( 'Generate embeddings', 'ai' ) }
					variant="primary"
					buttonRef={ primaryActionRef }
					isBusy={ isActing }
					onClick={ () => setIsConfirmOpen( true ) }
				/>
			</>
		);
	}

	const isProviderError = !! status?.provider_error && isSavedReady;

	// Move focus to the new primary action and announce the new state after a
	// user-initiated change, or when a provider error first appears.
	useEffect( () => {
		const providerError = status?.provider_error ?? null;
		const errorAppeared =
			providerError !== null && previousProviderErrorRef.current === null;
		previousProviderErrorRef.current = providerError;

		// Only act on flags whose action response has been applied.
		const isActionResolved = pendingActionIdRef.current === ACTION_RESOLVED;
		const shouldFocus = isActionResolved && pendingFocusRef.current;
		const shouldAnnounce = isActionResolved && pendingAnnounceRef.current;

		if ( isActionResolved ) {
			pendingActionIdRef.current = null;
			pendingFocusRef.current = false;
			pendingAnnounceRef.current = false;
		}

		if ( shouldFocus ) {
			( primaryActionRef.current ?? bodyRef.current )?.focus();
		}

		if ( ( shouldAnnounce || errorAppeared ) && message ) {
			speak( message );
		}
	}, [ status, fetchError, message ] );

	return (
		<Stack className="ai-embedding-sync-panel" direction="column" gap="sm">
			<span className="ai-embedding-field__label">{ field.label }</span>
			<div
				ref={ bodyRef }
				tabIndex={ -1 }
				className="ai-embedding-sync-panel__body"
			>
				<Stack direction="column" gap="sm">
					{ message && isProviderError && (
						<Notice.Root intent="error">
							<Notice.Description>{ message }</Notice.Description>
						</Notice.Root>
					) }
					{ message && ! isProviderError && (
						<p className="ai-embedding-sync-panel__message">
							{ message }
						</p>
					) }
					{ fetchError && isSavedReady && ! isDirty && (
						<Notice.Root intent="error">
							<Notice.Description>
								{ fetchError }
							</Notice.Description>
							<Notice.Actions>
								<Notice.ActionButton
									onClick={ () => void fetchStatus() }
								>
									{ __( 'Try again', 'ai' ) }
								</Notice.ActionButton>
							</Notice.Actions>
						</Notice.Root>
					) }
					{ content }
				</Stack>
			</div>
			<AlertDialog.Root
				open={ isConfirmOpen }
				onOpenChange={ setIsConfirmOpen }
				onConfirm={ () =>
					// Focus is restored by `finalFocus` once the dialog closes.
					runBackfill( 'POST', { focus: false } ).then( ( error ) =>
						error ? { error } : undefined
					)
				}
			>
				<AlertDialog.Popup
					title={ __( 'Generate embeddings', 'ai' ) }
					description={ sprintf(
						// translators: 1: Number of terms to embed. 2: Embedding provider name.
						_n(
							'Generate embeddings for %1$d term? This sends requests to %2$s in the background and may incur costs.',
							'Generate embeddings for %1$d terms? This sends requests to %2$s in the background and may incur costs.',
							remaining,
							'ai'
						),
						remaining,
						providerName
					) }
					confirmButtonText={ __( 'Generate embeddings', 'ai' ) }
					// The Generate button is replaced once the backfill starts,
					// so return focus to whichever primary action is now shown.
					finalFocus={ () =>
						primaryActionRef.current ?? bodyRef.current
					}
				/>
			</AlertDialog.Root>
		</Stack>
	);
}

/**
 * Renders one of the panel's action buttons.
 *
 * @param {Object}                            props           The component props.
 * @param {string}                            props.label     The button text.
 * @param {'primary'|'secondary'}             props.variant   The button variant.
 * @param {boolean}                           props.isBusy    Whether a request is in flight.
 * @param {RefObject<HTMLButtonElement|null>} props.buttonRef Ref for the button, used to restore focus.
 * @param {() => void}                        props.onClick   Click handler.
 * @return {React.JSX.Element} The button.
 */
function PanelButton( {
	label,
	variant = 'primary',
	isBusy = false,
	buttonRef,
	onClick,
}: {
	label: string;
	variant?: 'primary' | 'secondary';
	isBusy?: boolean;
	buttonRef?: RefObject< HTMLButtonElement | null >;
	onClick?: () => void;
} ): React.JSX.Element {
	return (
		<Stack direction="row">
			<Button
				ref={ buttonRef }
				variant={ variant }
				onClick={ onClick }
				disabled={ ! onClick || isBusy }
				isBusy={ isBusy }
				accessibleWhenDisabled
				__next40pxDefaultSize
			>
				{ label }
			</Button>
		</Stack>
	);
}
