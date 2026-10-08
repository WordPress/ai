/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import {
	createBlock,
	getBlockType,
	getBlockTypes,
	getPossibleBlockTransformations,
	switchToBlockType,
} from '@wordpress/blocks';
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
	parentClientId?: unknown;
	clientId?: unknown;
	name?: unknown;
	search?: unknown;
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
	'core/verse',
	'core/preformatted',
	'core/code',
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
	getCurrentPostAttribute: ( attribute: string ) => unknown;
	isSavingPost: () => boolean;
	didPostSaveRequestFail: () => boolean;
	isEditedPostSaveable: () => boolean;
	isEditedPostDirty: () => boolean;
	isPostLocked: () => boolean;
	isPostSavingLocked: () => boolean;
}
interface EditorActions {
	editPost: ( edits: Record< string, unknown > ) => unknown;
	savePost: () => Promise< unknown >;
	undo: () => unknown;
}
interface BlockEditorSelectors {
	getBlocks: () => EditorBlock[];
	getBlock: ( clientId: string ) => EditorBlock | null;
	getBlockIndex: ( clientId: string ) => number;
	getBlockRootClientId: ( clientId: string ) => string;
	getBlockOrder: ( rootClientId?: string ) => string[];
	canInsertBlockType: ( name: string, rootClientId?: string ) => boolean;
	canEditBlock: ( clientId: string ) => boolean;
	getBlockEditingMode: (
		clientId: string
	) => 'default' | 'contentOnly' | 'disabled';
	canMoveBlock: ( clientId: string ) => boolean;
	canRemoveBlock: ( clientId: string ) => boolean;
	getBlockParents: ( clientId: string ) => string[];
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
	moveBlockToPosition: (
		clientId: string,
		fromRootClientId: string,
		toRootClientId: string,
		index: number
	) => unknown;
	duplicateBlocks: ( clientIds: string[] ) => Promise< string[] | undefined >;
	replaceBlocks: (
		clientIds: string | string[],
		blocks: unknown[]
	) => unknown;
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

/**
 * Throws unless the editor lets the person change these attributes.
 */
const assertCanEdit = ( block: EditorBlock, keys: string[] ) => {
	if ( ! blocksSelect().canEditBlock( block.clientId ) ) {
		throw new Error( 'This block is locked against editing.' );
	}

	const mode = blocksSelect().getBlockEditingMode( block.clientId );
	if ( mode === 'disabled' ) {
		throw new Error( 'This block cannot be edited here.' );
	}

	if ( mode === 'contentOnly' ) {
		const definitions = ( getBlockType( block.name )?.attributes ??
			{} ) as Record<
			string,
			{ role?: unknown; __experimentalRole?: unknown }
		>;
		const blocked = keys.filter( ( key ) => {
			const definition = definitions[ key ];
			return (
				definition?.role !== 'content' &&
				definition?.__experimentalRole !== 'content'
			);
		} );

		if ( blocked.length > 0 ) {
			throw new Error(
				`Only this block's content can be edited here, not: ${ blocked.join(
					', '
				) }.`
			);
		}
	}
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

/** Throws unless the post can be saved right now. */
const assertSaveable = () => {
	if ( editorSelect().isPostLocked() ) {
		throw new Error(
			'Another user is editing this post, so it cannot be saved from here.'
		);
	}
	if ( editorSelect().isPostSavingLocked() ) {
		throw new Error(
			'Saving is locked in the editor right now, usually by a plugin waiting on something. Check the editor before trying again.'
		);
	}
	if ( editorSelect().isSavingPost() ) {
		throw new Error(
			'A post save is already in progress. Try again after it finishes.'
		);
	}
	if ( ! editorSelect().isEditedPostSaveable() ) {
		throw new Error(
			'The post cannot be saved yet. Add a title or content first.'
		);
	}
};

const save = async () => {
	assertSaveable();
	await editorDispatch().savePost();
	const editor = editorSelect();
	if ( editor.isSavingPost() ) {
		throw new Error(
			'The post save has not finished. Check the editor before trying again.'
		);
	}
	if ( editor.didPostSaveRequestFail() ) {
		throw new Error(
			'The post could not be saved. Check the error in the editor and try again.'
		);
	}
	// A save that reports no failure but leaves changes behind did not save
	// everything; say so rather than report success.
	if ( editor.isEditedPostDirty() ) {
		throw new Error(
			'The post still has unsaved changes after the save. Check the editor before trying again.'
		);
	}
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

		let index: number | undefined;
		let rootClientId: string | undefined;
		if ( typeof input.afterClientId === 'string' && input.afterClientId ) {
			const after = requireBlock( input.afterClientId );
			index = blocksSelect().getBlockIndex( after.clientId ) + 1;
			rootClientId =
				blocksSelect().getBlockRootClientId( after.clientId ) ||
				undefined;
		} else if (
			typeof input.parentClientId === 'string' &&
			input.parentClientId
		) {
			rootClientId = requireBlock( input.parentClientId ).clientId;
		}

		// The editor's own rules decide: locked templates, allowed block
		// lists and parent restrictions all answer through this selector.
		if ( ! blocksSelect().canInsertBlockType( name, rootClientId ) ) {
			throw new Error(
				`${ name } cannot be inserted here. Call editor-get-block-types for what this position allows.`
			);
		}

		const block = createBlock( name, attributes ) as unknown as EditorBlock;
		blocksDispatch().insertBlock( block, index, rootClientId );
		blocksDispatch().selectBlock( block.clientId );
		return { clientId: block.clientId, name };
	},

	'editor-update-block-text': ( input ) => {
		const block = requireBlock( input.clientId );
		assertCanEdit( block, [ 'content' ] );
		if ( ! TEXT_BLOCKS.has( block.name ) ) {
			throw new Error(
				`${ block.name } does not use a content attribute. Edit its inner text blocks or use editor-update-block-attributes with its attribute schema.`
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
		assertCanEdit( block, Object.keys( attributes ) );
		// Changing the lock here would let a second call remove or move a
		// block the editor has locked, so locks stay with the person. The same
		// goes for templateLock, which locks a container's inner blocks.
		for ( const key of [ 'lock', 'templateLock' ] ) {
			if ( Object.prototype.hasOwnProperty.call( attributes, key ) ) {
				throw new Error(
					`The ${ key } attribute cannot be changed by an agent. Ask the person to change it in the editor.`
				);
			}
		}
		blocksDispatch().updateBlockAttributes( block.clientId, attributes );
		blocksDispatch().selectBlock( block.clientId );
		return { clientId: block.clientId, attributes };
	},

	'editor-remove-block': ( input ) => {
		const block = requireBlock( input.clientId );
		if ( ! blocksSelect().canRemoveBlock( block.clientId ) ) {
			throw new Error( 'This block is locked against removal.' );
		}
		blocksDispatch().removeBlock( block.clientId );
		return { removed: block.clientId, name: block.name };
	},

	'editor-select-block': ( input ) => {
		const block = requireBlock( input.clientId );
		blocksDispatch().selectBlock( block.clientId );
		return { clientId: block.clientId, name: block.name };
	},

	'editor-move-block': ( input ) => {
		const block = requireBlock( input.clientId );
		const select_ = blocksSelect();
		const fromRoot = select_.getBlockRootClientId( block.clientId ) || '';
		let toRoot = fromRoot;
		let index = 0;

		if ( typeof input.afterClientId === 'string' && input.afterClientId ) {
			const after = requireBlock( input.afterClientId );
			if ( after.clientId === block.clientId ) {
				return {
					clientId: block.clientId,
					moved: false,
					message: 'Nothing to move.',
				};
			}
			toRoot = select_.getBlockRootClientId( after.clientId ) || '';
			const afterIndex = select_.getBlockIndex( after.clientId );
			// Within one parent the block is removed before it is placed, so a
			// move downwards lands on the target's index, not one past it.
			const movingDown =
				toRoot === fromRoot &&
				select_.getBlockIndex( block.clientId ) < afterIndex;
			index = movingDown ? afterIndex : afterIndex + 1;
		} else if (
			typeof input.parentClientId === 'string' &&
			input.parentClientId
		) {
			toRoot = requireBlock( input.parentClientId ).clientId;
			index = select_.getBlockOrder( toRoot ).length;
		}

		if (
			toRoot === block.clientId ||
			( toRoot &&
				select_.getBlockParents( toRoot ).includes( block.clientId ) )
		) {
			throw new Error(
				'A block cannot be moved into itself or one of its descendants.'
			);
		}
		if ( ! select_.canMoveBlock( block.clientId ) ) {
			throw new Error( 'This block is locked against moving.' );
		}
		if (
			toRoot !== fromRoot &&
			! select_.canRemoveBlock( block.clientId )
		) {
			throw new Error(
				'This block is locked against removal from its current parent.'
			);
		}

		if (
			toRoot !== fromRoot &&
			! select_.canInsertBlockType( block.name, toRoot || undefined )
		) {
			throw new Error( `${ block.name } cannot be moved there.` );
		}

		blocksDispatch().moveBlockToPosition(
			block.clientId,
			fromRoot,
			toRoot,
			index
		);
		blocksDispatch().selectBlock( block.clientId );
		return { clientId: block.clientId, index, parentClientId: toRoot };
	},

	'editor-duplicate-block': async ( input ) => {
		const block = requireBlock( input.clientId );
		const returned = await blocksDispatch().duplicateBlocks( [
			block.clientId,
		] );
		const created = ( Array.isArray( returned ) ? returned : [] ).filter(
			( id ) => blocksSelect().getBlock( id )
		);
		if ( created.length === 0 ) {
			throw new Error(
				`${ block.name } was not duplicated. It may be locked, or its parent may not allow another copy.`
			);
		}
		return { duplicated: block.clientId, clientIds: created };
	},

	'editor-transform-block': ( input ) => {
		const block = requireBlock( input.clientId );
		const target = asString( input.blockName, 'blockName' );
		// A transform replaces the block, so it needs the same permission as
		// removing it.
		if ( ! blocksSelect().canRemoveBlock( block.clientId ) ) {
			throw new Error( 'This block is locked against removal.' );
		}
		const possible = getPossibleBlockTransformations( [
			block as unknown as Parameters<
				typeof getPossibleBlockTransformations
			>[ 0 ][ number ],
		] ) as Array< { name: string } >;
		if ( ! possible.some( ( type ) => type.name === target ) ) {
			throw new Error(
				`${
					block.name
				} cannot be transformed into ${ target }. It can become: ${
					possible.map( ( type ) => type.name ).join( ', ' ) ||
					'nothing'
				}.`
			);
		}
		const transformed = switchToBlockType(
			block as unknown as Parameters< typeof switchToBlockType >[ 0 ],
			target
		) as unknown as EditorBlock[] | null;
		if ( ! transformed || transformed.length === 0 ) {
			throw new Error(
				`${ block.name } cannot be transformed into ${ target }.`
			);
		}
		const root = blocksSelect().getBlockRootClientId( block.clientId );
		const refused = transformed.find(
			( item ) =>
				! blocksSelect().canInsertBlockType(
					item.name,
					root || undefined
				)
		);
		if ( refused ) {
			throw new Error(
				`${ refused.name } is not allowed here, so ${ block.name } cannot be transformed into ${ target }.`
			);
		}
		blocksDispatch().replaceBlocks( block.clientId, transformed );

		if (
			blocksSelect().getBlock( block.clientId ) ||
			! transformed.every( ( item ) =>
				blocksSelect().getBlock( item.clientId )
			)
		) {
			throw new Error(
				`${ block.name } was not transformed into ${ target }. The editor refused the change.`
			);
		}
		const first = transformed[ 0 ];
		if ( first ) {
			blocksDispatch().selectBlock( first.clientId );
		}
		return {
			replaced: block.clientId,
			clientIds: transformed.map( ( item ) => item.clientId ),
			name: target,
		};
	},

	'editor-get-block-types': ( input ) => {
		if ( typeof input.name === 'string' && input.name ) {
			const type = getBlockType( input.name );
			if ( ! type ) {
				throw new Error( `No block type named ${ input.name }.` );
			}
			const attributes: Record< string, unknown > = {};
			for ( const [ key, definition ] of Object.entries(
				( type.attributes ?? {} ) as Record<
					string,
					{ type?: unknown; enum?: unknown; default?: unknown }
				>
			) ) {
				attributes[ key ] = {
					type: definition.type,
					...( definition.enum ? { enum: definition.enum } : {} ),
					...( definition.default !== undefined
						? { default: definition.default }
						: {} ),
				};
			}
			return {
				name: type.name,
				title: type.title,
				description: type.description,
				attributes,
			};
		}

		const parent =
			typeof input.parentClientId === 'string' && input.parentClientId
				? requireBlock( input.parentClientId ).clientId
				: undefined;
		const search =
			typeof input.search === 'string' ? input.search.toLowerCase() : '';
		const types = getBlockTypes()
			.filter( ( type ) =>
				blocksSelect().canInsertBlockType( type.name, parent )
			)
			.filter(
				( type ) =>
					! search ||
					type.name.toLowerCase().includes( search ) ||
					String( type.title ).toLowerCase().includes( search )
			)
			.map( ( type ) => ( {
				name: type.name,
				title: type.title,
				category: type.category,
			} ) );
		return { count: types.length, blockTypes: types.slice( 0, 60 ) };
	},

	'editor-undo': () => {
		editorDispatch().undo();
		return document();
	},

	'editor-save': () => save(),

	'editor-publish': async () => {
		// Check before touching the status: if the save then failed, a
		// status left on publish would publish the post at the next draft save.
		assertSaveable();
		const previous = editorSelect().getEditedPostAttribute( 'status' );
		editorDispatch().editPost( { status: 'publish' } );
		return save().catch( ( error: unknown ) => {
			// Check if save worked but still threw an error.
			// If so, we don't want to roll back the status.
			const editor = editorSelect();
			const saved = editor.getCurrentPostAttribute( 'status' );
			if (
				! editor.didPostSaveRequestFail() &&
				( saved === 'publish' || saved === 'future' )
			) {
				return {
					postId: editor.getCurrentPostId(),
					status: saved,
					warning:
						error instanceof Error
							? error.message
							: String( error ),
				};
			}
			editorDispatch().editPost( { status: previous } );
			throw error;
		} );
	},
};

/** Whether this page has an initialized block editor, not just its scripts. */
export const hasEditor = (): boolean => {
	try {
		return (
			window.document.body.classList.contains( 'block-editor-page' ) &&
			Boolean( editorSelect().getCurrentPostId() ) &&
			Array.isArray( blocksSelect().getBlocks() )
		);
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
