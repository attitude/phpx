import * as cp from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import * as vscode from 'vscode';

export interface CompilationResult {
	php: string;
	error?: string;
}

/**
 * Bridge to the PHPX PHP compiler.
 * Calls the PHP-based PHPX→PHP compiler via child_process.
 */
export class PHPXCompiler {
	private outputChannel: vscode.OutputChannel;
	private compilationErrors: Map<string, string> = new Map();

	constructor(outputChannel: vscode.OutputChannel) {
		this.outputChannel = outputChannel;
	}

	/**
	 * Get the configured PHP executable path
	 */
	private getPhpPath(): string {
		return (
			vscode.workspace
				.getConfiguration('phpx')
				.get<string>('phpExecutablePath') || 'php'
		);
	}

	/**
	 * Find the compile-stdin.php script.
	 * Searches in the workspace (as the PHPX project itself, or as a dependency).
	 */
	private findCompilerScript(documentUri: vscode.Uri): string | undefined {
		const workspaceFolder = vscode.workspace.getWorkspaceFolder(documentUri);
		if (!workspaceFolder) {
			return undefined;
		}

		const searchPaths = [
			// This IS the PHPX project
			path.join(workspaceFolder.uri.fsPath, 'scripts', 'compile-stdin.php'),
			// PHPX installed as a Composer dependency
			path.join(
				workspaceFolder.uri.fsPath,
				'vendor',
				'attitude',
				'phpx',
				'scripts',
				'compile-stdin.php',
			),
		];

		for (const p of searchPaths) {
			if (fs.existsSync(p)) {
				return p;
			}
		}

		return undefined;
	}

	/**
	 * Find the project root (where vendor/autoload.php lives)
	 */
	private findProjectRoot(documentUri: vscode.Uri): string {
		const workspaceFolder = vscode.workspace.getWorkspaceFolder(documentUri);
		return workspaceFolder?.uri.fsPath || path.dirname(documentUri.fsPath);
	}

	/**
	 * Compile PHPX source to PHP via the PHP compiler process
	 */
	async compile(
		phpxContent: string,
		documentUri: vscode.Uri,
	): Promise<CompilationResult> {
		const phpPath = this.getPhpPath();
		const compilerScript = this.findCompilerScript(documentUri);

		if (!compilerScript) {
			return {
				php: '',
				error:
					'PHPX compiler not found. Ensure attitude/phpx is installed via Composer or you are in the PHPX project.',
			};
		}

		const cwd = this.findProjectRoot(documentUri);

		return new Promise((resolve) => {
			const proc = cp.spawn(phpPath, [compilerScript], {
				cwd,
				stdio: ['pipe', 'pipe', 'pipe'],
			});

			let stdout = '';
			let stderr = '';

			proc.stdout.on('data', (data: Buffer) => {
				stdout += data.toString();
			});
			proc.stderr.on('data', (data: Buffer) => {
				stderr += data.toString();
			});

			proc.on('error', (err) => {
				resolve({
					php: '',
					error: `Failed to start PHP (${phpPath}): ${err.message}`,
				});
			});

			proc.on('close', (code) => {
				if (code === 0) {
					this.compilationErrors.delete(documentUri.toString());
					resolve({ php: stdout });
				} else {
					let errorMessage = stderr;
					try {
						const parsed = JSON.parse(stderr);
						errorMessage = parsed.error || stderr;
					} catch {
						// stderr is not JSON, use as-is
					}
					this.compilationErrors.set(documentUri.toString(), errorMessage);
					resolve({
						php: '',
						error: errorMessage,
					});
				}
			});

			proc.stdin.write(phpxContent);
			proc.stdin.end();
		});
	}

	/**
	 * Get the URI of the compiled PHP file for a given PHPX file.
	 * Convention: file.phpx → file.php (strips trailing 'x')
	 */
	static getCompiledUri(phpxUri: vscode.Uri): vscode.Uri {
		const phpPath = phpxUri.fsPath.slice(0, -1);
		return vscode.Uri.file(phpPath);
	}

	/**
	 * Check if a .php URI has a corresponding .phpx source file
	 */
	static hasPhpxSource(phpUri: vscode.Uri): boolean {
		const phpxPath = phpUri.fsPath + 'x';
		return fs.existsSync(phpxPath);
	}

	/**
	 * Get the PHPX source URI for a compiled PHP file
	 */
	static getSourceUri(phpUri: vscode.Uri): vscode.Uri {
		return vscode.Uri.file(phpUri.fsPath + 'x');
	}

	/**
	 * Compile and write to the shadow .php file
	 */
	async compileAndWrite(
		document: vscode.TextDocument,
	): Promise<{ phpUri: vscode.Uri; error?: string }> {
		const result = await this.compile(document.getText(), document.uri);
		const phpUri = PHPXCompiler.getCompiledUri(document.uri);

		if (result.error) {
			this.outputChannel.appendLine(
				`PHPX compile error [${path.basename(document.uri.fsPath)}]: ${result.error}`,
			);
			return { phpUri, error: result.error };
		}

		try {
			fs.writeFileSync(phpUri.fsPath, result.php, 'utf-8');
			this.outputChannel.appendLine(
				`Compiled: ${path.basename(document.uri.fsPath)} → ${path.basename(phpUri.fsPath)}`,
			);
		} catch (err: any) {
			this.outputChannel.appendLine(
				`Failed to write compiled file: ${err.message}`,
			);
			return { phpUri, error: err.message };
		}

		return { phpUri };
	}

	/**
	 * Get the last compilation error for a document
	 */
	getCompilationError(documentUri: vscode.Uri): string | undefined {
		return this.compilationErrors.get(documentUri.toString());
	}
}
