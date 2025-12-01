/**
 * External dependencies
 */
import { MoreMenu } from 'wp-interface';

/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';

/**
 * Renders the More menu to display in the header of the playground app.
 *
 * @since n.e.x.t
 *
 * @return The component to be rendered.
 */
export default function PlaygroundMoreMenu() {
	const { settingsUrl, supportUrl, contributingUrl } = useSelect( () => {
		return {
			settingsUrl: '/wp-admin/admin.php?page=wp-ai-client-settings', // TODO: Fix this.
			supportUrl: 'https://wordpress.org/support/plugin/ai/',
			contributingUrl: 'https://github.com/WordPress/ai',
		};
	}, [] );

	return (
		<MoreMenu
			menuLabel={ __( 'Options', 'ai' ) }
			externalLinkA11yHint={
				/* translators: accessibility text */
				__( '(opens in a new tab)', 'ai' )
			}
		>
			{ () => (
				<>
					<MoreMenu.MenuGroup label={ _x( 'View', 'noun', 'ai' ) }>
						<MoreMenu.DistractionFreePreferenceToggleMenuItem
							menuItemLabel={ __( 'Distraction free', 'ai' ) }
							menuItemInfo={ __(
								'Hide secondary interface to help focus',
								'ai'
							) }
							messageActivated={ __(
								'Distraction free mode activated',
								'ai'
							) }
							messageDeactivated={ __(
								'Distraction free mode deactivated',
								'ai'
							) }
						/>
					</MoreMenu.MenuGroup>
					<MoreMenu.MenuGroup label={ __( 'Tools', 'ai' ) }>
						<MoreMenu.KeyboardShortcutsMenuItem
							menuItemLabel={ __( 'Keyboard shortcuts', 'ai' ) }
						/>
						{ !! settingsUrl && (
							<MoreMenu.InternalLinkMenuItem href={ settingsUrl }>
								{ __( 'AI Credentials Settings', 'ai' ) }
							</MoreMenu.InternalLinkMenuItem>
						) }
					</MoreMenu.MenuGroup>
					<MoreMenu.MenuGroup label={ __( 'Resources', 'ai' ) }>
						{ !! supportUrl && (
							<MoreMenu.ExternalLinkMenuItem href={ supportUrl }>
								{ __( 'Support', 'ai' ) }
							</MoreMenu.ExternalLinkMenuItem>
						) }
						{ !! contributingUrl && (
							<MoreMenu.ExternalLinkMenuItem
								href={ contributingUrl }
							>
								{ __( 'Contributing', 'ai' ) }
							</MoreMenu.ExternalLinkMenuItem>
						) }
					</MoreMenu.MenuGroup>
				</>
			) }
		</MoreMenu>
	);
}
