/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Returns the translated label for a log status.
 *
 * @param status Log status.
 * @return Translated label, or the status itself when it is unknown.
 */
export const getStatusLabel = ( status: string ): string => {
	switch ( status ) {
		case 'success':
			return __( 'Success', 'ai' );
		case 'error':
			return __( 'Error', 'ai' );
		case 'timeout':
			return __( 'Timeout', 'ai' );
		default:
			return status;
	}
};
