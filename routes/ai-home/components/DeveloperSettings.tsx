/**
 * WordPress dependencies
 */
import { Button, Spinner } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import type { Field, Form } from '@wordpress/dataviews';
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

	const [ draftSettings, setDraftSettings ] =
		useState< DeveloperSelection | null >( null );
	const [ isSavingThis, setIsSavingThis ] = useState( false );

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
			{ value: '', label: __( '— Default —', 'ai' ) },
			...provider.models.map( ( m ) => ( {
				value: m.id,
				label: m.name,
			} ) ),
		] );
	}, [ currentSettings.provider, providers ] );

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
					{ hasStaleProvider && (
						<Notice.Root intent="warning">
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
								onClick={ handleSave }
								disabled={ isSavingThis || ! hasUnsavedChanges }
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
							>
								{ __( 'Reset to default', 'ai' ) }
							</Button>
						) }
					</Stack>
				</Stack>
			) }
		</Stack>
	);
}
