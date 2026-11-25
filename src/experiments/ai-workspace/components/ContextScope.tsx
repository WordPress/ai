/**
 * The context-scope control.
 */

/**
 * WordPress dependencies
 */
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { check as checkIcon, globe as globeIcon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { ContextScope as Scope } from '../types';

/**
 * Renders the two scopes the turn route accepts.
 *
 * @param props          Component props.
 * @param props.value    The selected scope.
 * @param props.onChange Change handler.
 * @param props.disabled Whether the control is disabled.
 * @return The rendered control.
 */
export default function ContextScope( {
	value,
	onChange,
	disabled,
}: {
	value: Scope;
	onChange: ( scope: Scope ) => void;
	disabled: boolean;
} ) {
	// Named rather than indexed so `selected` needs no fallback.
	const site = {
		value: 'site' as const,
		label: __( 'Site Context', 'ai' ),
		info: __(
			'Looks up your posts, pages and taxonomies, and can only ever read content you are allowed to read.',
			'ai'
		),
	};
	const general = {
		value: 'general' as const,
		label: __( 'General Knowledge', 'ai' ),
		info: __(
			'Gives the assistant no access to this site. No web browsing.',
			'ai'
		),
	};

	const scopes = [ site, general ];
	const selected = 'general' === value ? general : site;

	return (
		<DropdownMenu
			className="ai-workspace__scope"
			icon={ globeIcon }
			text={ selected.label }
			label={ __( 'Context scope', 'ai' ) }
			toggleProps={ {
				disabled,
				accessibleWhenDisabled: true,
				size: 'compact',
				variant: 'tertiary',
			} }
			popoverProps={ { placement: 'top-start' } }
		>
			{ ( { onClose }: { onClose: () => void } ) => (
				<MenuGroup label={ __( 'Context scope', 'ai' ) }>
					{ scopes.map( ( scope ) => (
						<MenuItem
							key={ scope.value }
							role="menuitemradio"
							isSelected={ scope.value === value }
							icon={ scope.value === value ? checkIcon : null }
							info={ scope.info }
							onClick={ () => {
								onChange( scope.value );
								onClose();
							} }
						>
							{ scope.label }
						</MenuItem>
					) ) }
				</MenuGroup>
			) }
		</DropdownMenu>
	);
}
