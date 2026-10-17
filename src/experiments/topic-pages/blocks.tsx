/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, Placeholder } from '@wordpress/components';
import { page } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

declare global {
	interface Window {
		aiTopicBlockData?: { terms: Array< { value: number; label: string } > };
	}
}

const sections = [
	{ name: 'summary', title: __( 'Topic Summary', 'ai' ) },
	{ name: 'overview', title: __( 'Topic Overview', 'ai' ) },
	{ name: 'timeline', title: __( 'Topic Timeline', 'ai' ) },
];

for ( const section of sections ) {
	registerBlockType( `ai/topic-${ section.name }`, {
		apiVersion: 3,
		title: section.title,
		category: 'widgets',
		icon: { src: page },
		description: __(
			'Displays an approved topic section. Manage its content in the topic editor linked from the category or tag.',
			'ai'
		),
		attributes: { termId: { type: 'number', default: 0 } },
		supports: {
			html: false,
			align: [ 'wide', 'full' ],
			color: { background: true, text: true },
			spacing: { margin: true, padding: true },
			typography: { fontSize: true, lineHeight: true },
		},
		edit: function TopicBlockEdit( { attributes, setAttributes } ) {
			const { termId } = attributes;
			const options = [
				{ value: '0', label: __( 'Current archive topic', 'ai' ) },
				...( window.aiTopicBlockData?.terms ?? [] ).map( ( term ) => ( {
					value: String( term.value ),
					label: term.label,
				} ) ),
			];
			return (
				<div { ...useBlockProps() }>
					<InspectorControls>
						<PanelBody title={ __( 'Topic', 'ai' ) }>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Source topic', 'ai' ) }
								value={ String( termId ) }
								options={ options }
								onChange={ ( value ) =>
									setAttributes( { termId: Number( value ) } )
								}
								help={ __(
									'Use the current topic in an archive template, or select a topic for another context.',
									'ai'
								) }
							/>
						</PanelBody>
					</InspectorControls>
					<Placeholder
						icon="book-alt"
						label={ section.title }
						instructions={ __(
							'The approved section appears on the site. Pending or unavailable content is hidden.',
							'ai'
						) }
					>
						<p>
							{
								options.find(
									( option ) =>
										option.value === String( termId )
								)?.label
							}
						</p>
					</Placeholder>
				</div>
			);
		},
		save: () => null,
	} );
}
