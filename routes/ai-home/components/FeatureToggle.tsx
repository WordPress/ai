/**
 * WordPress dependencies
 */
import { ToggleControl } from '@wordpress/components';
import type { DataFormControlProps } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { useDeveloperModeContext } from '../hooks/use-developer-mode';
import { DeveloperSettings } from './DeveloperSettings';

type AISettings = Record< string, boolean >;

type FeatureToggleProps = DataFormControlProps< AISettings > & {
	featureId?: string;
	capability?: string;
};

const FEATURE_SETTING_PATTERN = /^wpai_feature_(.+)_enabled$/;

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
			{ checked && isDeveloperMode && (
				<DeveloperSettings
					featureId={ resolvedFeatureId }
					capability={ capability }
				/>
			) }
		</>
	);
}
