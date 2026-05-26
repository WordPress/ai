/**
 * WordPress dependencies
 */
import {
	Button,
	Notice,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import { __, isRTL, sprintf } from '@wordpress/i18n';
import { chevronLeft, chevronRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { HistoryEntry } from '../../types';

// ─── GeneratingState ─────────────────────────────────────────────────────────

interface GeneratingStateProps {
	progress: string;
	previewSrc?: string | null;
	previewAlt?: string;
}

/**
 * Loading state: dimmed previous image (or skeleton on first run) + centered spinner.
 */
export function GeneratingState( {
	progress,
	previewSrc,
	previewAlt = '',
}: GeneratingStateProps ) {
	return (
		<div className="ai-image-generation__generating">
			{ previewSrc ? (
				<img
					src={ previewSrc }
					alt={ previewAlt }
					className="ai-image-generation__preview-image ai-image-generation__preview-image--dimmed"
				/>
			) : (
				<div
					className="ai-image-generation__skeleton"
					aria-hidden="true"
				/>
			) }
			<div
				className="ai-image-generation__spinner-wrap"
				role="status"
				aria-live="polite"
			>
				<Spinner />
				<span className="ai-image-generation__spinner-label">
					{ progress }
				</span>
			</div>
		</div>
	);
}

// ─── PromptForm ───────────────────────────────────────────────────────────────

interface PromptFormProps {
	prompt: string;
	onPromptChange: ( value: string ) => void;
	onGenerate: () => void;
	error?: string | null;
}

/**
 * Idle state: prompt textarea + Generate button.
 */
export function PromptForm( {
	prompt,
	onPromptChange,
	onGenerate,
	error,
}: PromptFormProps ) {
	return (
		<div className="ai-image-generation__idle">
			<p className="description">
				{ __( 'Describe the image you want to generate.', 'ai' ) }
			</p>
			<TextareaControl
				label={ __( 'Prompt', 'ai' ) }
				value={ prompt }
				onChange={ onPromptChange }
				rows={ 4 }
				hideLabelFromVision
			/>
			<div className="ai-image-generation__actions">
				<Button
					variant="primary"
					disabled={ ! prompt.trim() }
					onClick={ onGenerate }
				>
					{ __( 'Generate', 'ai' ) }
				</Button>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</div>
	);
}

// ─── ImageHistoryNav ──────────────────────────────────────────────────────────

interface ImageHistoryNavProps {
	previewSrc: string;
	activeEntry: HistoryEntry | null;
	canGoBack: boolean;
	canGoForward: boolean;
	onGoBack: () => void;
	onGoForward: () => void;
	historyLength: number;
	historyIndex: number;
	showComparison: boolean;
	comparisonLeftLabel: string;
	comparisonRightLabel: string;
}

/**
 * History navigation: prev/next arrows, image or side-by-side comparison, version counter.
 */
export function ImageHistoryNav( {
	previewSrc,
	activeEntry,
	canGoBack,
	canGoForward,
	onGoBack,
	onGoForward,
	historyLength,
	historyIndex,
	showComparison,
	comparisonLeftLabel,
	comparisonRightLabel,
}: ImageHistoryNavProps ) {
	return (
		<>
			<div className="ai-image-history-nav">
				<Button
					className="ai-image-history-nav__arrow"
					icon={ isRTL() ? chevronRight : chevronLeft }
					disabled={ ! canGoBack }
					onClick={ onGoBack }
					label={ __( 'Previous version', 'ai' ) }
				/>
				<div className="ai-image-history-nav__content">
					{ showComparison ? (
						<div className="ai-image-generation__comparison">
							<div className="ai-image-generation__comparison-item">
								<p className="ai-image-generation__comparison-label">
									{ comparisonLeftLabel }
								</p>
								<img
									src={ activeEntry?.referenceSrc ?? '' }
									alt={ comparisonLeftLabel }
									className="ai-image-generation__preview-image"
								/>
							</div>
							<div className="ai-image-generation__comparison-item">
								<p className="ai-image-generation__comparison-label">
									{ comparisonRightLabel }
								</p>
								<img
									src={ previewSrc }
									alt={
										activeEntry?.generatedData?.prompt ?? ''
									}
									className="ai-image-generation__preview-image is-active"
								/>
							</div>
						</div>
					) : (
						<img
							src={ previewSrc }
							alt={ activeEntry?.generatedData?.prompt ?? '' }
							className="ai-image-generation__preview-image is-active"
						/>
					) }
				</div>
				<Button
					className="ai-image-history-nav__arrow"
					icon={ isRTL() ? chevronLeft : chevronRight }
					disabled={ ! canGoForward }
					onClick={ onGoForward }
					label={ __( 'Next version', 'ai' ) }
				/>
			</div>
			{ historyLength > 1 && (
				<p className="ai-image-history-nav__counter">
					{ sprintf(
						/* translators: 1: current position, 2: total count */
						__( '%1$d / %2$d', 'ai' ),
						historyIndex + 1,
						historyLength
					) }
				</p>
			) }
		</>
	);
}

// ─── RefinePromptForm ─────────────────────────────────────────────────────────

interface RefinePromptFormProps {
	previewSrc: string;
	previewAlt: string;
	refinePrompt: string;
	onRefinePromptChange: ( value: string ) => void;
	onRefine: () => void;
	onCancel: () => void;
	cancelIsDestructive?: boolean;
	error?: string | null;
}

/**
 * Refining state: current image + follow-up prompt input.
 */
export function RefinePromptForm( {
	previewSrc,
	previewAlt,
	refinePrompt,
	onRefinePromptChange,
	onRefine,
	onCancel,
	cancelIsDestructive = false,
	error,
}: RefinePromptFormProps ) {
	return (
		<div className="ai-image-generation__refining">
			<img
				src={ previewSrc }
				alt={ previewAlt }
				className="ai-image-generation__preview-image"
			/>
			<TextareaControl
				label={ __(
					'Describe the refinements you want to make to the image.',
					'ai'
				) }
				value={ refinePrompt }
				onChange={ onRefinePromptChange }
				rows={ 3 }
			/>
			<div className="ai-image-generation__actions">
				<Button
					variant="primary"
					disabled={ ! refinePrompt.trim() }
					onClick={ onRefine }
				>
					{ __( 'Refine', 'ai' ) }
				</Button>
				<Button
					variant="tertiary"
					isDestructive={ cancelIsDestructive }
					onClick={ onCancel }
				>
					{ __( 'Cancel Refinement', 'ai' ) }
				</Button>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</div>
	);
}
