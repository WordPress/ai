/**
 * WordPress dependencies
 */
import { ToggleControl } from '@wordpress/components';
import type { DataFormControlProps } from '@wordpress/dataviews';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useDeveloperModeContext } from '../hooks/use-developer-mode';
import { ConnectorApprovalNotice } from './ConnectorApprovalNotice';
import { DeveloperSettings } from './DeveloperSettings';

type AISettings = Record< string, boolean >;

type FeatureToggleProps = DataFormControlProps< AISettings > & {
	featureId?: string;
	capability?: string;
	connectorApprovalUrl?: string;
	hasApprovedConnector?: boolean;
};

const FEATURE_SETTING_PATTERN = /^wpai_feature_(.+)_enabled$/;
const CONNECTOR_APPROVAL_FEATURE_ID = 'connector-approval';

/**
 * FeatureToggle component.
 *
 * @param {FeatureToggleProps} props                      The component props.
 * @param {FeatureToggleProps} props.field                The field to display.
 * @param {AISettings}         props.data                 The data to display.
 * @param {Function}           props.onChange             The function to call when the value changes.
 * @param {string}             props.featureId            The feature ID.
 * @param {string}             props.capability           The AI capability type for model filtering.
 * @param {string}             props.connectorApprovalUrl URL to the Connector Approvals admin page.
 * @param {boolean}            props.hasApprovedConnector Whether the AI plugin has been approved for at least one connector.
 * @return {React.JSX.Element} The component.
 */
export function FeatureToggle( {
	field,
	data,
	onChange,
	featureId,
	capability = 'text_generation',
	connectorApprovalUrl = '',
	hasApprovedConnector = false,
}: FeatureToggleProps ): React.JSX.Element {
	const checked = !! field.getValue( { item: data } );
	const isDeveloperMode = useDeveloperModeContext();

	const resolvedFeatureId =
		featureId ??
		FEATURE_SETTING_PATTERN.exec( field.id )?.[ 1 ] ??
		field.id;

	return (
		<>
			<ToggleControl
				label={ field.label }
				help={ field.description }
				checked={ checked }
				onChange={ ( value ) => {
					onChange( { [ field.id ]: value } );
				} }
			/>
			{ checked &&
				resolvedFeatureId === CONNECTOR_APPROVAL_FEATURE_ID && (
					<Stack
						className="ai-developer-mode-fields ai-feature-settings-form"
						direction="column"
						gap="md"
					>
						<Stack direction="column" gap="md">
							<ConnectorApprovalNotice
								connectorApprovalUrl={ connectorApprovalUrl }
								hasApprovedConnector={ hasApprovedConnector }
							/>
						</Stack>
					</Stack>
				) }
			{ checked && isDeveloperMode && (
				<DeveloperSettings
					featureId={ resolvedFeatureId }
					capability={ capability }
				/>
			) }
		</>
	);
}
