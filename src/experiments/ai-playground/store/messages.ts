/**
 * External dependencies
 */
import type { StoreConfig, Action, ThunkArgs } from 'wp-store-utils';

/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { uploadMedia } from '@wordpress/media-utils';

/**
 * Internal dependencies
 */
import { STORE_NAME } from './name';
import {
	fileToBase64DataUrl,
	base64DataUrlToBlob,
	logError,
	errorToString,
} from '../helpers';
import {
	Capability,
	MessageRole,
	MessagePartType,
	MessagePartChannel,
	FileType,
	type MediaOrientation,
	type Modality,
} from '../ai-client-enums';
import type {
	Message,
	File as FileDto,
	ProviderMetadata,
	ModelMetadata,
	ModelConfig,
} from '../ai-client-types';
import type {
	AiPlaygroundMessage,
	AiPlaygroundMessageAdditionalData,
	WordPressAttachment,
} from '../types';

const EMPTY_MESSAGE_ARRAY: AiPlaygroundMessage[] = [];

const FEATURE_SLUG = 'ai-playground';
const HISTORY_SLUG = 'default';
const UPLOAD_ATTACHMENT_NOTICE_ID = 'UPLOAD_ATTACHMENT_NOTICE_ID';

const prepareMessageForCache = (
	message: Message,
	attachments: ( WordPressAttachment | null )[]
): Message => {
	return {
		...message,
		parts: message.parts.map( ( part, partIndex ) => {
			/*
			 * For inline data where the attachment is known, strip the actual base64 data to save space.
			 * Otherwise, the data may be too large for session storage.
			 */
			if (
				'file' in part &&
				'base64Data' in part.file &&
				part.file.base64Data &&
				attachments[ partIndex ]
			) {
				const { base64Data, ...otherInlineData } = part.file;
				return {
					...part,
					file: {
						...otherInlineData,
						base64Data: '',
					},
				};
			}
			return part;
		} ),
	};
};

const parseMessageFromCache = async (
	message: Message,
	attachments: ( WordPressAttachment | null )[]
): Promise< Message > => {
	return {
		...message,
		parts: await Promise.all(
			message.parts.map( async ( part, partIndex ) => {
				// For inline data where the attachment is known but base64 data was stripped before cache, restore it.
				if (
					'file' in part &&
					'base64Data' in part.file &&
					! part.file.base64Data &&
					attachments[ partIndex ]
				) {
					return {
						...part,
						file: {
							...part.file,
							base64Data: await fileToBase64DataUrl(
								attachments[ partIndex ].sizes?.large?.url ||
									attachments[ partIndex ].url
							),
						},
					};
				}
				return part;
			} )
		),
	};
};

const preparePlaygroundMessageForCache = (
	message: AiPlaygroundMessage
): AiPlaygroundMessage => {
	// We can only optimize messages with inline media if they have the attachments specified.
	if ( ! message.attachments ) {
		return message;
	}

	const prepared = {
		...message,
		content: prepareMessageForCache( message.content, message.attachments ),
	};
	return prepared;
};

const parsePlaygroundMessageFromCache = async (
	message: AiPlaygroundMessage
): Promise< AiPlaygroundMessage > => {
	// We can only parse messages with inline media if they have the attachments specified.
	if ( ! message.attachments ) {
		return message;
	}

	const parsed = {
		...message,
		content: await parseMessageFromCache(
			message.content,
			message.attachments
		),
	};
	return parsed;
};

const retrieveMessages = async (): Promise< AiPlaygroundMessage[] > => {
	const history = await helpers
		.historyPersistence()
		.loadHistory( FEATURE_SLUG, HISTORY_SLUG );
	if ( history && history.entries ) {
		const entries = await Promise.all(
			( history.entries as AiPlaygroundMessage[] ).map(
				parsePlaygroundMessageFromCache
			)
		);

		return entries;
	}
	return EMPTY_MESSAGE_ARRAY;
};

const storeMessages = async ( messages: AiPlaygroundMessage[] ) => {
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	const history = {
		feature: FEATURE_SLUG,
		slug: HISTORY_SLUG,
		lastUpdated: '',
		entries: messages.map( preparePlaygroundMessageForCache ),
	};
	// TODO: Handle storing history.
};

const clearMessages = async () => {
	// TODO: Handle clearing history.
};

