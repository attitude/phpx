import * as assert from 'assert';
import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';
import * as vscode from 'vscode';

suite('PHPX Extension Test Suite', () => {
	vscode.window.showInformationMessage('Start all tests.');

	test('Extension activates without errors', async () => {
		const extension = vscode.extensions.getExtension('attitude.phpx-language-support');
		assert.ok(extension, 'Extension must be installed in test host');
		await extension.activate();
		assert.ok(extension.isActive);
	});

	test('PHPX language is registered', async () => {
		const languages = await vscode.languages.getLanguages();
		assert.ok(languages.includes('phpx'));
	});

	test('.phpx files are auto-detected as phpx language', async () => {
		const tmpFile = path.join(os.tmpdir(), `phpx-test-${Date.now()}.phpx`);
		fs.writeFileSync(tmpFile, '<?php return <div>test</div>;');
		try {
			const doc = await vscode.workspace.openTextDocument(vscode.Uri.file(tmpFile));
			assert.strictEqual(doc.languageId, 'phpx');
		} finally {
			fs.unlinkSync(tmpFile);
		}
	});
});
