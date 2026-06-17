/**
 * WordPress dependencies
 */
import {
	Spinner,
	ToolbarDropdownMenu,
	ToolbarGroup,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { useContentTranslation } from '../hooks/useContentTranslation';

type ContentTranslationToolbarProps = {
	clientId: string;
};

/**
 * Content translation toolbar component.
 *
 * @param props          Component props.
 * @param props.clientId The block client ID.
 */
export default function ContentTranslationToolbar( {
	clientId,
}: ContentTranslationToolbarProps ) {
	const { isLoading, translate, canTranslate } =
		useContentTranslation( clientId );

	const controls = useMemo(
		() =>
			window?.aiContentTranslationData?.languages.map( ( language ) => ( {
				title: language.name,
				isDisabled: ! canTranslate,
				onClick: () => {
					translate( language.code );
				},
			} ) ),
		[ translate, canTranslate ]
	);

	return (
		<>
			<ToolbarGroup className="ai-content-translation-toolbar">
				<ToolbarDropdownMenu
					icon={
						isLoading ? (
							<Spinner className="ai-content-translation-toolbar__spinner" />
						) : (
							'translation'
						)
					}
					label={ __( 'AI Translate', 'ai' ) }
					controls={ controls }
				/>
			</ToolbarGroup>
		</>
	);
}