const formatNewMessage = async (
	prompt: string,
	attachments?: WordPressAttachment[]
): Promise< Message > => {
	if ( attachments && attachments.length ) {
		return {
			role: MessageRole.USER,
			parts: [
				{
					channel: MessagePartChannel.CONTENT,
					type: MessagePartType.TEXT,
					text: prompt,
				},
				...( await Promise.all(
					attachments.map( async ( attachment ) => {
						const mimeType = attachment.mime;
						const base64DataUrl = await fileToBase64DataUrl(
							attachment.sizes?.large?.url || attachment.url
						);
						return {
							channel: MessagePartChannel.CONTENT,
							type: MessagePartType.FILE,
							file: {
								fileType: FileType.INLINE,
								mimeType,
								base64Data: base64DataUrl.replace(
									/^data:[a-z0-9-]+\/[a-z0-9-]+;base64,/,
									''
								),
							},
						};
					} )
				) ),
			],
		};
	}
	return {
		role: MessageRole.USER,
		parts: [
			{
				channel: MessagePartChannel.CONTENT,
				type: MessagePartType.TEXT,
				text: prompt,
			},
		],
	};
};

const formatErrorMessage = ( error: unknown ): Message => {
	return {
		role: MessageRole.MODEL,
		parts: [
			{
				channel: MessagePartChannel.CONTENT,
				type: MessagePartType.TEXT,
				text: errorToString( error ),
			},
		],
	};
};

const generateFilename = (
	partIndex: number,
	mimeType: string,
	providerId?: string,
	modelId?: string
) => {
	let extension = mimeType.split( '/' )[ 1 ];
	if ( extension === 'jpeg' ) {
		extension = 'jpg';
	}

	let source = '';
	if ( providerId ) {
		source = `${ providerId }-`;
		if ( modelId ) {
			source += `${ modelId }-`;
		}
	}

	const now = new Date();
	const dateSuffix = now
		.toISOString()
		.substring( 0, 19 )
		.replace( 'T', '-' )
		.replace( /:/g, '' );

	return `ai-generated-${ partIndex }-${ source }${ dateSuffix }.${ extension }`;
};

const getFreshPartsAttachments = (
	message: AiPlaygroundMessage,
	partIndex: number,
	attachment: WordPressAttachment
) => {
	const attachments = [ ...( message.attachments || [] ) ];
	if ( attachments.length < message.content.parts.length ) {
		const missingIndexes =
			message.content.parts.length - attachments.length;
		for ( let i = 0; i < missingIndexes; i++ ) {
			attachments.push( null );
		}
	}
	attachments[ partIndex ] = attachment;
	return attachments;
};

export enum ActionType {
	Unknown = 'REDUX_UNKNOWN',
	ReceiveMessage = 'RECEIVE_MESSAGE',
	ReceiveMessagesFromCache = 'RECEIVE_MESSAGES_FROM_CACHE',
	ResetMessages = 'RESET_MESSAGES',
	SetActiveMessage = 'SET_ACTIVE_MESSAGE',
	SetMessageAttachment = 'SET_MESSAGE_ATTACHMENT',
	LoadStart = 'LOAD_START',
	LoadFinish = 'LOAD_FINISH',
}

type UnknownAction = Action< ActionType.Unknown >;
type ReceiveMessageAction = Action<
	ActionType.ReceiveMessage,
	{
		type: AiPlaygroundMessage[ 'type' ];
		content: AiPlaygroundMessage[ 'content' ];
		additionalData: AiPlaygroundMessageAdditionalData;
	}
>;
type ReceiveMessagesFromCacheAction = Action<
	ActionType.ReceiveMessagesFromCache,
	{ messages: AiPlaygroundMessage[] }
>;
type ResetMessagesAction = Action< ActionType.ResetMessages >;
type SetActiveMessageAction = Action<
	ActionType.SetActiveMessage,
	{ message: AiPlaygroundMessage }
>;
type SetMessageAttachmentAction = Action<
	ActionType.SetMessageAttachment,
	{
		index: number;
		partIndex: number;
		attachment: WordPressAttachment;
	}
>;
type LoadStartAction = Action< ActionType.LoadStart >;
type LoadFinishAction = Action< ActionType.LoadFinish >;

export type CombinedAction =
	| UnknownAction
	| ReceiveMessageAction
	| ReceiveMessagesFromCacheAction
	| ResetMessagesAction
	| SetActiveMessageAction
	| SetMessageAttachmentAction
	| LoadStartAction
	| LoadFinishAction;

export type State = {
	messages: AiPlaygroundMessage[] | undefined;
	loading: boolean;
	activeMessage: AiPlaygroundMessage | null;
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
	messages: undefined,
	loading: false,
	activeMessage: null,
};

