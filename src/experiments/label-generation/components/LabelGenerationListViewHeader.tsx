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
import {
	useLabelGeneration,
	type UseLabelGenerationReturn,
} from '../hooks/useLabelGeneration';
import { getSettings } from '../utils';

const LIST_VIEW_CONTAINER_SELECTOR =
	'.editor-list-view-sidebar__list-view-container';

export default function LabelGenerationListViewHeader( {
	labelGeneration: externalLabelGeneration,
}: {
	labelGeneration?: UseLabelGenerationReturn;
} = {} ) {
	const [ targetContainer, setTargetContainer ] =
		useState< HTMLElement | null >( null );

	const isListViewOpened = useSelect( ( sel ) => {
		const editor = sel( editorStore ) as {
			isListViewOpened?: () => boolean;
		};
		return editor?.isListViewOpened ? editor.isListViewOpened() : false;
	}, [] );

	const defaultLabelGeneration = useLabelGeneration();
	const {
		isLoading: isGeneratingLabel,
		activeClientId,
		progress,
		total,
		minContentLength,
		isContentTooShort,
		generate: generateLabel,
	} = externalLabelGeneration ?? defaultLabelGeneration;

	// Visual and physical disable on the block currently being processed in List View.
	useEffect( () => {
		if ( ! activeClientId ) {
			document
				.querySelectorAll< HTMLElement >(
					'.ai-label-generation--is-generating'
				)
				.forEach( ( el ) => {
					el.classList.remove( 'ai-label-generation--is-generating' );
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
			blockRow.classList.add( 'ai-label-generation--is-generating' );
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
					'ai-label-generation--is-generating'
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

		const findAndAttach = () => {
			const container = document.querySelector< HTMLElement >(
				LIST_VIEW_CONTAINER_SELECTOR
			);

			if ( ! container ) {
				setTargetContainer( null );
				return false;
			}

			// If we already have the mountPoint in this container, ensure it's still attached.
			if ( mountPoint && container.contains( mountPoint ) ) {
				return true;
			}

			mountPoint = document.createElement( 'div' );
			mountPoint.className = 'ai-label-generation-list-view-header-mount';
			container.prepend( mountPoint );
			setTargetContainer( mountPoint );
			return true;
		};

		// Attempt immediate attachment.
		if ( ! findAndAttach() ) {
			// If not yet available, observe DOM until the list view container mounts.
			const observer = new MutationObserver( () => {
				if ( findAndAttach() ) {
					observer.disconnect();
				}
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
			};
		}

		return () => {
			if ( mountPoint && mountPoint.parentNode ) {
				mountPoint.parentNode.removeChild( mountPoint );
			}
		};
	}, [ isListViewOpened ] );

	if ( ! getSettings().enabled || ! targetContainer ) {
		return null;
	}

	const buttonLabel = isGeneratingLabel
		? sprintf(
				/* translators: %1$d: number of blocks with labels generated, %2$d: total number of blocks */
				__( 'Generating labels… (%1$d/%2$d)', 'ai' ),
				progress,
				total
		  )
		: __( 'Generate Block Labels', 'ai' );

	const buttonTitle = isContentTooShort
		? sprintf(
				/* translators: %d: minimum number of characters required */
				__(
					'Block label generation will be available when the post content has at least %d characters.',
					'ai'
				),
				minContentLength
		  )
		: __(
				'Generates concise labels for individual container blocks.',
				'ai'
		  );

	return createPortal(
		<div className="ai-label-generation-list-view-header">
			<Button
				icon={ <Icon icon={ pencil } aria-hidden="true" /> }
				variant="secondary"
				className="ai-label-generation-list-view-header__button"
				__next40pxDefaultSize
				onClick={ generateLabel }
				disabled={ isGeneratingLabel || isContentTooShort }
				isBusy={ isGeneratingLabel }
				accessibleWhenDisabled
				{ ...( isContentTooShort && {
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
