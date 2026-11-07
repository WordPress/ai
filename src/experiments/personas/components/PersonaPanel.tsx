/**
 * Sidebar panel component for the personas experiment.
 */

/**
 * WordPress dependencies
 */
import { ExternalLink, SelectControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PersonaOption } from '../types';

type PersonaPanelProps = {
	metaKey: string;
	personas: PersonaOption[];
	defaultPersona: string;
	manageUrl: string;
};

/**
 * Panel letting an author pick the persona applied to this post's generations.
 *
 * The selection is stored in post meta, so it applies to every persona-aware
 * ability once the post is saved. Leaving it on "Site default" keeps the post
 * following whichever persona the site has configured.
 *
 * @param props                Component props.
 * @param props.metaKey        Post meta key holding the persona override.
 * @param props.personas       Selectable personas.
 * @param props.defaultPersona The site-wide default persona ID.
 * @param props.manageUrl      Admin URL for managing persona definitions.
 */
export default function PersonaPanel( {
	metaKey,
	personas,
	defaultPersona,
	manageUrl,
}: PersonaPanelProps ): React.JSX.Element {
	const { editPost } = useDispatch( editorStore );

	const { meta, selected } = useSelect(
		( select ) => {
			const currentMeta = select( editorStore ).getEditedPostAttribute(
				'meta'
			) as Record< string, string > | undefined;

			return {
				meta: currentMeta,
				selected: currentMeta?.[ metaKey ] ?? '',
			};
		},
		[ metaKey ]
	);

	// The stored value is an override; an empty value defers to the site
	// default, which is what the first option represents.
	const defaultLabel =
		personas.find( ( persona ) => persona.value === defaultPersona )
			?.label ?? __( 'None', 'ai' );

	const options = [
		{
			value: '',
			label: sprintf(
				/* translators: %s: Name of the site-wide default persona. */
				__( 'Site default (%s)', 'ai' ),
				defaultLabel
			),
		},
		...personas.filter( ( persona ) => persona.value !== '' ),
		{ value: 'none', label: __( 'No persona', 'ai' ) },
	];

	const onChange = ( value: string ) => {
		editPost( { meta: { ...meta, [ metaKey ]: value } } );
	};

	return (
		<div className="ai-personas-panel">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Persona', 'ai' ) }
				help={ __(
					'The role, audience, and voice applied to AI-generated content for this post.',
					'ai'
				) }
				value={ selected }
				options={ options }
				onChange={ onChange }
			/>
			<ExternalLink href={ manageUrl }>
				{ __( 'Manage personas', 'ai' ) }
			</ExternalLink>
		</div>
	);
}
