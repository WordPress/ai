/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { store as playgroundStore } from '../../store';

/**
 * Renders the loader UI.
 *
 * @since n.e.x.t
 *
 * @return The component to be rendered.
 */
export default function Loader() {
	const loading = useSelect(
		( select ) => select( playgroundStore ).isLoading(),
		[]
	);

	if ( ! loading ) {
		return null;
	}

	return (
		<div className="ai-playground__loader-container">
			<svg
				width="50px"
				height="21px"
				viewBox="0 0 132 58"
				version="1.1"
				xmlns="http://www.w3.org/2000/svg"
			>
				<g stroke="none" fill="none">
					<g className="ai-playground__loader">
						<circle
							className="ai-playground__loader-dot"
							cx="25"
							cy="30"
							r="13"
						></circle>
						<circle
							className="ai-playground__loader-dot"
							cx="65"
							cy="30"
							r="13"
						></circle>
						<circle
							className="ai-playground__loader-dot"
							cx="105"
							cy="30"
							r="13"
						></circle>
					</g>
				</g>
			</svg>
		</div>
	);
}
