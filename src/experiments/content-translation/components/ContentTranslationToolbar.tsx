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

type ContentTranslationToolbarProps = {
	isLoading: boolean;
	translate: ( languageCode: string ) => Promise< void >;
	canTranslate: boolean;
};

/**
 * Content translation toolbar component.
 *
 * @param props              Component props.
 * @param props.isLoading    Whether a translation request is in progress.
 * @param props.translate    Callback to translate the selected block.
 * @param props.canTranslate Whether the selected block can be translated.
 */
export default function ContentTranslationToolbar( {
	isLoading,
	translate,
	canTranslate,
}: ContentTranslationToolbarProps ) {
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
