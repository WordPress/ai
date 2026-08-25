/**
 * WordPress dependencies
 */
import { Button, Icon } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import TranslationModal from './TranslationModal';
import { useContentTranslation } from '../hooks/useContentTranslation';
import { getSettings } from '../utils';

export default function ContentTranslationPlugin() {
	const [ isOpen, setIsOpen ] = useState( false );
	const {
		isLoading: isTranslating,
		isContentTooShort,
		isTitleTooShort,
		progress,
		total,
		minContentLength,
		translate,
	} = useContentTranslation();

	const descriptionId = useInstanceId(
		ContentTranslationPlugin,
		'ai-content-translation-plugin-description'
	);

	if ( ! getSettings().enabled ) {
		return null;
	}

	const translatingLabel =
		total > 0
			? sprintf(
					/* translators: %1$d: number of translated blocks, %2$d: total number of blocks */
					__( 'Translating blocks… (%1$d/%2$d)', 'ai' ),
					progress,
					total
			  )
			: __( 'Translating…', 'ai' );

	const buttonLabel = isTranslating
		? translatingLabel
		: __( 'Generate Translation', 'ai' );

	const buttonDescription = isContentTooShort
		? sprintf(
				/* translators: %d: minimum number of characters required */
				__(
					'Content translation will be available when the post content has at least %d characters.',
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
					aria-haspopup="dialog"
					aria-describedby={ descriptionId }
					className="ai-content-translation-plugin__trigger"
					disabled={ isTranslating || isContentTooShort }
					isBusy={ isTranslating }
					accessibleWhenDisabled
				>
					{ buttonLabel }
				</Button>

				{ isOpen && (
					<TranslationModal
						canTranslateContent={ ! isContentTooShort }
						canTranslateTitle={ ! isTitleTooShort }
						minContentLength={ minContentLength }
						closeModal={ () => setIsOpen( false ) }
						translate={ translate }
					/>
				) }

				<Text
					id={ descriptionId }
					className="ai-content-translation-plugin__description"
				>
					{ buttonDescription }
				</Text>
			</Stack>
		</PluginPostStatusInfo>
	);
}
