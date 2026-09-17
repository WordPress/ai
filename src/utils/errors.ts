/**
 * Shared error utilities.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Gets a user-friendly error message from an unknown error value.
 *
 * Handles the shapes thrown across the plugin: plain strings, `Error`
 * instances, and the `{ code, message }` objects returned by REST and
 * ability failures.
 *
 * @param {unknown} error    The error value to extract a message from.
 * @param {string}  fallback Message to use when no usable message is found.
 * @return {string} The error message to display to the user.
 */
export function getErrorMessage( error: unknown, fallback?: string ): string {
	if ( typeof error === 'string' && error.trim().length > 0 ) {
		return error;
	}

	if ( error && typeof error === 'object' && 'message' in error ) {
		const { message } = error as { message: unknown };

		if ( typeof message === 'string' && message.trim().length > 0 ) {
			return message;
		}
	}

	return fallback ?? __( 'Something went wrong. Please try again.', 'ai' );
}
