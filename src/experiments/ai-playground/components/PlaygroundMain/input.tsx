/**
 * WordPress dependencies
 */
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { Flex, Notice, ToggleControl } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useState,
	useRef,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { send, upload, close } from '@wordpress/icons';
import { UP, DOWN } from '@wordpress/keycodes';

/**
 * Internal dependencies
 */
import { store as playgroundStore } from '../../store';
import MediaModal from './media-modal';
import { Capability } from '../../ai-client-enums';
import type { ModelMetadata, SupportedOption } from '../../ai-client-types';
import type { AiPlaygroundMessage, WordPressAttachment } from '../../types';

const EMPTY_CAPABILITY_ARRAY: Capability[] = [];
const EMPTY_OPTION_ARRAY: SupportedOption[] = [];

const matchMessage = ( message: AiPlaygroundMessage, prompt: string ) => {
	if ( message.type !== 'user' ) {
		return '';
	}

	if ( prompt === '' && 'text' in message.content.parts[ 0 ] ) {
		return message.content.parts[ 0 ].text;
	}

	for ( let j = 0; j < message.content.parts.length; j++ ) {
		const part = message.content.parts[ j ];
		if (
			'text' in part &&
			part.text.startsWith( prompt ) &&
			part.text !== prompt
		) {
			return part.text;
		}
	}

	return '';
};

const matchLastMessage = (
	messages: AiPlaygroundMessage[],
	prompt: string,
	matchedIndex = -1,
	searchForwards = false
): [ number, string ] => {
	if ( ! messages || ! messages.length ) {
		return [ -1, '' ];
	}

	if ( searchForwards ) {
		const startIndex = matchedIndex === -1 ? 0 : matchedIndex + 1;
		for ( let i = startIndex; i < messages.length; i++ ) {
			const match = matchMessage( messages[ i ], prompt );
			if ( match ) {
				return [ i, match ];
			}
		}
	} else {
		const startIndex =
			matchedIndex === -1 ? messages.length - 1 : matchedIndex - 1;
		for ( let i = startIndex; i >= 0; i-- ) {
			const match = matchMessage( messages[ i ], prompt );
			if ( match ) {
				return [ i, match ];
			}
		}
	}

	return [ -1, '' ];
};

/**
 * Renders the prompt input UI.
 *
 * @since n.e.x.t
 *
 * @return The component to be rendered.
 */
