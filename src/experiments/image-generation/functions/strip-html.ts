/**
 * Strips HTML tags from a string, preserving text content.
 *
 * Also removes linebreaks from the text content.
 *
 * @param {string} html The HTML string to strip.
 * @return {string} The text content without HTML tags.
 */
export function stripHTML( html: string ): string {
	const tempDiv = document.createElement( 'div' );
	tempDiv.innerHTML = html;
	const textContent = tempDiv.textContent || tempDiv.innerText || '';
	return textContent.replace( /\n/g, '' );
}
