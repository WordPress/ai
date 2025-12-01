/**
 * External dependencies
 */
import { store as interfaceStore, useInterfaceScope } from 'wp-interface';

/**
 * WordPress dependencies
 */
import { Flex, PanelBody, Notice, SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	createInterpolateElement,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { store as playgroundStore } from '../../store';

const MODEL_SELECT_PLACEHOLDER_OPTIONS = [
	{
		value: '',
		label: __( 'Select service to see models', 'ai' ),
	},
];

/**
 * Renders the playground sidebar panel for AI provider and model selection.
 *
 * @since n.e.x.t
 *
 * @return The component to be rendered.
 */
export default function PlaygroundProviderModelPanel() {
	const scope = useInterfaceScope();

	const {
		hasAnyAvailableServices,
		availableProviders,
		availableModels,
		provider,
		model,
		servicesSettingsUrl,
		currentUserCanManageServices,
		isPanelOpened,
	} = useSelect(
		( select ) => {
			const {
				getAvailableProviders,
				getAvailableModels,
				getProvider,
				getModel,
			} = select( playgroundStore );
			const { isPanelActive } = select( interfaceStore );

			return {
				hasAnyAvailableServices: true, // TODO: Implement check.
				availableProviders: getAvailableProviders(),
				availableModels: getAvailableModels(),
				provider: getProvider(),
				model: getModel(),
				servicesSettingsUrl:
					'/wp-admin/admin.php?page=wp-ai-client-settings', // TODO: Fix this.
				currentUserCanManageServices: true, // TODO: Implement check.
				isPanelOpened: isPanelActive(
					scope,
					'playground-service-model',
					true
				),
			};
		},
		[ scope ]
	);

	const { setProvider, setModel } = useDispatch( playgroundStore );
	const { togglePanel } = useDispatch( interfaceStore );

	const providerSelectOptions = useMemo( () => {
		return [
			{
				value: '',
				label: __( 'Select provider…', 'ai' ),
			},
			...( availableProviders || [] ).map(
				( { identifier, label } ) => ( {
					value: identifier,
					label,
				} )
			),
		];
	}, [ availableProviders ] );

	const modelSelectOptions = useMemo( () => {
		return [
			{
				value: '',
				label: __( 'Select model…', 'ai' ),
			},
			...( availableModels || [] ).map( ( { identifier, label } ) => ( {
				value: identifier,
				label,
			} ) ),
		];
	}, [ availableModels ] );

	const [ changedProvider, setChangedProvider ] = useState( false );
	const onChangeProvider = ( value: string ) => {
		setProvider( value );
		setChangedProvider( true );
	};

	// Announce to screen readers when model selection was cleared after a service change.
	useEffect( () => {
		if ( ! changedProvider ) {
			return;
		}

		setChangedProvider( false );
		if ( ! model ) {
			speak(
				__( 'Please continue navigating to select a model.', 'ai' ),
				'polite'
			);
		}
	}, [ changedProvider, model ] );

	return (
		<PanelBody
			title={ __( 'Model selection', 'ai' ) }
			opened={ isPanelOpened }
			onToggle={ () => togglePanel( scope, 'playground-service-model' ) }
			className="ai-playground-service-model-panel"
		>
			{ availableProviders !== undefined && (
				<>
					{ availableProviders.length ? (
						<Flex direction="column" gap="4">
							<SelectControl
								className="ai-playground-service"
								label={ __( 'Provider', 'ai' ) }
								value={
									typeof provider === 'string' ? provider : ''
								}
								options={ providerSelectOptions }
								onChange={ onChangeProvider }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<SelectControl
								className="ai-playground-model"
								label={ __( 'Model', 'ai' ) }
								value={ typeof model === 'string' ? model : '' }
								options={
									modelSelectOptions.length > 1
										? modelSelectOptions
										: MODEL_SELECT_PLACEHOLDER_OPTIONS
								}
								onChange={ setModel }
								disabled={ modelSelectOptions.length <= 1 }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						</Flex>
					) : (
						<Notice status="warning" isDismissible={ false }>
							{ hasAnyAvailableServices
								? __(
										'No services available for the configured capabilities.',
										'ai'
								  )
								: __( 'No services available.', 'ai' ) }
							{ currentUserCanManageServices &&
								createInterpolateElement(
									' ' +
										( hasAnyAvailableServices
											? __(
													'Please modify the selected capabilities or configure additional <a>AI providers</a>.',
													'ai'
											  )
											: __(
													'Please configure <a>AI providers</a>.',
													'ai'
											  ) ),
									{
										// eslint-disable-next-line jsx-a11y/anchor-has-content
										a: <a href={ servicesSettingsUrl } />,
									}
								) }
						</Notice>
					) }
				</>
			) }
		</PanelBody>
	);
}
