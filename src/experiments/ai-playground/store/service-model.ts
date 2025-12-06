/**
 * External dependencies
 */
import memoize from 'memize';
import type { StoreConfig, Action, ThunkArgs } from 'wp-store-utils';

/**
 * WordPress dependencies
 */
import { createRegistrySelector } from '@wordpress/data';
import { store as preferencesStore } from '@wordpress/preferences';

/**
 * Internal dependencies
 */
import { STORE_NAME } from './name';
import { optionsListToOptionsMap } from '../helpers';
import type { Capability } from '../ai-client-enums';
import type { ProviderMetadata, ModelMetadata } from '../ai-client-types';
import type { AiProviderOption, AiModelOption } from '../types';

const EMPTY_AI_MODEL_OPTION_ARRAY: AiModelOption[] = [];

const prepareAvailableProviders = memoize(
	( registeredProviders: ProviderMetadata[] ) => {
		return registeredProviders.map( ( { id, name } ): AiProviderOption => {
			return {
				identifier: id,
				label: name,
			};
		} );
	}
);

const filterAvailableModels = memoize(
	(
		registeredModels: ModelMetadata[],
		requiredCapability: Capability,
		requiredOptions: string[]
	) => {
		return registeredModels
			.filter( ( modelMetadata ) => {
				const nameValueMap = optionsListToOptionsMap(
					modelMetadata.supportedOptions
				);
				return (
					modelMetadata.supportedCapabilities.includes(
						requiredCapability
					) &&
					requiredOptions.every( ( option ) => {
						// Special cases to check for multimodal input/output.
						if ( option === 'multimodalInput' ) {
							return (
								nameValueMap.inputModalities &&
								nameValueMap.inputModalities.length > 1
							);
						}
						if ( option === 'multimodalOutput' ) {
							return (
								nameValueMap.outputModalities &&
								nameValueMap.outputModalities.length > 1
							);
						}
						return !! nameValueMap[ option ];
					} )
				);
			} )
			.map( ( { id, name } ): AiModelOption => {
				return {
					identifier: id,
					label: name,
				};
			} );
	}
);

export enum ActionType {
	Unknown = 'REDUX_UNKNOWN',
}

type UnknownAction = Action< ActionType.Unknown >;

export type CombinedAction = UnknownAction;

export type State = Record< string, never >;

export const initialState: State = {};

export type ActionCreators = typeof actions;
export type Selectors = typeof selectors;

type DispatcherArgs = ThunkArgs<
	State,
	ActionCreators,
	CombinedAction,
	Selectors
>;

const actions = {
	/**
	 * Sets the provider.
	 *
	 * @since n.e.x.t
	 *
	 * @param providerId - Provider identifier.
	 * @return Action creator.
	 */
	setProvider( providerId: string ) {
		return ( { registry, dispatch, select }: DispatcherArgs ) => {
			registry
				.dispatch( preferencesStore )
				.set( 'ai-playground', 'provider', providerId );

			const availableModels = select.getAvailableModels();
			if ( availableModels && availableModels.length === 1 ) {
				dispatch.setModel( availableModels[ 0 ].identifier );
			} else {
				dispatch.setModel( '' );
			}
		};
	},

	/**
	 * Sets the model.
	 *
	 * @since n.e.x.t
	 *
	 * @param modelId - Model identifier.
	 * @return Action creator.
	 */
	setModel( modelId: string ) {
		return ( { registry }: DispatcherArgs ) => {
			registry
				.dispatch( preferencesStore )
				.set( 'ai-playground', 'model', modelId );
		};
	},

	/**
	 * Sets a model configuration parameter.
	 *
	 * @since n.e.x.t
	 *
	 * @param key   - Parameter key.
	 * @param value - Parameter value.
	 * @return Action creator.
	 */
	setModelParam( key: string, value: unknown ) {
		return ( { registry }: DispatcherArgs ) => {
			registry
				.dispatch( preferencesStore )
				.set( 'ai-playground', `model_param_${ key }`, value );
		};
	},

	/**
	 * Sets the system instruction.
	 *
	 * @since n.e.x.t
	 *
	 * @param systemInstruction - System instruction.
	 * @return Action creator.
	 */
	setSystemInstruction( systemInstruction: string ) {
		return ( { registry }: DispatcherArgs ) => {
			registry
				.dispatch( preferencesStore )
				.set(
					'ai-playground',
					'system-instruction',
					systemInstruction
				);
		};
	},

	/**
	 * Shows the system instruction.
	 *
	 * @since n.e.x.t
	 *
	 * @return Action creator.
	 */
	showSystemInstruction() {
		return ( { registry }: DispatcherArgs ) => {
			registry
				.dispatch( preferencesStore )
				.set( 'ai-playground', 'system-instruction-visible', true );
		};
	},

	/**
	 * Hides the system instruction.
	 *
	 * @since n.e.x.t
	 *
	 * @return Action creator.
	 */
	hideSystemInstruction() {
		return ( { registry }: DispatcherArgs ) => {
			registry
				.dispatch( preferencesStore )
				.set( 'ai-playground', 'system-instruction-visible', false );
		};
	},
};

