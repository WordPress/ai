/**
 * Collection of image utilities.
 */

/**
 * Fetches an image from a URL and returns it as a base64 data URI
 * (e.g. `data:image/jpeg;base64,...`).
 *
 * @param {string} url The URL of the image to convert.
 * @return {Promise<string>} Base64 data URI string.
 */
export async function urlToBase64( url: string ): Promise< string > {
	const response = await fetch( url );
	const blob = await response.blob();
	const mimeType = blob.type || 'image/jpeg';
	const buffer = await blob.arrayBuffer();
	const bytes = new Uint8Array( buffer );
	let binary = '';

	for ( let i = 0; i < bytes.byteLength; i++ ) {
		binary += String.fromCharCode( bytes[ i ] as number );
	}

	return `data:${ mimeType };base64,${ btoa( binary ) }`;
}
