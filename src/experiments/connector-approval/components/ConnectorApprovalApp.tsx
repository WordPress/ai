/**
 * Top-level component orchestrating the connector approval admin UI.
 */

/**
 * WordPress dependencies
 */
import { Notice, Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useApprovalState } from '../functions/useApprovalState';
import ApprovalMatrixCard from './ApprovalMatrixCard';
import PendingRequestsCard from './PendingRequestsCard';

const ConnectorApprovalApp = (): JSX.Element => {
	const { state, error, isSaving, clearError, setApproval, dismissPending } =
		useApprovalState();

	if ( null === state ) {
		return (
			<div className="ai-connector-approval">
				{ error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : (
					<Spinner />
				) }
			</div>
		);
	}

	return (
		<div className="ai-connector-approval">
			{ error ? (
				<Notice status="error" onDismiss={ clearError }>
					{ error }
				</Notice>
			) : null }

			<PendingRequestsCard
				connectors={ state.connectors }
				pending={ state.pending }
				isSaving={ isSaving }
				onApprove={ ( pluginBasename, connectorId ) =>
					setApproval( pluginBasename, connectorId, true )
				}
				onDismiss={ dismissPending }
			/>

			<ApprovalMatrixCard
				connectors={ state.connectors }
				plugins={ state.plugins }
				themes={ state.themes }
				approvals={ state.approvals }
				isSaving={ isSaving }
				onToggle={ setApproval }
			/>
		</div>
	);
};

export default ConnectorApprovalApp;
