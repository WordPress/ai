/**
 * Transforms an error object into a user-facing error message.
 *
 * @since n.e.x.t
 *
 * @param error - The error to transform.
 * @return The error message as a string.
 */
export function errorToString( error: unknown ): string {
	if ( error instanceof Error ) {
		return error.message;
	}
	if ( typeof error === 'object' && error !== null && 'message' in error ) {
		return String( error.message );
	}
	return String( error );
}

/**
 * Logs an error message to the console.
 *
 * @since n.e.x.t
 *
 * @param error - The error to log.
 */
export function logError( error: unknown ): void {
	const message = errorToString( error );
	console.error( message ); // eslint-disable-line no-console
}

/**
 * Returns the base64-encoded data URL representation of the given file URL.
 *
 * @since n.e.x.t
 *
 * @param file     - The file URL.
 * @param mimeType - Optional. The MIME type of the file, to prefix `data:{mime_type};base64,`. Default empty string.
 * @return The base64-encoded file data URL, or empty string on failure.
 */
export async function fileToBase64DataUrl(
	file: string,
	mimeType: string = ''
): Promise< string > {
	const blob = await fileToBlob( file, mimeType );
	if ( ! blob ) {
		return '';
	}

	return blobToBase64DataUrl( blob );
}

/**
 * Returns the binary data blob representation of the given file URL.
 *
 * @since n.e.x.t
 *
 * @param file     - The file URL.
 * @param mimeType - Optional. The MIME type of the file, to override detected MIME type. Default empty string.
 * @return The binary data blob, or null on failure.
 */
export async function fileToBlob(
	file: string,
	mimeType: string = ''
): Promise< Blob | null > {
	const data = await fetch( file );
	const blob = await data.blob();
	if ( ! blob ) {
		return null;
	}
	if ( mimeType && mimeType !== blob.type ) {
		return new Blob( [ blob ], { type: mimeType } );
	}
	return blob;
}

/**
 * Returns the base64-encoded data URL representation of the given binary data blob.
 *
 * @since n.e.x.t
 *
 * @param blob - The binary data blob.
 * @return The base64-encoded data URL, or empty string on failure.
 */
export async function blobToBase64DataUrl( blob: Blob ): Promise< string > {
	const base64DataUrl = await new Promise( ( resolve ) => {
		const reader = new window.FileReader();
		reader.readAsDataURL( blob );
		reader.onloadend = () => {
			const base64data = reader.result;
			resolve( base64data );
		};
	} );

	if ( typeof base64DataUrl !== 'string' ) {
		return '';
	}

	return base64DataUrl;
}

/**
 * Returns the binary data blob representation of the given base64-encoded data URL.
 *
 * @since n.e.x.t
 *
 * @param base64DataUrl - The base64-encoded data URL.
 * @return The binary data blob, or null on failure.
 */
export async function base64DataUrlToBlob(
	base64DataUrl: string
): Promise< Blob | null > {
	const prefixMatch = base64DataUrl.match(
		/^data:([a-z0-9-]+\/[a-z0-9-]+);base64,/
	);
	if ( ! prefixMatch ) {
		return null;
	}

	const base64Data = base64DataUrl.substring( prefixMatch[ 0 ].length );
	const binaryData = atob( base64Data );
	const byteArrays = [];

	for ( let offset = 0; offset < binaryData.length; offset += 512 ) {
		const slice = binaryData.slice( offset, offset + 512 );

		const byteNumbers = new Array( slice.length );
		for ( let i = 0; i < slice.length; i++ ) {
			byteNumbers[ i ] = slice.charCodeAt( i );
		}
		byteArrays.push( new Uint8Array( byteNumbers ) );
	}

	return new Blob( byteArrays, {
		type: prefixMatch[ 1 ],
	} );
}
