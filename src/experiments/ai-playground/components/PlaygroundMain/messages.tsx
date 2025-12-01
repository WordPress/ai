/**
 * WordPress dependencies
 */
import { useSelect, useDispatch } from '@wordpress/data';
import { Flex, Button } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { upload } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { store as playgroundStore } from '../../store';
import type { MessagePart, File } from '../../ai-client-types';
import MessageParts from './message-parts';
import Loader from './loader';
import type {
	AiPlaygroundMessage,
	AiPlaygroundMessageAdditionalData,
	WordPressAttachment,
} from '../../types';

const getModelAuthor = (
	additionalData: AiPlaygroundMessageAdditionalData
) => {
	if ( additionalData.provider?.name && additionalData.model?.name ) {
		return sprintf(
			/* translators: %1$s: service name, %2$s: model name */
			__( '%1$s: %2$s', 'ai' ),
			additionalData.provider.name,
			additionalData.model.name
		);
	}

	if ( additionalData.provider?.name ) {
		return additionalData.provider.name;
	}

	return __( 'AI Model', 'ai' );
};

const findMediaMessagePartsToUpload = (
	parts: MessagePart[],
	existingAttachments: ( WordPressAttachment | null )[]
) => {
	const mediaMessageParts = [];
	for ( let partIndex = 0; partIndex < parts.length; partIndex++ ) {
		const part = parts[ partIndex ];
		if (
			'file' in part &&
			'base64Data' in part.file &&
			part.file.base64Data &&
			! existingAttachments[ partIndex ]
		) {
			mediaMessageParts.push( { partIndex, fileData: part.file } );
		}
	}
	return mediaMessageParts;
};

type MessageProps = {
	message: AiPlaygroundMessage;
	index: number;
	onUploadAttachment: (
		index: number,
		partIndex: number,
		fileData: File
	) => Promise< void >;
};

/**
 * Renders a single message.
 *
 * @since n.e.x.t
 *
 * @param props - The component props.
 * @return The component to be rendered.
 */
function Message( props: MessageProps ) {
	const { message, index, onUploadAttachment } = props;
	const { type, content, ...additionalData } = message;

	const mediaMessagePartsToUpload = useMemo( () => {
		if ( ! content.parts ) {
			return [];
		}
		return findMediaMessagePartsToUpload(
			content.parts,
			additionalData.attachments || []
		);
	}, [ content.parts, additionalData.attachments ] );
	const allowUploadAttachment = mediaMessagePartsToUpload.length > 0;

	const showActions = allowUploadAttachment;

	return (
		<div
			id={ `ai-playground-message-${ index }` }
			className={ `ai-playground__message-container ai-playground__message-container--${ type }` }
		>
			<div
				className={ `ai-playground__message ai-playground__message--${ type }` }
			>
				<div className="ai-playground__message-author">
					{ type === 'user'
						? __( 'You', 'ai' )
						: getModelAuthor( additionalData ) }
				</div>
				<div className="ai-playground__message-content">
					<MessageParts parts={ content.parts } />
				</div>
				{ showActions && (
					<Flex
						className="ai-playground__message-toolbar"
						role="toolbar"
						aria-orientation="horizontal"
						aria-label={ __( 'Additional message actions', 'ai' ) }
						justify="flex-end"
						gap={ 2 }
					>
						{ allowUploadAttachment && (
							<Button
								variant="primary"
								size="small"
								icon={ upload }
								iconSize={ 18 }
								onClick={ () => {
									for ( const {
										partIndex,
										fileData,
									} of mediaMessagePartsToUpload ) {
										onUploadAttachment(
											index,
											partIndex,
											fileData
										);
									}
								} }
								__next40pxDefaultSize
							>
								{ mediaMessagePartsToUpload.length > 1
									? __( 'Save files', 'ai' )
									: __( 'Save file', 'ai' ) }
							</Button>
						) }
					</Flex>
				) }
			</div>
		</div>
	);
}

/**
 * Renders the messages UI.
 *
 * @since n.e.x.t
 *
 * @return The component to be rendered.
 */
export default function Messages() {
	const messages = useSelect(
		( select ) => select( playgroundStore ).getMessages(),
		[]
	);

	const { uploadAttachment } = useDispatch( playgroundStore );

	const messagesContainerRef = useRef< HTMLDivElement | null >( null );

	const scrollIntoView = () => {
		const interval = setInterval( () => {
			if ( messagesContainerRef.current ) {
				/*
				 * Subtract 5px to account for potential half pixel issues.
				 * These can cause the scroll to not reach the bottom, which can then trigger infinite scroll.
				 */
				if (
					messagesContainerRef.current.scrollTop +
						messagesContainerRef.current.clientHeight >=
					messagesContainerRef.current.scrollHeight - 5
				) {
					clearInterval( interval );
					return;
				}
				messagesContainerRef.current.scrollTop =
					messagesContainerRef.current.scrollHeight;
			}
		}, 100 );
		return interval;
	};

	// Scroll to the latest message when the component mounts.
	useEffect( () => {
		const interval = scrollIntoView();

		return () => clearInterval( interval );
	}, [ messages ] );

	const onUploadAttachment = useCallback(
		async ( index: number, partIndex: number, fileData: File ) => {
			await uploadAttachment( index, partIndex, fileData );
		},
		[ uploadAttachment ]
	);

	return (
		<div
			className="ai-playground__messages-container"
			ref={ messagesContainerRef }
		>
			<div className="ai-playground__messages" role="log">
				{ messages.map( ( message, index ) => (
					<Message
						key={ index }
						message={ message }
						index={ index }
						onUploadAttachment={ onUploadAttachment }
					/>
				) ) }
			</div>
			<Loader />
		</div>
	);
}
