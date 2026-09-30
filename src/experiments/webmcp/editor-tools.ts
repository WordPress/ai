/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import { EDITOR_TOOL_DEFINITIONS } from './editor-tool-definitions.mjs';
import type { ToolDefinition, ToolResult, WebMCPTool } from './types';

/** What a tool may receive. Every field is checked before use. */
interface ToolInput {
	title?: unknown;
	content?: unknown;
	blockName?: unknown;
	attributes?: unknown;
	afterClientId?: unknown;
	clientId?: unknown;
}

interface EditorBlock {
	clientId: string;
	name: string;
	attributes: Record< string, unknown >;
	innerBlocks: EditorBlock[];
}

/** Blocks whose visible text lives in `attributes[ 'content' ]`. */
const TEXT_BLOCKS = new Set( [
	'core/paragraph',
	'core/heading',
	'core/list-item',
	'core/quote',
	'core/verse',
	'core/preformatted',
	'core/code',
	'core/pullquote',
] );

const text = ( value: unknown ): ToolResult => ( {
	content: [
		{
			type: 'text',
			text: typeof value === 'string' ? value : JSON.stringify( value ),
		},
	],
} );

const asInput = ( value: unknown ): ToolInput =>
	value && typeof value === 'object' && ! Array.isArray( value )
		? ( value as ToolInput )
		: {};

const asObject = ( value: unknown ): Record< string, unknown > =>
	value && typeof value === 'object' && ! Array.isArray( value )
		? ( value as Record< string, unknown > )
		: {};

const asString = ( value: unknown, name: string ): string => {
	if ( typeof value !== 'string' || value === '' ) {
		throw new Error( `${ name } is required.` );
	}
	return value;
};

/** Plain-text preview of a block's content attribute, HTML stripped. */
const preview = ( attributes: { content?: unknown } ): string => {
	const content = attributes.content;
	let raw = '';
	if ( typeof content === 'string' ) {
		raw = content;
	} else if ( content && typeof content === 'object' ) {
		raw = String( content );
	}
	return raw
		.replace( /<[^>]+>/g, '' )
		.replace( /\s+/g, ' ' )
		.trim()
		.slice( 0, 120 );
};

const outline = (
	blocks: EditorBlock[],
	depth = 0
): Array< {
	clientId: string;
	name: string;
	depth: number;
	text: string;
} > =>
	blocks.flatMap( ( block ) => [
		{
			clientId: block.clientId,
			name: block.name,
			depth,
			text: preview( block.attributes ),
		},
		...outline( block.innerBlocks || [], depth + 1 ),
	] );

// The editor stores are typed for React hooks in this repository; the bridge
// calls them imperatively, so the few selectors and actions it needs are
// declared here rather than cast at every call site.
interface EditorSelectors {
	getCurrentPostId: () => number | null;
	getCurrentPostType: () => string;
	getEditedPostAttribute: ( attribute: string ) => unknown;
	isSavingPost: () => boolean;
}
interface EditorActions {
	editPost: ( edits: Record< string, unknown > ) => unknown;
	savePost: () => Promise< unknown >;
}
interface BlockEditorSelectors {
	getBlocks: () => EditorBlock[];
	getBlock: ( clientId: string ) => EditorBlock | null;
	getBlockIndex: ( clientId: string ) => number;
	getBlockRootClientId: ( clientId: string ) => string;
}
interface BlockEditorActions {
	insertBlock: (
		block: unknown,
		index?: number,
		rootClientId?: string
	) => unknown;
	updateBlockAttributes: (
		clientId: string,
		attributes: Record< string, unknown >
	) => unknown;
	removeBlock: ( clientId: string ) => unknown;
	selectBlock: ( clientId: string ) => unknown;
}

const editorSelect = () => select( editorStore ) as unknown as EditorSelectors;
const editorDispatch = () =>
	dispatch( editorStore ) as unknown as EditorActions;
const blocksSelect = () =>
	select( blockEditorStore ) as unknown as BlockEditorSelectors;
const blocksDispatch = () =>
	dispatch( blockEditorStore ) as unknown as BlockEditorActions;

