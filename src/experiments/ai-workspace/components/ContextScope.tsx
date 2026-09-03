/**
 * The context-scope control.
 */

/**
 * WordPress dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ContextScope as Scope } from '../types';

/**
 * Renders the two scopes the turn route accepts (R6).
 *
 * The control never claims more than the server will do: when Site Context has
 * no tools to offer, the turn reports it and the transcript says so rather than
 * quietly answering as though General Knowledge had been chosen (R7).
 *
 * @param props          Component props.
 * @param props.value    The selected scope.
 * @param props.onChange Change handler.
 * @param props.disabled Whether the control is disabled.
 * @return The rendered control.
 */
export default function ContextScope( {
	value,
	onChange,
	disabled,
}: {
	value: Scope;
	onChange: ( scope: Scope ) => void;
	disabled: boolean;
} ) {
	return (
		<SelectControl
			__nextHasNoMarginBottom
			label={ __( 'Context scope', 'ai' ) }
			help={ __(
				'Site Context lets the assistant look up your content, and it can only ever read content you are allowed to read. General Knowledge gives it no access to this site.',
				'ai'
			) }
			value={ value }
			disabled={ disabled }
			options={ [
				{ value: 'site', label: __( 'Site Context', 'ai' ) },
				{ value: 'general', label: __( 'General Knowledge', 'ai' ) },
			] }
			onChange={ ( next ) => onChange( next as Scope ) }
		/>
	);
}
