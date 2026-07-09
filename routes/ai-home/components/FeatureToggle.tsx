/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Spinner, ToggleControl } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import type { DataFormControlProps } from '@wordpress/dataviews';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useDeveloperModeContext } from '../hooks/use-developer-mode';
import { DeveloperSettings } from './DeveloperSettings';
import { ConnectorApprovalNotice } from './ConnectorApprovalNotice';

type AISettings = Record< string, boolean >;

type FeatureToggleProps = DataFormControlProps< AISettings > & {
	featureId?: string;
	capability?: string;
};

interface ConnectorApprovalState {
	connectors: { id: string; name: string }[];
	approvals: Record< string, Record< string, boolean > >;
}

const FEATURE_SETTING_PATTERN = /^wpai_feature_(.+)_enabled$/;
const CONNECTOR_APPROVAL_FEATURE_ID = 'connector-approval';
const AI_PLUGIN = 'ai/ai.php';

/**
 * FeatureToggle component.
 *
 * @param {FeatureToggleProps} props            The component props.
 * @param {FeatureToggleProps} props.field      The field to display.
 * @param {AISettings}         props.data       The data to display.
 * @param {Function}           props.onChange   The function to call when the value changes.
 * @param {string}             props.featureId  The feature ID.
 * @param {string}             props.capability The AI capability type for model filtering.
 * @return {React.JSX.Element} The component.
 */
export function FeatureToggle( {
	field,
	data,
	onChange,
	featureId,
	capability = 'text_generation',
}: FeatureToggleProps ): React.JSX.Element {
	const checked = !! field.getValue( { item: data } );
	const isDeveloperMode = useDeveloperModeContext();
	const { createErrorNotice } = useDispatch( noticesStore );

	const [ approvalState, setApprovalState ] =
		useState< ConnectorApprovalState | null >( null );
	const [ isCheckingApprovals, setIsCheckingApprovals ] = useState( false );

	const resolvedFeatureId =
		featureId ??
		FEATURE_SETTING_PATTERN.exec( field.id )?.[ 1 ] ??
		field.id;

	const hasApprovedConnector =
		approvalState?.approvals[ AI_PLUGIN ] &&
		Object.entries( approvalState.approvals[ AI_PLUGIN ] ).some(
			( [ connectorId, approved ] ) =>
				approved &&
				approvalState.connectors.some(
					( connector ) => connector.id === connectorId
				)
		);

	const checkConnectorApprovals = useCallback( () => {
		setIsCheckingApprovals( true );
		apiFetch< ConnectorApprovalState >( {
			path: '/ai/v1/connector-approvals',
		} )
			.then( setApprovalState )
			.catch( () => {
				createErrorNotice(
					__( 'Failed to load connector approval status.', 'ai' ),
					{ type: 'snackbar' }
				);
			} )
			.finally( () => setIsCheckingApprovals( false ) );
	}, [ createErrorNotice ] );

	useEffect( () => {
		if ( resolvedFeatureId === CONNECTOR_APPROVAL_FEATURE_ID && checked ) {
			checkConnectorApprovals();
		}
		// Intentionally mount-only: toggling during the session is handled by
		// the ToggleControl's onChange below, so re-running this on every
		// `checked`/`resolvedFeatureId` change would double-fetch.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<>
			<ToggleControl
				label={ field.label }
				help={ field.description }
				checked={ checked }
				onChange={ ( value ) => {
					// `onChange` is typed as `void` but the DataForm's actual
					// handler (see stage.tsx's handleChange) is async and only
					// resolves once the setting has been persisted. Wait for
					// it before checking approval state, otherwise the check
					// can reach the server before the feature is enabled.
					const saved = onChange( { [ field.id ]: value } );

					if ( resolvedFeatureId === CONNECTOR_APPROVAL_FEATURE_ID ) {
						if ( value ) {
							Promise.resolve( saved ).then(
								checkConnectorApprovals
							);
						} else {
							setApprovalState( null );
						}
					}
				} }
			/>
			{ checked && isDeveloperMode && (
				<DeveloperSettings
					featureId={ resolvedFeatureId }
					capability={ capability }
				/>
			) }
			{ checked &&
				resolvedFeatureId === CONNECTOR_APPROVAL_FEATURE_ID && (
					<>
						<Stack
							className="ai-developer-mode-fields ai-feature-settings-form"
							direction="column"
							gap="md"
						>
							<Stack direction="column" gap="md">
								{ isCheckingApprovals && <Spinner /> }
								{ ! isCheckingApprovals &&
									approvalState &&
									! hasApprovedConnector && (
										<ConnectorApprovalNotice />
									) }
							</Stack>
						</Stack>
					</>
				) }
		</>
	);
}
