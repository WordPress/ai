/**
 * WordPress dependencies
 */
import { registerBlockVariation } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

const RELATED_POSTS_NAMESPACE = 'ai/related-posts';

registerBlockVariation( 'core/query', {
	name: 'ai-related-posts',
	title: __( 'Related Posts', 'ai' ),
	description: __(
		'Display semantically related posts for the current post.',
		'ai'
	),
	scope: [ 'inserter' ],
	attributes: {
		namespace: RELATED_POSTS_NAMESPACE,
		query: {
			perPage: 3,
			pages: 0,
			offset: 0,
			postType: 'post',
			order: 'desc',
			orderBy: 'date',
			author: '',
			search: '',
			exclude: [],
			sticky: '',
			inherit: false,
			taxQuery: null,
			parents: [],
		},
		displayLayout: {
			type: 'flex',
			columns: 3,
		},
	},
	innerBlocks: [
		[
			'core/heading',
			{
				level: 3,
				content: __( 'Related posts', 'ai' ),
			},
		],
		[
			'core/post-template',
			{
				layout: {
					type: 'grid',
					columnCount: 3,
				},
			},
			[
				[
					'core/post-featured-image',
					{
						isLink: true,
					},
				],
				[
					'core/post-title',
					{
						isLink: true,
						level: 4,
					},
				],
				[ 'core/post-excerpt', { moreText: '' } ],
			],
		],
	],
	isActive: ( blockAttributes ) =>
		// eslint-disable-next-line dot-notation -- Gutenberg block attributes are typed with an index signature.
		blockAttributes[ 'namespace' ] === RELATED_POSTS_NAMESPACE,
} );
