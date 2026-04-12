import * as assert from 'assert';
import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';
import * as vscode from 'vscode';
import { PHPXCompiler } from '../compiler';
import { PHPXDiagnosticsManager } from '../diagnostics';
import { PHPXRenameProvider } from '../providers';

function createFixturePair(rootDir: string, baseName: string) {
	const phpxPath = path.join(rootDir, `${baseName}.phpx`);
	const phpPath = path.join(rootDir, `${baseName}.php`);

	const phpxContent = `<?php\nreturn <h1>{$title}</h1>;\n`;
	const phpContent = `<?php\nreturn ['$', 'h1', null, [($title)]];\n`;

	fs.writeFileSync(phpxPath, phpxContent, 'utf8');
	fs.writeFileSync(phpPath, phpContent, 'utf8');

	const phpxUri = vscode.Uri.file(phpxPath);
	const phpUri = vscode.Uri.file(phpPath);

	const phpxLine = phpxContent.split('\n')[1];
	const phpLine = phpContent.split('\n')[1];

	const phpxTitleStart = phpxLine.indexOf('$title');
	const phpTitleStart = phpLine.indexOf('$title');

	assert.ok(phpxTitleStart >= 0, 'Fixture PHPX line must include $title');
	assert.ok(phpTitleStart >= 0, 'Fixture PHP line must include $title');
	assert.notStrictEqual(phpxTitleStart, phpTitleStart, 'Column offsets must differ so remapping is non-trivial');

	return {
		phpxUri,
		phpUri,
		phpxTitleStart,
		phpTitleStart,
		phpxTitleRange: new vscode.Range(1, phpxTitleStart, 1, phpxTitleStart + '$title'.length),
		phpTitleRange: new vscode.Range(1, phpTitleStart, 1, phpTitleStart + '$title'.length),
	};
}

