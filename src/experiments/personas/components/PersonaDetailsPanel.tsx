/**
 * Sidebar panel for editing a persona's role and audience.
 */

/**
 * WordPress dependencies
 */
import { TextareaControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

type PersonaDetailsPanelProps = {
	roleMetaKey: string;
	audienceMetaKey: string;
};

/**
 * Panel shown while editing a persona.
 *
 * The title names the persona and the content describes its voice; these two
 * fields complete it with who is speaking and who they are speaking to.
 *
 * @param props                 Component props.
 * @param props.roleMetaKey     Post meta key holding the persona's role.
 * @param props.audienceMetaKey Post meta key holding the persona's audience.
 */
export default function PersonaDetailsPanel( {
	roleMetaKey,
	audienceMetaKey,
}: PersonaDetailsPanelProps ): React.JSX.Element {
	const { editPost } = useDispatch( editorStore );

	const meta = useSelect(
		( select ) =>
			select( editorStore ).getEditedPostAttribute( 'meta' ) as
				| Record< string, string >
				| undefined,
		[]
	);

	const update = ( key: string, value: string ) => {
		editPost( { meta: { ...meta, [ key ]: value } } );
	};

	return (
		<div className="ai-persona-details-panel">
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Role', 'ai' ) }
				help={ __(
					'Who is speaking, for example "A support specialist answering a customer question."',
					'ai'
				) }
				value={ meta?.[ roleMetaKey ] ?? '' }
				onChange={ ( value ) => update( roleMetaKey, value ) }
				rows={ 3 }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Audience', 'ai' ) }
				help={ __(
					'Who the content is written for, for example "Customers who want the fix quickly."',
					'ai'
				) }
				value={ meta?.[ audienceMetaKey ] ?? '' }
				onChange={ ( value ) => update( audienceMetaKey, value ) }
				rows={ 3 }
			/>
		</div>
	);
}
