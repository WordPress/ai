/**
 * WordPress dependencies
 */
import { BaseControl, FormToggle } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

type AISettings = Record< string, boolean >;

interface SectionMasterToggleProps {
	groupId: string;
	groupLabel: string;
	experimentSettings: string[];
	data: AISettings;
	onBulkChange: ( edits: Record< string, boolean > ) => void;
}

/**
 * Section-level master toggle with off, indeterminate, and on states.
 *
 * @param {SectionMasterToggleProps} props                    The component props.
 * @param {string}                   props.groupId            The feature group ID.
 * @param {string}                   props.groupLabel         The feature group label.
 * @param {string[]}                 props.experimentSettings Feature setting names in the group.
 * @param {AISettings}               props.data               Current settings values.
 * @param {Function}                 props.onBulkChange       Bulk settings change handler.
 * @return {React.JSX.Element} The component.
 */
export function SectionMasterToggle( {
	groupId,
	groupLabel,
	experimentSettings,
	data,
	onBulkChange,
}: SectionMasterToggleProps ) {
	const toggleId = `section-master-toggle-${ groupId }`;
	const inputRef = useRef< HTMLInputElement >( null );
	const allEnabled = experimentSettings.every(
		( settingName ) => data[ settingName ]
	);
	const allDisabled = experimentSettings.every(
		( settingName ) => ! data[ settingName ]
	);
	const isIndeterminate = ! allEnabled && ! allDisabled;
	const label = sprintf(
		// translators: %s: Feature group label.
		__( 'Enable %s', 'ai' ),
		groupLabel
	);
	const help = sprintf(
		// translators: %s: Feature group label.
		__(
			'Enable or disable all features in %s. When partially enabled, the toggle shows a mixed state.',
			'ai'
		),
		groupLabel
	);

	useEffect( () => {
		if ( inputRef.current ) {
			inputRef.current.indeterminate = isIndeterminate;
		}
	}, [ isIndeterminate, allEnabled ] );

	const handleToggle = () => {
		const edits: Record< string, boolean > = {};

		if ( allEnabled ) {
			for ( const settingName of experimentSettings ) {
				if ( data[ settingName ] ) {
					edits[ settingName ] = false;
				}
			}
		} else {
			for ( const settingName of experimentSettings ) {
				if ( ! data[ settingName ] ) {
					edits[ settingName ] = true;
				}
			}
		}

		if ( Object.keys( edits ).length > 0 ) {
			onBulkChange( edits );
		}
	};

	return (
		<BaseControl
			className="ai-section-master-toggle"
			help={ help }
			__nextHasNoMarginBottom
		>
			<div
				className={
					isIndeterminate
						? 'ai-section-master-toggle__control is-indeterminate'
						: 'ai-section-master-toggle__control'
				}
			>
				<FormToggle
					ref={ inputRef }
					id={ toggleId }
					checked={ allEnabled }
					onChange={ handleToggle }
					aria-label={ label }
				/>
				<label
					className="ai-section-master-toggle__label"
					htmlFor={ toggleId }
				>
					{ label }
				</label>
			</div>
		</BaseControl>
	);
}
