/**
 * WordPress dependencies
 */
import { Button, Stack } from '@wordpress/ui';
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface ImportConfirmModalProps {
	onConfirm: () => void;
	onCancel: () => void;
	isImporting: boolean;
}

/**
 * ImportConfirmModal component.
 *
 * Displayed to ask the user to confirm before overwriting settings during
 * an AI settings import.
 *
 * @param {ImportConfirmModalProps} props             The component props.
 * @param {Function}                props.onConfirm   Called when the import is confirmed.
 * @param {Function}                props.onCancel    Called when the import is cancelled.
 * @param {boolean}                 props.isImporting Whether the import request is in flight.
 * @return {React.JSX.Element} The component.
 */
export function ImportConfirmModal( {
	onConfirm,
	onCancel,
	isImporting,
}: ImportConfirmModalProps ): React.JSX.Element {
	return (
		<Modal
			title={ __( 'Import AI Settings', 'ai' ) }
			onRequestClose={ onCancel }
			size="medium"
		>
			<p>
				{ __(
					'This will overwrite your existing AI settings. Are you sure?',
					'ai'
				) }
			</p>
			<Stack direction="row" gap="sm" justify="flex-end">
				<Button
					variant="outline"
					onClick={ onCancel }
					disabled={ isImporting }
				>
					{ __( 'Cancel', 'ai' ) }
				</Button>
				<Button
					variant="solid"
					onClick={ onConfirm }
					disabled={ isImporting }
					loading={ isImporting }
					loadingAnnouncement={
						isImporting ? __( 'Importing settings…', 'ai' ) : ''
					}
				>
					{ __( 'Import', 'ai' ) }
				</Button>
			</Stack>
		</Modal>
	);
}
