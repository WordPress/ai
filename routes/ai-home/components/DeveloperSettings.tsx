/**
 * Internal dependencies
 */
import { ModelSelector } from './ModelSelector';

/**
 * DeveloperSettings component.
 *
 * Renders additional settings visible only when developer mode is active.
 *
 * @return {React.JSX.Element} The component.
 */
export function DeveloperSettings(): React.JSX.Element {
	return (
		<>
			<ModelSelector />
		</>
	);
}
