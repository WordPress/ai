/**
 * Card listing pending connector approval requests with Approve/Dismiss actions.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { connectorLabel, formatTimestamp } from '../functions/helpers';
import type { Connector, PendingEntry } from '../types';

interface PendingRequestsCardProps {
	connectors: Connector[];
	pending: PendingEntry[];
	isSaving: boolean;
	onApprove: ( pluginBasename: string, connectorId: string ) => void;
	onDismiss: ( key: string ) => void;
}

const PendingRequestsCard = ( {
	connectors,
	pending,
	isSaving,
	onApprove,
	onDismiss,
}: PendingRequestsCardProps ): JSX.Element => {
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Pending requests', 'ai' ) }</h2>
			</CardHeader>
			<CardBody>
				{ 0 === pending.length ? (
					<p>
						{ __(
							'No plugins or themes are currently waiting for AI access.',
							'ai'
						) }
					</p>
				) : (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Caller', 'ai' ) }</th>
								<th>{ __( 'Connector', 'ai' ) }</th>
								<th>{ __( 'Attempts', 'ai' ) }</th>
								<th>{ __( 'Last seen', 'ai' ) }</th>
								<th>{ __( 'Actions', 'ai' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ pending.map( ( entry ) => (
								<tr key={ entry.key }>
									<td>
										<strong>{ entry.caller_name }</strong>
										<br />
										<code>{ entry.caller_basename }</code>
										<br />
										<em>{ entry.caller_type }</em>
									</td>
									<td>
										{ connectorLabel(
											connectors,
											entry.connector_id
										) }
									</td>
									<td>{ entry.attempts }</td>
									<td>
										{ formatTimestamp( entry.last_seen ) }
									</td>
									<td>
										<Flex justify="flex-start" gap={ 2 }>
											<FlexItem>
												<Button
													variant="primary"
													isBusy={ isSaving }
													disabled={ isSaving }
													onClick={ () =>
														onApprove(
															entry.caller_basename,
															entry.connector_id
														)
													}
												>
													{ __( 'Approve', 'ai' ) }
												</Button>
											</FlexItem>
											<FlexItem>
												<Button
													variant="secondary"
													isDestructive
													disabled={ isSaving }
													onClick={ () =>
														onDismiss( entry.key )
													}
												>
													{ __( 'Dismiss', 'ai' ) }
												</Button>
											</FlexItem>
										</Flex>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</CardBody>
		</Card>
	);
};

export default PendingRequestsCard;
