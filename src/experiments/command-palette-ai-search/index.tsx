/**
 * Command palette AI search, part of the AI Workspace experiment.
 *
 * Adds a command loader to the command palette that asks the
 * `ai/command-palette-ai-search` ability which registered commands answer a search
 * the palette found nothing for, and shows them as normal palette results.
 * Users who can open the workspace also get an "Ask AI" row that carries the
 * search into it.
 */

/**
 * WordPress dependencies
 */
import {
	store as commandsStore,
	type WPCommand,
	type WPCommandLoader,
} from '@wordpress/commands';
import { dispatch, select } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import AIIcon from '../../../routes/ai-home/ai-icon';
import { runAbility } from '../../utils/run-ability';
import './index.scss';

type AbilityResponse = {
	commands?: string[];
};

/**
 * Where the Ask AI row leads. The server sends it only to users who can open
 * the workspace.
 */
type AskAISettings = {
	workspaceUrl: string;
	promptArg: string;
	promptMaxLength: number;
};

declare global {
	interface Window {
		aiCommandPaletteAISearch?: {
			askAi?: AskAISettings | null;
		};
	}
}

const LOADER_NAME = 'ai/command-palette-ai-search';
const ITEM_PREFIX = 'ai-command-palette-ai-search-';
const THINKING_CLASS = 'ai-command-palette-ai-search-thinking';
const DEBOUNCE_MS = 450;
const RECHECK_MS = 150;
const SETTLE_TIMEOUT_MS = 2000;
const MIN_LENGTH = 3;

/*
 * The ability's input limits, as its input schema sets them. A request past
 * any of them is refused outright, so the payload is cut to fit first.
 */
const MAX_QUERY_LENGTH = 200;
const MAX_COMMANDS = 500;
const MAX_NAME_LENGTH = 200;
const MAX_LABEL_LENGTH = 300;
const MAX_KEYWORDS = 30;
const MAX_KEYWORD_LENGTH = 100;

const answers = new Map< string, WPCommand[] >();

const icon = <AIIcon className="ai-command-palette-ai-search-icon" />;

const askAI = window.aiCommandPaletteAISearch?.askAi ?? null;

/**
 * Builds the row that opens the AI Workspace with the search in its composer.
 *
 * @param query  The trimmed search.
 * @param key    The search the row belongs to, lowercased.
 * @param search The text typed into the command palette.
 * @return The row, or null for a user who cannot open the workspace.
 */
function getAskAICommand(
	query: string,
	key: string,
	search: string
): WPCommand | null {
	if ( ! askAI ) {
		return null;
	}

	return {
		name: `${ LOADER_NAME }/ask-ai`,
		label: sprintf(
			/* translators: %s: What the user typed into the command palette. */
			__( 'Ask AI: %s', 'ai' ),
			query
		),
		searchLabel: `${ ITEM_PREFIX }ask-ai ${ key }`,
		keywords: [ search ],
		icon,
		callback: ( { close } ) => {
			close();
			window.location.assign(
				addQueryArgs( askAI.workspaceUrl, {
					[ askAI.promptArg ]: query.slice(
						0,
						askAI.promptMaxLength
					),
				} )
			);
		},
	};
}

/**
 * Checks whether the palette shows any results other than this loader's.
 *
 * cmdk unmounts the items that don't match the search, so only matches are in the DOM.
 *
 * @return Whether the palette found something without AI.
 */
function hasOtherResults(): boolean {
	return !! document.querySelector(
		`.commands-command-menu [cmdk-item]:not([data-value^="${ ITEM_PREFIX }"])`
	);
}

/**
 * Checks whether the palette has finished loading and found nothing.
 *
 * The palette shows its empty state only once every command loader has
 * finished and nothing matched the search.
 *
 * @return Whether the palette settled with no results.
 */
function isPaletteEmpty(): boolean {
	return !! document.querySelector( '.commands-command-menu [cmdk-empty]' );
}

/**
 * Counts the characters in a string the way the ability's schema does.
 *
 * @param text The text.
 * @return The number of characters.
 */
function getLength( text: string ): number {
	return Array.from( text ).length;
}

/**
 * Cuts a string to a number of characters.
 *
 * @param text The text.
 * @param max  The most characters to keep.
 * @return The text, cut to fit.
 */
function truncate( text: string, max: number ): string {
	return getLength( text ) > max
		? Array.from( text ).slice( 0, max ).join( '' )
		: text;
}

/**
 * Returns the commands currently registered in the palette.
 *
 * @return The registered commands that have a plain text label.
 */
function getRegisteredCommands(): WPCommand[] {
	const { getCommands } = select( commandsStore );

	return [ ...getCommands( false ), ...getCommands( true ) ].filter(
		( command ) => 'string' === typeof command.label
	);
}

/**
 * Command loader hook that returns the commands the model picked for a search.
 *
 * @param props        Loader props.
 * @param props.search The text typed into the command palette.
 * @return The commands to show and whether they are still loading.
 */
function useCommandPaletteAISearchLoader( {
	search,
}: Parameters< WPCommandLoader[ 'hook' ] >[ 0 ] ): ReturnType<
	WPCommandLoader[ 'hook' ]
