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
			'Insert a new block into the post open in the editor. Defaults to a paragraph (core/paragraph) with the given text in attributes.content; use core/heading with attributes.content and attributes.level for a heading. Appends at the end of the post unless afterClientId names the block to insert after, or parentClientId names a container (group, column, list, quote) to insert into as its last child. Refuses a block the editor does not allow at that position. The new block appears on the page and is selected.',
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
				parentClientId: {
					type: 'string',
					description:
						'clientId of a container block to insert into, as its last child. Ignored when afterClientId is given.',
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
		name: 'editor-move-block',
		description:
			'Move an existing block identified by clientId: after the block named by afterClientId, or into the container named by parentClientId as its last child, or to the top of its current parent when neither is given. The block visibly moves on the page and is selected.',
		inputSchema: {
			type: 'object',
			properties: {
				clientId: {
					type: 'string',
					description: 'clientId of the block to move.',
				},
				afterClientId: {
					type: 'string',
					description: 'clientId of the block it should follow.',
				},
				parentClientId: {
					type: 'string',
					description:
						'clientId of a container to move it into. Ignored when afterClientId is given.',
				},
			},
			required: [ 'clientId' ],
		},
		annotations: { readOnlyHint: false },
	},
	{
		name: 'editor-duplicate-block',
		description:
			'Duplicate an existing block identified by clientId. The copy appears directly after the original. Returns the clientId of the copy.',
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
		annotations: { readOnlyHint: false },
	},
	{
		name: 'editor-transform-block',
		description:
			"Turn an existing block into another block type, the way the editor's own Transform menu does, for example a paragraph into a heading or a list into paragraphs. Fails when the editor has no transform between the two types. Returns the new clientIds.",
		inputSchema: {
			type: 'object',
			properties: {
				clientId: {
					type: 'string',
					description: 'clientId from editor-get-document.',
				},
				blockName: {
					type: 'string',
					description: 'Target block name, for example core/heading.',
				},
			},
			required: [ 'clientId', 'blockName' ],
		},
		annotations: { readOnlyHint: false },
	},
	{
		name: 'editor-get-block-types',
		description:
			"Discover what can be inserted. Without name: lists the block types the editor allows at the top level, or inside the container named by parentClientId, optionally filtered by a search word. With name: returns that block type's attributes and their types, so attributes can be set correctly. Changes nothing.",
		inputSchema: {
			type: 'object',
			properties: {
				name: {
					type: 'string',
					description:
						'A block name, to get its attribute schema, for example core/image.',
				},
				search: {
					type: 'string',
					description:
						'Filter the list by a word in the name or title.',
				},
				parentClientId: {
					type: 'string',
					description:
						'List what is allowed inside this container instead of at the top level.',
				},
			},
		},
		annotations: { readOnlyHint: true },
	},
	{
		name: 'editor-undo',
		description:
			"Undo the last change in the editor, exactly like the editor's own Undo button. Each tool call that changed something is one undo step. Returns the document as it is afterwards.",
		inputSchema: { type: 'object', properties: {} },
		annotations: { readOnlyHint: false },
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
