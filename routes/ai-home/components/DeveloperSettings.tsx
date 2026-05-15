/**
 * WordPress dependencies
 */
import { Button, Spinner } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import type { Field, Form } from '@wordpress/dataviews';
import { useCallback, useMemo, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useDeveloperFeatureSettings } from '../hooks/use-developer-feature-settings';
import { useProviders } from '../hooks/use-providers';

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
	const { providers, isLoading, fetchError } = useProviders( capability );

	const formWrapperRef = useRef< HTMLDivElement >( null );

	const { settings, update, clear, isSaving } =
		useDeveloperFeatureSettings( featureId );

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
				Edit: 'select',
			},
			{
				id: 'model',
				type: 'text' as const,
				label: __( 'Model', 'ai' ),
				isVisible: ( data: DeveloperSelection ) =>
					!! data.provider &&
					!! providers.find( ( p ) => p.id === data.provider ),
				getElements: getModelElements,
				Edit: 'select',
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
	const hasStaleProvider =
		!! settings.provider &&
		! providers.find( ( p ) => p.id === settings.provider );

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
			{ isLoading && (
				<div className="ai-developer-mode-fields__loading-provider">
					<span className="ai-developer-mode-fields__loading-provider-label">
						{ __( 'Provider', 'ai' ) }
					</span>
					<Spinner />
				</div>
			) }
			{ ! isLoading && fetchError && (
				<p className="ai-developer-mode-field__error">{ fetchError }</p>
			) }
			{ ! isLoading && ! fetchError && (
				<>
					{ hasStaleProvider && (
						<Notice.Root
							className="ai-developer-mode-fields__notice"
							intent="warning"
						>
							<Notice.Description>
								{ __(
									'The previously selected provider is no longer available. This feature will not function as expected until a valid provider is selected or the selection is reset to default.',
									'ai'
								) }
							</Notice.Description>
						</Notice.Root>
					) }
					<div ref={ formWrapperRef }>
						<DataForm< DeveloperSelection >
							data={ settings }
							fields={ fields }
							form={ form }
							onChange={ handleChange }
						/>
					</div>
					{ hasSavedSelection && (
						<Button
							variant="link"
							className="ai-developer-mode-fields__reset-button"
							onClick={ () => {
								formWrapperRef.current
									?.querySelector< HTMLSelectElement >(
										'select'
									)
									?.focus();
								void clear();
							} }
							disabled={ isSaving }
							accessibleWhenDisabled
						>
							{ __( 'Reset to default', 'ai' ) }
						</Button>
					) }
				</>
			) }
		</div>
	);
}
