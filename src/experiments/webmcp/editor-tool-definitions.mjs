/**
 * The editor tools' names, descriptions and input schemas.
 *
 * Plain JavaScript on purpose: the bridge imports it for registration and
 * `tools/webmcp-evals.mjs` imports it to judge the descriptions with a
 * model. Descriptions are written for the model, in English.
 */
export const EDITOR_TOOL_DEFINITIONS = [
	{
		name: 'editor-get-document',
		description:
			"Read the post open in the editor: its ID, type, status, title, and an outline of its blocks with each block's clientId, block name and a short text preview. Call this first to find the clientId of a block before changing it. Changes nothing.",
		inputSchema: { type: 'object', properties: {} },
		annotations: { readOnlyHint: true },
	},
	{
		name: 'editor-set-title',
		description:
			'Set the title of the post open in the editor. The title field updates on the page.',
		inputSchema: {
			type: 'object',
			properties: {
				title: { type: 'string', description: 'The new title.' },
			},
			required: [ 'title' ],
		},
		annotations: { readOnlyHint: false },
	},
	{
		name: 'editor-insert-block',
		description:
			'Insert a new block into the post open in the editor. Defaults to a paragraph (core/paragraph) with the given text in attributes.content; use core/heading with attributes.content and attributes.level for a heading. Appends at the end unless afterClientId names the block to insert after. The new block appears on the page and is selected.',
		inputSchema: {
			type: 'object',
			properties: {
				blockName: {
					type: 'string',
					description:
						'Block name, for example core/paragraph, core/heading, core/list. Default core/paragraph.',
				},
				attributes: {
					type: 'object',
					description:
						'Block attributes. For text blocks, content is the text (inline HTML allowed).',
				},
				afterClientId: {
					type: 'string',
					description:
						'clientId of the block to insert after. Omit to append at the end.',
				},
			},
		},
		annotations: { readOnlyHint: false },
	},
	{
		name: 'editor-update-block-text',
		description:
			'Replace the text of an existing text block (paragraph, heading, list item, quote, verse, preformatted) identified by clientId. The block updates on the page and is selected.',
		inputSchema: {
			type: 'object',
			properties: {
				clientId: {
					type: 'string',
					description: 'clientId from editor-get-document.',
				},
				content: {
					type: 'string',
					description: 'The new text (inline HTML allowed).',
				},
			},
			required: [ 'clientId', 'content' ],
		},
		annotations: { readOnlyHint: false },
	},
	{
		name: 'editor-update-block-attributes',
		description:
			'Change any attributes of an existing block identified by clientId, for example the level of a heading or the alignment of an image. Attributes not given are left as they are. The block updates on the page and is selected.',
		inputSchema: {
			type: 'object',
			properties: {
				clientId: {
					type: 'string',
					description: 'clientId from editor-get-document.',
				},
				attributes: {
					type: 'object',
					description: 'Attributes to set.',
				},
			},
			required: [ 'clientId', 'attributes' ],
		},
		annotations: { readOnlyHint: false },
	},
	{
		name: 'editor-remove-block',
		description:
			'Remove an existing block identified by clientId. The block disappears from the page; the person can undo it.',
		inputSchema: {
			type: 'object',
			properties: {
				clientId: {
					type: 'string',
					description: 'clientId from editor-get-document.',
				},
			},
			required: [ 'clientId' ],
		},
		annotations: { readOnlyHint: false, destructiveHint: true },
	},
	{
		name: 'editor-select-block',
		description:
			'Highlight a block on the page identified by clientId, to point the person at it. Changes nothing.',
		inputSchema: {
			type: 'object',
			properties: {
				clientId: {
					type: 'string',
					description: 'clientId from editor-get-document.',
				},
			},
			required: [ 'clientId' ],
		},
		annotations: { readOnlyHint: true },
	},
	{
		name: 'editor-save',
		description:
			'Save the post open in the editor without changing its status: a draft stays a draft, a published post updates. Returns the status.',
		inputSchema: { type: 'object', properties: {} },
		annotations: { readOnlyHint: false, idempotentHint: true },
	},
	{
		name: 'editor-publish',
		description:
			'Publish the post open in the editor: its status becomes published and it is saved. Visible to the public afterwards, so confirm with the person before calling this.',
		inputSchema: { type: 'object', properties: {} },
		annotations: { readOnlyHint: false },
	},
];
