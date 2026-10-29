/**
 * The JavaScript parts of the excerpt field once the excerpt generation
 * experiment is enabled: the `@ai/excerpt-field` script module.
 *
 * WordPress 7.2 renders the post excerpt in the editor inspector with the
 * Entity Fields API, so the experiment hands the excerpt field's `render`
 * (the summary in the panel) and `Edit` (the control in the popover) to this
 * module, which adds the generate buttons to them. Everything else about the
 * field (label, placeholder, description) stays core's.
 *
 * @see includes/Experiments/Excerpt_Generation/Excerpt_Generation.php
 */

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import type {
	DataFormControlProps,
	DataViewRenderFieldProps,
} from '@wordpress/dataviews/wp';
import { store as editorStore } from '@wordpress/editor';
import { useCallback } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { Stack, Text, TextareaControl } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import ExcerptGeneration from '../../../src/experiments/excerpt-generation/components/ExcerptGeneration';
import ExcerptInlineButton from '../../../src/experiments/excerpt-generation/components/ExcerptInlineButton';

/**
 * The parts of a post record the excerpt field reads.
 */
interface PostRecord {
	id?: number;
	excerpt?: string | { raw?: string };
}

/**
 * Reads the raw excerpt of a post record, as core's excerpt field does.
 *
 * @param item The post record.
 * @return The excerpt, empty when the post has none.
 */
function getExcerpt( item: PostRecord ): string {
	const { excerpt } = item;

	if ( typeof excerpt === 'string' ) {
		return excerpt;
	}

	return excerpt?.raw ?? '';
}

/**
 * The summary of the excerpt in the inspector panel: its first three lines,
 * or the field's placeholder when it is empty, with the generate button next
 * to it for the post being edited.
 *
 * The panel's edit button stretches over the whole row, so clicking the
 * excerpt opens the popover as it does for core's summary; the experiment's
 * stylesheet lifts only the generate button above it.
 *
 * @param props       The field render props.
 * @param props.item  The post record.
 * @param props.field The excerpt field.
 * @return The summary.
 */
function ExcerptSummary( {
	item,
	field,
}: DataViewRenderFieldProps< PostRecord > ): React.JSX.Element {
	const excerpt = getExcerpt( item );
	const isEditedPost = useSelect(
		( select ) => select( editorStore ).getCurrentPostId() === item.id,
		[ item.id ]
	);

	return (
		<span className="ai-excerpt-field__summary">
			{ isEditedPost && <ExcerptInlineButton /> }
			{ excerpt ? (
				<Text className="ai-excerpt-field__excerpt">
					{ decodeEntities( excerpt ) }
				</Text>
			) : (
				field.placeholder && (
					<Text className="ai-excerpt-field__placeholder">
						{ field.placeholder }
					</Text>
				)
			) }
		</span>
	);
}

/**
 * The control of the excerpt in the inspector popover: core's textarea with
 * the generate button under it.
 *
 * @param props                     The control props.
 * @param props.data                The post record being edited.
 * @param props.field               The excerpt field.
 * @param props.onChange            Applies an edit to the record.
 * @param props.hideLabelFromVision Whether the label is visually hidden.
 * @return The control.
 */
function ExcerptEdit( {
	data,
	field,
	onChange,
	hideLabelFromVision,
}: DataFormControlProps< PostRecord > ): React.JSX.Element {
	const { label, placeholder, description } = field;
	const value = field.getValue( { item: data } ) ?? '';
	const applyExcerpt = useCallback(
		( excerpt: string ) =>
			onChange( field.setValue( { item: data, value: excerpt } ) ),
		[ data, field, onChange ]
	);

	return (
		<Stack direction="column" gap="md">
			<TextareaControl
				label={ label }
				placeholder={ placeholder }
				value={ value }
				{ ...( typeof description === 'string'
					? { description }
					: { details: description } ) }
				onValueChange={ applyExcerpt }
				rows={ 4 }
				disabled={ field.isDisabled( { item: data, field } ) }
				hideLabelFromVision={ !! hideLabelFromVision }
			/>
			<ExcerptGeneration onApply={ applyExcerpt } />
		</Stack>
	);
}

export default {
	excerpt: {
		render: ExcerptSummary,
		Edit: ExcerptEdit,
	},
};
