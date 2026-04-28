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

/**
 * FeatureToggle component.
 *
 * @param {DataFormControlProps< AISettings >} props          The component props.
 * @param {DataFormControlProps< AISettings >} props.field    The field to display.
 * @param {AISettings}                         props.data     The data to display.
 * @param {Function}                           props.onChange The function to call when the value changes.
 * @return {React.JSX.Element} The component.
 */
export function FeatureToggle( {
	field,
	data,
	onChange,
}: DataFormControlProps< AISettings > ): React.JSX.Element {
	const checked = !! field.getValue( { item: data } );
	const isDeveloperMode = useDeveloperModeContext();

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
			{ checked && isDeveloperMode && <DeveloperSettings /> }
		</>
	);
}
