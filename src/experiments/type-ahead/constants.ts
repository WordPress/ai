/**
 * Constants for type-ahead.
 */

export const ALLOWED_BLOCKS = [ 'core/paragraph' ];
export const REQUEST_TIMEOUT_MS = 15000;
export const WHITESPACE_REGEX = /\s/;
export const LEADING_WHITESPACE_REGEX = /^\s/;
export const NO_SPACE_SCRIPT_REGEX =
	/[\p{scx=Han}\p{scx=Hiragana}\p{scx=Katakana}\p{scx=Thai}\p{scx=Lao}\p{scx=Khmer}\p{scx=Myanmar}\u3000-\u303F\uFF01-\uFF0F\uFF1A-\uFF20\uFF3B-\uFF40\uFF5B-\uFF65]/u;
