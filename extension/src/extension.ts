import * as vscode from 'vscode';
import { PHPXCompiler } from './compiler';
import { PHPXDiagnosticsManager } from './diagnostics';
import {
	PHPXCompletionProvider,
	PHPXHoverProvider,
	PHPXDefinitionProvider,
	PHPXTypeDefinitionProvider,
	PHPXReferenceProvider,
	PHPXSignatureHelpProvider,
	PHPXDocumentSymbolProvider,
	PHPXCodeActionProvider,
	PHPXRenameProvider,
	PHPXImplementationProvider,
} from './providers';

const PHPX_SELECTOR: vscode.DocumentSelector = {
	language: 'phpx',
	scheme: 'file',
};

/** Debounce timers per document URI */
const compilationTimers: Map<string, ReturnType<typeof setTimeout>> = new Map();

export function activate(context: vscode.ExtensionContext) {
	const outputChannel = vscode.window.createOutputChannel('PHPX');
	outputChannel.appendLine('PHPX Language Support extension is now active');

	const compiler = new PHPXCompiler(outputChannel);
	const diagnosticsManager = new PHPXDiagnosticsManager();

	// ─── Compile on open / change / save ─────────────────────────────────────

	/**
	 * Compile a PHPX document and update diagnostics.
	 */
	async function compileDocument(document: vscode.TextDocument) {
		if (document.languageId !== 'phpx') {
			return;
		}

		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		diagnosticsManager.registerMapping(document.uri, phpUri);

		const result = await compiler.compileAndWrite(document);

		if (result.error) {
			diagnosticsManager.setCompilationError(document.uri, result.error);
		} else {
			diagnosticsManager.clearCompilationError(document.uri);
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

	// Clean up diagnostics when a PHPX file is closed
	context.subscriptions.push(
		vscode.workspace.onDidCloseTextDocument((document) => {
			if (document.languageId === 'phpx') {
				const phpUri = PHPXCompiler.getCompiledUri(document.uri);
				diagnosticsManager.clearDiagnostics(document.uri);
				diagnosticsManager.unregisterMapping(phpUri);
			}
		}),
	);

	// ─── Register Language Feature Providers ─────────────────────────────────

	// Completion
	context.subscriptions.push(
		vscode.languages.registerCompletionItemProvider(
			PHPX_SELECTOR,
			new PHPXCompletionProvider(),
			// Trigger characters for PHP completions
			'.',
			'>',
			'$',
			'\\',
			':',
			'<',
		),
	);

	// Hover (includes PHPX-specific hovers + delegated PHP hovers)
	context.subscriptions.push(
		vscode.languages.registerHoverProvider(
			PHPX_SELECTOR,
			new PHPXHoverProvider(),
		),
	);

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

	// ─── PHPX-specific Hover (Fragments, etc.) ───────────────────────────────

	context.subscriptions.push(
		vscode.languages.registerHoverProvider(PHPX_SELECTOR, {
			provideHover(document, position, _token) {
				const range = document.getWordRangeAtPosition(position);
				if (!range) {
					return null;
				}

				const word = document.getText(range);
				const linePrefix = document.getText(
					new vscode.Range(position.line, 0, position.line, position.character),
				);

				// PHPX Fragment hover
				if (word === 'fragment' || linePrefix.includes('<>')) {
					return new vscode.Hover(
						'**PHPX Fragment** — A wrapper for multiple elements without adding extra nodes to the DOM',
					);
				}

				return null;
			},
		}),
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

	// ─── Diagnostics & Output ────────────────────────────────────────────────

	context.subscriptions.push(outputChannel);
	context.subscriptions.push(diagnosticsManager);
}

export function deactivate() {
	// Clear all pending compilation timers
	for (const timer of compilationTimers.values()) {
		clearTimeout(timer);
	}
	compilationTimers.clear();
}
