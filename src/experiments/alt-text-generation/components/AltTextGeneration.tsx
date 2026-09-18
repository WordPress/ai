/**
 * Alt text generation component for the image block inspector.
 */

/**
 * Internal dependencies
 */
import { useAltTextFocus } from '../hooks/useAltTextFocus';
import type { ImageBlockAttributes } from '../types';
import { AltTextControls } from './AltTextControls';
import { AltTextDisabledNotice } from './AltTextDisabledNotice';

type AltTextGenerationProps = {
	clientId: string;
	attributes: ImageBlockAttributes;
	setAttributes: ( attributes: Partial< ImageBlockAttributes > ) => void;
};

/**
 * Renders alt text generation controls for the selected image block.
 *
 * Shows a notice when the image is marked as decorative and coordinates
 * focus as the available controls change.
 *
 * @param {AltTextGenerationProps} props               The component props.
 * @param {string}                 props.clientId      The client ID of the selected image block.
 * @param {ImageBlockAttributes}   props.attributes    The attributes of the selected image block.
 * @param {Function}               props.setAttributes The function to set the attributes of the selected image block.
 * @return {React.JSX.Element} The rendered component.
 */
export function AltTextGeneration( {
	clientId,
	attributes,
	setAttributes,
}: AltTextGenerationProps ): React.JSX.Element {
	const {
		requestFocus,
		generateButtonRef,
		primaryButtonRef,
		noticeButtonRef,
	} = useAltTextFocus();

	if ( attributes?.isDecorative ) {
		return (
			<AltTextDisabledNotice
				actionButtonRef={ noticeButtonRef }
				onEnable={ () => {
					requestFocus( 'generate' );
					setAttributes( { isDecorative: false } );
				} }
			/>
		);
	}

	return (
		<AltTextControls
			clientId={ clientId }
			attributes={ attributes }
			setAttributes={ setAttributes }
			generateButtonRef={ generateButtonRef }
			primaryButtonRef={ primaryButtonRef }
			requestFocus={ requestFocus }
		/>
	);
}
