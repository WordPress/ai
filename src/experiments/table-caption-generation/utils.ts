/**
 * WordPress dependencies
 */
import { select, type SelectFunction } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import {
	getBlockHTML,
	getPostContentBlockContext,
	getRichTextHTML,
} from '../../utils/blocks';
import type { TableAttributes, TableData, TableSnapshot } from './types';

/**
 * Escapes plain text for the caption attribute.
 *
 * @param text Plain text.
 * @return HTML-escaped text.
 */
export function escapeCaption( text: string ): string {
	return text
		.replaceAll( '&', '&amp;' )
		.replaceAll( '<', '&lt;' )
		.replaceAll( '>', '&gt;' );
}

/**
 * Serializes table cells without including the existing caption.
 *
 * @param attributes Table attributes.
 * @return Serialized table data.
 */
export function getTableContent( attributes: TableAttributes ): string {
	const table: TableData = { head: [], body: [], foot: [] };
	for ( const section of [ 'head', 'body', 'foot' ] as const ) {
		table[ section ] = ( attributes[ section ] ?? [] ).map( ( row ) =>
			row.cells.map( ( cell ) => ( {
				content: getRichTextHTML( cell.content ),
				tag: cell.tag ?? 'td',
				...( cell.scope ? { scope: cell.scope } : {} ),
				...( Number( cell.colspan ) > 1
					? { colspan: Number( cell.colspan ) }
					: {} ),
				...( Number( cell.rowspan ) > 1
					? { rowspan: Number( cell.rowspan ) }
					: {} ),
			} ) )
		);
	}
	return JSON.stringify( table );
}

/**
 * Reads the selected table and its nearby post content.
 *
 * @param clientId Table client ID.
 * @param selectFn Registry selector.
 * @return Current source, or null if the table is not editable post content.
 */
export function getSnapshot(
	clientId: string,
	selectFn: SelectFunction = select
): TableSnapshot | null {
	const editor = selectFn( blockEditorStore );
	const block = editor.getBlock( clientId );
	const { getBlockEditingMode } = editor as unknown as {
		getBlockEditingMode: ( id: string ) => string;
	};
	if (
		! block ||
		block.name !== 'core/table' ||
		getBlockEditingMode( clientId ) !== 'default' ||
		editor.getBlockParentsByBlockName( clientId, [
			'core/block',
			'core/template-part',
			'core/query',
		] ).length > 0
	) {
		return null;
	}
	const post = getPostContentBlockContext( selectFn );
	if (
		post.isMissingPostContent ||
		( post.rootClientId &&
			! editor.getBlockParents( clientId ).includes( post.rootClientId ) )
	) {
		return null;
	}
	const attributes = block.attributes as TableAttributes;
	const siblings = editor.getBlocks(
		editor.getBlockRootClientId( clientId ) ?? undefined
	);
	const index = siblings.findIndex(
		( sibling ) => sibling.clientId === clientId
	);
	const context = [ siblings[ index - 1 ], siblings[ index + 1 ] ]
		.map( ( sibling ) => ( sibling ? getBlockHTML( sibling ) : '' ) )
		.filter( Boolean )
		.join( '\n' );
	const postEditor = selectFn( editorStore );
	const title = escapeCaption(
		String( postEditor.getEditedPostAttribute( 'title' ) ?? '' )
	);
	return {
		postId: postEditor.getCurrentPostId() as number,
		table: getTableContent( attributes ),
		caption: getRichTextHTML( attributes.caption ),
		title,
		context,
	};
}

/**
 * Checks whether a suggestion still matches the current source.
 *
 * @param current Current source.
 * @param source  Source used for generation.
 * @return Whether the source is unchanged.
 */
export function isSameSource(
	current: TableSnapshot | null,
	source: TableSnapshot
): boolean {
	return (
		current !== null &&
		current.postId === source.postId &&
		current.table === source.table &&
		current.caption === source.caption &&
		current.title === source.title &&
		current.context === source.context
	);
}
