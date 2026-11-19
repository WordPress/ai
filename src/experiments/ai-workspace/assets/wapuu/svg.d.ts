/**
 * SVG imports: `@wordpress/scripts` turns them into data URI strings.
 */

declare module '*.svg' {
	const src: string;
	export default src;
}
