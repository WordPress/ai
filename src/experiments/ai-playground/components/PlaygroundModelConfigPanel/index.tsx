/**
 * External dependencies
 */
import { MultiCheckboxControl } from 'wp-admin-components';
import { store as interfaceStore, useInterfaceScope } from 'wp-interface';

/**
 * WordPress dependencies
 */
import {
	PanelBody,
	Flex,
	TextControl,
	SelectControl,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { store as playgroundStore } from '../../store';
import {
	Capability,
	MediaOrientation,
	type Modality,
} from '../../ai-client-enums';
import type { ModelConfig } from '../../ai-client-types';

const EMPTY_MODALITY_ARRAY: Modality[] = [];

/**
 * Renders the playground sidebar panel for AI model configuration.
 *
 * @since n.e.x.t
 *
 * @return The component to be rendered.
 */
export default function PlaygroundModelConfigPanel() {
	const scope = useInterfaceScope();

	const {
		capability,
		availableModalities,
		maxTokens,
		temperature,
		topP,
		outputModalities,
		outputMediaOrientation,
		outputSpeechVoice,
		isPanelOpened,
	} = useSelect(
		( select ) => {
			const { getCapability, getAvailableModalities, getModelParam } =
				select( playgroundStore );
			const { isPanelActive } = select( interfaceStore );

			return {
				capability: getCapability(),
				availableModalities: getAvailableModalities(),
				maxTokens: getModelParam(
					'maxTokens'
				) as ModelConfig[ 'maxTokens' ],
				temperature: getModelParam(
					'temperature'
				) as ModelConfig[ 'temperature' ],
				topP: getModelParam( 'topP' ) as ModelConfig[ 'topP' ],
				outputModalities:
					( getModelParam(
						'outputModalities'
					) as ModelConfig[ 'outputModalities' ] ) ||
					EMPTY_MODALITY_ARRAY,
				outputMediaOrientation: getModelParam(
					'outputMediaOrientation'
				) as ModelConfig[ 'outputMediaOrientation' ],
				outputSpeechVoice: getModelParam(
					'outputSpeechVoice'
				) as ModelConfig[ 'outputSpeechVoice' ],
				isPanelOpened: isPanelActive(
					scope,
					'playground-model-config'
				),
			};
		},
		[ scope ]
	);

	const { setModelParam } = useDispatch( playgroundStore );
	const { togglePanel } = useDispatch( interfaceStore );

	// Get option objects for available modalities to render in the checkbox list.
	const modalityOptions = useMemo( () => {
		return availableModalities.map( ( modality ) => {
			return {
				value: modality.identifier,
				label: modality.label,
			};
		} );
	}, [ availableModalities ] );

	return (
		<PanelBody
			title={ __( 'Model configuration', 'ai' ) }
			opened={ isPanelOpened }
			onToggle={ () => togglePanel( scope, 'playground-model-config' ) }
			className="ai-playground-model-config-panel"
		>
			{ capability === Capability.TEXT_GENERATION && (
				<Flex direction="column" gap="4">
					<MultiCheckboxControl
						label={ __( 'Output modalities', 'ai' ) }
						help={ __(
							'Not every model supports all output modalities. Select the modalities based on the model you are using.',
							'ai'
						) }
						value={ outputModalities }
						options={ modalityOptions }
						onChange={ ( value: string[] ) =>
							setModelParam( 'outputModalities', value )
						}
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						min="0"
						step="1"
						label={ __( 'Max output tokens', 'ai' ) }
						help={ __(
							'The maximum number of tokens to include in a response candidate.',
							'ai'
						) }
						value={ maxTokens || '' }
						onChange={ ( value ) =>
							setModelParam( 'maxTokens', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						type="number"
						min="0"
						max="1"
						step="0.01"
						label={ __( 'Temperature', 'ai' ) }
						help={ sprintf(
							/* translators: 1: Minimum value, 2: Maximum value */
							__(
								'Floating point value to control the randomness of the output, between %1$s and %2$s.',
								'ai'
							),
							'0.0',
							'1.0'
						) }
						value={ temperature || '' }
						onChange={ ( value ) =>
							setModelParam( 'temperature', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						type="number"
						min="0"
						step="0.01"
						label={ __( 'Top P', 'ai' ) }
						help={ __(
							'The maximum cumulative probability of tokens to consider when sampling.',
							'ai'
						) }
						value={ topP || '' }
						onChange={ ( value ) => setModelParam( 'topP', value ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</Flex>
			) }
			{ capability === Capability.IMAGE_GENERATION && (
				<Flex direction="column" gap="4">
					<SelectControl
						label={ __( 'Orientation', 'ai' ) }
						help={ __(
							'The orientation for the generated images.',
							'ai'
						) }
						value={ outputMediaOrientation || '' }
						options={ [
							{
								value: '',
								label: __( 'Select orientation…', 'ai' ),
							},
							{
								value: MediaOrientation.SQUARE,
								label: _x( 'Square', 'orientation', 'ai' ),
							},
							{
								value: MediaOrientation.LANDSCAPE,
								label: _x( 'Landscape', 'orientation', 'ai' ),
							},
							{
								value: MediaOrientation.PORTRAIT,
								label: _x( 'Portrait', 'orientation', 'ai' ),
							},
						] }
						onChange={ ( value ) =>
							setModelParam( 'outputMediaOrientation', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</Flex>
			) }
			{ capability === Capability.TEXT_TO_SPEECH_CONVERSION && (
				<Flex direction="column" gap="4">
					<TextControl
						label={ __( 'Voice', 'ai' ) }
						help={ __(
							'Identifier of the voice to use for generated speech. Consult with the selected model documentation for available voices.',
							'ai'
						) }
						value={ outputSpeechVoice || '' }
						onChange={ ( value ) =>
							setModelParam( 'outputSpeechVoice', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</Flex>
			) }
		</PanelBody>
	);
}
