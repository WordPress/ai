/**
 * External dependencies
 */
import {
	App,
	Header,
	HeaderActions,
	Footer,
	Sidebar,
	PinnedSidebars,
} from 'wp-interface';

/**
 * WordPress dependencies
 */
import { __, isRTL } from '@wordpress/i18n';
import { drawerLeft, drawerRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import PlaygroundMoreMenu from '../PlaygroundMoreMenu';
import PlaygroundStatus from '../PlaygroundStatus';
import PlaygroundMain from '../PlaygroundMain';
import PlaygroundCapabilitiesPanel from '../PlaygroundCapabilitiesPanel';
import PlaygroundProviderModelPanel from '../PlaygroundProviderModelPanel';
import PlaygroundModelConfigPanel from '../PlaygroundModelConfigPanel';
import SystemInstructionToggle from './system-instruction-toggle';
import ResetMessagesButton from './reset-messages-button';
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
					<ResetMessagesButton />
					<SystemInstructionToggle />
					<PinnedSidebars />
					<PlaygroundMoreMenu />
				</HeaderActions>
			</Header>
			<PlaygroundMain />
			<Sidebar
				identifier="ai/playground-sidebar"
				title={ __( 'AI Configuration', 'ai' ) }
				icon={ isRTL() ? drawerLeft : drawerRight }
				header={
					<h2 className="interface-complementary-area-header__title">
						{ __( 'AI Configuration', 'ai' ) }
					</h2>
				}
				isActiveByDefault
			>
				<PlaygroundCapabilitiesPanel />
				<PlaygroundProviderModelPanel />
				<PlaygroundModelConfigPanel />
			</Sidebar>
			<Footer>
				<PlaygroundStatus />
			</Footer>
		</App>
	);
}
