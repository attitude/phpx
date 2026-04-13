import * as assert from 'assert';
import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';
import * as vscode from 'vscode';

const fixturesDir = path.join(__dirname, '../../src/test/fixtures');

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

	test('fixture: page.phpx is detected as phpx language', async () => {
		const doc = await vscode.workspace.openTextDocument(
			vscode.Uri.file(path.join(fixturesDir, 'page.phpx')),
		);
		assert.strictEqual(doc.languageId, 'phpx');
	});

	test('fixture: html-page-template.phpx is detected as phpx language', async () => {
		const doc = await vscode.workspace.openTextDocument(
			vscode.Uri.file(path.join(fixturesDir, 'html-page-template.phpx')),
		);
		assert.strictEqual(doc.languageId, 'phpx');
	});

	test('fixture: syntax-samples.phpx is detected as phpx language', async () => {
		const doc = await vscode.workspace.openTextDocument(
			vscode.Uri.file(path.join(fixturesDir, 'syntax-samples.phpx')),
		);
		assert.strictEqual(doc.languageId, 'phpx');
	});
});