const actions = {
	/**
	 * Sends a message.
	 *
	 * @since n.e.x.t
	 * @since n.e.x.t Now expects an array of attachments instead of a single attachment.
	 *
	 * @param prompt         - Message prompt.
	 * @param attachments    - Optional array of attachment objects.
	 * @param includeHistory - Whether to include the message history before the prompt. Default false.
	 * @return Action creator.
	 */
	sendMessage(
		prompt: string,
		attachments?: WordPressAttachment[],
		includeHistory?: boolean
	) {
		return async ( { registry, dispatch, select }: DispatcherArgs ) => {
			const providerId = registry.select( STORE_NAME ).getProvider();
			const modelId = registry.select( STORE_NAME ).getModel();
			if ( ! providerId || ! modelId ) {
				logError( 'No AI provider or model selected.' );
				return;
			}

			const modelConfig: ModelConfig = {};

			const capability = registry
				.select( STORE_NAME )
				.getCapability() as Capability;

			if ( capability === Capability.TEXT_TO_SPEECH_CONVERSION ) {
				const voice = registry
					.select( STORE_NAME )
					.getModelParam( 'outputSpeechVoice' ) as string;
				if ( voice ) {
					modelConfig.outputSpeechVoice = voice;
				}
			} else if ( capability === Capability.IMAGE_GENERATION ) {
				const outputMediaOrientation = registry
					.select( STORE_NAME )
					.getModelParam(
						'outputMediaOrientation'
					) as MediaOrientation;
				if ( outputMediaOrientation ) {
					modelConfig.outputMediaOrientation = outputMediaOrientation;
				}
			} else if ( capability === Capability.TEXT_GENERATION ) {
				const maxOutputTokens = registry
					.select( STORE_NAME )
					.getModelParam( 'maxTokens' );
				if ( maxOutputTokens ) {
					modelConfig.maxTokens = Number( maxOutputTokens );
				}
				const temperature = registry
					.select( STORE_NAME )
					.getModelParam( 'temperature' );
				if ( temperature ) {
					modelConfig.temperature = Number( temperature );
				}
				const topP = registry
					.select( STORE_NAME )
					.getModelParam( 'topP' );
				if ( topP ) {
					modelConfig.topP = Number( topP );
				}

				const outputModalities = registry
					.select( STORE_NAME )
					.getModelParam( 'outputModalities' ) as Modality[];
				if (
					Array.isArray( outputModalities ) &&
					outputModalities.length
				) {
					modelConfig.outputModalities = outputModalities;
				}
			}

			const systemInstruction = registry
				.select( STORE_NAME )
				.getSystemInstruction();
			if ( systemInstruction ) {
				modelConfig.systemInstruction = systemInstruction;
			}

			const originalMessages = select.getMessages();

			const newMessage = await formatNewMessage( prompt, attachments );

			let contentToSend: Message | Message[] = newMessage;
			if ( includeHistory ) {
				if ( originalMessages && originalMessages.length ) {
					contentToSend = [
						...originalMessages.map(
							( message ) => message.content
						),
						newMessage,
					];
				}
			}

			dispatch( {
				type: ActionType.LoadStart,
				payload: {},
			} );

			const providerMetadata = registry
				.select(
					// @ts-expect-error
					window.wp.aiClient.store
				)
				.getProvider( providerId ) as ProviderMetadata | undefined;
			const modelMetadata = registry
				.select(
					// @ts-expect-error
					window.wp.aiClient.store
				)
				.getProviderModel( providerId, modelId ) as
				| ModelMetadata
				| undefined;

			// @ts-expect-error
			const promptBuilder = window.wp.aiClient
				.prompt( contentToSend )
				.usingModel( providerId, modelId );
			if ( Object.keys( modelConfig ).length ) {
				promptBuilder.usingModelConfig( modelConfig );
			}

			const additionalData: AiPlaygroundMessageAdditionalData = {
				capability,
				provider: {
					id: providerId,
					name: providerMetadata?.name || providerId,
				},
				model: {
					id: modelId,
					name: modelMetadata?.name || modelId,
				},
			};

			const additionalPromptData: AiPlaygroundMessageAdditionalData = {
				...additionalData,
			};
			if ( attachments && attachments.length ) {
				additionalPromptData.attachments = [
					null, // Based on `formatNewMessage()`, the first part is always text, i.e. no related attachment.
					...attachments,
				];
			}

			dispatch.receiveMessage( 'user', newMessage, additionalPromptData );

			let responseMessage: Message | undefined;
			try {
				switch ( capability ) {
					case Capability.IMAGE_GENERATION:
						const imageResult =
							await promptBuilder.generateImageResult();
						responseMessage = imageResult.toMessage() as Message;
						break;
					case Capability.TEXT_TO_SPEECH_CONVERSION:
						const textToSpeechResult =
							await promptBuilder.convertTextToSpeechResult();
						responseMessage =
							textToSpeechResult.toMessage() as Message;
						break;
					default:
						const textResult =
							await promptBuilder.generateTextResult();
						responseMessage = textResult.toMessage() as Message;
				}

				dispatch.receiveMessage( 'model', responseMessage, {
					...additionalData,
				} );
			} catch ( error ) {
				dispatch.receiveMessage( 'error', formatErrorMessage( error ) );
			}

			dispatch( {
				type: ActionType.LoadFinish,
				payload: {},
			} );

			return responseMessage;
		};
	},

	/**
	 * Uploads inline data of a specific message to the media library.
	 *
	 * @since n.e.x.t
	 *
	 * @param index     - The index of the message.
	 * @param partIndex - The index of the part within the message.
	 * @param fileData  - The file object.
	 * @return Action creator.
	 */
	uploadAttachment( index: number, partIndex: number, fileData: FileDto ) {
		return async ( { dispatch, registry, select }: DispatcherArgs ) => {
			const messages = select.getMessages();
			const message = messages?.[ index ];
			if ( ! message ) {
				return;
			}

			// Sanity check that it's the correct message.
			const filePart = message.content.parts?.[ partIndex ];
			if (
				! ( 'file' in filePart ) ||
				filePart.file.base64Data !== fileData.base64Data
			) {
				return;
			}

			const fileBlob = await base64DataUrlToBlob(
				`data:${ fileData.mimeType };base64,${ fileData.base64Data }`
			);
			if ( ! fileBlob ) {
				logError( 'Could not transform base64 data URL to blob.' );
				return;
			}

			const file = new File(
				[ fileBlob ],
				generateFilename(
					partIndex,
					fileBlob.type,
					message.provider?.id,
					message.model?.id
				),
				{
					type: fileBlob.type,
					lastModified: new Date().getTime(),
				}
			);

			const attachmentData: { caption?: string } = {};
			if ( message.type === 'model' ) {
				const previousMessage = messages?.[ index - 1 ];
				if ( previousMessage && previousMessage.type === 'user' ) {
					if (
						'text' in ( previousMessage.content.parts?.[ 0 ] || {} )
					) {
						const prompt =
							previousMessage.content.parts?.[ 0 ].text;
						if ( prompt ) {
							attachmentData.caption = sprintf(
								/* translators: %s: prompt text */
								_x(
									'Generated for prompt: %s',
									'attachment caption',
									'ai'
								),
								prompt
							);
						}
					}
				}
			}

			return new Promise( ( resolve ) => {
				uploadMedia( {
					filesList: [ file ],
					// @ts-expect-error WordPress expecting the `RestAttachment` type here is incorrect.
					additionalData: attachmentData,
					onFileChange: ( [ attachment ] ) => {
						if ( ! attachment ) {
							registry
								.dispatch( noticesStore )
								.createErrorNotice(
									__( 'Saving file failed.', 'ai' ),
									{
										id: UPLOAD_ATTACHMENT_NOTICE_ID,
										type: 'snackbar',
										speak: true,
									}
								);
							resolve( null );
							return;
						}
						if ( attachment.id ) {
							dispatch.setMessageAttachment(
								index,
								partIndex,
								attachment as WordPressAttachment
							);
							registry
								.dispatch( noticesStore )
								.createSuccessNotice(
									__( 'File saved to media library.', 'ai' ),
									{
										id: UPLOAD_ATTACHMENT_NOTICE_ID,
										type: 'snackbar',
										speak: true,
									}
								);
							resolve( attachment );
						}
					},
					onError: ( err ) => {
						registry.dispatch( noticesStore ).createErrorNotice(
							sprintf(
								/* translators: %s: error message */
								__( 'Saving file failed with error: %s', 'ai' ),
								errorToString( err )
							),
							{
								id: UPLOAD_ATTACHMENT_NOTICE_ID,
								type: 'snackbar',
								speak: true,
							}
						);
						resolve( null );
					},
				} );
			} );
		};
	},

	/**
	 * Receives new content to append to the list of messages.
	 *
	 * @since n.e.x.t
	 *
	 * @param type           - Message type. Either 'user', 'model', or 'error'.
	 * @param content        - Message content.
	 * @param additionalData - Additional data to include with the message.
	 * @return Action creator.
	 */
	receiveMessage(
		type: AiPlaygroundMessage[ 'type' ],
		content: AiPlaygroundMessage[ 'content' ],
		additionalData: AiPlaygroundMessageAdditionalData = {}
	) {
		return ( { dispatch }: DispatcherArgs ) => {
			dispatch( {
				type: ActionType.ReceiveMessage,
				payload: { type, content, additionalData },
			} );
		};
	},

	/**
	 * Receives messages from cache to restore the session.
	 *
	 * @since n.e.x.t
	 *
	 * @param messages - Messages to restore.
	 * @return Action creator.
	 */
	receiveMessagesFromCache( messages: AiPlaygroundMessage[] ) {
		return ( { dispatch }: DispatcherArgs ) => {
			dispatch( {
				type: ActionType.ReceiveMessagesFromCache,
				payload: { messages },
			} );
		};
	},

	/**
	 * Resets all messages, effectively deleting them to start a new session.
	 *
	 * @since n.e.x.t
	 *
	 * @return Action creator.
	 */
	resetMessages() {
		return ( { dispatch }: DispatcherArgs ) => {
			dispatch( {
				type: ActionType.ResetMessages,
				payload: {},
			} );
		};
	},

	/**
	 * Sets the active message (to display a modal for it).
	 *
	 * @since n.e.x.t
	 *
	 * @param message - Message to display.
	 * @return Action creator.
	 */
	setActiveMessage( message: AiPlaygroundMessage ) {
		return ( { dispatch }: DispatcherArgs ) => {
			dispatch( {
				type: ActionType.SetActiveMessage,
				payload: { message },
			} );
		};
	},

	/**
	 * Sets the attachment for a message.
	 *
	 * @since n.e.x.t
	 *
	 * @param index      - The index of the message.
	 * @param partIndex  - The index of the part within the message.
	 * @param attachment - The attachment object.
	 * @return Action creator.
	 */
	setMessageAttachment(
		index: number,
		partIndex: number,
		attachment: WordPressAttachment
	) {
		return ( { dispatch }: DispatcherArgs ) => {
			dispatch( {
				type: ActionType.SetMessageAttachment,
				payload: { index, partIndex, attachment },
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
		case ActionType.ReceiveMessage: {
			const { type, content, additionalData } = action.payload;
			const newMessage: AiPlaygroundMessage = { type, content };
			if ( additionalData ) {
				newMessage.capability = additionalData.capability;
				newMessage.provider = additionalData.provider;
				newMessage.model = additionalData.model;
				if ( additionalData.attachments ) {
					newMessage.attachments = additionalData.attachments;
				}
			}

			const messages = [ ...( state.messages || [] ), newMessage ];
			storeMessages( messages );
			return {
				...state,
				messages,
			};
		}
		case ActionType.ReceiveMessagesFromCache: {
			const { messages } = action.payload;
			return {
				...state,
				messages,
			};
		}
		case ActionType.ResetMessages: {
			clearMessages();
			return {
				...state,
				messages: [],
			};
		}
		case ActionType.SetActiveMessage: {
			const { message } = action.payload;
			return {
				...state,
				activeMessage: message,
			};
		}
		case ActionType.SetMessageAttachment: {
			const { index, partIndex, attachment } = action.payload;
			if ( state.messages?.[ index ] ) {
				const messages = [ ...state.messages ];
				messages[ index ] = {
					...messages[ index ],
					attachments: getFreshPartsAttachments(
						messages[ index ],
						partIndex,
						attachment
					),
				};
				storeMessages( messages );
				return {
					...state,
					messages,
				};
			}
			return state;
		}
		case ActionType.LoadStart: {
			return {
				...state,
				loading: true,
			};
		}
		case ActionType.LoadFinish: {
			return {
				...state,
				loading: false,
			};
		}
	}

	return state;
}

const resolvers = {
	/**
	 * Retrieves messages from session storage.
	 *
	 * @since n.e.x.t
	 *
	 * @return Action creator.
	 */
	getMessages() {
		return async ( { dispatch }: DispatcherArgs ) => {
			const messages = await retrieveMessages();
			dispatch.receiveMessagesFromCache( messages );
		};
	},
};

const selectors = {
	getMessages: ( state: State ) => {
		return state.messages || EMPTY_MESSAGE_ARRAY;
	},

	isLoading: ( state: State ) => {
		return state.loading || state.messages === undefined;
	},

	getActiveMessage: ( state: State ) => {
		return state.activeMessage;
	},
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
