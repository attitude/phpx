import * as fs from 'fs';
import * as vscode from 'vscode';

/**
 * Position mapping between PHPX source and compiled PHP.
 *
 * The PHPX compiler preserves line counts, so the line number is always
 * the same. However, inside JSX regions the column offsets differ because
 * `<tag attr={$val}>text</tag>` becomes `['$', 'tag', ['attr'=>($val)], ['text']]`.
 *
 * This module resolves positions by finding the word/symbol under the cursor
 * in the PHPX source, then locating the same token on the corresponding line
 * of the compiled PHP (and vice-versa).
 */

/**
 * PHP identifier pattern: variable ($name) or identifier (name).
 * Matches the same tokens the PHP language server would consider "words".
 */
const PHP_WORD_PATTERN = /\$?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*/g;

/**
 * File content cache with mtime validation.
 * Maps fsPath → { content, mtime }
 * This avoids synchronous disk reads on every hover/completion/definition request.
 */
const fileCache = new Map<string, { content: string; mtime: number }>();

/**
 * Initialize file watching to invalidate cache on changes.
 * Should be called once during extension activation.
 */
export function initFileCache(): vscode.Disposable {
	// Watch for file changes to invalidate cache
	const fileWatcher = vscode.workspace.createFileSystemWatcher(
		'**/*.{php,phpx}',
	);

	const invalidateOnChange = (uri: vscode.Uri) => {
		fileCache.delete(uri.fsPath);
	};

	fileWatcher.onDidChange(invalidateOnChange);
	fileWatcher.onDidCreate(invalidateOnChange);
	fileWatcher.onDidDelete(invalidateOnChange);

	// Also invalidate cache when documents are saved (for quick updates)
	const savedDisposable = vscode.workspace.onDidSaveTextDocument((doc) => {
		fileCache.delete(doc.uri.fsPath);
	});

	return vscode.Disposable.from(fileWatcher, savedDisposable);
}

/**
 * Get file content with intelligent caching.
 * - Prioritizes open documents in VS Code workspace
 * - Falls back to cache with mtime validation
 * - Finally reads from disk
 */
function getFileContent(uri: vscode.Uri): string | null {
	const fsPath = uri.fsPath;

	// 1. Check if file is open in workspace (most up-to-date)
	const openDoc = vscode.workspace.textDocuments.find(
		(doc) => doc.uri.fsPath === fsPath,
	);
	if (openDoc) {
		return openDoc.getText();
	}

	// 2. Check cache with mtime validation
	const cached = fileCache.get(fsPath);
	if (cached) {
		try {
			const stat = fs.statSync(fsPath);
			if (stat.mtimeMs === cached.mtime) {
				return cached.content;
			}
		} catch {
			// File was deleted or access denied; clear cache
			fileCache.delete(fsPath);
		}
	}

	// 3. Read from disk and cache
	try {
		const content = fs.readFileSync(fsPath, 'utf-8');
		const stat = fs.statSync(fsPath);
		fileCache.set(fsPath, { content, mtime: stat.mtimeMs });
		return content;
	} catch {
		return null;
	}
}

/**
 * Given a PHPX document and a cursor position, return the mapped position
 * in the compiled PHP file where the same word/token appears on the same line.
 *
 * Returns the original position if no word is found or mapping fails (this is
 * fine for pure-PHP lines that are identical in both files).
 */
export function mapPositionToPhp(
	document: vscode.TextDocument,
	position: vscode.Position,
	phpUri: vscode.Uri,
): vscode.Position {
	// Get the word at the cursor in the PHPX source
	const wordRange = document.getWordRangeAtPosition(position, PHP_WORD_PATTERN);
	if (!wordRange) {
		return position;
	}
	const word = document.getText(wordRange);
	if (!word) {
		return position;
	}

	// Determine which occurrence this is on the PHPX line
	// (handles cases where the same variable appears multiple times)
	const phpxLine = document.lineAt(position.line).text;
	const occurrenceIndex = getNthOccurrence(
		phpxLine,
		word,
		wordRange.start.character,
	);

	// Get compiled PHP file content (uses cache when available)
	const phpContent = getFileContent(phpUri);
	if (!phpContent) {
		return position;
	}

	const phpLines = phpContent.split('\n');
	if (position.line >= phpLines.length) {
		return position;
	}
	const phpLine = phpLines[position.line];

	// Find the same occurrence of the word on the PHP line
	const phpCol = findNthOccurrence(phpLine, word, occurrenceIndex);
	if (phpCol === -1) {
		return position;
	}

	return new vscode.Position(position.line, phpCol);
}

