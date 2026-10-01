/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Fill, Icon, MenuItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getSettings } from './utils';
import { TRANSLATION_SUPPORTED_BLOCK_TYPES } from './constants';
import type { ToolbarContext } from './types';
import BlockTranslationModal from './components/BlockTranslationModal';
import { usePostTranslating } from './hooks/usePostTranslating';

export default function TranslationToolbar() {
	const [ modalClientId, setModalClientId ] = useState< string | null >(
		null
	);

	const isPostTranslatingGlobal = usePostTranslating();

	if ( ! getSettings().enabled ) {
		return null;
	}

	return (
		<>
			<Fill name="ai.contentResizing.additionalControls">
				{ ( fillProps ) => {
					const { clientId, blockName, onClose } =
						fillProps as ToolbarContext;

					if (
						! TRANSLATION_SUPPORTED_BLOCK_TYPES.includes(
							blockName
						)
					) {
						return null;
					}

					return (
						<MenuItem
							icon={
								<Icon icon="translation" aria-hidden="true" />
							}
							iconPosition="left"
							onClick={ () => {
								setModalClientId( clientId );
								onClose();
							} }
							disabled={ isPostTranslatingGlobal }
						>
							{ isPostTranslatingGlobal
								? __( 'Translating…', 'ai' )
								: __( 'Translate', 'ai' ) }
						</MenuItem>
					);
				} }
			</Fill>

			{ modalClientId && (
				<BlockTranslationModal
					key={ modalClientId }
					clientId={ modalClientId }
					closeModal={ () => setModalClientId( null ) }
				/>
			) }
		</>
	);
}