export default function Input() {
	const [ prompt, setPrompt ] = useState( '' );
	const [ attachments, setAttachments ] = useState< WordPressAttachment[] >(
		[]
	);
	const [ includeHistory, setIncludeHistory ] = useState( false );
	const [ promptToMatch, setPromptToMatch ] = useState< string | false >(
		false
	);
	const [ matchedIndex, setMatchedIndex ] = useState( -1 );

	const {
		provider,
		model,
		supportedCapabilities,
		supportedOptionsMap,
		messages,
		canUploadMedia,
	} = useSelect( ( select ) => {
		const { getProvider, getModel, getMessages } =
			select( playgroundStore );

		// @ts-expect-error
		const { getProviderModel } = select( window.wp.aiClient.store );

		const currentProvider = getProvider();
		const currentModel = getModel();

		let currentCapabilities = EMPTY_CAPABILITY_ARRAY;
		let currentOptions = EMPTY_OPTION_ARRAY;
		const currentOptionsMap: Record< string, unknown[] | undefined > = {};
		if ( currentProvider && currentModel ) {
			const modelMetadata = getProviderModel() as
				| ModelMetadata
				| undefined;
			if ( modelMetadata ) {
				currentCapabilities = modelMetadata.supportedCapabilities;
				currentOptions = modelMetadata.supportedOptions;
				currentOptions.forEach( ( option ) => {
					currentOptionsMap[ option.name ] = option.supportedValues;
				} );
			}
		}

		const { canUser } = select( coreStore );

		return {
			provider: currentProvider,
			model: currentModel,
			supportedCapabilities: currentCapabilities,
			supportedOptionsMap: currentOptionsMap,
			messages: getMessages(),
			canUploadMedia:
				canUser( 'create', {
					kind: 'postType',
					name: 'attachment',
				} ) ?? true,
		};
	}, [] );

	const { sendMessage } = useDispatch( playgroundStore );

	const disabled =
		! provider ||
		! model ||
		( ! prompt && ( ! attachments || ! attachments.length ) );

	const sendPrompt = async () => {
		if ( disabled ) {
			return;
		}

		setPromptToMatch( false );
		setMatchedIndex( -1 );
		setPrompt( '' );
		setAttachments( [] );
		await sendMessage(
			prompt,
			supportedOptionsMap?.inputModalities?.length &&
				supportedOptionsMap?.inputModalities?.length > 1
				? attachments
				: undefined,
			supportedCapabilities.includes( Capability.CHAT_HISTORY )
				? includeHistory
				: false
		);
	};

	const searchLastMessage = useCallback(
		( event: KeyboardEvent ) => {
			if ( event.keyCode === UP || event.keyCode === DOWN ) {
				if ( false === promptToMatch ) {
					setPromptToMatch( prompt );
				}
				const [ foundIndex, matchedMessage ] = matchLastMessage(
					messages,
					false === promptToMatch ? prompt : promptToMatch,
					matchedIndex,
					event.keyCode === DOWN
				);
				if ( matchedMessage ) {
					setPrompt( matchedMessage );
					setMatchedIndex( foundIndex );
				}
			}
		},
		[ messages, prompt, promptToMatch, matchedIndex ]
	);

	// If the last message is a function call, allow providing JSON data as a prompt for the function response.
	const allowFunctionResponse = useMemo( () => {
		if ( ! supportedOptionsMap.functionDeclarations ) {
			return false;
		}

		if ( ! messages || ! messages.length ) {
			return false;
		}

		const lastMessage = messages[ messages.length - 1 ];

		if ( lastMessage.type !== 'model' ) {
			return false;
		}

		return !! lastMessage.content?.parts?.some(
			( part ) => 'functionCall' in part
		);
	}, [ supportedOptionsMap.functionDeclarations, messages ] );

	const inputRef = useRef< HTMLTextAreaElement | null >( null );

	useEffect( () => {
		if ( ! inputRef.current ) {
			return;
		}

		inputRef.current.focus();
	}, [ inputRef ] );

	useEffect( () => {
		if ( ! inputRef.current ) {
			return;
		}

		const inputElement = inputRef.current;
		inputElement.addEventListener( 'keydown', searchLastMessage );
		return () => {
			inputElement.removeEventListener( 'keydown', searchLastMessage );
		};
	}, [ inputRef, searchLastMessage ] );

	let inputPlaceholder: string;
	if ( allowFunctionResponse && includeHistory ) {
		inputPlaceholder = __(
			'Enter AI prompt or JSON data for a function response',
			'ai'
		);
	} else if (
		supportedCapabilities.includes( Capability.TEXT_TO_SPEECH_CONVERSION )
	) {
		inputPlaceholder = __( 'Enter AI text to transform to speech', 'ai' );
	} else if (
		supportedCapabilities.includes( Capability.IMAGE_GENERATION )
	) {
		inputPlaceholder = __( 'Enter AI prompt to generate images', 'ai' );
	} else if ( supportedCapabilities.includes( Capability.TEXT_GENERATION ) ) {
		inputPlaceholder = __( 'Enter AI prompt to generate content', 'ai' );
	} else {
		inputPlaceholder = __( 'Enter AI prompt', 'ai' );
	}

	const attachmentIds = useMemo(
		() => attachments.map( ( attachment ) => attachment.id ),
		[ attachments ]
	);

	const removeAttachment = ( indexToRemove: number ) => {
		setAttachments( ( prevAttachments ) =>
			prevAttachments.filter( ( _, index ) => index !== indexToRemove )
		);
	};

	return (
		<div className="ai-playground__input-backdrop">
			<div className="ai-playground__input-container">
				<textarea
					className="ai-playground__input"
					ref={ inputRef }
					placeholder={ inputPlaceholder }
					aria-label={ __( 'AI prompt', 'ai' ) }
					value={ prompt }
					onChange={ ( event ) => setPrompt( event.target.value ) }
					rows={ 2 }
				/>
				<Flex direction="column" gap="2">
					{ supportedOptionsMap?.inputModalities?.length &&
						supportedOptionsMap?.inputModalities?.length > 1 &&
						attachments.length > 0 && (
							<Flex justify="flex-start" gap="2">
								{ attachments.map( ( attachment, index ) => (
									<div
										key={ attachment.id || index }
										className="ai-playground__input-attachment"
									>
										<img
											className="attachment-preview"
											src={
												attachment.sizes?.thumbnail
													?.url || attachment.icon
											}
											alt={ sprintf(
												/* translators: %s: attachment filename */
												__( 'Selected file: %s', 'ai' ),
												attachment.filename as string
											) }
											width="80"
											height="80"
										/>
										<button
											className="attachment-remove-button"
											aria-label={ __(
												'Remove selected media',
												'ai'
											) }
											onClick={ () =>
												removeAttachment( index )
											}
										>
											{ close }
										</button>
									</div>
								) ) }
							</Flex>
						) }
					{ !! ( allowFunctionResponse && ! includeHistory ) && (
						<div className="ai-playground__input-notices">
							<Notice status="info" isDismissible={ false }>
								{ __(
									'In order to send a function response for the received function call, you need to enable message history below.',
									'ai'
								) }
							</Notice>
						</div>
					) }
					<div className="ai-playground__input-actions">
						<div className="ai-playground__input-action-group">
							{ supportedOptionsMap?.inputModalities?.length &&
								supportedOptionsMap?.inputModalities?.length >
									1 &&
								canUploadMedia && (
									<MediaModal
										onSelect={ setAttachments }
										allowedTypes={ [ 'image' ] }
										multiple
										attachmentIds={ attachmentIds }
										render={ ( renderProps: {
											open: () => void;
										} ) => {
											const { open } = renderProps;
											return (
												<button
													className="ai-playground__input-action ai-playground__input-action--secondary"
													aria-label={ __(
														'Select media for multimodal prompt',
														'ai'
													) }
													onClick={ open }
												>
													{ upload }
												</button>
											);
										} }
									/>
								) }
							{ supportedOptionsMap?.inputModalities?.length &&
								supportedOptionsMap?.inputModalities?.length >
									1 &&
								! canUploadMedia && (
									<button
										className="ai-playground__input-action ai-playground__input-action--secondary"
										aria-label={ __(
											'Missing required permissions to select media',
											'ai'
										) }
										disabled
										onClick={ () => {} }
									>
										{ upload }
									</button>
								) }
							{ supportedCapabilities.includes(
								Capability.CHAT_HISTORY
							) && (
								<ToggleControl
									__nextHasNoMarginBottom
									className="ai-playground__input-action ai-playground__input-action--complex"
									label={ __(
										'Send message history with the prompt',
										'ai'
									) }
									checked={ includeHistory }
									onChange={ () =>
										setIncludeHistory( ! includeHistory )
									}
								/>
							) }
						</div>
						<div className="ai-playground__input-action-group">
							<button
								className="ai-playground__input-action ai-playground__input-action--primary"
								aria-label={ __( 'Send AI prompt', 'ai' ) }
								disabled={ disabled }
								onClick={ sendPrompt }
							>
								{ send }
							</button>
						</div>
					</div>
				</Flex>
			</div>
		</div>
	);
}
