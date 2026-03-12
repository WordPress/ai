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

/**
 * Loads an image URL onto a larger canvas (with transparent borders) and
 * returns the result as a PNG data URI. The original image is centered on the
 * new canvas. The transparent borders act as an implicit mask so image-editing
 * models know which areas to fill.
 *
 * @param {string} url   URL of the source image. Must be CORS-accessible.
 * @param {number} scale Factor by which to multiply each dimension (default 1.5).
 * @return {Promise<string>} PNG data URI of the expanded canvas.
 */
export async function prepareExpandCanvas(
	url: string,
	scale: number = 1.5
): Promise< string > {
	const MAX_DIMENSION = 4096;

	const img = await new Promise< HTMLImageElement >( ( resolve, reject ) => {
		const el = new Image();
		el.crossOrigin = 'anonymous';
		el.onload = () => resolve( el );
		el.onerror = () =>
			reject( new Error( `Failed to load image: ${ url }` ) );
		el.src = url;
	} );

	const srcW = img.naturalWidth;
	const srcH = img.naturalHeight;

	const rawW = Math.round( srcW * scale );
	const rawH = Math.round( srcH * scale );

	// Cap both dimensions at MAX_DIMENSION while preserving aspect ratio.
	const capScale = Math.min( 1, MAX_DIMENSION / rawW, MAX_DIMENSION / rawH );
	const canvasW = Math.round( rawW * capScale );
	const canvasH = Math.round( rawH * capScale );

	// Scale the original image to fit the new canvas proportionally.
	const imgScale = capScale * scale; // combined scaling from src to canvas slot
	const slotW = Math.round( srcW * imgScale );
	const slotH = Math.round( srcH * imgScale );

	const offsetX = Math.round( ( canvasW - slotW ) / 2 );
	const offsetY = Math.round( ( canvasH - slotH ) / 2 );

	const canvas = document.createElement( 'canvas' );
	canvas.width = canvasW;
	canvas.height = canvasH;

	const ctx = canvas.getContext( '2d' );
	if ( ! ctx ) {
		throw new Error( 'Could not get 2D canvas context.' );
	}

	// Leave canvas transparent (default) — transparent borders are the mask.
	ctx.drawImage( img, offsetX, offsetY, slotW, slotH );

	return canvas.toDataURL( 'image/png' );
}
