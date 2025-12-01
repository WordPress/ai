/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { MediaUpload } from '@wordpress/media-utils';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { WordPressAttachment } from '../../types';

type MediaButtonProps = {
	open: () => void;
};

/**
 * Renders the default button to open the media library.
 *
 * @since n.e.x.t
 *
 * @param props - The component props.
 * @return The component to be rendered.
 */
function DefaultMediaButton( props: MediaButtonProps ) {
	const { open } = props;

	return (
		<Button variant="secondary" onClick={ open } __next40pxDefaultSize>
			{ __( 'Media Library', 'ai' ) }
		</Button>
	);
}

const DEFAULT_ATTACHMENT_IDS: number[] = [];

type MediaModalProps = {
	attachmentIds: number | number[];
	onSelect:
		| ( ( value: WordPressAttachment ) => void )
		| ( ( value: WordPressAttachment[] ) => void );
	render?: React.ElementType;
	allowedTypes?: string[];
	multiple?: boolean;
};

/**
 * Renders the media modal.
 *
 * @since n.e.x.t
 *
 * @param props - The component props.
 * @return The component to be rendered.
 */
export default function MediaModal( props: MediaModalProps ) {
	const {
		onSelect,
		render,
		allowedTypes,
		multiple = false,
		attachmentIds = DEFAULT_ATTACHMENT_IDS,
	} = props;

	const sanitizedAttachmentIds = useMemo( () => {
		if ( multiple ) {
			if ( Array.isArray( attachmentIds ) ) {
				return attachmentIds;
			}
			if ( typeof attachmentIds === 'number' ) {
				return [ attachmentIds ];
			}
			return [];
		}

		if ( typeof attachmentIds === 'number' ) {
			return attachmentIds;
		}
		if ( Array.isArray( attachmentIds ) ) {
			return attachmentIds[ 0 ];
		}
		return [];
	}, [ multiple, attachmentIds ] );

	return (
		<MediaUpload
			gallery={ false }
			multiple={ multiple }
			onSelect={ onSelect }
			allowedTypes={ allowedTypes }
			mode="browse"
			value={ sanitizedAttachmentIds }
			render={ render || DefaultMediaButton }
		/>
	);
}