/**
 * Given a PHP file line and a position in it, map back to the PHPX source position.
 * Used for reverse-mapping results from the PHP language server.
 */
export function mapPositionToPhpx(
	phpUri: vscode.Uri,
	phpxUri: vscode.Uri,
	position: vscode.Position,
): vscode.Position {
	// Get file contents (uses cache when available)
	const phpContent = getFileContent(phpUri);
	const phpxContent = getFileContent(phpxUri);

	if (!phpContent || !phpxContent) {
		return position;
	}

	const phpLines = phpContent.split('\n');
	const phpxLines = phpxContent.split('\n');

	if (position.line >= phpLines.length || position.line >= phpxLines.length) {
		return position;
	}

	const phpLine = phpLines[position.line];
	const phpxLine = phpxLines[position.line];

	// If the lines are identical, no mapping needed
	if (phpLine === phpxLine) {
		return position;
	}

	// Find what word is at the position in the PHP line
	const word = getWordAt(phpLine, position.character);
	if (!word) {
		return position;
	}

	const occurrenceIndex = getNthOccurrence(phpLine, word.text, word.start);
	const phpxCol = findNthOccurrence(phpxLine, word.text, occurrenceIndex);
	if (phpxCol === -1) {
		return position;
	}

	return new vscode.Position(position.line, phpxCol);
}

/**
 * Map a Range from PHP back to PHPX source.
 *
 * For single-line ranges (token/symbol ranges) the end position often sits
 * exactly one character past the word boundary, which `getWordAt` won't match.
 * Instead of mapping the end independently, we preserve the original token
 * length relative to the mapped start. For multi-line ranges (e.g. a full
 * function definition) we fall back to mapping both endpoints individually.
 */
export function mapRangeToPhpx(
	phpUri: vscode.Uri,
	phpxUri: vscode.Uri,
	range: vscode.Range,
): vscode.Range {
	const start = mapPositionToPhpx(phpUri, phpxUri, range.start);
	if (range.start.line === range.end.line) {
		const length = range.end.character - range.start.character;
		const end = new vscode.Position(range.end.line, start.character + length);
		return new vscode.Range(start, end);
	}
	const end = mapPositionToPhpx(phpUri, phpxUri, range.end);
	return new vscode.Range(start, end);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Get the word (PHP identifier or variable) at a character offset in a line.
 */
function getWordAt(
	line: string,
	charOffset: number,
): { text: string; start: number } | null {
	const matches = [...line.matchAll(PHP_WORD_PATTERN)];
	for (const match of matches) {
		const start = match.index!;
		const end = start + match[0].length;
		if (charOffset >= start && charOffset < end) {
			return { text: match[0], start };
		}
	}
	return null;
}

/**
 * Determine which occurrence (0-based) of `word` the `charOffset` falls within
 * on the given line.
 */
function getNthOccurrence(
	line: string,
	word: string,
	charOffset: number,
): number {
	let n = 0;
	const pattern = new RegExp(PHP_WORD_PATTERN.source, 'g');
	let match: RegExpExecArray | null;
	while ((match = pattern.exec(line)) !== null) {
		if (match[0] !== word) {
			continue;
		}
		if (match.index === charOffset) {
			return n;
		}
		// Also match if cursor is inside the word
		if (charOffset >= match.index && charOffset < match.index + word.length) {
			return n;
		}
		n++;
	}
	return 0; // fallback: first occurrence
}

/**
 * Find the starting character offset of the nth occurrence (0-based) of `word`
 * in the given line. Returns -1 if not found.
 */
function findNthOccurrence(line: string, word: string, n: number): number {
	let count = 0;
	const pattern = new RegExp(PHP_WORD_PATTERN.source, 'g');
	let match: RegExpExecArray | null;
	while ((match = pattern.exec(line)) !== null) {
		if (match[0] !== word) {
			continue;
		}
		if (count === n) {
			return match.index;
		}
		count++;
	}
	return -1;
}
