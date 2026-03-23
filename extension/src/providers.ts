import * as vscode from 'vscode';
import { PHPXCompiler } from './compiler';
import { mapPositionToPhp, mapRangeToPhpx } from './positionMapper';

/**
 * PHPX Language Feature Providers
 *
 * These providers delegate language intelligence requests from .phpx files
 * to the corresponding compiled .php files, using the PHP language server
 * that the user has installed (PHP IntelliSense, DEVSENSE, etc.).
 *
 * The PHPX compiler preserves line counts. Within JSX regions the column
 * offsets differ, so we use positionMapper to translate cursor positions
 * to the correct column in the compiled PHP and back.
 */

/**
 * Map a location from the compiled .php file back to the .phpx source.
 * If the location points to the compiled .php file, redirect it to the .phpx file
 * and remap columns so the range points at the right token in the PHPX source.
 * Otherwise (e.g., a definition in another file), leave it unchanged.
 */
function mapLocationToSource(
	location: vscode.Location,
	phpxUri: vscode.Uri,
	phpUri: vscode.Uri,
): vscode.Location {
	if (location.uri.fsPath === phpUri.fsPath) {
		const range = mapRangeToPhpx(phpUri, phpxUri, location.range);
		return new vscode.Location(phpxUri, range);
	}
	// If the location points to another compiled .php that has a .phpx source, redirect
	if (PHPXCompiler.hasPhpxSource(location.uri)) {
		const sourceUri = PHPXCompiler.getSourceUri(location.uri);
		const range = mapRangeToPhpx(location.uri, sourceUri, location.range);
		return new vscode.Location(sourceUri, range);
	}
	return location;
}

/**
 * Map a LocationLink from compiled .php back to .phpx source
 */
function mapLocationLinkToSource(
	link: vscode.LocationLink,
	phpxUri: vscode.Uri,
	phpUri: vscode.Uri,
): vscode.LocationLink {
	let targetUri = link.targetUri;
	let targetRange = link.targetRange;
	let targetSelectionRange = link.targetSelectionRange;

	if (targetUri.fsPath === phpUri.fsPath) {
		targetRange = mapRangeToPhpx(phpUri, phpxUri, link.targetRange);
		targetSelectionRange = link.targetSelectionRange
			? mapRangeToPhpx(phpUri, phpxUri, link.targetSelectionRange)
			: undefined;
		targetUri = phpxUri;
	} else if (PHPXCompiler.hasPhpxSource(targetUri)) {
		const sourceUri = PHPXCompiler.getSourceUri(targetUri);
		targetRange = mapRangeToPhpx(targetUri, sourceUri, link.targetRange);
		targetSelectionRange = link.targetSelectionRange
			? mapRangeToPhpx(targetUri, sourceUri, link.targetSelectionRange)
			: undefined;
		targetUri = sourceUri;
	}
	return {
		originSelectionRange: link.originSelectionRange,
		targetUri,
		targetRange,
		targetSelectionRange,
	};
}

// ─── Completion ──────────────────────────────────────────────────────────────

export class PHPXCompletionProvider implements vscode.CompletionItemProvider {
	async provideCompletionItems(
		document: vscode.TextDocument,
		position: vscode.Position,
		_token: vscode.CancellationToken,
		context: vscode.CompletionContext,
	): Promise<vscode.CompletionList | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const mappedPosition = mapPositionToPhp(document, position, phpUri);

		try {
			const result =
				await vscode.commands.executeCommand<vscode.CompletionList>(
					'vscode.executeCompletionItemProvider',
					phpUri,
					mappedPosition,
					context.triggerCharacter,
				);
			return result || null;
		} catch {
			return null;
		}
	}
}

// ─── Hover ───────────────────────────────────────────────────────────────────

export class PHPXHoverProvider implements vscode.HoverProvider {
	async provideHover(
		document: vscode.TextDocument,
		position: vscode.Position,
		_token: vscode.CancellationToken,
	): Promise<vscode.Hover | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const mappedPosition = mapPositionToPhp(document, position, phpUri);

		try {
			const hovers = await vscode.commands.executeCommand<vscode.Hover[]>(
				'vscode.executeHoverProvider',
				phpUri,
				mappedPosition,
			);
			if (hovers && hovers.length > 0) {
				const firstHover = hovers[0];
				const mappedRange = firstHover.range
					? mapRangeToPhpx(phpUri, document.uri, firstHover.range)
					: undefined;
				return new vscode.Hover(firstHover.contents, mappedRange);
			}
		} catch {
			// Silently fail — hover is best-effort
		}

		return null;
	}
}

// ─── Definition ──────────────────────────────────────────────────────────────

export class PHPXDefinitionProvider implements vscode.DefinitionProvider {
	async provideDefinition(
		document: vscode.TextDocument,
		position: vscode.Position,
		_token: vscode.CancellationToken,
	): Promise<vscode.Definition | vscode.LocationLink[] | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const phpxUri = document.uri;
		const mappedPosition = mapPositionToPhp(document, position, phpUri);

