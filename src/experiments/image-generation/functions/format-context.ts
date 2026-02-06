/**
 * Internal dependencies
 */
import type { ContextRecord } from '../types';

/**
 * Formats an object as a string with "Key: Value" pairs on separate lines.
 *
 * @param {ContextRecord} obj The object to format.
 * @return {string} The formatted string.
 */
export function formatContext( obj: ContextRecord ): string {
	return Object.entries( obj )
		.filter(
			( [ , value ] ) =>
				value !== undefined && value !== null && value !== ''
		)
		.map( ( [ key, value ] ) => {
			// Capitalize first letter of key and replace underscores with spaces
			const formattedKey = key
				.replace( /_/g, ' ' )
				.replace( /(?:^|\s)\S/g, ( char ) => char.toUpperCase() );
			return `${ formattedKey }: ${ value }`;
		} )
		.join( '\n' );
}