suite('PHPX Range Remap', () => {
	let tempDir: string;

	suiteSetup(() => {
		tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'phpx-remap-'));
	});

	suiteTeardown(() => {
		fs.rmSync(tempDir, { recursive: true, force: true });
	});

	test('forwards diagnostics with remapped ranges', async () => {
		const { phpxUri, phpUri, phpxTitleRange, phpTitleRange } = createFixturePair(
			tempDir,
			'diagnostics',
		);

		const manager = new PHPXDiagnosticsManager();
		manager.registerMapping(phpxUri, phpUri);

		const originalGetDiagnostics = vscode.languages.getDiagnostics;
		(vscode.languages as any).getDiagnostics = (uri: vscode.Uri) => {
			if (uri.toString() !== phpUri.toString()) {
				return [];
			}

			const diagnostic = new vscode.Diagnostic(
				phpTitleRange,
				'Undefined variable $title',
				vscode.DiagnosticSeverity.Error,
			);
			diagnostic.relatedInformation = [
				new vscode.DiagnosticRelatedInformation(
					new vscode.Location(phpUri, phpTitleRange),
					'Referenced here',
				),
			];
			return [diagnostic];
		};

		try {
			(manager as any).onDiagnosticsChanged({ uris: [phpUri] });

			const forwarded = (manager as any).diagnosticCollection.get(phpxUri) as
				| vscode.Diagnostic[]
				| undefined;
			assert.ok(forwarded, 'Expected forwarded diagnostics for PHPX URI');
			assert.strictEqual(forwarded!.length, 1);
			assert.ok(
				forwarded![0].range.start.isEqual(phpxTitleRange.start),
				`Diagnostic start should remap to PHPX column ${phpxTitleRange.start.character}, got ${forwarded![0].range.start.character}`,
			);
			assert.ok(
				forwarded![0].range.end.isEqual(phpxTitleRange.end),
				`Diagnostic end should remap to PHPX column ${phpxTitleRange.end.character}, got ${forwarded![0].range.end.character}`,
			);
			assert.ok(forwarded![0].relatedInformation);
			assert.strictEqual(forwarded![0].relatedInformation!.length, 1);
			assert.strictEqual(
				forwarded![0].relatedInformation![0].location.uri.toString(),
				phpxUri.toString(),
			);
			assert.ok(
				forwarded![0].relatedInformation![0].location.range.start.isEqual(
					phpxTitleRange.start,
				),
				'Related info start should be remapped to PHPX position',
			);
		} finally {
			(vscode.languages as any).getDiagnostics = originalGetDiagnostics;
			manager.dispose();
		}
	});

	test('remaps ranges in provideRenameEdits when redirecting compiled URIs', async () => {
		const { phpxUri, phpUri, phpxTitleRange, phpTitleRange, phpTitleStart } = createFixturePair(
			tempDir,
			'rename-edits',
		);

		const doc = await vscode.workspace.openTextDocument(phpxUri);
		const provider = new PHPXRenameProvider();
		const originalExecuteCommand = vscode.commands.executeCommand;

		let capturedUri: vscode.Uri | undefined;
		let capturedPosition: vscode.Position | undefined;

		(vscode.commands as any).executeCommand = async (
			command: string,
			uri: vscode.Uri,
			position: vscode.Position,
		) => {
			if (command !== 'vscode.executeDocumentRenameProvider') {
				return undefined;
			}
			capturedUri = uri;
			capturedPosition = position;
			const edit = new vscode.WorkspaceEdit();
			edit.replace(phpUri, phpTitleRange, '$renamed');
			return edit;
		};

		try {
			const result = await provider.provideRenameEdits(
				doc,
				new vscode.Position(1, phpxTitleRange.start.character + 1),
				'renamed',
				{} as vscode.CancellationToken,
			);

			assert.ok(result, 'Expected WorkspaceEdit result from rename provider');

			// Verify the command was forwarded to the compiled PHP file
			assert.strictEqual(
				capturedUri?.toString(),
				phpUri.toString(),
				'Command must target the compiled PHP URI, not the PHPX source',
			);
			// Verify PHPX→PHP position mapping happened
			assert.strictEqual(
				capturedPosition?.character,
				phpTitleStart,
				`Forward-mapped column should be PHP column ${phpTitleStart}, got ${capturedPosition?.character}`,
			);

			const entries = result!.entries();
			assert.strictEqual(entries.length, 1);
			assert.strictEqual(entries[0][0].toString(), phpxUri.toString());
			assert.strictEqual(entries[0][1].length, 1);
			assert.ok(
				entries[0][1][0].range.start.isEqual(phpxTitleRange.start),
				`Remapped range start should be PHPX column ${phpxTitleRange.start.character}`,
			);
			assert.ok(
				entries[0][1][0].range.end.isEqual(phpxTitleRange.end),
				`Remapped range end should be PHPX column ${phpxTitleRange.end.character}`,
			);
		} finally {
			(vscode.commands as any).executeCommand = originalExecuteCommand;
		}
	});

	test('remaps prepareRename range from compiled PHP back to PHPX', async () => {
		const { phpxUri, phpUri, phpxTitleRange, phpTitleRange, phpTitleStart } = createFixturePair(
			tempDir,
			'prepare-rename',
		);

		const doc = await vscode.workspace.openTextDocument(phpxUri);
		const provider = new PHPXRenameProvider();
		const originalExecuteCommand = vscode.commands.executeCommand;

		let capturedUri: vscode.Uri | undefined;
		let capturedPosition: vscode.Position | undefined;

		(vscode.commands as any).executeCommand = async (
			command: string,
			uri: vscode.Uri,
			position: vscode.Position,
		) => {
			if (command !== 'vscode.prepareRename') {
				return undefined;
			}
			capturedUri = uri;
			capturedPosition = position;
			return { range: phpTitleRange, placeholder: '$title' };
		};

		try {
			const result = await provider.prepareRename(
				doc,
				new vscode.Position(1, phpxTitleRange.start.character + 1),
				{} as vscode.CancellationToken,
			);

			assert.ok(result, 'Expected prepareRename result');

			// Verify forwarded to PHP URI with mapped position
			assert.strictEqual(
				capturedUri?.toString(),
				phpUri.toString(),
				'prepareRename must target the compiled PHP URI',
			);
			assert.strictEqual(
				capturedPosition?.character,
				phpTitleStart,
				`Forward-mapped column should be PHP column ${phpTitleStart}`,
			);

			assert.ok(!(result instanceof vscode.Range));
			const { range, placeholder } = result as { range: vscode.Range; placeholder: string };
			assert.ok(
				range.start.isEqual(phpxTitleRange.start),
				`prepareRename start should remap to PHPX column ${phpxTitleRange.start.character}`,
			);
			assert.ok(
				range.end.isEqual(phpxTitleRange.end),
				`prepareRename end should remap to PHPX column ${phpxTitleRange.end.character}`,
			);
			assert.strictEqual(placeholder, '$title');
		} finally {
			(vscode.commands as any).executeCommand = originalExecuteCommand;
		}
	});

	test('remaps prepareRename when command returns a bare Range', async () => {
		const { phpxUri, phpUri, phpxTitleRange, phpTitleRange, phpTitleStart } = createFixturePair(
			tempDir,
			'prepare-rename-range',
		);

		const doc = await vscode.workspace.openTextDocument(phpxUri);
		const provider = new PHPXRenameProvider();
		const originalExecuteCommand = vscode.commands.executeCommand;

		let capturedUri: vscode.Uri | undefined;
		let capturedPosition: vscode.Position | undefined;

		(vscode.commands as any).executeCommand = async (
			command: string,
			uri: vscode.Uri,
			position: vscode.Position,
		) => {
			if (command !== 'vscode.prepareRename') {
				return undefined;
			}
			capturedUri = uri;
			capturedPosition = position;
			return phpTitleRange;
		};

		try {
			const result = await provider.prepareRename(
				doc,
				new vscode.Position(1, phpxTitleRange.start.character + 1),
				{} as vscode.CancellationToken,
			);

			// Verify forwarded to PHP URI with mapped position
			assert.strictEqual(
				capturedUri?.toString(),
				phpUri.toString(),
				'prepareRename must target the compiled PHP URI',
			);
			assert.strictEqual(
				capturedPosition?.character,
				phpTitleStart,
				`Forward-mapped column should be PHP column ${phpTitleStart}`,
			);

			assert.ok(result instanceof vscode.Range, 'Expected bare Range result');
			assert.ok(
				(result as vscode.Range).start.isEqual(phpxTitleRange.start),
				`Bare range start should remap to PHPX column ${phpxTitleRange.start.character}`,
			);
			assert.ok(
				(result as vscode.Range).end.isEqual(phpxTitleRange.end),
				`Bare range end should remap to PHPX column ${phpxTitleRange.end.character}`,
			);
		} finally {
			(vscode.commands as any).executeCommand = originalExecuteCommand;
		}
	});
});
