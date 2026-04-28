/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useDeveloperFeatureSettings } from '../hooks/use-developer-feature-settings';

interface ModelData {
	id: string;
	name: string;
}

interface ProviderData {
	id: string;
	name: string;
	models: ModelData[];
}

interface DeveloperSettingsProps {
	featureId: string;
	capability: string;
}

/**
 * DeveloperSettings component.
 *
 * Renders provider and model selectors for developer mode, allowing per-feature
 * AI provider and model overrides.
 *
 * @param {DeveloperSettingsProps} props            The component props.
 * @param {string}                 props.featureId  The feature ID.
 * @param {string}                 props.capability The AI capability type for filtering models.
 * @return {React.JSX.Element} The component.
 */
export function DeveloperSettings( {
	featureId,
	capability,
}: DeveloperSettingsProps ): React.JSX.Element {
	const [ providers, setProviders ] = useState< ProviderData[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ fetchError, setFetchError ] = useState< string | null >( null );

	const { settings, update, clear, isSaving } =
		useDeveloperFeatureSettings( featureId );

	useEffect( () => {
		setIsLoading( true );
		setFetchError( null );

		apiFetch< ProviderData[] >( {
			path: `/ai/v1/providers?capability=${ encodeURIComponent(
				capability
			) }`,
		} )
			.then( ( data ) => {
				setProviders( data );
			} )
			.catch( () => {
				setFetchError( __( 'Failed to load providers.', 'ai' ) );
			} )
			.finally( () => {
				setIsLoading( false );
			} );
	}, [ capability ] );

	const selectedProvider = providers.find(
		( p ) => p.id === settings.provider
	);

	const providerOptions = [
		{ label: __( '— Default —', 'ai' ), value: '' },
		...providers.map( ( p ) => ( { label: p.name, value: p.id } ) ),
	];

	const modelOptions = selectedProvider
		? [
				{ label: __( '— Default —', 'ai' ), value: '' },
				...selectedProvider.models.map( ( m ) => ( {
					label: m.name,
					value: m.id,
				} ) ),
		  ]
		: null;

	const hasSavedSelection = settings.provider !== '' || settings.model !== '';

	const handleProviderChange = ( value: string ) => {
		void update( { provider: value, model: '' } );
	};

	const handleModelChange = ( value: string ) => {
		void update( { provider: settings.provider, model: value } );
	};

	const handleClear = () => {
		void clear();
	};

	return (
		<div className="ai-developer-mode-field">
			{ isLoading && <Spinner /> }
			{ ! isLoading && fetchError && (
				<p className="ai-developer-mode-field__error">{ fetchError }</p>
			) }
			{ ! isLoading && ! fetchError && (
				<>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Provider', 'ai' ) }
						options={ providerOptions }
						value={ settings.provider }
						onChange={ handleProviderChange }
						disabled={ isSaving }
					/>
					{ modelOptions !== null && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Model', 'ai' ) }
							options={ modelOptions }
							value={ settings.model }
							onChange={ handleModelChange }
							disabled={ isSaving }
						/>
					) }
					{ hasSavedSelection && (
						<Button
							variant="tertiary"
							size="compact"
							onClick={ handleClear }
							disabled={ isSaving }
						>
							{ __( 'Reset to default', 'ai' ) }
						</Button>
					) }
				</>
			) }
		</div>
	);
}
