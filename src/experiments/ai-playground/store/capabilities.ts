/**
 * External dependencies
 */
import type { StoreConfig, Action, ThunkArgs } from 'wp-store-utils';

/**
 * WordPress dependencies
 */
import { createRegistrySelector } from '@wordpress/data';
import { __, _x } from '@wordpress/i18n';
import { store as preferencesStore } from '@wordpress/preferences';

/**
 * Internal dependencies
 */
import { Capability, Modality } from '../ai-client-enums';
import type { CapabilityOption, OptionOption, ModalityOption } from '../types';

const EMPTY_OPTION_ARRAY: string[] = [];

export enum ActionType {
	Unknown = 'REDUX_UNKNOWN',
	ReceiveCapabilities = 'RECEIVE_CAPABILITIES',
	ReceiveOptions = 'RECEIVE_OPTIONS',
	ReceiveModalities = 'RECEIVE_MODALITIES',
}

type UnknownAction = Action< ActionType.Unknown >;
type ReceiveCapabilitiesAction = Action<
	ActionType.ReceiveCapabilities,
	{ capabilities: CapabilityOption[] }
>;
type ReceiveOptionsAction = Action<
	ActionType.ReceiveOptions,
	{ options: OptionOption[] }
>;
type ReceiveModalitiesAction = Action<
	ActionType.ReceiveModalities,
	{ modalities: ModalityOption[] }
>;

export type CombinedAction =
	| UnknownAction
	| ReceiveCapabilitiesAction
	| ReceiveOptionsAction
	| ReceiveModalitiesAction;

export type State = {
	availableCapabilities: CapabilityOption[];
	availableOptions: OptionOption[];
	availableModalities: ModalityOption[];
};

export type ActionCreators = typeof actions;
export type Selectors = typeof selectors;

type DispatcherArgs = ThunkArgs<
	State,
	ActionCreators,
	CombinedAction,
	Selectors
>;

const initialState: State = {
	availableCapabilities: [],
	availableOptions: [],
	availableModalities: [],
};

const actions = {
	/**
	 * Sets the capability.
	 *
	 * @since n.e.x.t
	 *
	 * @param capability - Capability identifier.
	 * @return Action creator.
	 */
	setCapability( capability: Capability ) {
		return ( { registry }: DispatcherArgs ) => {
			registry
				.dispatch( preferencesStore )
				.set( 'ai-playground', 'capability', capability );
		};
	},

	/**
	 * Toggles one of the options.
	 *
	 * @since n.e.x.t
	 *
	 * @param option - Option identifier.
	 * @return Action creator.
	 */
	toggleOption( option: string ) {
		return ( { registry }: DispatcherArgs ) => {
			const options: string[] | undefined = registry
				.select( preferencesStore )
				.get( 'ai-playground', 'options' );
			if ( ! options ) {
				registry
					.dispatch( preferencesStore )
					.set( 'ai-playground', 'options', [ option ] );
				return;
			}

			if ( options.includes( option ) ) {
				registry.dispatch( preferencesStore ).set(
					'ai-playground',
					'options',
					options.filter( ( opt ) => opt !== option )
				);
			} else {
				registry
					.dispatch( preferencesStore )
					.set( 'ai-playground', 'options', [ ...options, option ] );
			}
		};
	},

	/**
	 * Receives available capabilities.
	 *
	 * @since n.e.x.t
	 *
	 * @param capabilities - Capabilities, as array of objects with `identifier` and `label` properties.
	 * @return Action creator.
	 */
	receiveCapabilities( capabilities: CapabilityOption[] ) {
		return ( { dispatch }: DispatcherArgs ) => {
			dispatch( {
				type: ActionType.ReceiveCapabilities,
				payload: {
					capabilities,
				},
			} );
		};
	},

	/**
	 * Receives available options.
	 *
	 * @since n.e.x.t
	 *
	 * @param options - Options, as array of objects with `identifier` and `label` properties.
	 * @return Action creator.
	 */
	receiveOptions( options: OptionOption[] ) {
		return ( { dispatch }: DispatcherArgs ) => {
			dispatch( {
				type: ActionType.ReceiveOptions,
				payload: {
					options,
				},
			} );
		};
	},

	/**
	 * Receives available modalities.
	 *
	 * @since n.e.x.t
	 *
	 * @param modalities - Modalities, as array of objects with `identifier` and `label` properties.
	 * @return Action creator.
	 */
	receiveModalities( modalities: ModalityOption[] ) {
		return ( { dispatch }: DispatcherArgs ) => {
			dispatch( {
				type: ActionType.ReceiveModalities,
				payload: {
					modalities,
				},
			} );
		};
	},
};

