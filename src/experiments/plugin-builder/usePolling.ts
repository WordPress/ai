import { useRef, useCallback } from '@wordpress/element';

/**
 * Executes a callback periodically.
 * If the callback returns `true`, polling stops.
 * If the callback returns `false`, polling continues.
 */
export function usePolling(callback: () => Promise<boolean>, intervalMs: number = 2000) {
	const timerRef = useRef<number | null>(null);

	const stop = useCallback(() => {
		if (timerRef.current !== null) {
			window.clearTimeout(timerRef.current);
			timerRef.current = null;
		}
	}, []);

	const start = useCallback(() => {
		stop();

		const poll = async () => {
			try {
				const shouldStop = await callback();
				if (shouldStop) {
					stop();
					return;
				}
			} catch (e) {
				// Continue polling even on error unless manually stopped
				console.error('Polling error', e);
			}

			// Schedule next tick
			timerRef.current = window.setTimeout(poll, intervalMs);
		};

		// Start first tick immediately
		void poll();
	}, [callback, intervalMs, stop]);

	return { start, stop };
}
