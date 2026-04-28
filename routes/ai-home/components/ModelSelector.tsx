/**
 * WordPress dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * ModelSelector component.
 *
 * @return {React.JSX.Element} The component.
 */
export function ModelSelector(): React.JSX.Element {
	return (
		<div className="ai-developer-mode-field">
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Choose Model', 'ai' ) }
				options={ [
					{ label: __( '— No models available —', 'ai' ), value: '' },
				] }
				value=""
				onChange={ () => {} }
			/>
		</div>
	);
}