		try {
			const result = await vscode.commands.executeCommand<
				(vscode.Location | vscode.LocationLink)[]
			>('vscode.executeDefinitionProvider', phpUri, mappedPosition);

			if (!result || result.length === 0) {
				return null;
			}

			// Separate into typed arrays to satisfy the union return type
			if ('targetUri' in result[0]) {
				return (result as vscode.LocationLink[]).map((item) =>
					mapLocationLinkToSource(item, phpxUri, phpUri),
				);
			}
			return (result as vscode.Location[]).map((item) =>
				mapLocationToSource(item, phpxUri, phpUri),
			);
		} catch {
			return null;
		}
	}
}

// ─── Type Definition ─────────────────────────────────────────────────────────

export class PHPXTypeDefinitionProvider
	implements vscode.TypeDefinitionProvider
{
	async provideTypeDefinition(
		document: vscode.TextDocument,
		position: vscode.Position,
		_token: vscode.CancellationToken,
	): Promise<vscode.Definition | vscode.LocationLink[] | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const phpxUri = document.uri;
		const mappedPosition = mapPositionToPhp(document, position, phpUri);

		try {
			const result = await vscode.commands.executeCommand<
				(vscode.Location | vscode.LocationLink)[]
			>('vscode.executeTypeDefinitionProvider', phpUri, mappedPosition);

			if (!result || result.length === 0) {
				return null;
			}

			if ('targetUri' in result[0]) {
				return (result as vscode.LocationLink[]).map((item) =>
					mapLocationLinkToSource(item, phpxUri, phpUri),
				);
			}
			return (result as vscode.Location[]).map((item) =>
				mapLocationToSource(item, phpxUri, phpUri),
			);
		} catch {
			return null;
		}
	}
}

// ─── References ──────────────────────────────────────────────────────────────

export class PHPXReferenceProvider implements vscode.ReferenceProvider {
	async provideReferences(
		document: vscode.TextDocument,
		position: vscode.Position,
		context: vscode.ReferenceContext,
		_token: vscode.CancellationToken,
	): Promise<vscode.Location[] | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const phpxUri = document.uri;
		const mappedPosition = mapPositionToPhp(document, position, phpUri);

		try {
			const result = await vscode.commands.executeCommand<vscode.Location[]>(
				'vscode.executeReferenceProvider',
				phpUri,
				mappedPosition,
			);

			if (!result || result.length === 0) {
				return null;
			}

			return result.map((loc) => mapLocationToSource(loc, phpxUri, phpUri));
		} catch {
			return null;
		}
	}
}

// ─── Signature Help ──────────────────────────────────────────────────────────

export class PHPXSignatureHelpProvider implements vscode.SignatureHelpProvider {
	async provideSignatureHelp(
		document: vscode.TextDocument,
		position: vscode.Position,
		_token: vscode.CancellationToken,
		context: vscode.SignatureHelpContext,
	): Promise<vscode.SignatureHelp | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const mappedPosition = mapPositionToPhp(document, position, phpUri);

		try {
			const result = await vscode.commands.executeCommand<vscode.SignatureHelp>(
				'vscode.executeSignatureHelpProvider',
				phpUri,
				mappedPosition,
				context.triggerCharacter,
			);
			return result || null;
		} catch {
			return null;
		}
	}
}

// ─── Document Symbols ────────────────────────────────────────────────────────

export class PHPXDocumentSymbolProvider
	implements vscode.DocumentSymbolProvider
{
	async provideDocumentSymbols(
		document: vscode.TextDocument,
		_token: vscode.CancellationToken,
	): Promise<vscode.DocumentSymbol[] | vscode.SymbolInformation[] | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const phpxUri = document.uri;

		try {
			const result = await vscode.commands.executeCommand<
				vscode.DocumentSymbol[] | vscode.SymbolInformation[]
			>('vscode.executeDocumentSymbolProvider', phpUri);

			if (!result || result.length === 0) {
				return result || null;
			}

			// Remap symbol ranges back to the PHPX source
			if ('range' in result[0]) {
				const remapDocSymbol = (sym: vscode.DocumentSymbol): vscode.DocumentSymbol => {
					const range = mapRangeToPhpx(phpUri, phpxUri, sym.range);
					const selectionRange = mapRangeToPhpx(phpUri, phpxUri, sym.selectionRange);
					const remapped = new vscode.DocumentSymbol(sym.name, sym.detail, sym.kind, range, selectionRange);
					remapped.children = sym.children.map(remapDocSymbol);
					return remapped;
				};
				return (result as vscode.DocumentSymbol[]).map(remapDocSymbol);
			}

			return (result as vscode.SymbolInformation[]).map((sym) => {
				if (sym.location.uri.fsPath !== phpUri.fsPath) {
					return sym;
				}
				const range = mapRangeToPhpx(phpUri, phpxUri, sym.location.range);
				return new vscode.SymbolInformation(
					sym.name,
					sym.kind,
					sym.containerName,
					new vscode.Location(phpxUri, range),
				);
			});
		} catch {
			return null;
		}
	}
}

