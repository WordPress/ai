/**
 * Internal dependencies
 */
import { getSettings } from '../settings';

/**
 * Turns a provider into the class suffix the provider pill uses, like PHP's
 * `sanitize_title()`.
 *
 * @param provider The provider.
 * @return The class suffix.
 */
const toClassSuffix = ( provider: string ): string =>
	provider
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );

/**
 * Returns the translated label for a provider.
 *
 * @param provider The provider.
 * @return The label.
 */
export const getProviderLabel = ( provider: string ): string =>
	getSettings().providerLabels[ provider ] ?? provider;

interface ProviderBadgeProps {
	/** The provider. */
	provider: string;
	/**
	 * The server's label for it. Null when the server could not encode the
	 * label, in which case the localized label map is used instead.
	 */
	label: string | null;
}

/**
 * The provider pill.
 *
 * @param props          Component props.
 * @param props.provider The provider.
 * @param props.label    The server's label, or null.
 * @return The pill.
 */
export default function ProviderBadge( {
	provider,
	label,
}: ProviderBadgeProps ) {
	return (
		<span
			className={ `ability-provider ability-provider-${ toClassSuffix(
				provider
			) }` }
		>
			{ label ?? getProviderLabel( provider ) }
		</span>
	);
}
