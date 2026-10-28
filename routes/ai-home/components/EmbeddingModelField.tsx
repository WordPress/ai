/**
 * WordPress dependencies
 */
import { SelectControl, Spinner } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import type { DataFormControlProps } from '@wordpress/dataviews/wp';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useProviders } from '../hooks/use-providers';

export const EMBEDDING_CAPABILITY = 'embedding_generation';

export interface EmbeddingModelValue {
	provider: string;
	model: string;
}

/**
 * Normalizes a stored embedding model option into a provider/model pair.
 *
 * @param {unknown} value The raw option value.
 * @return {EmbeddingModelValue} The provider and model IDs.
 */
export function normalizeEmbeddingModel( value: unknown ): EmbeddingModelValue {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return { provider: '', model: '' };
	}

	const raw = value as { provider?: unknown; model?: unknown };

	return {
		provider: typeof raw.provider === 'string' ? raw.provider : '',
		model: typeof raw.model === 'string' ? raw.model : '',
	};
}

/**
 * Checks whether two embedding model values point at the same model.
 *
 * @param {EmbeddingModelValue} a The first value.
 * @param {EmbeddingModelValue} b The second value.
 * @return {boolean} True when provider and model both match.
 */
export function isSameEmbeddingModel(
	a: EmbeddingModelValue,
	b: EmbeddingModelValue
): boolean {
	return a.provider === b.provider && a.model === b.model;
}

type Settings = Record< string, unknown >;

/**
 * EmbeddingModelField component.
 *
 * @param {DataFormControlProps<Settings>} props          The component props.
 * @param {Settings}                       props.data     The edited settings.
 * @param {Object}                         props.field    The field being edited.
 * @param {(value: Settings) => void}      props.onChange The change handler.
 * @return {React.JSX.Element} The component.
 */
export function EmbeddingModelField( {
	data,
	field,
	onChange,
}: DataFormControlProps< Settings > ): React.JSX.Element {
	const { providers, isLoading, fetchError } =
		useProviders( EMBEDDING_CAPABILITY );

	const savedValue = useSelect(
		( select ) => {
			const record = ( select( coreStore ) as any ).getEntityRecord(
				'root',
				'site'
			) as Settings | undefined;
			return record?.[ field.id ];
		},
		[ field.id ]
	);

	const saved = normalizeEmbeddingModel( savedValue );
	const value = normalizeEmbeddingModel( field.getValue( { item: data } ) );

	const showChangeWarning =
		saved.model !== '' && ! isSameEmbeddingModel( value, saved );

	const selectedProvider = providers.find( ( p ) => p.id === value.provider );

	const providerOptions = [
		{ value: '', label: __( '— Select —', 'ai' ) },
		...providers.map( ( p ) => ( { value: p.id, label: p.name } ) ),
	];

	// Keep a provider that is no longer listed visible rather than silently
	// showing the placeholder for a saved value.
	if ( value.provider !== '' && ! selectedProvider ) {
		providerOptions.push( {
			value: value.provider,
			label: value.provider,
		} );
	}

	const modelOptions = [
		{ value: '', label: __( '— Select —', 'ai' ) },
		...( selectedProvider?.models ?? [] ).map( ( m ) => ( {
			value: m.id,
			label: m.name,
		} ) ),
	];

	if (
		value.model !== '' &&
		! modelOptions.some( ( option ) => option.value === value.model )
	) {
		modelOptions.push( { value: value.model, label: value.model } );
	}

	const update = ( next: EmbeddingModelValue ) => {
		onChange( { [ field.id ]: next } );
	};

	let body: React.JSX.Element;

	if ( isLoading ) {
		body = <Spinner />;
	} else if ( fetchError ) {
		body = <p className="ai-developer-mode-field__error">{ fetchError }</p>;
	} else if ( providers.length === 0 ) {
		body = (
			<Notice.Root intent="warning">
				<Notice.Description>
					{ __(
						'No connected provider supports embeddings. Connect one in Settings > Connectors.',
						'ai'
					) }
				</Notice.Description>
			</Notice.Root>
		);
	} else {
		body = (
			<Stack direction="column" gap="md">
				<SelectControl
					// Explicit IDs: DataForm's own controls use a separate
					// instance counter, so generated IDs can collide.
					id={ `${ field.id }-provider` }
					label={ __( 'Provider', 'ai' ) }
					value={ value.provider }
					options={ providerOptions }
					onChange={ ( provider: string ) =>
						update( { provider, model: '' } )
					}
					__next40pxDefaultSize
				/>
				{ value.provider !== '' && (
					<SelectControl
						id={ `${ field.id }-model` }
						label={ __( 'Model', 'ai' ) }
						value={ value.model }
						options={ modelOptions }
						onChange={ ( model: string ) =>
							update( { provider: value.provider, model } )
						}
						__next40pxDefaultSize
					/>
				) }
			</Stack>
		);
	}

	return (
		<Stack
			render={ <fieldset /> }
			className="ai-embedding-model-field"
			direction="column"
			gap="sm"
		>
			<legend className="ai-embedding-field__label">
				{ field.label }
			</legend>
			{ body }
			{ showChangeWarning && (
				<Notice.Root intent="warning">
					<Notice.Description>
						{ sprintf(
							// translators: %s: The ID of the currently saved embedding model.
							__(
								"Embeddings from different models can't be compared. Saving will delete the embeddings generated with %s, and your terms will need to be embedded again with the new model, which costs money and runs in the background.",
								'ai'
							),
							saved.model
						) }
					</Notice.Description>
				</Notice.Root>
			) }
		</Stack>
	);
}
