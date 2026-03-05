import * as assert from 'assert';
import * as vscode from 'vscode';
import * as path from 'path';

suite('PHPX Extension Test Suite', () => {
	vscode.window.showInformationMessage('Start all tests.');

	test('Extension should be present', () => {
		assert.ok(vscode.extensions.getExtension('attitude.phpx-language-support'));
	});

	test('Extension should activate on PHPX file', async () => {
		const extension = vscode.extensions.getExtension(
			'attitude.phpx-language-support',
		);
		assert.ok(extension);

		// Create a test PHPX document
		const doc = await vscode.workspace.openTextDocument({
			language: 'phpx',
			content: '<div>Hello World</div>',
		});

		// Wait for extension to activate
		await extension.activate();
		assert.ok(extension.isActive);
	});

	test('PHPX language should be registered', async () => {
		const languages = await vscode.languages.getLanguages();
		assert.ok(languages.includes('phpx'));
	});

	test('PHPX files should be recognized', async () => {
		// Test that .phpx extension is recognized
		const doc = await vscode.workspace.openTextDocument({
			language: 'phpx',
			content: '<?php declare(strict_types = 1);\nreturn <div>Hello</div>;',
		});

		assert.strictEqual(doc.languageId, 'phpx');
	});
});
