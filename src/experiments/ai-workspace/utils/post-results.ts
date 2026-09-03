/**
 * Narrows an untrusted tool result to the post list the transcript can render.
 */

/**
 * Internal dependencies
 */
import type { PostResultRow, PostResultSet } from '../types';

/**
 * Reads a string property from an untrusted record.
 *
 * @param record The record.
 * @param key    The property to read.
 * @return The string value, or an empty string.
 */
function readString( record: Record< string, unknown >, key: string ): string {
	const value = record[ key ];

	return typeof value === 'string' ? value : '';
}

/**
 * Reads an integer property from an untrusted record.
 *
 * @param record The record.
 * @param key    The property to read.
 * @return The number, or zero.
 */
function readNumber( record: Record< string, unknown >, key: string ): number {
	const value = record[ key ];

	return typeof value === 'number' && Number.isFinite( value ) ? value : 0;
}

/**
 * Narrows a tool result to the post list this table can render.
 *
 * Rows are rebuilt from the fields the ability declares and nothing else, so a
 * result carrying extra properties cannot smuggle them into the table, and a
 * result of another shape is simply not rendered. A row without a usable ID is
 * dropped rather than rendered without an identity DataViews can key on.
 *
 * @param value The raw tool result.
 * @return The post list, or null when the result is not one.
 */
export function toPostResultSet( value: unknown ): PostResultSet | null {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return null;
	}

	const payload = value as Record< string, unknown >;
	const { results: rows } = payload;

	if ( ! Array.isArray( rows ) ) {
		return null;
	}

	const results: PostResultRow[] = [];

	rows.forEach( ( row ) => {
		if ( ! row || typeof row !== 'object' || Array.isArray( row ) ) {
			return;
		}

		const source = row as Record< string, unknown >;
		const id = readNumber( source, 'id' );

		if ( 0 === id ) {
			return;
		}

		const result: PostResultRow = {
			id,
			post_type: readString( source, 'post_type' ),
			status: readString( source, 'status' ),
			date: readString( source, 'date' ),
			slug: readString( source, 'slug' ),
			link: readString( source, 'link' ),
			title: readString( source, 'title' ),
			excerpt: readString( source, 'excerpt' ),
		};

		const editLink = readString( source, 'edit_link' );

		if ( '' !== editLink ) {
			result.edit_link = editLink;
		}

		results.push( result );
	} );

	return {
		results,
		total: readNumber( payload, 'total' ),
		total_pages: readNumber( payload, 'total_pages' ),
	};
}
