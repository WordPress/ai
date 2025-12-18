/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const { aiImageGenerationData } = window as any;

/**
 * Uploads an image to the media library.
 *
 * @param {string} image The image to upload.
 * @return {Promise<{ id: number; url: string; title: string }>} A promise that resolves to the uploaded image data.
 */
export async function uploadImage( image: string ): Promise< {
	id: number;
	url: string;
	title: string;
} > {
	return apiFetch( {
		path: aiImageGenerationData?.importPath ?? '',
		method: 'POST',
		data: {
			input: {
				data: image,
				mime_type: 'image/png',
				title: __( 'AI Generated Image', 'ai' ),
				description: __( 'This is an AI generated image.', 'ai' ),
				meta: [
					{
						key: 'ai_generated',
						value: '1',
					},
				],
			},
		},
	} )
		.then( ( response: any ) => {
			if (
				response &&
				typeof response === 'object' &&
				'image' in response
			) {
				return response.image as {
					id: number;
					url: string;
					title: string;
				};
			}

			throw new Error( 'Invalid response from image import' );
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}
