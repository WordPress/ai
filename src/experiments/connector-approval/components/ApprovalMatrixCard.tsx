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
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { buildMatrixCallerList } from '../functions/helpers';
import type {
	ApprovalMatrix,
	Connector,
	PluginSummary,
	ThemeSummary,
} from '../types';

interface ApprovalMatrixCardProps {
	connectors: Connector[];
	plugins: PluginSummary[];
	themes: ThemeSummary[];
	approvals: ApprovalMatrix;
	isSaving: boolean;
	onToggle: (
		callerBasename: string,
		connectorId: string,
		approved: boolean
	) => void;
}

const ApprovalMatrixCard = ( {
	connectors,
	plugins,
	themes,
	approvals,
	isSaving,
	onToggle,
}: ApprovalMatrixCardProps ): JSX.Element => {
	const matrixCallers = buildMatrixCallerList( plugins, themes, approvals );

	const lastToggledRef = useRef< HTMLElement | null >( null );
	const wasSavingRef = useRef< boolean >( false );
	const tableRef = useRef< HTMLTableElement | null >( null );

	useEffect( () => {
		if ( wasSavingRef.current && ! isSaving ) {
			lastToggledRef.current?.focus();
			lastToggledRef.current = null;
		}

		wasSavingRef.current = isSaving;
	}, [ isSaving ] );

	return (
		<Card className="ai-connector-approval__matrix">
			<CardHeader>
				<h2>{ __( 'Approval matrix', 'ai' ) }</h2>
			</CardHeader>
			<CardBody>
				{ 0 === connectors.length ? (
					<p>
						{ __(
							'No AI connectors are currently registered. Configure a connector first.',
							'ai'
						) }
					</p>
				) : (
					<table className="widefat striped" ref={ tableRef }>
						<thead>
							<tr>
								<th>{ __( 'Caller', 'ai' ) }</th>
								{ connectors.map( ( connector ) => (
									<th key={ connector.id }>
										{ connector.name }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ matrixCallers.map( ( caller ) => (
								<tr key={ caller.basename }>
									<td>
										<strong>{ caller.name }</strong>
										<br />
										<em>
											{ 'theme' === caller.type
												? __( 'Theme', 'ai' )
												: __( 'Plugin', 'ai' ) }
										</em>
										<br />
										<code>{ caller.basename }</code>
									</td>
									{ connectors.map( ( connector ) => {
										const approved = Boolean(
											approvals[ caller.basename ]?.[
												connector.id
											]
										);

										return (
											<td key={ connector.id }>
												<ToggleControl
													checked={ approved }
													disabled={ isSaving }
													label=""
													onChange={ (
														value: boolean
													) => {
														lastToggledRef.current =
															tableRef.current
																?.ownerDocument
																.activeElement as HTMLElement;
														onToggle(
															caller.basename,
															connector.id,
															value
														);
													} }
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
