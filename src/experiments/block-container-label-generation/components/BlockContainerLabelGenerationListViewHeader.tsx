/**
 * WordPress dependencies
 */
import { useEffect, useState, createPortal } from '@wordpress/element';
import { Button, Icon } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { pencil } from '@wordpress/icons';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import { useBlockContainerLabelGeneration } from '../hooks/useBlockContainerLabelGeneration';
import { getSettings } from '../utils';

const LIST_VIEW_CONTAINER_SELECTOR =
	'.editor-list-view-sidebar__list-view-container';

/**
 * Retrieves the List View container element only when the List View tab (and not Outline) is active.
 *
 * @return The List View container element, or null if Outline or closed.
 */
const getListViewContainer = (): HTMLElement | null => {
	/*
	 * The List View and Outline tabs share the container class, so identify the
	 * List View tab by content that only it renders.
	 */
	const listViewContent = document.querySelector< HTMLElement >(
		'.editor-list-view-sidebar__list-view-panel-content, .editor-list-view-sidebar .block-editor-list-view'
	);

	return (
		listViewContent?.closest< HTMLElement >(
			LIST_VIEW_CONTAINER_SELECTOR
		) ?? null
	);
};

export default function BlockContainerLabelGenerationListViewHeader() {
	const [ targetContainer, setTargetContainer ] =
		useState< HTMLElement | null >( null );

	const isListViewOpened = useSelect( ( sel ) => {
		const editor = sel( editorStore ) as {
			isListViewOpened?: () => boolean;
		};
		return editor?.isListViewOpened ? editor.isListViewOpened() : false;
	}, [] );

	const {
		isLoading: isGeneratingLabel,
		activeClientId,
		progress,
		total,
		minContentLength,
		isContentTooShort,
		hasContainerBlocks,
		generate: generateLabel,
	} = useBlockContainerLabelGeneration();

	// Visual and physical disable on the block currently being processed in List View.
	useEffect( () => {
		if ( ! activeClientId ) {
			document
				.querySelectorAll< HTMLElement >(
					'.ai-block-container-label-generation--is-generating'
				)
				.forEach( ( el ) => {
					el.classList.remove(
						'ai-block-container-label-generation--is-generating'
					);
					el.removeAttribute( 'aria-busy' );
					el.removeAttribute( 'aria-disabled' );
					el.removeAttribute( 'inert' );
					el.querySelectorAll< HTMLButtonElement >(
						'button'
					).forEach( ( btn ) => btn.removeAttribute( 'disabled' ) );
				} );
			return;
		}

		const blockRow = document.querySelector< HTMLElement >(
			`[data-block="${ activeClientId }"]`
		);
		if ( blockRow ) {
			blockRow.classList.add(
				'ai-block-container-label-generation--is-generating'
			);
			blockRow.setAttribute( 'aria-busy', 'true' );
			blockRow.setAttribute( 'aria-disabled', 'true' );
			blockRow.setAttribute( 'inert', '' );
			blockRow
				.querySelectorAll< HTMLButtonElement >( 'button' )
				.forEach( ( btn ) => btn.setAttribute( 'disabled', 'true' ) );
		}

		return () => {
			if ( blockRow ) {
				blockRow.classList.remove(
					'ai-block-container-label-generation--is-generating'
				);
				blockRow.removeAttribute( 'aria-busy' );
				blockRow.removeAttribute( 'aria-disabled' );
				blockRow.removeAttribute( 'inert' );
				blockRow
					.querySelectorAll< HTMLButtonElement >( 'button' )
					.forEach( ( btn ) => btn.removeAttribute( 'disabled' ) );
			}
		};
	}, [ activeClientId ] );

	useEffect( () => {
		if ( ! getSettings().enabled ) {
			return;
		}

		let mountPoint: HTMLDivElement | null = null;

		const syncAttachment = () => {
			const container = getListViewContainer();

			if ( ! container ) {
				if ( mountPoint && mountPoint.parentNode ) {
					mountPoint.parentNode.removeChild( mountPoint );
				}
				mountPoint = null;
				setTargetContainer( null );
				return;
			}

			// If we already have the mountPoint in this container and attached in the DOM, keep it.
			if (
				mountPoint &&
				container.contains( mountPoint ) &&
				document.body.contains( mountPoint )
			) {
				return;
			}

			if ( mountPoint && mountPoint.parentNode ) {
				mountPoint.parentNode.removeChild( mountPoint );
			}

			mountPoint = document.createElement( 'div' );
			mountPoint.className =
				'ai-block-container-label-generation-list-view-header-mount';
			container.prepend( mountPoint );
			setTargetContainer( mountPoint );
		};

		// Attempt immediate attachment.
		syncAttachment();

		// Observe DOM changes continuously so switching between List View and Outline
		// or opening/closing the sidebar re-attaches the mount point seamlessly.
		const observer = new MutationObserver( () => {
			syncAttachment();
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );

		return () => {
			observer.disconnect();
			if ( mountPoint && mountPoint.parentNode ) {
				mountPoint.parentNode.removeChild( mountPoint );
			}
			mountPoint = null;
			setTargetContainer( null );
		};
	}, [ isListViewOpened ] );

	if ( ! getSettings().enabled || ! targetContainer ) {
		return null;
	}

	const buttonLabel = isGeneratingLabel
		? sprintf(
				/* translators: %1$d: number of blocks processed so far, %2$d: total number of blocks */
				__( 'Generating labels… (%1$d/%2$d)', 'ai' ),
				progress,
				total
		  )
		: __( 'Generate Block Labels', 'ai' );

	let buttonTitle: string | undefined;

	if ( ! hasContainerBlocks ) {
		buttonTitle = __(
			'No container blocks found to generate labels for.',
			'ai'
		);
	} else if ( isContentTooShort ) {
		buttonTitle = sprintf(
			/* translators: %d: minimum number of characters required */
			__(
				'Block label generation will be available when the post content has at least %d characters.',
				'ai'
			),
			minContentLength
		);
	}

	const isButtonDisabled =
		isGeneratingLabel || ! hasContainerBlocks || isContentTooShort;

	return createPortal(
		<div className="ai-block-container-label-generation-list-view-header">
			<Button
				icon={ <Icon icon={ pencil } aria-hidden="true" /> }
				variant="secondary"
				className="ai-block-container-label-generation-list-view-header__button"
				__next40pxDefaultSize
				onClick={ generateLabel }
				disabled={ isButtonDisabled }
				isBusy={ isGeneratingLabel }
				accessibleWhenDisabled
				{ ...( buttonTitle && {
					label: buttonTitle,
					showTooltip: true,
				} ) }
			>
				{ buttonLabel }
			</Button>
		</div>,
		targetContainer
	);
}
