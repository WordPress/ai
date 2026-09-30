/**
 * Semantic search command loader for the block editor command palette.
 *
 * Registers a command loader with `core/commands` so that typing in the
 * Cmd/Ctrl+K palette surfaces semantically ranked posts alongside the
 * built-in commands. Results come from GET /ai/v1/semantic-search?q={query}.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';

interface SemanticSearchResult {
	id: number;
	title: string;
	type: string;
	url: string;
	excerpt: string;
	score: number;
}

interface SemanticSearchResponse {
	available: boolean;
	results: SemanticSearchResult[];
}

interface CommandLoaderCommand {
	name: string;
	label: string;
	callback: ( extras?: { close?: () => void } ) => void;
}

declare global {
	interface Window {
		wpaiSemanticSearch?: {
			restUrl: string;
			nonce: string;
		};
	}
}

const config = window.wpaiSemanticSearch;

/**
 * Command palette hook that fetches semantically ranked posts as the user types.
 *
 * @param {Object} props        Hook props supplied by the command palette.
 * @param {string} props.search The current palette search string.
 * @return {{ commands: CommandLoaderCommand[], isLoading: boolean }} Commands and loading state.
 */
function useSemanticSearchLoader( { search }: { search: string } ): {
	commands: CommandLoaderCommand[];
	isLoading: boolean;
} {
	const [ commands, setCommands ] = useState< CommandLoaderCommand[] >( [] );
	const [ isLoading, setIsLoading ] = useState( false );

	useEffect( () => {
		if ( ! search || ! config?.restUrl ) {
			setCommands( [] );
			return;
		}

		const controller = new AbortController();
		setIsLoading( true );

		apiFetch< SemanticSearchResponse >( {
			url: `${ config.restUrl }?q=${ encodeURIComponent( search ) }`,
			signal: controller.signal,
			headers: { 'X-WP-Nonce': config.nonce },
		} )
			.then( ( response ) => {
				if ( ! response?.available ) {
					setCommands( [] );
					return;
				}

				setCommands(
					response.results.map( ( result ) => ( {
						// The search term is embedded in `name` so it is always part of the
						// string the palette filters on. Gutenberg versions differ on whether
						// `keywords` is forwarded, and `name` is never rendered to the user.
						name: `wpai/semantic-search/${ encodeURIComponent(
							search
						) }/${ result.id }`,
						label: `${ result.title } (${ result.type })`,
						callback: ( extras ) => {
							window.location.href = result.url;
							extras?.close?.();
						},
					} ) )
				);
			} )
			.catch( () => {
				// Aborted or failed requests simply yield no semantic results.
				setCommands( [] );
			} )
			.finally( () => {
				setIsLoading( false );
			} );

		return () => {
			controller.abort();
		};
	}, [ search ] );

	return { commands, isLoading };
}

if ( config?.restUrl ) {
	// The store is resolved by name rather than by importing @wordpress/commands,
	// which is not a declared dependency and is absent on older WordPress versions.
	// Looking it up at runtime lets the palette integration degrade quietly instead
	// of breaking the editor bundle.
	// eslint-disable-next-line @wordpress/data-no-store-string-literals
	const commandStore = dispatch( 'core/commands' ) as unknown as
		| {
				registerCommandLoader?: ( loader: {
					name: string;
					hook: typeof useSemanticSearchLoader;
				} ) => void;
		  }
		| undefined;

	commandStore?.registerCommandLoader?.( {
		name: 'wpai/semantic-search',
		hook: useSemanticSearchLoader,
	} );
}
