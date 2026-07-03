/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Link, Notice } from '@wordpress/ui';

interface ConnectorApprovalNoticeProps {
	connectorApprovalUrl: string;
	hasApprovedConnector: boolean;
}

/**
 * ConnectorApprovalNotice component.
 *
 * Warns that no plugin or theme has been approved to use an AI connector
 * yet, since Connector Approval blocks all AI functionality until at least
 * one caller is approved.
 *
 * @param {ConnectorApprovalNoticeProps} props                      The component props.
 * @param {string}                       props.connectorApprovalUrl URL to the Connector Approvals admin page.
 * @param {boolean}                      props.hasApprovedConnector Whether the AI plugin has been approved for at least one connector.
 * @return {React.JSX.Element | null} The component, or null when a connector is already approved.
 */
export function ConnectorApprovalNotice( {
	connectorApprovalUrl,
	hasApprovedConnector,
}: ConnectorApprovalNoticeProps ): React.JSX.Element | null {
	if ( hasApprovedConnector ) {
		return null;
	}

	return (
		<Notice.Root intent="warning">
			<Notice.Description>
				{ __(
					'No plugin or theme has been approved to use an AI connector yet. AI functionality will not work until at least one is approved.',
					'ai'
				) }
				{ connectorApprovalUrl && (
					<>
						{ ' ' }
						<Link href={ connectorApprovalUrl }>
							{ __( 'Review connector approvals', 'ai' ) }
						</Link>
					</>
				) }
			</Notice.Description>
		</Notice.Root>
	);
}
