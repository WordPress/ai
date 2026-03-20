import { PluginFile, SecurityIssue } from './types';

// Dangerous patterns for rudimentary security scan
const DANGEROUS_PATTERNS = [
	/\beval\s*\(/i,
	/\bexec\s*\(/i,
	/\bsystem\s*\(/i,
	/\bpassthru\s*\(/i,
	/\bshell_exec\s*\(/i,
	/\bproc_open\s*\(/i,
	/\bpopen\s*\(/i,
	/\bfile_put_contents\s*\(\s*\$_(GET|POST|REQUEST)/i,
	/\b(unlink|rmdir)\s*\(\s*\$_(GET|POST|REQUEST)/i,
	/\bbase64_decode\s*\(\s*\$_(GET|POST|REQUEST)/i,
	/\$_GET\b(?!.*\b(sanitize_|esc_|wp_verify_nonce|absint|intval))/i,
	/\$_POST\b(?!.*\b(sanitize_|esc_|wp_verify_nonce|absint|intval))/i,
];

export function scanFiles(files: PluginFile[]): {
	passed: boolean;
	issues: SecurityIssue[];
} {
	const issues: SecurityIssue[] = [];

	for (const file of files) {
		if (file.type !== 'php' || !file.content) {
			continue;
		}

		const lines = file.content.split('\n');
		for (let i = 0; i < lines.length; i++) {
			const line = lines[i];
			for (const pattern of DANGEROUS_PATTERNS) {
				if (pattern.test(line)) {
					issues.push({
						file_path: file.path,
						line: i + 1,
						pattern: pattern.toString(),
						line_content: line.trim(),
					});
				}
			}
		}
	}

	return {
		passed: issues.length === 0,
		issues: issues.slice(0, 10), // cap at 10 issues
	};
}
