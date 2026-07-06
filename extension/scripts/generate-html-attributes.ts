#!/usr/bin/env pnpm exec tsx
/**
 * Generates src/language-server/HTMLAttributes.php from
 * @types/react's JSX.IntrinsicElements — the community-maintained
 * source of truth for HTML element attribute types.
 *
 * Usage:
 *   cd extension && pnpm exec tsx scripts/generate-html-attributes.ts
 *   # or:  pnpm generate:attributes
 */

import * as ts from 'typescript';
import * as fs from 'fs';
import * as path from 'path';

const EXTENSION_ROOT = path.resolve(__dirname, '..');
const PROJECT_ROOT = path.resolve(EXTENSION_ROOT, '..');
const OUTPUT = path.join(PROJECT_ROOT, 'src', 'language-server', 'HTMLAttributes.php');

// ─── Bootstrap a TypeScript program that includes @types/react ────────────────

// Write a real temp file so the program resolves @types/react properly
const TEMP_FILE = path.join(EXTENSION_ROOT, '.generate-tmp.tsx');
fs.writeFileSync(TEMP_FILE, `
import type { JSX } from 'react';
export type Elements = JSX.IntrinsicElements;
`, 'utf-8');

try {
    const compilerOptions: ts.CompilerOptions = {
        target: ts.ScriptTarget.ES2020,
        module: ts.ModuleKind.CommonJS,
        jsx: ts.JsxEmit.ReactJSX,
        moduleResolution: ts.ModuleResolutionKind.Node10,
        esModuleInterop: true,
        strict: false,
        noEmit: true,
        skipLibCheck: true,
    };

    const program = ts.createProgram([TEMP_FILE], compilerOptions);
    const checker = program.getTypeChecker();
    const sourceFile = program.getSourceFile(TEMP_FILE)!;

    // Find the `Elements` type alias
    let intrinsicType: ts.Type | undefined;
    ts.forEachChild(sourceFile, (node) => {
        if (ts.isTypeAliasDeclaration(node) && node.name.text === 'Elements') {
            intrinsicType = checker.getTypeAtLocation(node);
        }
    });

    if (!intrinsicType) {
        // Try via global JSX namespace as fallback
        const jsxSymbol = checker.resolveName('JSX', undefined, ts.SymbolFlags.Namespace, false);
        if (jsxSymbol) {
            const jsxType = checker.getDeclaredTypeOfSymbol(jsxSymbol);
            const intrinsicSymbol = jsxType.getProperty('IntrinsicElements');
            if (intrinsicSymbol) {
                intrinsicType = checker.getDeclaredTypeOfSymbol(intrinsicSymbol);
            }
        }
    }

    if (!intrinsicType || !intrinsicType.getProperties().length) {
        console.error('Diagnostics:');
        for (const d of ts.getPreEmitDiagnostics(program)) {
            console.error(' ', ts.flattenDiagnosticMessageText(d.messageText, '\n'));
        }
        // Throw (not process.exit) so the finally block cleans up the temp file.
        throw new Error('Could not resolve JSX.IntrinsicElements from @types/react');
    }

    // ─── Extract properties from a type ───────────────────────────────────────

    interface PropInfo {
        type: string;
        description: string;
    }

    function extractProps(type: ts.Type): Map<string, PropInfo> {
        const props = new Map<string, PropInfo>();

        for (const symbol of checker.getPropertiesOfType(type)) {
            const name = symbol.getName();

            // Skip internals
            if (name.startsWith('__')) continue;

            const decl = symbol.valueDeclaration ?? symbol.declarations?.[0];
            if (!decl) continue;

            const propType = checker.getTypeOfSymbolAtLocation(symbol, decl);
            let typeStr = checker.typeToString(
                propType, decl,
                ts.TypeFormatFlags.NoTruncation | ts.TypeFormatFlags.WriteArrayAsGenericType,
            );

            // Simplify verbose React types
            typeStr = typeStr
                .replace(/React\.\w+</g, (m) => m.split('.')[1])
                .replace(/\| undefined/g, '')
                .replace(/\s+/g, ' ')
                .trim();

            const doc = ts.displayPartsToString(symbol.getDocumentationComment(checker)).trim();

            props.set(name, { type: typeStr, description: doc });
        }

        return props;
    }

    // ─── Walk IntrinsicElements ───────────────────────────────────────────────

    // Get div props as the common baseline
    const divSymbol = intrinsicType.getProperty('div');
    if (!divSymbol) {
        // Throw (not process.exit) so the finally block cleans up the temp file.
        throw new Error('"div" not found in IntrinsicElements');
    }
    const divDecl = divSymbol.valueDeclaration ?? divSymbol.declarations?.[0];
    const divType = checker.getTypeOfSymbolAtLocation(divSymbol, divDecl!);
    const commonProps = extractProps(divType);

    console.log(`  Found ${commonProps.size} common attributes (from <div>)`);

    // Per-element specific props
    const elementSpecific = new Map<string, Map<string, PropInfo>>();

    for (const symbol of checker.getPropertiesOfType(intrinsicType)) {
        const tagName = symbol.getName();
        if (tagName.startsWith('__')) continue;

        const decl = symbol.valueDeclaration ?? symbol.declarations?.[0];
        if (!decl) continue;

        const elemType = checker.getTypeOfSymbolAtLocation(symbol, decl);
        const allProps = extractProps(elemType);

        const specific = new Map<string, PropInfo>();
        for (const [name, info] of allProps) {
            const common = commonProps.get(name);
            if (!common || common.type !== info.type) {
                specific.set(name, info);
            }
        }

        if (specific.size > 0) {
            elementSpecific.set(tagName, specific);
        }
    }

    // ─── Generate PHP ─────────────────────────────────────────────────────────

    function phpEscape(s: string): string {
        return s.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    function propEntryToPhp(name: string, info: PropInfo, indent: string): string {
        const type = phpEscape(info.type);
        const desc = phpEscape(info.description);
        return `${indent}'${phpEscape(name)}' => ['${type}', '${desc}'],`;
    }

    const lines: string[] = [];

    lines.push(`<?php declare(strict_types=1);`);
    lines.push(``);
    lines.push(`// ┌──────────────────────────────────────────────────────────────────────────┐`);
    lines.push(`// │  AUTO-GENERATED from @types/react JSX.IntrinsicElements — do not edit.   │`);
    lines.push(`// │  Generator: extension/scripts/generate-html-attributes.ts                 │`);
    lines.push(`// │  Run:  cd extension && pnpm generate:attributes                           │`);
    lines.push(`// └──────────────────────────────────────────────────────────────────────────┘`);
    lines.push(``);
    lines.push(`namespace Attitude\\PHPX\\LanguageServer;`);
    lines.push(``);
    lines.push(`final class HTMLAttributes`);
    lines.push(`{`);
    lines.push(`    /**`);
    lines.push(`     * Get attributes for a specific HTML element, merged with common attributes.`);
    lines.push(`     *`);
    lines.push(`     * @return array<string, array{string, string}>  attrName => [type, description]`);
    lines.push(`     */`);
    lines.push(`    public static function forElement(string $tagName): array`);
    lines.push(`    {`);
    lines.push(`        return array_merge(`);
    lines.push(`            self::COMMON,`);
    lines.push(`            self::ELEMENT_SPECIFIC[$tagName] ?? [],`);
    lines.push(`        );`);
    lines.push(`    }`);
    lines.push(``);
    lines.push(`    /**`);
    lines.push(`     * Look up a single attribute on a specific element.`);
    lines.push(`     *`);
    lines.push(`     * @return array{string, string}|null  [type, description] or null`);
    lines.push(`     */`);
    lines.push(`    public static function lookup(string $tagName, string $attrName): ?array`);
    lines.push(`    {`);
    lines.push(`        return self::ELEMENT_SPECIFIC[$tagName][$attrName]`);
    lines.push(`            ?? self::COMMON[$attrName]`);
    lines.push(`            ?? null;`);
    lines.push(`    }`);
    lines.push(``);

    // Common attributes (from div)
    lines.push(`    private const COMMON = [`);
    const sortedCommon = [...commonProps.entries()].sort((a, b) => a[0].localeCompare(b[0]));
    for (const [name, info] of sortedCommon) {
        lines.push(propEntryToPhp(name, info, '        '));
    }
    lines.push(`    ];`);
    lines.push(``);

    // Element-specific attributes
    lines.push(`    private const ELEMENT_SPECIFIC = [`);
    const sortedElements = [...elementSpecific.entries()].sort((a, b) => a[0].localeCompare(b[0]));
    for (const [tag, props] of sortedElements) {
        lines.push(`        '${tag}' => [`);
        const sortedProps = [...props.entries()].sort((a, b) => a[0].localeCompare(b[0]));
        for (const [name, info] of sortedProps) {
            lines.push(propEntryToPhp(name, info, '            '));
        }
        lines.push(`        ],`);
    }
    lines.push(`    ];`);
    lines.push(`}`);
    lines.push(``);

    const output = lines.join('\n');
    fs.writeFileSync(OUTPUT, output, 'utf-8');

    const reactVersion = require('@types/react/package.json').version;
    const commonCount = commonProps.size;
    const elementCount = elementSpecific.size;
    const totalSpecific = [...elementSpecific.values()].reduce((n, m) => n + m.size, 0);
    console.log(`Generated ${OUTPUT}`);
    console.log(`  Source: @types/react ${reactVersion}`);
    console.log(`  ${commonCount} common attributes (from <div>)`);
    console.log(`  ${elementCount} elements with ${totalSpecific} element-specific attributes`);

} finally {
    // Clean up temp file
    try { fs.unlinkSync(TEMP_FILE); } catch { /* ignore */ }
}
