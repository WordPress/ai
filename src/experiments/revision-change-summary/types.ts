/**
 * Revision change summary types.
 */

export interface RevisionChangeSummaryData {
	enabled: boolean;
	// `wp_localize_script()` passes numbers as strings.
	postId?: number | string;
}

export interface RevisionChangeSummaryResult {
	summary: string;
	changes: string[];
	truncated: boolean;
	from_revision_id: number;
	revision_id: number;
}

declare global {
	interface Window {
		aiRevisionChangeSummaryData?: RevisionChangeSummaryData;
	}
}
