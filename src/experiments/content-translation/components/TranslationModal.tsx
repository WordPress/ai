/**
 * WordPress dependencies
 */
import {
	Button,
	Modal,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { getSettings } from '../utils';

type TranslationModalProps = {
	canTranslate: boolean;
	closeModal: () => void;
	translate: (
		languageCode: string,
		options?: { translateTitle?: boolean }
	) => Promise< void >;
};

/**
 * TranslationModal component.
 *
 * @param props              Component props.
 * @param props.canTranslate Whether translation can be started.
 * @param props.closeModal   Callback to close the modal.
 * @param props.translate    Callback to translate content.
 */
export default function TranslationModal( {
	canTranslate,
	closeModal,
	translate,
}: TranslationModalProps ) {
	const controls = useMemo(
		() =>
			getSettings().languages.map( ( language ) => ( {
				label: language.name,
				value: language.code,
			} ) ),
		[]
	);

	const [ translateTitle, setTranslateTitle ] = useState( false );
	const [ selectedLanguage, setSelectedLanguage ] = useState(
		controls?.[ 0 ]?.value || ''
	);

	return (
		<Modal
			onRequestClose={ closeModal }
			title={ __( 'Generate Translation', 'ai' ) }
			focusOnMount="firstInputElement"
			size="medium"
		>
			<Stack direction="column" gap="xl">
				<SelectControl
					label={ __( 'Translate to', 'ai' ) }
					options={ controls }
					value={ selectedLanguage }
					onChange={ ( value ) => setSelectedLanguage( value ) }
					__next40pxDefaultSize
				/>

				<ToggleControl
					label={ __( 'Also translate the title', 'ai' ) }
					help={ __(
						'Translates the title along with the post content.',
						'ai'
					) }
					onChange={ ( value ) => setTranslateTitle( value ) }
					checked={ translateTitle }
				/>

				<Stack direction="row" gap="sm" justify="flex-end">
					<Button
						variant="primary"
						__next40pxDefaultSize
						onClick={ () => {
							closeModal();
							void translate( selectedLanguage, {
								translateTitle,
							} );
						} }
						disabled={ ! canTranslate || ! selectedLanguage }
					>
						{ __( 'Translate', 'ai' ) }
					</Button>
					<Button
						variant="secondary"
						__next40pxDefaultSize
						onClick={ closeModal }
					>
						{ __( 'Cancel', 'ai' ) }
					</Button>
				</Stack>
			</Stack>
		</Modal>
	);
}
