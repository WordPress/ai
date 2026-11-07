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
const MIN_LENGTH = 3;

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
	const [ answer, setAnswer ] = useState< {
		key: string;
		results: WPCommand[];
	} >( { key: '', results: [] } );
	const [ pending, setPending ] = useState( '' );

	let current: WPCommand[] | undefined;
	if ( answer.key === key ) {
		current = answer.results;
	} else {
		current = answers.get( key );
	}

	const loading = eligible && pending === key && ! current;
	const askAICommand =
		eligible && ( loading || current )
			? getAskAICommand( query, key, search )
			: null;
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
		if ( ! eligible || answers.has( key ) ) {
			return;
		}

		const controller = new window.AbortController();
		const timer = setTimeout( () => {
			// Only ask the model when the palette found nothing by itself.
			if ( hasOtherResults() ) {
				return;
			}

			setPending( key );
			const commands = getRegisteredCommands();

			runAbility< AbilityResponse >(
				LOADER_NAME,
				{
					query,
					commands: commands.map( ( command ) => ( {
						name: command.name,
						label: command.label,
						keywords: ( command.keywords ?? [] ).filter(
							( keyword ): keyword is string =>
								'string' === typeof keyword
						),
					} ) ),
				},
				{ signal: controller.signal }
			)
				.then( ( response ) => {
					if ( controller.signal.aborted ) {
						return;
					}

					const results = ( response?.commands ?? [] )
						.map( ( name ) =>
							commands.find(
								( command ) => command.name === name
							)
						)
						.filter(
							( command ): command is WPCommand => !! command
						);

					answers.set( key, results );
					setAnswer( { key, results } );
				} )
				.catch( () => {
					if ( ! controller.signal.aborted ) {
						setAnswer( { key, results: [] } );
					}
				} );
		}, DEBOUNCE_MS );

		return () => {
			clearTimeout( timer );
			controller.abort();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- `query` only changes with `key`.
	}, [ key, eligible ] );

	if ( ! eligible || ( ! loading && ! current ) ) {
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