> {
	const query = search.trim();
	const key = query.toLowerCase();
	const eligible = query.length >= MIN_LENGTH;
	// Results of null mean the palette found something itself, so this loader shows nothing.
	const [ answer, setAnswer ] = useState< {
		key: string;
		results: WPCommand[] | null;
	} >( { key: '', results: null } );
	const [ pending, setPending ] = useState( '' );

	const settled = eligible && answer.key === key;
	const current = settled ? answer.results : null;
	const loading = eligible && pending === key && ! settled;
	const askAICommand =
		loading || current ? getAskAICommand( query, key, search ) : null;
	const hasRows = !! current && ( !! current.length || !! askAICommand );

	useEffect( () => {
		document.body.classList.toggle( THINKING_CLASS, loading );
	}, [ loading ] );

	useEffect(
		() => () => document.body.classList.remove( THINKING_CLASS ),
		[]
	);

	// When the loading row is replaced, cmdk selects nothing, so Enter would do nothing.
	useEffect( () => {
		if ( ! hasRows ) {
			return;
		}

		const frame = window.requestAnimationFrame( () => {
			if (
				! document.querySelector( '[cmdk-item][aria-selected="true"]' )
			) {
				document.querySelector( '[cmdk-input]' )?.dispatchEvent(
					new window.KeyboardEvent( 'keydown', {
						key: 'Home',
						bubbles: true,
					} )
				);
			}
		} );

		return () => window.cancelAnimationFrame( frame );
	}, [ hasRows, key ] );

	useEffect( () => {
		if ( ! eligible ) {
			return;
		}

		const controller = new window.AbortController();
		const startedAt = Date.now();
		let timer: ReturnType< typeof setTimeout >;

		const finish = ( results: WPCommand[] | null ): void => {
			setPending( ( value ) => ( value === key ? '' : value ) );
			setAnswer( { key, results } );
		};

		const ask = (): void => {
			const commands = getRegisteredCommands();
			// A cut name would not match its command, so those commands are left out.
			const sent = commands
				.filter(
					( command ) => getLength( command.name ) <= MAX_NAME_LENGTH
				)
				.slice( 0, MAX_COMMANDS );

			if ( getLength( query ) > MAX_QUERY_LENGTH || ! sent.length ) {
				finish( [] );
				return;
			}

			setPending( key );

			runAbility< AbilityResponse >(
				LOADER_NAME,
				{
					query,
					commands: sent.map( ( command ) => ( {
						name: command.name,
						label: truncate(
							command.label as string,
							MAX_LABEL_LENGTH
						),
						keywords: ( command.keywords ?? [] )
							.filter(
								( keyword ): keyword is string =>
									'string' === typeof keyword
							)
							.slice( 0, MAX_KEYWORDS )
							.map( ( keyword ) =>
								truncate( keyword, MAX_KEYWORD_LENGTH )
							),
					} ) ),
				},
				{ signal: controller.signal }
			)
				.then( ( response ) => {
					if ( controller.signal.aborted ) {
						return;
					}

					// Only commands from the list that was sent can be shown.
					const results = ( response?.commands ?? [] )
						.map( ( name ) =>
							sent.find( ( command ) => command.name === name )
						)
						.filter(
							( command ): command is WPCommand => !! command
						);

					answers.set( key, results );
					finish( hasOtherResults() ? null : results );
				} )
				.catch( () => {
					if ( ! controller.signal.aborted ) {
						finish( hasOtherResults() ? null : [] );
					}
				} );
		};

		/*
		 * Only ask the model once the palette has finished and found nothing.
		 * Other loaders, like the post search, can still be fetching when the
		 * debounce runs out, so wait for them, and give up if they take too long.
		 */
		const check = (): void => {
			if ( hasOtherResults() ) {
				finish( null );
				return;
			}

			if ( ! isPaletteEmpty() ) {
				if ( Date.now() - startedAt < SETTLE_TIMEOUT_MS ) {
					timer = setTimeout( check, RECHECK_MS );
				} else {
					finish( null );
				}
				return;
			}

			const cached = answers.get( key );

			if ( cached ) {
				finish( cached );
				return;
			}

			ask();
		};

		timer = setTimeout( check, DEBOUNCE_MS );

		return () => {
			clearTimeout( timer );
			controller.abort();
			setPending( ( value ) => ( value === key ? '' : value ) );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- `query` only changes with `key`.
	}, [ key, eligible ] );

	if ( ! loading && ! current ) {
		return { commands: [], isLoading: false };
	}

	if ( ! current ) {
		return {
			isLoading: true,
			commands: [
				{
					name: `${ LOADER_NAME }/thinking`,
					label: __( 'Searching with AI…', 'ai' ),
					searchLabel: `${ ITEM_PREFIX }thinking ${ key }`,
					keywords: [ search ],
					icon,
					callback: () => {},
				},
				...( askAICommand ? [ askAICommand ] : [] ),
			],
		};
	}

	// The query goes into each searchLabel because cmdk only re-reads keywords when an item's value changes.
	// Ask AI comes after the suggestions, and stays when the request failed.
	return {
		isLoading: false,
		commands: [
			...current.map( ( command ) => ( {
				name: `${ LOADER_NAME }/${ command.name }`,
				label: command.label,
				searchLabel: `${ ITEM_PREFIX }${ command.name } ${ key }`,
				keywords: [ search ],
				icon,
				category: 'view',
				callback: command.callback,
			} ) ),
			...( askAICommand ? [ askAICommand ] : [] ),
		],
	};
}

dispatch( commandsStore ).registerCommandLoader( {
	name: LOADER_NAME,
	hook: useCommandPaletteAISearchLoader,
} );