/**
 * Reducer for the store mutations.
 *
 * @since n.e.x.t
 *
 * @param state - Current state.
 * @return New state.
 */
function reducer( state: State = initialState ): State {
	return state;
}

const selectors = {
	getProvider: createRegistrySelector( ( select ) => () => {
		const providerId = select( preferencesStore ).get(
			'ai-playground',
			'provider'
		) as string | undefined;
		if ( ! providerId ) {
			return false;
		}
		const availableProviders = select(
			STORE_NAME
		).getAvailableProviders() as AiProviderOption[] | undefined;
		if (
			! availableProviders ||
			! availableProviders.find(
				( { identifier } ) => identifier === providerId
			)
		) {
			return false;
		}
		return providerId;
	} ),

	getModel: createRegistrySelector( ( select ) => () => {
		const modelId = select( preferencesStore ).get(
			'ai-playground',
			'model'
		) as string | undefined;
		if ( ! modelId ) {
			return false;
		}
		const availableModels = select( STORE_NAME ).getAvailableModels() as
			| AiModelOption[]
			| undefined;
		if (
			! availableModels ||
			! availableModels.find(
				( { identifier } ) => identifier === modelId
			)
		) {
			return false;
		}
		return modelId;
	} ),

	getProviderName: createRegistrySelector( ( select ) => () => {
		const providerId = select( preferencesStore ).get(
			'ai-playground',
			'provider'
		) as string | undefined;
		if ( ! providerId ) {
			return false;
		}
		// @ts-expect-error
		const providerMetadata = select( window.wp.aiClient.store ).getProvider(
			providerId
		) as ProviderMetadata | undefined;
		if ( ! providerMetadata ) {
			return false;
		}
		return providerMetadata.name;
	} ),

	getModelName: createRegistrySelector( ( select ) => () => {
		const providerId = select( preferencesStore ).get(
			'ai-playground',
			'provider'
		) as string | undefined;
		if ( ! providerId ) {
			return false;
		}
		const modelId = select( preferencesStore ).get(
			'ai-playground',
			'model'
		) as string | undefined;
		if ( ! modelId ) {
			return false;
		}
		const modelMetadata = select(
			// @ts-expect-error
			window.wp.aiClient.store
		).getProviderModel( providerId, modelId ) as ModelMetadata | undefined;
		if ( ! modelMetadata ) {
			return false;
		}
		return modelMetadata.name;
	} ),

	getModelParam: createRegistrySelector(
		( select ) => ( _state: State, key: string ) => {
			return select( preferencesStore ).get(
				'ai-playground',
				`model_param_${ key }`
			);
		}
	),

	getAvailableProviders: createRegistrySelector( ( select ) => () => {
		const registeredProviders = select(
			// @ts-expect-error
			window.wp.aiClient.store
		).getProviders() as ProviderMetadata[];
		if ( ! registeredProviders.length ) {
			return undefined;
		}

		const requiredCapability = select(
			STORE_NAME
		).getCapability() as Capability;
		const requiredOptions = select( STORE_NAME ).getOptions() as string[];

		const providersWithRelevantModels: ProviderMetadata[] = [];
		for ( const provider of registeredProviders ) {
			const providerModels = select(
				// @ts-expect-error
				window.wp.aiClient.store
			).getProviderModels( provider.id ) as ModelMetadata[];
			if ( ! providerModels.length ) {
				continue;
			}
			const filteredModels = filterAvailableModels(
				providerModels,
				requiredCapability,
				requiredOptions
			);
			if ( ! filteredModels.length ) {
				continue;
			}
			providersWithRelevantModels.push( provider );
		}

		return prepareAvailableProviders( registeredProviders );
	} ),

	getAvailableModels: createRegistrySelector( ( select ) => () => {
		const providerId = select( STORE_NAME ).getProvider() as string | false;
		if ( ! providerId ) {
			return EMPTY_AI_MODEL_OPTION_ARRAY;
		}

		const registeredProviderModels = select(
			// @ts-expect-error
			window.wp.aiClient.store
		).getProviderModels( providerId ) as ModelMetadata[];
		if ( ! registeredProviderModels.length ) {
			return undefined;
		}

		const requiredCapability = select(
			STORE_NAME
		).getCapability() as Capability;
		const requiredOptions = select( STORE_NAME ).getOptions() as string[];
		return filterAvailableModels(
			registeredProviderModels,
			requiredCapability,
			requiredOptions
		);
	} ),

	getSystemInstruction: createRegistrySelector( ( select ) => () => {
		const systemInstruction = select( preferencesStore ).get(
			'ai-playground',
			'system-instruction'
		) as string | undefined;
		if ( ! systemInstruction ) {
			return '';
		}
		return systemInstruction;
	} ),

	isSystemInstructionVisible: createRegistrySelector( ( select ) => () => {
		const isVisible = select( preferencesStore ).get(
			'ai-playground',
			'system-instruction-visible'
		);
		return !! isVisible;
	} ),
};

const storeConfig: StoreConfig<
	State,
	ActionCreators,
	CombinedAction,
	Selectors
> = {
	actions,
	reducer,
	selectors,
};

export default storeConfig;
