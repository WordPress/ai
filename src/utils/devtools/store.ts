/**
 * In-memory ring buffer of AI request records for Chrome DevTools 3P Tools.
 *
 * Uses window.__aiRequestLog as the backing store so records written by any
 * feature bundle are visible to the single DevTools listener registered by
 * whichever bundle loads first.
 */

export interface AIRequestRecord {
	id: string;
	ability: string;
	input: unknown;
	output: unknown;
	status: 'pending' | 'success' | 'error';
	startedAt: string;
	durationMs: number | null;
	error: string | null;
}

declare global {
	interface Window {
		__aiRequestLog?: AIRequestRecord[];
		__aiRequestLogNextId?: number;
	}
}

const MAX_RECORDS = 50;

/**
 * Returns the store of request records.
 *
 * @since x.x.x
 * @return {AIRequestRecord[]} List of request records.
 */
function getRecords(): AIRequestRecord[] {
	if ( ! window.__aiRequestLog ) {
		window.__aiRequestLog = [];
	}
	return window.__aiRequestLog;
}

/**
 * Returns the next request record ID.
 *
 * @since x.x.x
 * @return {string} The next request record ID.
 */
function nextId(): string {
	if ( ! window.__aiRequestLogNextId ) {
		window.__aiRequestLogNextId = 1;
	}
	return String( window.__aiRequestLogNextId++ );
}

/**
 * Records the start of an ability execution.
 *
 * @since x.x.x
 * @param {string}  ability The ability slug being executed.
 * @param {unknown} input   The input passed to the ability.
 * @return {string} The record ID, used to complete or error the record.
 */
export function recordStart( ability: string, input: unknown ): string {
	const id = nextId();

	const record: AIRequestRecord = {
		id,
		ability,
		input,
		output: null,
		status: 'pending',
		startedAt: new Date().toISOString(),
		durationMs: null,
		error: null,
	};

	const log = getRecords();
	log.push( record );

	if ( log.length > MAX_RECORDS ) {
		log.shift();
	}

	return id;
}

/**
 * Marks a pending record as successfully completed.
 *
 * @since x.x.x
 * @param {string}  id     The record ID returned by recordStart.
 * @param {unknown} output The ability response.
 */
export function recordComplete( id: string, output: unknown ): void {
	const record = getRecords().find( ( r: AIRequestRecord ) => r.id === id );
	if ( ! record ) {
		return;
	}
	record.output = output;
	record.status = 'success';
	record.durationMs =
		new Date().getTime() - new Date( record.startedAt ).getTime();
}

/**
 * Marks a pending record as failed.
 *
 * @since x.x.x
 * @param {string} id    The record ID returned by recordStart.
 * @param {string} error The error message.
 */
export function recordError( id: string, error: string ): void {
	const record = getRecords().find( ( r: AIRequestRecord ) => r.id === id );
	if ( ! record ) {
		return;
	}
	record.status = 'error';
	record.error = error;
	record.durationMs =
		new Date().getTime() - new Date( record.startedAt ).getTime();
}

/**
 * Returns recent records in reverse-chronological order.
 *
 * @since x.x.x
 * @param {number} limit Maximum number of records to return (1–50).
 * @return {AIRequestRecord[]} List of AI request records.
 */
export function getHistory( limit: number = 10 ): AIRequestRecord[] {
	const clamped = Math.min( Math.max( limit, 1 ), MAX_RECORDS );
	return getRecords().slice( -clamped ).reverse();
}
