import * as vscode from 'vscode';
import { PHPXCompiler } from './compiler';
import { mapRangeToPhpx } from './positionMapper';

/**
 * Manages diagnostics for PHPX files.
 *
 * Two sources of diagnostics:
 * 1. Compilation errors from the PHPX→PHP compiler
 * 2. PHP diagnostics forwarded from the compiled .php file
 *
 * Similar to how Vue's language tools forward TypeScript diagnostics
 * from generated virtual files back to the .vue source.
 */
export class PHPXDiagnosticsManager {
	private diagnosticCollection: vscode.DiagnosticCollection;
	private compilationDiagnostics: vscode.DiagnosticCollection;
	private disposables: vscode.Disposable[] = [];

	/** Tracks which .php URIs map to which .phpx URIs */
	private phpToPhpxMap: Map<string, vscode.Uri> = new Map();

	constructor() {
		this.diagnosticCollection =
			vscode.languages.createDiagnosticCollection('phpx-php');
		this.compilationDiagnostics =
			vscode.languages.createDiagnosticCollection('phpx-compile');

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
	 * Set a compilation error diagnostic on a PHPX file
	 */
	setCompilationError(phpxUri: vscode.Uri, message: string) {
		const diagnostic = new vscode.Diagnostic(
			new vscode.Range(0, 0, 0, 0),
			`PHPX compilation error: ${message}`,
			vscode.DiagnosticSeverity.Error,
		);
		diagnostic.source = 'phpx';
		this.compilationDiagnostics.set(phpxUri, [diagnostic]);
	}

	/**
	 * Clear compilation error diagnostics for a PHPX file
	 */
	clearCompilationError(phpxUri: vscode.Uri) {
		this.compilationDiagnostics.delete(phpxUri);
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

			// Forward diagnostics to the PHPX file
			// Filter out diagnostics that are artifacts of compilation
			const forwarded = phpDiagnostics
				.filter((d) => this.shouldForwardDiagnostic(d))
				.map((d) => {
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
	 * Filter out diagnostics that are artifacts of the PHPX→PHP compilation.
	 * For example, the compiled PHP uses array syntax ['$', 'tag', ...] which
	 * might trigger some PHP linting rules that aren't relevant.
	 */
	private shouldForwardDiagnostic(diagnostic: vscode.Diagnostic): boolean {
		// Forward all diagnostics for now.
		// Can be refined later to filter out compilation artifacts.
		return true;
	}

	/**
	 * Remove all diagnostics for a PHPX file
	 */
	clearDiagnostics(phpxUri: vscode.Uri) {
		this.diagnosticCollection.delete(phpxUri);
		this.compilationDiagnostics.delete(phpxUri);
	}

	/**
	 * Unregister a mapping when a file is closed
	 */
	unregisterMapping(phpUri: vscode.Uri) {
		this.phpToPhpxMap.delete(phpUri.toString());
	}

	dispose() {
		this.diagnosticCollection.dispose();
		this.compilationDiagnostics.dispose();
		for (const d of this.disposables) {
			d.dispose();
		}
	}
}
