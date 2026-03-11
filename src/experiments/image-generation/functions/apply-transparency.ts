/**
 * Removes a specific chroma key color from a base64 image and returns base64 PNG.
 *
 * @param imageSourceBase64 Raw base64 image data (no data URL prefix).
 * @param targetRGB         The RGB color to key out (default: [0, 255, 0]).
 * @param threshold         How close a color needs to be to target RGB (0-255).
 * @return Raw base64 PNG data (no data URL prefix).
 */
export function applyTransparency(
	imageSourceBase64: string,
	targetRGB: [ number, number, number ] = [ 0, 255, 0 ],
	threshold: number = 80
): Promise< string > {
	return new Promise( ( resolve, reject ) => {
		const img = new Image();
		img.onload = () => {
			try {
				const canvas = document.createElement( 'canvas' );
				canvas.width = img.width;
				canvas.height = img.height;
				const ctx = canvas.getContext( '2d' );

				if ( ! ctx ) {
					reject( new Error( 'Canvas context not available' ) );
					return;
				}

				ctx.drawImage( img, 0, 0 );
				const imageData = ctx.getImageData(
					0,
					0,
					canvas.width,
					canvas.height
				);
				const data = imageData.data;

				for ( let i = 0; i < data.length; i += 4 ) {
					const r = data[ i ] ?? 0;
					const g = data[ i + 1 ] ?? 0;
					const b = data[ i + 2 ] ?? 0;
					const a = data[ i + 3 ] ?? 255;

					// Calculate Euclidean distance from the target color.
					const distance = Math.sqrt(
						Math.pow( r - targetRGB[ 0 ], 2 ) +
							Math.pow( g - targetRGB[ 1 ], 2 ) +
							Math.pow( b - targetRGB[ 2 ], 2 )
					);

					// A small feather region helps remove green halos on anti-aliased edges.
					const feather = Math.max(
						20,
						Math.round( threshold * 0.35 )
					);
					const isGreenDominant = g > r + 10 && g > b + 10;

					if ( isGreenDominant && distance < threshold ) {
						data[ i + 3 ] = 0; // Fully transparent.
					} else if (
						isGreenDominant &&
						distance < threshold + feather
					) {
						const ratio = ( distance - threshold ) / feather;
						// Gradually reduce alpha near the key boundary.
						data[ i + 3 ] = Math.max(
							0,
							Math.min( 255, Math.round( a * ratio ) )
						);

						// Light despill on edge pixels to reduce green fringe.
						data[ i + 1 ] = Math.max( r, b );
					}
				}

				ctx.putImageData( imageData, 0, 0 );
				const dataUrl = canvas.toDataURL( 'image/png' );
				resolve( dataUrl.replace( /^data:image\/png;base64,/, '' ) );
			} catch ( error ) {
				reject(
					error instanceof Error
						? error
						: new Error( 'Failed to process image transparency' )
				);
			}
		};
		img.onerror = () => {
			reject(
				new Error( 'Failed to load image for transparency processing' )
			);
		};
		img.src = `data:image/png;base64,${ imageSourceBase64 }`;
	} );
}
