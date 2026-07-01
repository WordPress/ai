/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Modal, Button, TextareaControl, Notice } from '@wordpress/components';

/**
 * @param {Object}   props
 * @param {Function} props.onClose      Close/unmount callback.
 * @param {Function} props.onSuccess    Called with the generated JSON string on success.
 * @param {string}   props.abilitySlug  Slug of the ability being tested.
 * @param {Object}   props.strings      Localised string map from aiAbilityExplorer.
 * @param {string}   props.ajaxUrl      WordPress admin-ajax URL.
 * @param {string}   props.nonce        Nonce for ai_ability_explorer_generate_payload.
 */
export default function GeneratePayloadModal( {
	onClose,
	onSuccess,
	abilitySlug,
	strings,
	ajaxUrl,
	nonce,
} ) {
	const [ command, setCommand ] = useState( '' );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ error, setError ] = useState( '' );

	async function handleGenerate() {
		if ( ! command.trim() ) {
			setError( strings.generateEmptyCommandError );
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
				setError( data.data?.message || strings.generateError );
			}
		} catch {
			setError( strings.generateError );
		} finally {
			setIsGenerating( false );
		}
	}

	return (
		<Modal
			title={ strings.generateModalTitle }
			onRequestClose={ onClose }
			size="medium"
		>
			<TextareaControl
				label={ strings.generateModalLabel }
				value={ command }
				onChange={ setCommand }
				rows={ 4 }
				placeholder={ strings.generateModalPlaceholder }
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
					{ isGenerating ? strings.generating : strings.generateBtn }
				</Button>
				<Button variant="tertiary" onClick={ onClose }>
					{ strings.cancelBtn }
				</Button>
			</div>
		</Modal>
	);
}
