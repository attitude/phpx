import * as fs from 'fs';
import * as path from 'path';
import * as vscode from 'vscode';
import {
	LanguageClient,
	LanguageClientOptions,
	ServerOptions,
} from 'vscode-languageclient/node';

let client: LanguageClient | undefined;

/**
 * Start the PHPX Language Server as a child process and connect
 * via the Language Server Protocol (stdio transport).
 *
 * The language server ships as part of the `attitude/phpx` Composer package.
 * It must come from the same installation as the compiler — the parser version
 * must match so diagnostics agree with compilation results.
 */
export function startLanguageClient(
	context: vscode.ExtensionContext,
	outputChannel: vscode.OutputChannel,
): LanguageClient | undefined {
	const config = vscode.workspace.getConfiguration('phpx');
	const phpPath = config.get<string>('phpExecutablePath', 'php');

	const serverScript = findServerScript(outputChannel);

	if (!serverScript) {
		outputChannel.appendLine(
			'[PHPX LSP] language-server.php not found — LSP features disabled.\n' +
			'  The language server ships with attitude/phpx.\n' +
			'  Run: composer update attitude/phpx\n' +
			'  Or set "phpx.languageServer.path" to a custom script path.',
		);
		return undefined;
	}

	outputChannel.appendLine(`[PHPX LSP] Using server script: ${serverScript}`);

	const cwd =
		vscode.workspace.workspaceFolders?.[0]?.uri.fsPath ?? undefined;

	const serverOptions: ServerOptions = {
		command: phpPath,
		args: [serverScript],
		options: {
			cwd,
		},
	};

	const clientOptions: LanguageClientOptions = {
		documentSelector: [{ language: 'phpx', scheme: 'file' }],
		outputChannel,
		synchronize: {
			fileEvents: vscode.workspace.createFileSystemWatcher('**/*.phpx'),
		},
	};

	client = new LanguageClient(
		'phpxLanguageServer',
		'PHPX Language Server',
		serverOptions,
		clientOptions,
	);

	client.start();
	return client;
}

/**
 * Stop the language client (and the PHP server process).
 */
export function stopLanguageClient(): Thenable<void> | undefined {
	const c = client;
	client = undefined;
	return c?.stop();
}

/**
 * Locate the language server script.
 *
 * Resolution order:
 *  1. Manual override: `phpx.languageServer.path` setting
 *  2. Composer vendor: `vendor/attitude/phpx/scripts/language-server.php`
 *  3. Walk up from workspace: find the PHPX source tree (for development)
 */
function findServerScript(
	outputChannel: vscode.OutputChannel,
): string | undefined {
	// 1. Explicit path from settings
	const config = vscode.workspace.getConfiguration('phpx');
	const manualPath = config.get<string>('languageServer.path', '').trim();

	if (manualPath) {
		const resolved = resolveWorkspacePath(manualPath);
		if (fs.existsSync(resolved)) {
			outputChannel.appendLine(`[PHPX LSP] Found via setting: ${resolved}`);
			return resolved;
		}
		outputChannel.appendLine(
			`[PHPX LSP] WARNING: phpx.languageServer.path is set to "${manualPath}" but the file does not exist.`,
		);
	}

	const workspaceFolders = vscode.workspace.workspaceFolders;
	if (!workspaceFolders) {
		return undefined;
	}

	for (const folder of workspaceFolders) {
		const root = folder.uri.fsPath;

		// 2. Composer vendor install
		const vendorScript = path.join(
			root, 'vendor', 'attitude', 'phpx', 'scripts', 'language-server.php',
		);
		if (fs.existsSync(vendorScript)) {
			outputChannel.appendLine(`[PHPX LSP] Found via vendor: ${vendorScript}`);
			return vendorScript;
		}

		// 3. Walk up looking for the PHPX source tree
		let dir = root;
		while (true) {
			const candidate = path.join(dir, 'scripts', 'language-server.php');
			const marker = path.join(dir, 'src', 'language-server', 'Server.php');
			if (fs.existsSync(candidate) && fs.existsSync(marker)) {
				outputChannel.appendLine(`[PHPX LSP] Found via walk-up: ${candidate}`);
				return candidate;
			}
			const parent = path.dirname(dir);
			if (parent === dir) {
				break;
			}
			dir = parent;
		}
	}

	return undefined;
}

/**
 * Resolve a potentially relative path against the first workspace folder.
 */
function resolveWorkspacePath(p: string): string {
	if (path.isAbsolute(p)) {
		return p;
	}
	const wsRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
	return wsRoot ? path.resolve(wsRoot, p) : path.resolve(p);
}
