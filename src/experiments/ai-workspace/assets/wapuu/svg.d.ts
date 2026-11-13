/**
 * Type declaration for SVG imports.
 *
 * `@wordpress/scripts` runs an SVG imported from script through `url-loader`,
 * so its default export is the file as a data URI.
 */

declare module '*.svg' {
	const src: string;
	export default src;
}
