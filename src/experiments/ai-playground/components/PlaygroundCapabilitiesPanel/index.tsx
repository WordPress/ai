/**
 * External dependencies
 */
import { MultiCheckboxControl } from 'wp-admin-components';
import { store as interfaceStore, useInterfaceScope } from 'wp-interface';

/**
 * WordPress dependencies
 */
import { Flex, PanelBody, SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { store as playgroundStore } from '../../store';
import type { Capability } from '../../ai-client-enums';

/**
 * Renders the playground sidebar panel for AI capabilities.
 *
 * @since n.e.x.t
 * @return The component to be rendered.
 */
export default function PlaygroundCapabilitiesPanel() {
	const scope = useInterfaceScope();

	const {
		availableCapabilities,
		availableOptions,
		capability,
		options,
		isPanelOpened,
	} = useSelect(
		( select ) => {
			const {
				getAvailableCapabilities,
				getAvailableOptions,
				getCapability,
				getOptions,
			} = select( playgroundStore );
			const { isPanelActive } = select( interfaceStore );

			return {
				availableCapabilities: getAvailableCapabilities(),
				availableOptions: getAvailableOptions(),
				capability: getCapability() as Capability,
				options: getOptions() as string[],
				isPanelOpened: isPanelActive(
					scope,
					'playground-capabilities',
					true
				),
			};
		},
		[ scope ]
	);

	const { setCapability, toggleOption } = useDispatch( playgroundStore );
	const { togglePanel } = useDispatch( interfaceStore );

	const capabilityOptions: Array< {
		value: Capability;
		label: string;
	} > = useMemo( () => {
		return availableCapabilities.map( ( cap ) => {
			return {
				value: cap.identifier,
				label: cap.label,
			};
		} );
	}, [ availableCapabilities ] );

	// Get option objects for available options to render in the checkbox list.
	const optionOptions: Array< {
		value: string;
		label: string;
	} > = useMemo( () => {
		return availableOptions.map( ( cap ) => {
			return {
				value: cap.identifier,
				label: cap.label,
			};
		} );
	}, [ availableOptions ] );

	return (
		<PanelBody
			title={ __( 'Capabilities', 'ai' ) }
			opened={ isPanelOpened }
			onToggle={ () => togglePanel( scope, 'playground-capabilities' ) }
			className="ai-playground-capabilities-panel"
		>
			<Flex direction="column" gap="4">
				<SelectControl
					className="ai-playground-capability"
					label={ __( 'Capability', 'ai' ) }
					value={ capability }
					options={ capabilityOptions }
					onChange={ ( value: Capability ) => setCapability( value ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<MultiCheckboxControl
					label={ __( 'Options', 'ai' ) }
					className="ai-playground-options"
					value={ options }
					options={ optionOptions }
					onToggle={ ( value: string ) => toggleOption( value ) }
					__nextHasNoMarginBottom
				/>
			</Flex>
		</PanelBody>
	);
}
