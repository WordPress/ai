/**
 * WordPress dependencies
 */
import { Notice, Stack } from '@wordpress/ui';
import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useDataRemovalSetting } from '../hooks/use-data-removal-setting';

/**
 * Renders the "remove all plugin data on uninstall" opt-in.
 *
 * Rendered as the content of a collapsible settings section. A warning Notice
 * accompanies the toggle because enabling it makes plugin deletion destructive:
 * the request logs table and all plugin options are permanently removed when
 * the plugin is uninstalled.
 *
 * @return {React.JSX.Element} The data removal setting.
 */
export function DataRemovalSetting(): React.JSX.Element {
	const { enabled, update } = useDataRemovalSetting();

	return (
		<Stack direction="column" gap="md">
			<Notice.Root intent="error">
				<Notice.Description>
					{ __(
						'When the plugin is deleted, permanently remove all of its data, including the request logs table and every plugin option. This cannot be undone. Deactivating the plugin leaves your data untouched.',
						'ai'
					) }
				</Notice.Description>
			</Notice.Root>
			<ToggleControl
				className="ai-data-removal-card__toggle"
				label={ __( 'Remove all plugin data on uninstall', 'ai' ) }
				checked={ enabled }
				onChange={ ( value ) => {
					void update( value );
				} }
			/>
		</Stack>
	);
}
