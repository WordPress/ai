/**
 * External dependencies
 */
import {
	App,
	Header,
	HeaderActions,
	PinnedSidebars,
	MoreMenu,
	Sidebar,
	Footer,
} from 'wp-interface';

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __, _x, isRTL } from '@wordpress/i18n';
import { drawerLeft, drawerRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * Renders the full playground application.
 *
 * @since n.e.x.t
 *
 * @return The component to be rendered.
 */
export default function PlaygroundApp() {
	const labels = {
		header: __( 'Playground top bar', 'ai' ),
		body: __( 'Playground content', 'ai' ),
		sidebar: __( 'Playground sidebar', 'ai' ),
		actions: __( 'Playground actions', 'ai' ),
		footer: __( 'Playground footer', 'ai' ),
		keyboardShortcutsModalTitle: __( 'Keyboard shortcuts', 'ai' ),
		keyboardShortcutsModalCloseButtonLabel: __(
			'Close keyboard shortcuts modal',
			'ai'
		),
		keyboardShortcutsGlobalSectionTitle: __( 'Global shortcuts', 'ai' ),
	};

	const shortcutsDescriptions = {
		'keyboard-shortcuts': __( 'Display these keyboard shortcuts.', 'ai' ),
		'next-region': __( 'Navigate to the next part of the screen.', 'ai' ),
		'previous-region': __(
			'Navigate to the previous part of the screen.',
			'ai'
		),
		'toggle-distraction-free': __( 'Toggle distraction free mode.', 'ai' ),
		'toggle-sidebar': __( 'Show or hide the sidebar.', 'ai' ),
	};

	return (
		<App
			scope="ai"
			labels={ labels }
			shortcutsDescriptions={ shortcutsDescriptions }
		>
			<Header>
				<h1>{ __( 'AI Playground', 'ai' ) }</h1>
				<HeaderActions>
					<Button variant="primary">
						{ __( 'Perform Primary Action', 'ai' ) }
					</Button>
					<PinnedSidebars />
					<MoreMenu>
						{ () => (
							<>
								<MoreMenu.MenuGroup
									label={ _x( 'View', 'noun', 'ai' ) }
								>
									<MoreMenu.DistractionFreePreferenceToggleMenuItem />
								</MoreMenu.MenuGroup>
								<MoreMenu.MenuGroup
									label={ __( 'Tools', 'ai' ) }
								>
									<MoreMenu.KeyboardShortcutsMenuItem />
									<MoreMenu.ExternalLinkMenuItem href="https://ai-website.com">
										{ __( 'Learn more', 'ai' ) }
									</MoreMenu.ExternalLinkMenuItem>
								</MoreMenu.MenuGroup>
							</>
						) }
					</MoreMenu>
				</HeaderActions>
			</Header>

			<div className="ai-content">
				<p>{ __( 'Main content goes here.', 'ai' ) }</p>
			</div>

			<Sidebar
				identifier="primary-sidebar"
				title={ __( 'Primary sidebar', 'ai' ) }
				icon={ isRTL() ? drawerLeft : drawerRight }
				isActiveByDefault
			>
				<p>{ __( 'Sidebar content goes here.', 'ai' ) }</p>
			</Sidebar>

			<Footer>
				<p>{ __( 'Version 1.0', 'ai' ) }</p>
			</Footer>
		</App>
	);
}
