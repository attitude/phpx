import * as vscode from 'vscode';
import { PHPXCompiler } from './compiler';
import { mapRangeToPhpx } from './positionMapper';

/**
 * Forwards PHP language-server diagnostics from compiled .php files
 * back to the original .phpx source files.
 *
 * PHPX parse diagnostics (syntax errors, unclosed tags, etc.) are handled
 * by the PHPX Language Server via the LSP client — this manager only deals
 * with PHP-level diagnostics (type errors, undefined functions, etc.) that
 * come from whichever PHP language server the user has installed
 * (Intelephense, DEVSENSE, etc.).
 */
export class PHPXDiagnosticsManager {
	private diagnosticCollection: vscode.DiagnosticCollection;
	private compilerDiagnostics: vscode.DiagnosticCollection;
	private disposables: vscode.Disposable[] = [];

	/** Tracks which .php URIs map to which .phpx URIs */
	private phpToPhpxMap: Map<string, vscode.Uri> = new Map();

	constructor() {
		this.diagnosticCollection =
			vscode.languages.createDiagnosticCollection('phpx-php');
		this.compilerDiagnostics =
			vscode.languages.createDiagnosticCollection('phpx-compiler');

		// Watch for diagnostic changes from the PHP language server
		this.disposables.push(
			vscode.languages.onDidChangeDiagnostics((e) => {
				this.onDiagnosticsChanged(e);
			}),
		);
	}

	/**
	 * Register a PHPX↔PHP file mapping
	 */
	registerMapping(phpxUri: vscode.Uri, phpUri: vscode.Uri) {
		this.phpToPhpxMap.set(phpUri.toString(), phpxUri);
	}

	/**
	 * Handle diagnostic changes from other extensions (PHP language servers)
	 */
	private onDiagnosticsChanged(e: vscode.DiagnosticChangeEvent) {
		for (const uri of e.uris) {
			const phpxUri = this.phpToPhpxMap.get(uri.toString());
			if (!phpxUri) {
				continue;
			}

			// Get diagnostics from the PHP language server for the compiled .php file
			const phpDiagnostics = vscode.languages.getDiagnostics(uri);

			if (phpDiagnostics.length === 0) {
				this.diagnosticCollection.delete(phpxUri);
				continue;
			}

			// Forward diagnostics to the PHPX file with remapped positions
			const forwarded = phpDiagnostics.map((d) => {
					const mappedRange = mapRangeToPhpx(uri, phpxUri, d.range);
					const mapped = new vscode.Diagnostic(
						mappedRange,
						d.message,
						d.severity,
					);
					mapped.source = d.source ? `phpx (${d.source})` : 'phpx';
					mapped.code = d.code;
					mapped.relatedInformation = d.relatedInformation?.map((info) => {
						let infoUri = info.location.uri;
						let infoRange = info.location.range;

						if (info.location.uri.fsPath === uri.fsPath) {
							infoUri = phpxUri;
							infoRange = mapRangeToPhpx(uri, phpxUri, info.location.range);
						} else if (PHPXCompiler.hasPhpxSource(info.location.uri)) {
							infoUri = PHPXCompiler.getSourceUri(info.location.uri);
							infoRange = mapRangeToPhpx(
								info.location.uri,
								infoUri,
								info.location.range,
							);
						} else {
							const mappedPhpxUri = this.phpToPhpxMap.get(
								info.location.uri.toString(),
							);
							if (mappedPhpxUri) {
								infoUri = mappedPhpxUri;
								infoRange = mapRangeToPhpx(
									info.location.uri,
									mappedPhpxUri,
									info.location.range,
								);
							}
						}

						return new vscode.DiagnosticRelatedInformation(
							new vscode.Location(infoUri, infoRange),
							info.message,
						);
					});
					return mapped;
				});

			this.diagnosticCollection.set(phpxUri, forwarded);
		}
	}

	/**
	 * Show a compiler failure as a diagnostic on the PHPX file.
	 * This is separate from LSP parse diagnostics — it indicates the
	 * compilation subprocess failed, so the shadow .php is stale.
	 */
	setCompilerError(phpxUri: vscode.Uri, message: string) {
		const diagnostic = new vscode.Diagnostic(
			new vscode.Range(0, 0, 0, 0),
			`PHPX compilation failed: ${message}`,
			vscode.DiagnosticSeverity.Error,
		);
		diagnostic.source = 'phpx-compiler';
		this.compilerDiagnostics.set(phpxUri, [diagnostic]);
	}

	/**
	 * Clear compiler error when compilation succeeds.
	 */
	clearCompilerError(phpxUri: vscode.Uri) {
		this.compilerDiagnostics.delete(phpxUri);
	}

	/**
	 * Remove all diagnostics for a PHPX file
	 */
	clearDiagnostics(phpxUri: vscode.Uri) {
		this.diagnosticCollection.delete(phpxUri);
		this.compilerDiagnostics.delete(phpxUri);
	}

	/**
	 * Unregister a mapping when a file is closed
	 */
	unregisterMapping(phpUri: vscode.Uri) {
		this.phpToPhpxMap.delete(phpUri.toString());
	}

	dispose() {
		this.diagnosticCollection.dispose();
		this.compilerDiagnostics.dispose();
		for (const d of this.disposables) {
			d.dispose();
		}
	}
}
