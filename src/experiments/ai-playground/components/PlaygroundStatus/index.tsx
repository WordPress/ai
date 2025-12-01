/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { store as playgroundStore } from '../../store';

/**
 * Renders the playground status text in a paragraph.
 *
 * @since n.e.x.t
 *
 * @return The component to be rendered.
 */
export default function PlaygroundStatus() {
	const { provider, model, messages, loading, providerName, modelName } =
		useSelect( ( select ) => {
			const {
				getProvider,
				getModel,
				getProviderName,
				getModelName,
				getMessages,
				isLoading,
			} = select( playgroundStore );

			return {
				provider: getProvider(),
				model: getModel(),
				messages: getMessages(),
				loading: isLoading(),
				providerName: getProviderName() as string,
				modelName: getModelName() as string,
			};
		}, [] );

	const [ messageCount, setMessageCount ] = useState< number | false >(
		false
	);
	const [ messageStatus, setMessageStatus ] = useState( '' );

	useEffect( () => {
		if ( messages.length === messageCount ) {
			return;
		}

		if (
			typeof messageCount === 'number' &&
			messages.length === messageCount + 1
		) {
			if ( messages[ messages.length - 1 ].type === 'error' ) {
				setMessageStatus( 'prompt_error' );
			} else {
				setMessageStatus( 'prompt_success' );
			}
		} else if (
			typeof messageCount === 'number' &&
			messages.length === 0 &&
			messageCount > 0
		) {
			setMessageStatus( 'reset' );
		}
		setMessageCount( messages.length );
	}, [ messages, messageCount ] );

	useEffect( () => {
		if ( ! messageStatus ) {
			return;
		}

		const timeout = setTimeout( () => {
			setMessageStatus( '' );
		}, 5000 );

		return () => clearTimeout( timeout );
	}, [ messageStatus ] );

	if ( ! provider ) {
		return <p>{ __( 'Please select an AI provider.', 'ai' ) }</p>;
	}

	if ( ! model ) {
		return <p>{ __( 'Please select an AI model.', 'ai' ) }</p>;
	}

	if ( loading ) {
		return (
			<p>
				{ sprintf(
					/* translators: 1: provider name, 2: model name */
					__( 'Sending prompt to %1$s model "%2$s"…', 'ai' ),
					providerName,
					modelName
				) }
			</p>
		);
	}

	if ( messageStatus === 'prompt_success' ) {
		return (
			<p>
				{ sprintf(
					/* translators: 1: provider name, 2: model name */
					__( 'Received response from %1$s model "%2$s".', 'ai' ),
					providerName,
					modelName
				) }
			</p>
		);
	}

	if ( messageStatus === 'prompt_error' ) {
		return (
			<p>
				{ sprintf(
					/* translators: 1: provider name, 2: model name */
					__( 'Received error from %1$s model "%2$s".', 'ai' ),
					providerName,
					modelName
				) }
			</p>
		);
	}

	if ( messageStatus === 'reset' ) {
		return <p>{ __( 'Messages were reset.', 'ai' ) }</p>;
	}

	return (
		<p>
			{ sprintf(
				/* translators: 1: provider name, 2: model name */
				__( 'Ready to use %1$s with model "%2$s".', 'ai' ),
				providerName,
				modelName
			) }
		</p>
	);
}
