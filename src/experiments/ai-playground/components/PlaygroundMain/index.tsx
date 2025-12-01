/**
 * Internal dependencies
 */
import SystemInstruction from './system-instruction';
import Messages from './messages';
import Input from './input';
import './style.scss';

/**
 * Renders the playground main content.
 *
 * @since n.e.x.t
 *
 * @return The component to be rendered.
 */
export default function PlaygroundMain() {
	return (
		<div className="ai-playground__main">
			<SystemInstruction />
			<Messages />
			<Input />
		</div>
	);
}
