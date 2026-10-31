/**
 * WordPress dependencies
 */
import { Button, Spinner } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews/wp';
import type { Field, Form } from '@wordpress/dataviews/wp';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useDeveloperFeatureSettings } from '../hooks/use-developer-feature-settings';
import { useProviders } from '../hooks/use-providers';

interface DeveloperSettingsProps {
	featureId: string;
	capability: string;
	settingsField?: 'developer' | 'embedding';
}

interface DeveloperSelection {
	provider: string;
	model: string;
}

/**
 * DeveloperSettings component.
 *
 * Renders provider and model selectors for a feature's developer override or
 * explicit embedding selection.
 *
 * @param {DeveloperSettingsProps} props               The component props.
 * @param {string}                 props.featureId     The feature ID.
 * @param {string}                 props.capability    The AI capability type for filtering models.
 * @param {string}                 props.settingsField The provider/model setting to save.
 * @return {React.JSX.Element} The component.
 */
export function DeveloperSettings( {
	featureId,
	capability,
	settingsField = 'developer',
}: DeveloperSettingsProps ): React.JSX.Element {
	const isEmbedding = settingsField === 'embedding';
	const { providers, isLoading, fetchError } = useProviders( capability );

	const formWrapperRef = useRef< HTMLDivElement >( null );

	const { settings, update, clear, isSaving } = useDeveloperFeatureSettings(
		featureId,
		settingsField
	);

	const [ draftSettings, setDraftSettings ] =
		useState< DeveloperSelection | null >( null );
	const [ isSavingThis, setIsSavingThis ] = useState( false );

	const focusResetButtonRef = useRef( false );

	const moveFocusToResetButton = ( node: HTMLButtonElement | null ) => {
		if (
			focusResetButtonRef.current &&
			node &&
			! isSavingThis &&
			! hasUnsavedChanges
		) {
			node.focus();
			focusResetButtonRef.current = false;
		}
	};

	useEffect( () => {
		if ( ! isSaving ) {
			setIsSavingThis( false );
		}
	}, [ isSaving ] );

	useEffect( () => {
		setDraftSettings( null );
	}, [ settings.provider, settings.model ] );

	const currentSettings = draftSettings ?? settings;

	const getModelElements = useCallback( () => {
		const provider = providers.find(
			( p ) => p.id === currentSettings.provider
		);
		if ( ! provider ) {
			return Promise.resolve( [] );
		}
		return Promise.resolve( [
			{
				value: '',
				label: isEmbedding
					? __( '— Select an embedding model —', 'ai' )
					: __( '— Default —', 'ai' ),
			},
			...provider.models.map( ( m ) => ( {
				value: m.id,
				label: m.name,
			} ) ),
		] );
	}, [ currentSettings.provider, providers, isEmbedding ] );

	const fields = useMemo< Field< DeveloperSelection >[] >(
		() => [
			{
				id: 'provider',
				type: 'text' as const,
				label: isEmbedding
					? __( 'Embedding provider', 'ai' )
					: __( 'Provider', 'ai' ),
				elements: [
					{
						value: '',
						label: isEmbedding
							? __( '— Select an embedding provider —', 'ai' )
							: __( '— Default —', 'ai' ),
					},
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
				label: isEmbedding
					? __( 'Embedding model', 'ai' )
					: __( 'Model', 'ai' ),
				isVisible: ( data: DeveloperSelection ) =>
					!! data.provider &&
					!! providers.find( ( p ) => p.id === data.provider ),
				getElements: getModelElements,
				Edit: 'select',
			},
		],
		[ providers, getModelElements, isEmbedding ]
	);

	const form = useMemo< Form >(
		() => ( { fields: [ 'provider', 'model' ] } ),
		[]
	);

	const handleChange = useCallback(
		( changes: Partial< DeveloperSelection > ) => {
			if ( 'provider' in changes ) {
				setDraftSettings( {
					provider: changes.provider ?? '',
					model: '',
				} );
			} else {
				setDraftSettings( { ...currentSettings, ...changes } );
			}
		},
		[ currentSettings ]
	);

	const handleSave = useCallback( () => {
		if ( draftSettings ) {
			setIsSavingThis( true );
			void update( draftSettings );
		}
	}, [ draftSettings, update ] );

	const hasSavedSelection = settings.provider !== '' || settings.model !== '';
	const hasUnsavedChanges =
		draftSettings !== null &&
		( draftSettings.provider !== settings.provider ||
			draftSettings.model !== settings.model );

	const hasStaleProvider =
		!! currentSettings.provider &&
		! providers.find( ( p ) => p.id === currentSettings.provider );
	const hasStaleModel =
		isEmbedding &&
		!! currentSettings.model &&
		! providers
			.find( ( p ) => p.id === currentSettings.provider )
			?.models.some( ( model ) => model.id === currentSettings.model );
	const hasValidSelection =
		! isEmbedding ||
		( !! currentSettings.provider &&
			!! currentSettings.model &&
			! hasStaleProvider &&
			! hasStaleModel );

	if ( capability === 'none' ) {
		return (
			<Stack
				className="ai-developer-mode-fields ai-feature-settings-form"
				direction="column"
				gap="md"
			>
				<p>
					{ __(
						'This feature does not require an AI provider or model.',
						'ai'
					) }
				</p>
			</Stack>
		);
	}

	return (
		<Stack
			className="ai-developer-mode-fields ai-feature-settings-form"
			direction="column"
			gap="md"
		>
			{ isEmbedding && (
				<p>
					{ __(
						'Choose the embedding model used to find related content. This selection is independent of the text-generation model.',
						'ai'
					) }
				</p>
			) }
			{ isLoading && (
				<Stack direction="column" gap="xs">
					<span className="ai-developer-mode-fields__loading-provider-label">
						{ __( 'Provider', 'ai' ) }
					</span>
					<Spinner />
				</Stack>
			) }
			{ ! isLoading && fetchError && (
				<p className="ai-developer-mode-field__error">{ fetchError }</p>
			) }
			{ ! isLoading && ! fetchError && (
				<Stack direction="column" gap="md">
					{ isEmbedding && providers.length === 0 && (
						<p>
							{ __(
								'No embedding models are available. Connect a provider that supports embeddings and ensure an embedding model is installed or available.',
								'ai'
							) }
						</p>
					) }
					{ hasStaleModel && ! hasStaleProvider && (
						<p>
							{ __(
								'The saved embedding model is no longer available. Select an available model and save the settings.',
								'ai'
							) }
						</p>
					) }
					{ hasStaleProvider && (
						<Notice.Root intent="warning">
							<Notice.Description>
								{ isEmbedding
									? __(
											'The saved embedding provider is no longer available. Select an available provider and model, then save the settings.',
											'ai'
									  )
									: __(
											'The previously selected provider is no longer available. This feature will not function as expected until a valid provider is selected or the selection is reset to default.',
											'ai'
									  ) }
							</Notice.Description>
						</Notice.Root>
					) }
					<div ref={ formWrapperRef }>
						<DataForm< DeveloperSelection >
							data={ currentSettings }
							fields={ fields }
							form={ form }
							onChange={ handleChange }
						/>
					</div>
					<Stack
						align="center"
						className="ai-developer-mode-fields__actions"
						direction="row"
						gap="lg"
					>
						{ ( hasUnsavedChanges || isSavingThis ) && (
							<Button
								variant="primary"
								onClick={ () => {
									handleSave();
									focusResetButtonRef.current = true;
								} }
								disabled={
									isSavingThis ||
									! hasUnsavedChanges ||
									! hasValidSelection
								}
								isBusy={ isSavingThis }
								accessibleWhenDisabled
								__next40pxDefaultSize
							>
								{ __( 'Save', 'ai' ) }
							</Button>
						) }
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
									setDraftSettings( null );
									void clear();
								} }
								disabled={ isSavingThis }
								accessibleWhenDisabled
								ref={ moveFocusToResetButton }
							>
								{ isEmbedding
									? __( 'Clear selection', 'ai' )
									: __( 'Reset to default', 'ai' ) }
							</Button>
						) }
					</Stack>
				</Stack>
			) }
		</Stack>
	);
}
