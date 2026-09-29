/**
 * WordPress dependencies
 */
import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Renders the notices store's snackbars, so a notice created anywhere on the
 * screen is shown as well as announced.
 *
 * @return The snackbar list.
 */
export default function Snackbars() {
	const notices = useSelect(
		( select ) => select( noticesStore ).getNotices(),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	const snackbars = useMemo(
		() =>
			notices
				.filter( ( notice ) => notice.type === 'snackbar' )
				.map( ( notice ) => ( {
					id: notice.id,
					content: notice.content,
					spokenMessage: notice.spokenMessage ?? notice.content,
					explicitDismiss: !! notice.explicitDismiss,
					actions: notice.actions,
				} ) ),
		[ notices ]
	);

	return (
		<SnackbarList
			className="ai-abilities-explorer__snackbars"
			notices={ snackbars }
			onRemove={ ( id ) => removeNotice( id ) }
		/>
	);
}