/**
 * Reducer for the store mutations.
 *
 * @since n.e.x.t
 *
 * @param state  - Current state.
 * @param action - Action object.
 * @return New state.
 */
function reducer( state: State = initialState, action: CombinedAction ): State {
	switch ( action.type ) {
		case ActionType.ReceiveCapabilities: {
			const { capabilities } = action.payload;
			return {
				...state,
				availableCapabilities: capabilities,
			};
		}
		case ActionType.ReceiveOptions: {
			const { options } = action.payload;
			return {
				...state,
				availableOptions: options,
			};
		}
		case ActionType.ReceiveModalities: {
			const { modalities } = action.payload;
			return {
				...state,
				availableModalities: modalities,
			};
		}
	}

	return state;
}

const resolvers = {
	/**
	 * Loads capabilities.
	 *
	 * @since n.e.x.t
	 *
	 * @return Action creator.
	 */
	getAvailableCapabilities() {
		return async ( { dispatch }: DispatcherArgs ) => {
			const capabilities: CapabilityOption[] = [
				{
					identifier: Capability.TEXT_GENERATION,
					label: __( 'Text generation', 'ai' ),
				},
				{
					identifier: Capability.IMAGE_GENERATION,
					label: __( 'Image generation', 'ai' ),
				},
				{
					identifier: Capability.TEXT_TO_SPEECH_CONVERSION,
					label: __( 'Text to speech', 'ai' ),
				},
			];

			dispatch.receiveCapabilities( capabilities );
		};
	},

	/**
	 * Loads options.
	 *
	 * @since n.e.x.t
	 *
	 * @return Action creator.
	 */
	getAvailableOptions() {
		return async ( { dispatch }: DispatcherArgs ) => {
			const options: OptionOption[] = [
				{
					identifier: 'maxTokens',
					label: __( 'Max output tokens', 'ai' ),
				},
				{
					identifier: 'temperature',
					label: __( 'Temperature', 'ai' ),
				},
				{
					identifier: 'functionDeclarations',
					label: __( 'Tool calling', 'ai' ),
				},
				{
					identifier: 'webSearch',
					label: __( 'Web search', 'ai' ),
				},
				{
					identifier: 'multimodalInput',
					label: __( 'Multimodal input', 'ai' ),
				},
				{
					identifier: 'multimodalOutput',
					label: __( 'Multimodal output', 'ai' ),
				},
			];

			dispatch.receiveOptions( options );
		};
	},
	/**
	 * Loads modalities.
	 *
	 * @since n.e.x.t
	 *
	 * @return Action creator.
	 */
	getAvailableModalities() {
		return async ( { dispatch }: DispatcherArgs ) => {
			const modalities: ModalityOption[] = [
				{
					identifier: Modality.TEXT,
					label: _x( 'Text', 'modality', 'ai' ),
				},
				{
					identifier: Modality.IMAGE,
					label: _x( 'Image', 'modality', 'ai' ),
				},
				{
					identifier: Modality.AUDIO,
					label: _x( 'Audio', 'modality', 'ai' ),
				},
			];

			dispatch.receiveModalities( modalities );
		};
	},
};

const selectors = {
	getCapability: createRegistrySelector( ( select ) => () => {
		const cap = select( preferencesStore ).get(
			'ai-playground',
			'capability'
		) as Capability | undefined;
		if ( ! cap ) {
			return Capability.TEXT_GENERATION;
		}
		return cap;
	} ),

	getOptions: createRegistrySelector( ( select ) => () => {
		const options = select( preferencesStore ).get(
			'ai-playground',
			'options'
		) as string[] | undefined;
		if ( ! options ) {
			return EMPTY_OPTION_ARRAY;
		}
		return options;
	} ),

	getAvailableCapabilities: ( state: State ) => state.availableCapabilities,

	getAvailableOptions: ( state: State ) => state.availableOptions,

	getAvailableModalities: ( state: State ) => state.availableModalities,
};

const storeConfig: StoreConfig<
	State,
	ActionCreators,
	CombinedAction,
	Selectors
> = {
	initialState,
	actions,
	reducer,
	resolvers,
	selectors,
};

export default storeConfig;
