/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
import { page } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

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
			'Displays a category guide. Review generated text on the Category edit screen.',
			'ai'
		),
		attributes: {},
		supports: {
			html: false,
			align: [ 'wide', 'full' ],
			color: { background: true, text: true },
			spacing: { margin: true, padding: true },
			typography: { fontSize: true, lineHeight: true },
		},
		edit: function TopicBlockEdit() {
			return (
				<div { ...useBlockProps() }>
					<Placeholder
						icon="book-alt"
						label={ section.title }
						instructions={
							section.name === 'timeline'
								? __(
										'Shows up to ten public posts in publication order. No AI generation is needed.',
										'ai'
								  )
								: __(
										'The published text appears on the site. Review replacements on the Category edit screen.',
										'ai'
								  )
						}
					>
						<p>
							{ __(
								'Displays the guide for the current category archive.',
								'ai'
							) }
						</p>
					</Placeholder>
				</div>
			);
		},
		save: () => null,
	} );
}
