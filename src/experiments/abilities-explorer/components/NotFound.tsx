/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { BackToListLink } from './RouterLink';

interface NotFoundProps {
	/** Why no ability is shown: none was named, or the named one is not registered. */
	reason: 'unspecified' | 'missing';
}

/**
 * The view for a detail or runner link whose ability cannot be shown.
 *
 * @param props        Component props.
 * @param props.reason Why no ability is shown.
 * @return The view.
 */
export default function NotFound( { reason }: NotFoundProps ) {
	return (
		<div className="ai-abilities-explorer__not-found">
			<Notice status="error" isDismissible={ false }>
				{ 'unspecified' === reason
					? __( 'No ability specified.', 'ai' )
					: __( 'Ability not found.', 'ai' ) }
			</Notice>
			<BackToListLink />
		</div>
	);
}
