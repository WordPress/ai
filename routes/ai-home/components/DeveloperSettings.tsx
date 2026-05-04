/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Spinner } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import type { Field, Form } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
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

interface DeveloperSelection {
	provider: string;
	model: string;
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
		if ( capability === 'none' ) {
			setProviders( [] );
			setFetchError( null );
			setIsLoading( false );
			return;
		}

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

	const getModelElements = useCallback( () => {
		const provider = providers.find( ( p ) => p.id === settings.provider );
		if ( ! provider ) {
			return Promise.resolve( [] );
		}
		return Promise.resolve( [
			{ value: '', label: __( '— Default —', 'ai' ) },
			...provider.models.map( ( m ) => ( {
				value: m.id,
				label: m.name,
			} ) ),
		] );
	}, [ settings.provider, providers ] );

	const fields = useMemo< Field< DeveloperSelection >[] >(
		() => [
			{
				id: 'provider',
				type: 'text' as const,
				label: __( 'Provider', 'ai' ),
				elements: [
					{ value: '', label: __( '— Default —', 'ai' ) },
					...providers.map( ( p ) => ( {
						value: p.id,
						label: p.name,
					} ) ),
				],
			},
			{
				id: 'model',
				type: 'text' as const,
				label: __( 'Model', 'ai' ),
				isVisible: ( data: DeveloperSelection ) => !! data.provider,
				getElements: getModelElements,
			},
		],
		[ providers, getModelElements ]
	);

	const form = useMemo< Form >(
		() => ( { fields: [ 'provider', 'model' ] } ),
		[]
	);

	const handleChange = useCallback(
		( changes: Partial< DeveloperSelection > ) => {
			if ( 'provider' in changes ) {
				void update( { provider: changes.provider ?? '', model: '' } );
			} else {
				void update( { ...settings, ...changes } );
			}
		},
		[ update, settings ]
	);

	const hasSavedSelection = settings.provider !== '' || settings.model !== '';

	if ( capability === 'none' ) {
		return (
			<div className="ai-developer-mode-fields ai-feature-settings-form">
				<p>
					{ __(
						'This feature does not require an AI provider or model.',
						'ai'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="ai-developer-mode-fields ai-feature-settings-form">
			{ isLoading && <Spinner /> }
			{ ! isLoading && fetchError && (
				<p className="ai-developer-mode-field__error">{ fetchError }</p>
			) }
			{ ! isLoading && ! fetchError && (
				<>
					<DataForm< DeveloperSelection >
						data={ settings }
						fields={ fields }
						form={ form }
						onChange={ handleChange }
					/>
					{ hasSavedSelection && (
						<Button
							variant="link"
							onClick={ () => {
								void clear();
							} }
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
