/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Modal, Button, TextareaControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * @param {Object}   props
 * @param {Function} props.onClose      Close/unmount callback.
 * @param {Function} props.onSuccess    Called with the generated JSON string on success.
 * @param {string}   props.abilitySlug  Slug of the ability being tested.
 * @param {string}   props.ajaxUrl      WordPress admin-ajax URL.
 * @param {string}   props.nonce        Nonce for ai_ability_explorer_generate_payload.
 */
export default function GeneratePayloadModal( {
	onClose,
	onSuccess,
	abilitySlug,
	ajaxUrl,
	nonce,
} ) {
	const [ command, setCommand ] = useState( '' );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ error, setError ] = useState( '' );

	async function handleGenerate() {
		if ( ! command.trim() ) {
			setError( __( 'Please enter a command.', 'ai' ) );
			return;
		}

		setIsGenerating( true );
		setError( '' );

		const formData = new FormData();
		formData.append( 'action', 'ai_ability_explorer_generate_payload' );
		formData.append( 'nonce', nonce );
		formData.append( 'ability', abilitySlug );
		formData.append( 'command', command );

		try {
			const response = await fetch( ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} );
			const data = await response.json();

			if ( data.success && data.data?.payload ) {
				onSuccess( data.data.payload );
				onClose();
			} else {
				setError( data.data?.message || __( 'An error occurred while generating the payload. Please try again.', 'ai' ) );
			}
		} catch {
			setError( __( 'An error occurred while generating the payload. Please try again.', 'ai' ) );
		} finally {
			setIsGenerating( false );
		}
	}

	return (
		<Modal
			title={ __( 'Generate Payload', 'ai' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			<TextareaControl
				label={ __( 'Describe what you want to test in natural language:', 'ai' ) }
				value={ command }
				onChange={ setCommand }
				rows={ 4 }
				placeholder={ __( 'e.g. Query only the site URL information', 'ai' ) }
			/>

			<div className="ability-generate-modal-error">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
			</div>

			<div className="ability-generate-modal-footer">
				<Button
					variant="primary"
					onClick={ handleGenerate }
					disabled={ isGenerating }
					isBusy={ isGenerating }
					accessibleWhenDisabled
				>
					{ isGenerating ? __( 'Generating...', 'ai' ) : __('Generate', 'ai') }
				</Button>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'ai' ) }
				</Button>
			</div>
		</Modal>
	);
}
