/**
 * Card with the plugin-by-connector approval matrix.
 */

/**
 * WordPress dependencies
 */
import {
	Card,
	CardBody,
	CardHeader,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { buildMatrixPluginList, isOwnerPlugin } from '../functions/helpers';
import type { ApprovalMatrix, Connector, PluginSummary } from '../types';

interface ApprovalMatrixCardProps {
	connectors: Connector[];
	plugins: PluginSummary[];
	approvals: ApprovalMatrix;
	isSaving: boolean;
	onToggle: (
		pluginBasename: string,
		connectorId: string,
		approved: boolean
	) => void;
}

const ApprovalMatrixCard = ( {
	connectors,
	plugins,
	approvals,
	isSaving,
	onToggle,
}: ApprovalMatrixCardProps ): JSX.Element => {
	const matrixPlugins = buildMatrixPluginList( plugins, approvals );

	return (
		<Card className="ai-connector-approval__matrix">
			<CardHeader>
				<h2>{ __( 'Approval matrix', 'ai' ) }</h2>
			</CardHeader>
			<CardBody>
				{ 0 === connectors.length ? (
					<p>
						{ __(
							'No connectors are currently registered. Configure a connector first.',
							'ai'
						) }
					</p>
				) : (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Plugin', 'ai' ) }</th>
								{ connectors.map( ( connector ) => (
									<th key={ connector.id }>
										{ connector.name }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ matrixPlugins.map( ( plugin ) => (
								<tr key={ plugin.basename }>
									<td>
										<strong>{ plugin.name }</strong>
										<br />
										<code>{ plugin.basename }</code>
									</td>
									{ connectors.map( ( connector ) => {
										if (
											isOwnerPlugin( connector, plugin )
										) {
											return (
												<td
													key={ connector.id }
													title={ __(
														'The connector plugin that owns this credential can always read it.',
														'ai'
													) }
												>
													{ __( 'Owner', 'ai' ) }
												</td>
											);
										}

										const approved = Boolean(
											approvals[ plugin.basename ]?.[
												connector.id
											]
										);

										return (
											<td key={ connector.id }>
												<ToggleControl
													__nextHasNoMarginBottom
													checked={ approved }
													disabled={ isSaving }
													label=""
													onChange={ (
														value: boolean
													) =>
														onToggle(
															plugin.basename,
															connector.id,
															value
														)
													}
												/>
											</td>
										);
									} ) }
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</CardBody>
		</Card>
	);
};

export default ApprovalMatrixCard;
