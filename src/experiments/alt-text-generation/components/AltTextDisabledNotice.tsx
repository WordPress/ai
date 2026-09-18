/**
 * Alt text disabled notice component for the image block inspector.
 */

/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { Stack } from '@wordpress/ui';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { RefCallback } from '@wordpress/element';

type AltTextDisabledNoticeProps = {
	actionButtonRef: RefCallback< HTMLButtonElement | null >;
	onEnable: () => void;
};

/**
 * Renders a notice when the image is marked as decorative and alt text generation is unavailable.
 *
 * @param {AltTextDisabledNoticeProps}              props                 The component props.
 * @param {RefCallback< HTMLButtonElement | null >} props.actionButtonRef The ref to the action button.
 * @param {Function}                                props.onEnable        The function to enable alt text generation.
 * @return {React.JSX.Element} The rendered component.
 */
export function AltTextDisabledNotice( {
	actionButtonRef,
	onEnable,
}: AltTextDisabledNoticeProps ): React.JSX.Element {
	return (
		<InspectorControls group="content">
			<Stack direction="column" style={ { padding: '0 16px' } }>
				<Notice status="info" isDismissible={ false }>
					<p>
						{ __(
							'This image is marked as decorative. Alt text generation is unavailable while this setting is enabled.',
							'ai'
						) }
					</p>

					<Button
						ref={ actionButtonRef }
						onClick={ onEnable }
						variant="secondary"
						style={ {
							width: '100%',
							justifyContent: 'center',
						} }
						__next40pxDefaultSize
					>
						{ __( 'Enable alt text generation', 'ai' ) }
					</Button>
				</Notice>
			</Stack>
		</InspectorControls>
	);
}
