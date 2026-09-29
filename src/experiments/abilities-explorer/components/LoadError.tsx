/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface LoadErrorProps {
	/** The failure to show. */
	message: string;
	/** Tries the load again. */
	onRetry: () => void;
}

/**
 * An inline load failure with a Retry action.
 *
 * Shown when a first load fails for a reason other than the screen's fatal
 * states, such as a network error or a 5xx. The notice is announced as well
 * as shown.
 *
 * @param props         Component props.
 * @param props.message The failure to show.
 * @param props.onRetry Tries the load again.
 * @return The notice.
 */
export default function LoadError( { message, onRetry }: LoadErrorProps ) {
	return (
		<Notice
			className="ai-abilities-explorer__load-error"
			status="error"
			isDismissible={ false }
			actions={ [
				{
					label: __( 'Retry', 'ai' ),
					onClick: onRetry,
					variant: 'secondary',
				},
			] }
		>
			{ message }
		</Notice>
	);
}
