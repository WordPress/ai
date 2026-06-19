/**
 * WordPress dependencies
 */
import { Button, Icon } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import TranslationModal from './TranslationModal';
import { useContentTranslation } from '../hooks/useContentTranslation';
import { formatMinLengthLabel } from '../../../utils/word-count';
import { getSettings } from '../utils';

export default function ContentTranslationPlugin() {
	const [ isOpen, setIsOpen ] = useState( false );
	const {
		isLoading: isTranslating,
		isContentTooShort,
		progress,
		total,
		minContentLength,
		translate,
	} = useContentTranslation();

	if ( ! getSettings().enabled ) {
		return null;
	}

	const buttonLabel = isTranslating
		? sprintf(
				/* translators: %1$d: number of translated blocks, %2$d: total number of blocks */
				__( 'Translating blocks… (%1$d/%2$d)', 'ai' ),
				progress,
				total
		  )
		: __( 'Generate Translation', 'ai' );

	const buttonDescription = isContentTooShort
		? formatMinLengthLabel(
				/* translators: %d: minimum number of characters required */
				__(
					'Content translation will be available when the post content has at least %d characters.',
					'ai'
				),
				/* translators: %d: minimum number of words required */
				__(
					'Content translation will be available when the post content has at least %d words.',
					'ai'
				),
				minContentLength
		  )
		: __(
				'Translates this post block by block and applies the translated content to each block.',
				'ai'
		  );

	return (
		<PluginPostStatusInfo>
			<Stack
				direction="column"
				gap="sm"
				align="stretch"
				className="ai-content-translation-plugin"
			>
				<Button
					icon={ <Icon icon="translation" aria-hidden="true" /> }
					variant="secondary"
					__next40pxDefaultSize
					onClick={ () => setIsOpen( ( prev ) => ! prev ) }
					aria-expanded={ isOpen }
					className="ai-content-translation-plugin__trigger"
					disabled={ isTranslating || isContentTooShort }
					isBusy={ isTranslating }
					accessibleWhenDisabled
				>
					{ buttonLabel }
				</Button>

				{ isOpen && (
					<TranslationModal
						canTranslate={ ! isContentTooShort }
						closeModal={ () => setIsOpen( false ) }
						translate={ translate }
					/>
				) }

				<Text className="ai-content-translation-plugin__description">
					{ buttonDescription }
				</Text>
			</Stack>
		</PluginPostStatusInfo>
	);
}