const requireBlock = ( clientId: unknown ): EditorBlock => {
	const id = asString( clientId, 'clientId' );
	const block = blocksSelect().getBlock( id );
	if ( ! block ) {
		throw new Error(
			`No block with clientId ${ id }. Call editor-get-document for the current outline.`
		);
	}
	return block;
};

const document = () => {
	const editor = editorSelect();
	return {
		postId: editor.getCurrentPostId(),
		postType: editor.getCurrentPostType(),
		status: editor.getEditedPostAttribute( 'status' ),
		title: editor.getEditedPostAttribute( 'title' ),
		blocks: outline( blocksSelect().getBlocks() ),
	};
};

const save = async () => {
	await editorDispatch().savePost();
	const editor = editorSelect();
	return {
		postId: editor.getCurrentPostId(),
		status: editor.getEditedPostAttribute( 'status' ),
		saving: editor.isSavingPost(),
	};
};

const implementations: Record<
	string,
	( input: ToolInput ) => Promise< unknown > | unknown
> = {
	'editor-get-document': () => document(),

	'editor-set-title': ( input ) => {
		const title = asString( input.title, 'title' );
		editorDispatch().editPost( { title } );
		return { title };
	},

	'editor-insert-block': ( input ) => {
		const name =
			typeof input.blockName === 'string' && input.blockName
				? input.blockName
				: 'core/paragraph';
		const attributes = asObject( input.attributes );
		const block = createBlock( name, attributes ) as unknown as EditorBlock;

		let index: number | undefined;
		let rootClientId: string | undefined;
		if ( typeof input.afterClientId === 'string' && input.afterClientId ) {
			const after = requireBlock( input.afterClientId );
			index = blocksSelect().getBlockIndex( after.clientId ) + 1;
			rootClientId =
				blocksSelect().getBlockRootClientId( after.clientId ) ||
				undefined;
		}

		blocksDispatch().insertBlock( block, index, rootClientId );
		blocksDispatch().selectBlock( block.clientId );
		return { clientId: block.clientId, name };
	},

	'editor-update-block-text': ( input ) => {
		const block = requireBlock( input.clientId );
		if ( ! TEXT_BLOCKS.has( block.name ) ) {
			throw new Error(
				`${ block.name } has no text content; use editor-update-block-attributes.`
			);
		}
		const content = asString( input.content, 'content' );
		blocksDispatch().updateBlockAttributes( block.clientId, { content } );
		blocksDispatch().selectBlock( block.clientId );
		return { clientId: block.clientId, content };
	},

	'editor-update-block-attributes': ( input ) => {
		const block = requireBlock( input.clientId );
		const attributes = asObject( input.attributes );
		if ( Object.keys( attributes ).length === 0 ) {
			throw new Error( 'attributes must have at least one key.' );
		}
		blocksDispatch().updateBlockAttributes( block.clientId, attributes );
		blocksDispatch().selectBlock( block.clientId );
		return { clientId: block.clientId, attributes };
	},

	'editor-remove-block': ( input ) => {
		const block = requireBlock( input.clientId );
		blocksDispatch().removeBlock( block.clientId );
		return { removed: block.clientId, name: block.name };
	},

	'editor-select-block': ( input ) => {
		const block = requireBlock( input.clientId );
		blocksDispatch().selectBlock( block.clientId );
		return { clientId: block.clientId, name: block.name };
	},

	'editor-save': () => save(),

	'editor-publish': async () => {
		editorDispatch().editPost( { status: 'publish' } );
		return save();
	},
};

/** Whether the editor stores exist on this page. */
export const hasEditor = (): boolean => {
	try {
		return typeof editorSelect().getCurrentPostId === 'function';
	} catch {
		return false;
	}
};

/** The editor tools, ready to register. */
export const getEditorTools = (): WebMCPTool[] =>
	( EDITOR_TOOL_DEFINITIONS as ToolDefinition[] ).map( ( definition ) => ( {
		...definition,
		execute: async ( input: unknown ) => {
			const run = implementations[ definition.name ];
			if ( ! run ) {
				throw new Error(
					`${ definition.name } has no implementation.`
				);
			}
			const result = await run( asInput( input ) );
			return text( result );
		},
	} ) );
