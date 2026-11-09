/**
 * WordPress dependencies
 */
import { select, type SelectFunction } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import {
	flattenBlocks,
	getBlockHTML,
	getPostContentBlockContext,
	getRichTextHTML,
} from '../../utils/blocks';

export interface TableAttributes {
	head?: { cells: { content: unknown }[] }[];
	body?: { cells: { content: unknown }[] }[];
	foot?: { cells: { content: unknown }[] }[];
	caption?: unknown;
}

/**
 * Preserves table sections, row boundaries, and empty cells in the prompt.
 *
 * @param attributes Table block attributes.
 * @return Serialized table cells, without the existing caption.
 */
export function getTableContent( attributes: TableAttributes ): string {
	return JSON.stringify( {
		head: ( attributes.head ?? [] ).map( ( row ) =>
			row.cells.map( ( cell ) => getRichTextHTML( cell.content ) )
		),
		body: ( attributes.body ?? [] ).map( ( row ) =>
			row.cells.map( ( cell ) => getRichTextHTML( cell.content ) )
		),
		foot: ( attributes.foot ?? [] ).map( ( row ) =>
			row.cells.map( ( cell ) => getRichTextHTML( cell.content ) )
		),
	} );
}

/**
 * Reads current post context and confirms the table is editable post content.
 *
 * @param clientId Table client ID.
 * @param selectFn Registry selector used by reactive controls.
 * @return A generation snapshot, or null for unsupported/removed blocks.
 */
export function getSnapshot(
	clientId: string,
	selectFn: SelectFunction = select
) {
	const editor = selectFn( blockEditorStore );
	const block = editor.getBlock( clientId );
	const { getBlockEditingMode } = editor as unknown as {
		getBlockEditingMode: ( id: string ) => string;
	};
	const context = getPostContentBlockContext( selectFn );
	if (
		! block ||
		block.name !== 'core/table' ||
		getBlockEditingMode( clientId ) !== 'default' ||
		context.isMissingPostContent ||
		editor.getBlockParentsByBlockName( clientId, 'core/block' ).length >
			0 ||
		! flattenBlocks( context.allBlocks ).some(
			( item ) => item.clientId === clientId
		)
	) {
		return null;
	}
	const attributes = block.attributes as TableAttributes;
	const siblings = editor.getBlocks(
		editor.getBlockRootClientId( clientId ) ?? undefined
	);
	const index = siblings.findIndex( ( item ) => item.clientId === clientId );
	const nearby = [ siblings[ index - 1 ], siblings[ index + 1 ] ]
		.map( ( sibling ) => {
			const html = sibling ? getBlockHTML( sibling ) : '';
			// Strip markup before decoding so entity-encoded literal text survives.
			return Array.from(
				decodeEntities( html.replace( /<[^>]*>/g, ' ' ) )
			)
				.slice( 0, 900 )
				.join( '' );
		} )
		.filter( Boolean )
		.join( '\n' );
	return {
		postId: selectFn( editorStore ).getCurrentPostId() as number,
		table: getTableContent( attributes ),
		caption: getRichTextHTML( attributes.caption ),
		title: Array.from(
			String(
				selectFn( editorStore ).getEditedPostAttribute( 'title' ) ?? ''
			)
		)
			.slice( 0, 300 )
			.join( '' ),
		context: nearby,
	};
}
