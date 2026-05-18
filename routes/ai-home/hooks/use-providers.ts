/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

interface ModelData {
	id: string;
	name: string;
}

interface ProviderData {
	id: string;
	name: string;
	models: ModelData[];
}

interface UseProvidersReturn {
	providers: ProviderData[];
	isLoading: boolean;
	fetchError: string | null;
}

const providersCache = new Map< string, Promise< ProviderData[] > >();

/**
 * Fetches providers for a given capability, deduplicating concurrent requests.
 *
 * Uses a module-level cache so that multiple callers with the same capability
 * share a single in-flight request. Failed requests are evicted from the cache
 * to allow retries.
 *
 * @param {string} capability The AI capability to filter providers by.
 * @return {Promise<ProviderData[]>} The providers matching the capability.
 */
function fetchProviders( capability: string ): Promise< ProviderData[] > {
	const existing = providersCache.get( capability );
	if ( existing ) {
		return existing;
	}

	const promise = apiFetch< ProviderData[] >( {
		path: `/ai/v1/providers?capability=${ encodeURIComponent(
			capability
		) }`,
	} ).catch( ( error ) => {
		// Remove failed entries so a retry is possible on next mount.
		providersCache.delete( capability );
		throw error;
	} );

	providersCache.set( capability, promise );
	return promise;
}

/**
 * Hook that fetches and caches AI providers by capability.
 *
 * Multiple components requesting the same capability will share a single network
 * request. Results are cached for the lifetime of the page.
 *
 * @param {string} capability The AI capability type (e.g. 'text_generation', 'vision').
 * @return {UseProvidersReturn} The providers list, loading state, and any error.
 */
export function useProviders( capability: string ): UseProvidersReturn {
	const [ providers, setProviders ] = useState< ProviderData[] >( [] );
	const [ isLoading, setIsLoading ] = useState( capability !== 'none' );
	const [ fetchError, setFetchError ] = useState< string | null >( null );

	useEffect( () => {
		if ( capability === 'none' ) {
			setProviders( [] );
			setFetchError( null );
			setIsLoading( false );
			return;
		}

		setIsLoading( true );
		setFetchError( null );

		fetchProviders( capability )
			.then( ( data ) => {
				setProviders( data );
				setFetchError( null );
			} )
			.catch( () => {
				setProviders( [] );
				setFetchError( __( 'Failed to load providers.', 'ai' ) );
			} )
			.finally( () => {
				setIsLoading( false );
			} );
	}, [ capability ] );

	return { providers, isLoading, fetchError };
}
