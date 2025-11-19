/**
 * Title toolbar component for generating post titles.
 */

/**
 * External Dependencies.
 */
import React from 'react';
import { executeAbility } from '@wordpress/abilities';
import {
	Button,
	Flex,
	FlexItem,
	Modal,
	TextareaControl,
	ToolbarGroup,
	ToolbarButton,
} from '@wordpress/components';
import { dispatch, select, useDispatch } from '@wordpress/data';
import { PostTypeSupportCheck } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { update } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

const { aiTitleGenerationData } = window as any;

/**
 * Renders the generated title data with editable textareas.
 *
 * @param {Object}   props              Component props.
 * @param {string[]} props.data         The array of titles to render.
 * @param {Function} props.onDataChange Callback to update the data array.
 * @param {Function} props.onSelect     Callback when a title is selected.
 * @return {JSX.Element | null} The rendered data.
 */
function RenderData({
	data: dataToRender,
	onDataChange,
	onSelect,
}: {
	data: string[];
	onDataChange: (newData: string[]) => void;
	onSelect: (title: string, index: number) => void;
}): JSX.Element | null {
	if (!dataToRender || dataToRender.length === 0) {
		return null;
	}

	return (
		<Flex gap="5" wrap direction="column">
			{dataToRender.map((title: string, i: number) => {
				return (
					<FlexItem className="ai-title" key={`title-${i}`}>
						<TextareaControl
							rows={2}
							label={__('Generated title', 'ai')}
							hideLabelFromVision
							value={title}
							onChange={(value: string) => {
								onDataChange(
									dataToRender.map((item, index) =>
										index === i ? value : item
									)
								);
							}}
							__nextHasNoMarginBottom
						/>
						<Button
							variant="secondary"
							style={{ marginTop: '15px' }}
							onClick={() => onSelect(title, i)}
						>
							{__('Select', 'ai')}
						</Button>
					</FlexItem>
				);
			})}
		</Flex>
	);
}

/**
 * Generates titles for the given post ID and content.
 *
 * @param {number} postId  The ID of the post to generate a title for.
 * @param {string} content The content of the post to generate a title for.
 * @return {Promise<string[]>} A promise that resolves to the generated titles.
 */
async function generateTitles(
	postId: number,
	content: string
): Promise<string[]> {
	return executeAbility('ai/title-generation', {
		content,
		post_id: postId,
	})
		.then((response) => {
			if (
				response &&
				typeof response === 'object' &&
				'titles' in response
			) {
				return response.titles as string[];
			}

			return [];
		})
		.catch((error) => {
			throw new Error(`Error generating titles: ${error.message}`);
		});
}

/**
 * TitleToolbar component.
 *
 * Provides Generate/Re-generate button.
 *
 * @return {JSX.Element} The toolbar component.
 */
export default function TitleToolbar(): JSX.Element | null {
	const postId = select('core/editor').getCurrentPostId();
	const postType = select('core/editor').getCurrentPostType();
	const content = select('core/editor').getEditedPostContent();
	const title = select('core/editor').getEditedPostAttribute('title');

	const { editPost } = useDispatch('core/editor');

	const [isGenerating, setIsGenerating] = useState(false);
	const [isOpen, setOpen] = useState(false);
	const [data, setData] = useState<string[]>([]);

	const openModal = () => setOpen(true);
	const closeModal = () => {
		setOpen(false);
		setData([]);
	};

	const hasTitle = title.trim().length > 0;
	const buttonLabel = hasTitle
		? __('Re-generate', 'ai')
		: __('Generate', 'ai');

	/**
	 * Handles the generate/re-generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating(true);
		(dispatch('core/notices') as any).removeNotice(
			'ai_title_generation_error'
		);

		try {
			const generatedTitles = await generateTitles(postId, content);
			setData(generatedTitles);
			openModal();
		} catch (error: any) {
			(dispatch('core/notices') as any).createErrorNotice(error, {
				id: 'ai_title_generation_error',
				isDismissible: true,
			});
			setData([]);
		} finally {
			setIsGenerating(false);
		}
	};

	/**
	 * Handles selecting a title.
	 *
	 * @param {string} selectedTitle The selected title.
	 */
	const handleSelectTitle = async (selectedTitle: string) => {
		const isDirty = select('core/editor').isEditedPostDirty();
		editPost({
			title: selectedTitle,
		});
		closeModal();
		if (!isDirty) {
			await (dispatch('core') as any).saveEditedEntityRecord(
				'postType',
				postType,
				postId
			);
		}
	};

	// Ensure the experiment is enabled.
	if (!aiTitleGenerationData?.enabled) {
		return null;
	}

	return (
		<>
			<PostTypeSupportCheck supportKeys="title">
				<ToolbarGroup>
					<ToolbarButton
						icon={update}
						label={buttonLabel}
						onClick={handleGenerate}
						disabled={isGenerating}
						isBusy={isGenerating}
					>
						{buttonLabel}
					</ToolbarButton>
				</ToolbarGroup>
			</PostTypeSupportCheck>
			{isOpen && (
				<Modal
					title={__('Select a title', 'ai')}
					onRequestClose={closeModal}
					isFullScreen={false}
					size="medium"
					className="ai-title-generation-modal"
				>
					{data && (
						<RenderData
							data={data}
							onDataChange={setData}
							onSelect={handleSelectTitle}
						/>
					)}
				</Modal>
			)}
		</>
	);
}
