/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/ui';

/**
 * ConnectorApprovalNotice component.
 *
 * Warns that no plugin or theme has been approved to use an AI connector
 * yet, since Connector Approval blocks all AI functionality until at least
 * one caller is approved.
 *
 * @return {React.JSX.Element} The component.
 */
export function ConnectorApprovalNotice(): React.JSX.Element {
	return (
		<Notice.Root intent="warning">
			<Notice.Description>
				{ __(
					'No plugin or theme has been approved to use an AI connector yet. AI functionality will not work until at least one is approved.',
					'ai'
				) }
			</Notice.Description>
		</Notice.Root>
	);
}
