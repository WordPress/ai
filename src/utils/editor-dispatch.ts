/**
 * WordPress dependencies
 */
import { useDispatch } from '@wordpress/data';

type EditorDispatch = {
	editPost: ( edits: Record< string, unknown > ) => void;
};

type EditPostDispatch = {
	openGeneralSidebar?: ( panelName: string ) => void;
};

const useNamedDispatch = useDispatch as unknown as (
	storeName?: string
) => unknown;

/**
 * Returns a typed dispatcher for the core editor store.
 *
 * The published `@wordpress/data` typings currently narrow `useDispatch()`
 * to the block editor store in this repo setup, so centralize the workaround.
 *
 * @return {EditorDispatch} Editor store action creators.
 */
export const useEditorDispatch = (): EditorDispatch =>
	useNamedDispatch( 'core/editor' ) as EditorDispatch;

/**
 * Returns a typed dispatcher for the edit-post store.
 *
 * @return {EditPostDispatch} Edit post store action creators.
 */
export const useEditPostDispatch = (): EditPostDispatch =>
	useNamedDispatch( 'core/edit-post' ) as EditPostDispatch;