// ─── Workspace Symbols ──────────────────────────────────────────────────────

export class PHPXWorkspaceSymbolProvider
	implements vscode.WorkspaceSymbolProvider
{
	async provideWorkspaceSymbols(
		query: string,
		_token: vscode.CancellationToken,
	): Promise<vscode.SymbolInformation[] | null> {
		try {
			const result = await vscode.commands.executeCommand<
				vscode.SymbolInformation[]
			>('vscode.executeWorkspaceSymbolProvider', query);

			if (!result) {
				return null;
			}

			return result.map((symbol) => {
				const loc = symbol.location;
				if (!PHPXCompiler.hasPhpxSource(loc.uri)) {
					return symbol;
				}
				const sourceUri = PHPXCompiler.getSourceUri(loc.uri);
				const range = mapRangeToPhpx(loc.uri, sourceUri, loc.range);
				return new vscode.SymbolInformation(
					symbol.name,
					symbol.kind,
					symbol.containerName ?? '',
					new vscode.Location(sourceUri, range),
				);
			});
		} catch {
			return null;
		}
	}
}

// ─── Code Actions ────────────────────────────────────────────────────────────

export class PHPXCodeActionProvider implements vscode.CodeActionProvider {
	async provideCodeActions(
		document: vscode.TextDocument,
		range: vscode.Range | vscode.Selection,
		context: vscode.CodeActionContext,
		_token: vscode.CancellationToken,
	): Promise<vscode.CodeAction[] | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const phpRange = new vscode.Range(
			mapPositionToPhp(document, range.start, phpUri),
			mapPositionToPhp(document, range.end, phpUri),
		);

		try {
			const result = await vscode.commands.executeCommand<vscode.CodeAction[]>(
				'vscode.executeCodeActionProvider',
				phpUri,
				phpRange,
			);
			return result || null;
		} catch {
			return null;
		}
	}
}

// ─── Document Formatting ─────────────────────────────────────────────────────

// Note: We intentionally do NOT provide formatting, because formatting
// the compiled PHP and mapping it back would destroy the PHPX syntax.
// Users should use a PHPX-aware formatter instead.

// ─── Rename ──────────────────────────────────────────────────────────────────

export class PHPXRenameProvider implements vscode.RenameProvider {
	async provideRenameEdits(
		document: vscode.TextDocument,
		position: vscode.Position,
		newName: string,
		_token: vscode.CancellationToken,
	): Promise<vscode.WorkspaceEdit | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const phpxUri = document.uri;
		const mappedPosition = mapPositionToPhp(document, position, phpUri);

		try {
			const result = await vscode.commands.executeCommand<vscode.WorkspaceEdit>(
				'vscode.executeDocumentRenameProvider',
				phpUri,
				mappedPosition,
				newName,
			);

			if (!result) {
				return null;
			}

			// Remap the workspace edit: redirect edits targeting .php files
			// to their .phpx source files where applicable
			const remapped = new vscode.WorkspaceEdit();

			for (const [uri, edits] of result.entries()) {
				let targetUri = uri;
				if (uri.fsPath === phpUri.fsPath) {
					targetUri = phpxUri;
				} else if (PHPXCompiler.hasPhpxSource(uri)) {
					targetUri = PHPXCompiler.getSourceUri(uri);
				}
				remapped.set(targetUri, edits);
			}

			return remapped;
		} catch {
			return null;
		}
	}

	async prepareRename(
		document: vscode.TextDocument,
		position: vscode.Position,
		_token: vscode.CancellationToken,
	): Promise<
		vscode.Range | { range: vscode.Range; placeholder: string } | null
	> {
		// Ensure the position is on a renameable symbol
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const mappedPosition = mapPositionToPhp(document, position, phpUri);

		try {
			const result = await vscode.commands.executeCommand<
				{ range: vscode.Range; placeholder: string } | undefined
			>('vscode.prepareRename', phpUri, mappedPosition);
			return result || null;
		} catch {
			return null;
		}
	}
}

// ─── Implementation ──────────────────────────────────────────────────────────

export class PHPXImplementationProvider
	implements vscode.ImplementationProvider
{
	async provideImplementation(
		document: vscode.TextDocument,
		position: vscode.Position,
		_token: vscode.CancellationToken,
	): Promise<vscode.Definition | vscode.LocationLink[] | null> {
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);
		const phpxUri = document.uri;
		const mappedPosition = mapPositionToPhp(document, position, phpUri);

		try {
			const result = await vscode.commands.executeCommand<
				(vscode.Location | vscode.LocationLink)[]
			>('vscode.executeImplementationProvider', phpUri, mappedPosition);

			if (!result || result.length === 0) {
				return null;
			}

			if ('targetUri' in result[0]) {
				return (result as vscode.LocationLink[]).map((item) =>
					mapLocationLinkToSource(item, phpxUri, phpUri),
				);
			}
			return (result as vscode.Location[]).map((item) =>
				mapLocationToSource(item, phpxUri, phpUri),
			);
		} catch {
			return null;
		}
	}
}
