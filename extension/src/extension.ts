import * as vscode from 'vscode';
import { PHPXCompiler } from './compiler';
import { PHPXDiagnosticsManager } from './diagnostics';
import { startLanguageClient, stopLanguageClient } from './languageClient';
import { initFileCache } from './positionMapper';
import {
	PHPXDefinitionProvider,
	PHPXTypeDefinitionProvider,
	PHPXReferenceProvider,
	PHPXSignatureHelpProvider,
	PHPXDocumentSymbolProvider,
	PHPXCodeActionProvider,
	PHPXRenameProvider,
	PHPXImplementationProvider,
} from './providers';
import {
	initTsxQuery,
	disposeTsxQuery,
	getTsxAttributeCompletions,
	getTsxAttributeHover,
	detectTagAtCursor,
	detectTagAndAttributeAtCursor,
} from './tsxQuery';

const PHPX_SELECTOR: vscode.DocumentSelector = {
	language: 'phpx',
	scheme: 'file',
};

/** Debounce timers per document URI */
const compilationTimers: Map<string, ReturnType<typeof setTimeout>> = new Map();

export function activate(context: vscode.ExtensionContext) {
	const outputChannel = vscode.window.createOutputChannel('PHPX');
	outputChannel.appendLine('PHPX Language Support extension is now active');

	// ─── PHPX Language Server (LSP) ─────────────────────────────────────────
	//
	// The PHP-based language server handles:
	//   • PHPX parse diagnostics (syntax errors, unclosed tags, etc.)
	//   • PHPX-specific completion (HTML tags, attributes, close tags)
	//   • PHPX-specific hover (attribute docs, component info, fragments)
	//
	// It communicates over stdio using JSON-RPC (Language Server Protocol).
	// ─────────────────────────────────────────────────────────────────────────

	const lspEnabled = vscode.workspace
		.getConfiguration('phpx')
		.get<boolean>('languageServer.enabled', true);

	if (lspEnabled) {
		const langClient = startLanguageClient(context, outputChannel);
		if (langClient) {
			context.subscriptions.push({ dispose: () => stopLanguageClient() });
			outputChannel.appendLine('[PHPX] Language Server client started');
		}
	}

	// ─── TypeScript JSX attribute query ──────────────────────────────────────
	//
	// Initialises a small virtual TypeScript project using phpx-intrinsics.d.ts
	// (the PHPX JSX type declarations, no React) in the extension's global
	// storage directory.  This lets us query VS Code's built-in TypeScript
	// language service for per-element HTML attribute completions and types.
	//
	// The query is purely additive — if the TypeScript server is unavailable
	// the PHPX LSP's own completions continue to work unchanged.
	// ─────────────────────────────────────────────────────────────────────────

	// Fire-and-forget: initialisation happens async so activation stays fast
	initTsxQuery(context).catch((err) => {
		outputChannel.appendLine(`[PHPX] TSX query init error: ${err}`);
	});

	// Supplementary completion provider: queries TypeScript for per-element
	// HTML attribute types and merges them with the PHPX LSP's own suggestions.
	context.subscriptions.push(
		vscode.languages.registerCompletionItemProvider(
			PHPX_SELECTOR,
			{
				async provideCompletionItems(
					document: vscode.TextDocument,
					position: vscode.Position,
				): Promise<vscode.CompletionItem[] | undefined> {
					const tagName = detectTagAtCursor(document, position);
					if (!tagName) {
						return undefined;
					}

					// Only trigger for HTML intrinsic elements (lowercase first char)
					if (tagName[0] !== tagName[0].toLowerCase() || tagName[0] === tagName[0].toUpperCase()) {
						return undefined;
					}

					return getTsxAttributeCompletions(tagName);
				},
			},
			' ',  // trigger on space (same as PHPX LSP)
			'\n', // and newline (for multi-line attribute lists)
		),
	);

	// Supplementary hover provider: queries TypeScript for attribute type info
	// when hovering over an HTML attribute name inside a PHPX tag.
	context.subscriptions.push(
		vscode.languages.registerHoverProvider(
			PHPX_SELECTOR,
			{
				async provideHover(
					document: vscode.TextDocument,
					position: vscode.Position,
				): Promise<vscode.Hover | undefined> {
					const hit = detectTagAndAttributeAtCursor(document, position);
					if (!hit) {
						return undefined;
					}

					const markdown = await getTsxAttributeHover(hit.tagName, hit.attrName);
					if (!markdown) {
						return undefined;
					}

					const range = new vscode.Range(
						position.line, hit.attrStart,
						position.line, hit.attrEnd,
					);
					return new vscode.Hover(
						new vscode.MarkdownString(markdown),
						range,
					);
				},
			},
		),
	);

	// ─── Compilation pipeline ────────────────────────────────────────────────
	//
	// The compiler creates a shadow .php file for each .phpx file. This is
	// needed so the PHP language server (Intelephense, DEVSENSE, etc.) can
	// provide PHP-level intelligence: go-to-definition, references, rename,
	// signature help, document symbols, code actions.
	//
	// The providers below delegate to the PHP language server using position
	// mapping between .phpx and .php files.
	// ─────────────────────────────────────────────────────────────────────────

	const compiler = new PHPXCompiler(outputChannel);
	const diagnosticsManager = new PHPXDiagnosticsManager();

	// Initialize file caching for position mapping
	context.subscriptions.push(initFileCache());

	/**
	 * Compile a PHPX document to its shadow .php file.
	 * This only writes the .php file — diagnostics are NOT managed here.
	 * PHPX parse diagnostics come from the LSP server.
	 * PHP-level diagnostics are forwarded by PHPXDiagnosticsManager.
	 */
	async function compileDocument(document: vscode.TextDocument) {
		if (document.languageId !== 'phpx') {
			return;
		}

		try {
			const phpUri = PHPXCompiler.getCompiledUri(document.uri);
			diagnosticsManager.registerMapping(document.uri, phpUri);

			const result = await compiler.compileAndWrite(document);

			if (result.error) {
				outputChannel.appendLine(
					`[PHPX] compile error: ${result.error}`,
				);
				diagnosticsManager.setCompilerError(document.uri, result.error);
			} else {
				diagnosticsManager.clearCompilerError(document.uri);
			}
		} catch (err) {
			const errorMessage = err instanceof Error ? err.message : String(err);
			const errorStack = err instanceof Error ? err.stack || err.message : String(err);
			outputChannel.appendLine(`[PHPX] compileDocument error — ${errorStack}`);
			diagnosticsManager.setCompilerError(document.uri, errorMessage);
		}
	}

	/**
	 * Schedule compilation with debouncing.
	 */
	function scheduleCompilation(document: vscode.TextDocument) {
		if (document.languageId !== 'phpx') {
			return;
		}

		const config = vscode.workspace.getConfiguration('phpx');
		if (!config.get<boolean>('compileOnChange', true)) {
			return;
		}

		const key = document.uri.toString();
		const existing = compilationTimers.get(key);
		if (existing) {
			clearTimeout(existing);
		}

		const delay = config.get<number>('compileDebounceMs', 500);
		compilationTimers.set(
			key,
			setTimeout(async () => {
				compilationTimers.delete(key);
				await compileDocument(document);
			}, delay),
		);
	}

	// Compile all already-open PHPX files on activation
	for (const document of vscode.workspace.textDocuments) {
		if (document.languageId === 'phpx') {
			compileDocument(document);
		}
	}

	// Compile when a PHPX file is opened
	context.subscriptions.push(
		vscode.workspace.onDidOpenTextDocument((document) => {
			if (document.languageId === 'phpx') {
				compileDocument(document);
			}
		}),
	);

	// Recompile on change (debounced)
	context.subscriptions.push(
		vscode.workspace.onDidChangeTextDocument((e) => {
			scheduleCompilation(e.document);
		}),
	);

	// Compile on save (immediate)
	context.subscriptions.push(
		vscode.workspace.onDidSaveTextDocument((document) => {
			if (document.languageId === 'phpx') {
				// Cancel any pending debounced compilation
				const key = document.uri.toString();
				const existing = compilationTimers.get(key);
				if (existing) {
					clearTimeout(existing);
					compilationTimers.delete(key);
				}
				compileDocument(document);
			}
		}),
	);

	// Invalidate caches when vendor/composer files change
	const vendorWatcher = vscode.workspace.createFileSystemWatcher('**/vendor/autoload.php');
	const clearCompilerCache = () => compiler.clearCache();
	vendorWatcher.onDidCreate(clearCompilerCache);
	vendorWatcher.onDidChange(clearCompilerCache);
	vendorWatcher.onDidDelete(clearCompilerCache);
	context.subscriptions.push(vendorWatcher);

	// Clean up when a PHPX file is closed
	context.subscriptions.push(
		vscode.workspace.onDidCloseTextDocument((document) => {
			if (document.languageId === 'phpx') {
				const phpUri = PHPXCompiler.getCompiledUri(document.uri);
				diagnosticsManager.clearDiagnostics(document.uri);
				diagnosticsManager.unregisterMapping(phpUri);
			}
		}),
	);

	// ─── PHP Intelligence Providers (position-mapped delegation) ─────────────
	//
	// These providers delegate to the PHP language server for PHP-level
	// intelligence, translating positions between .phpx and compiled .php.
	// Completion and hover are NOT registered here — the LSP server handles
	// those natively without needing position mapping.
	// ─────────────────────────────────────────────────────────────────────────

	// Go to Definition
	context.subscriptions.push(
		vscode.languages.registerDefinitionProvider(
			PHPX_SELECTOR,
			new PHPXDefinitionProvider(),
		),
	);

	// Go to Type Definition
	context.subscriptions.push(
		vscode.languages.registerTypeDefinitionProvider(
			PHPX_SELECTOR,
			new PHPXTypeDefinitionProvider(),
		),
	);

	// Find References
	context.subscriptions.push(
		vscode.languages.registerReferenceProvider(
			PHPX_SELECTOR,
			new PHPXReferenceProvider(),
		),
	);

	// Signature Help
	context.subscriptions.push(
		vscode.languages.registerSignatureHelpProvider(
			PHPX_SELECTOR,
			new PHPXSignatureHelpProvider(),
			{ triggerCharacters: ['(', ','], retriggerCharacters: [','] },
		),
	);

	// Document Symbols (Outline)
	context.subscriptions.push(
		vscode.languages.registerDocumentSymbolProvider(
			PHPX_SELECTOR,
			new PHPXDocumentSymbolProvider(),
		),
	);

	// Code Actions
	context.subscriptions.push(
		vscode.languages.registerCodeActionsProvider(
			PHPX_SELECTOR,
			new PHPXCodeActionProvider(),
		),
	);

	// Rename
	context.subscriptions.push(
		vscode.languages.registerRenameProvider(
			PHPX_SELECTOR,
			new PHPXRenameProvider(),
		),
	);

	// Implementation
	context.subscriptions.push(
		vscode.languages.registerImplementationProvider(
			PHPX_SELECTOR,
			new PHPXImplementationProvider(),
		),
	);

	// ─── Commands ────────────────────────────────────────────────────────────

	context.subscriptions.push(
		vscode.commands.registerCommand('phpx.compileFile', async () => {
			const editor = vscode.window.activeTextEditor;
			if (!editor || editor.document.languageId !== 'phpx') {
				vscode.window.showWarningMessage('No active PHPX file to compile.');
				return;
			}
			await compileDocument(editor.document);
			vscode.window.showInformationMessage('PHPX file compiled successfully.');
		}),
	);

	context.subscriptions.push(
		vscode.commands.registerCommand('phpx.showCompiledOutput', async () => {
			const editor = vscode.window.activeTextEditor;
			if (!editor || editor.document.languageId !== 'phpx') {
				vscode.window.showWarningMessage('No active PHPX file.');
				return;
			}
			const phpUri = PHPXCompiler.getCompiledUri(editor.document.uri);
			try {
				const doc = await vscode.workspace.openTextDocument(phpUri);
				await vscode.window.showTextDocument(doc, {
					viewColumn: vscode.ViewColumn.Beside,
					preserveFocus: true,
				});
			} catch {
				vscode.window.showErrorMessage(
					'Compiled PHP file not found. Try compiling first.',
				);
			}
		}),
	);

	// ─── Cleanup ─────────────────────────────────────────────────────────────

	context.subscriptions.push(outputChannel);
	context.subscriptions.push(diagnosticsManager);
}

export async function deactivate() {
	// Clear all pending compilation timers
	for (const timer of compilationTimers.values()) {
		clearTimeout(timer);
	}
	compilationTimers.clear();

	// Clean up the TypeScript JSX attribute query environment
	disposeTsxQuery();

	// Stop the PHPX Language Server
	await stopLanguageClient();
}
