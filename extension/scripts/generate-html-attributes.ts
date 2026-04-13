#!/usr/bin/env pnpm exec tsx
/**
 * Generates src/language-server/HTMLAttributes.php from
 * extension/types/phpx-intrinsics.d.ts.
 *
 * Single source of truth: the TypeScript declarations.
 *
 * Usage:
 *   cd extension && pnpm exec tsx scripts/generate-html-attributes.ts
 */

import * as ts from 'typescript';
import * as fs from 'fs';
import * as path from 'path';

const EXTENSION_ROOT = path.resolve(__dirname, '..');
const PROJECT_ROOT = path.resolve(EXTENSION_ROOT, '..');
const INTRINSICS = path.join(EXTENSION_ROOT, 'types', 'phpx-intrinsics.d.ts');
const OUTPUT = path.join(PROJECT_ROOT, 'src', 'language-server', 'HTMLAttributes.php');

// ─── Parse the declaration file ───────────────────────────────────────────────

const source = fs.readFileSync(INTRINSICS, 'utf-8');
const sourceFile = ts.createSourceFile(
    'phpx-intrinsics.d.ts',
    source,
    ts.ScriptTarget.Latest,
    true, // setParentNodes
);

// ─── Extract interfaces and their properties ─────────────────────────────────

interface PropInfo {
    type: string;
    description: string;
}

/** interfaceName → { propName → PropInfo } */
const interfaces = new Map<string, Map<string, PropInfo>>();

/** tagName → interfaceName (from PHPXIntrinsicElements) */
const elementMap = new Map<string, string>();

/** interfaceName → parentInterfaceName[] (extends chain) */
const extendsMap = new Map<string, string[]>();

function getJsDocComment(node: ts.Node): string {
    const fullText = sourceFile.getFullText();
    const ranges = ts.getLeadingCommentRanges(fullText, node.getFullStart());
    if (!ranges) return '';

    for (const range of ranges) {
        const text = fullText.slice(range.pos, range.end);
        // Extract /** Maps to `xxx` */ or /** description */
        const match = text.match(/\/\*\*\s*(.*?)\s*\*\//s);
        if (match) {
            return match[1].replace(/\s*\*\s*/g, ' ').trim();
        }
    }
    return '';
}

function typeToString(node: ts.TypeNode | undefined): string {
    if (!node) return 'unknown';
    return node.getText(sourceFile);
}

function visitInterface(node: ts.InterfaceDeclaration) {
    const name = node.name.text;
    const props = new Map<string, PropInfo>();

    // Track extends
    if (node.heritageClauses) {
        const parents: string[] = [];
        for (const clause of node.heritageClauses) {
            for (const type of clause.types) {
                parents.push(type.expression.getText(sourceFile));
            }
        }
        if (parents.length > 0) {
            extendsMap.set(name, parents);
        }
    }

    for (const member of node.members) {
        if (!ts.isPropertySignature(member) || !member.name) continue;

        const propName = member.name.getText(sourceFile);

        // Skip index signatures like [key: `data-${string}`]
        if (propName.startsWith('[')) continue;

        const type = typeToString(member.type);
        const doc = getJsDocComment(member);

        props.set(propName, { type, description: doc });
    }

    interfaces.set(name, props);
}

function visitIntrinsicElements(node: ts.InterfaceDeclaration) {
    for (const member of node.members) {
        if (!ts.isPropertySignature(member) || !member.name) continue;

        const tagName = member.name.getText(sourceFile);
        if (tagName.startsWith('[')) continue; // skip index signature

        const typeName = member.type?.getText(sourceFile) ?? '';

        // Handle inline intersection types like `PHPXSvgShapeAttributes & { x?: ... }`
        // Take the first named type
        const match = typeName.match(/^(\w+)/);
        if (match) {
            elementMap.set(tagName, match[1]);
        }
    }
}

// Walk the AST
ts.forEachChild(sourceFile, function visit(node) {
    if (ts.isInterfaceDeclaration(node)) {
        if (node.name.text === 'PHPXIntrinsicElements') {
            visitIntrinsicElements(node);
        } else if (node.name.text.startsWith('PHPX')) {
            visitInterface(node);
        }
    }
    ts.forEachChild(node, visit);
});

// ─── Resolve extends chains to get all properties ─────────────────────────────

function resolveAllProps(interfaceName: string, visited = new Set<string>()): Map<string, PropInfo> {
    if (visited.has(interfaceName)) return new Map();
    visited.add(interfaceName);

    const own = interfaces.get(interfaceName) ?? new Map();
    const parents = extendsMap.get(interfaceName) ?? [];

    const merged = new Map<string, PropInfo>();

    // Parent props first (so own props can override)
    for (const parent of parents) {
        const parentProps = resolveAllProps(parent, visited);
        for (const [k, v] of parentProps) {
            merged.set(k, v);
        }
    }

    // Own props override
    for (const [k, v] of own) {
        merged.set(k, v);
    }

    return merged;
}

// ─── Find common vs element-specific attributes ──────────────────────────────

const commonProps = resolveAllProps('PHPXHTMLAttributes');

// For each element, figure out which extra props it has beyond common
const elementSpecific = new Map<string, Map<string, PropInfo>>();

for (const [tag, ifaceName] of elementMap) {
    const allProps = resolveAllProps(ifaceName);

    const specific = new Map<string, PropInfo>();
    for (const [k, v] of allProps) {
        if (!commonProps.has(k)) {
            specific.set(k, v);
        }
    }

    if (specific.size > 0) {
        elementSpecific.set(tag, specific);
    }
}

// ─── Generate PHP ─────────────────────────────────────────────────────────────

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
lines.push(`// │  AUTO-GENERATED — do not edit by hand.                                   │`);
lines.push(`// │  Source: extension/types/phpx-intrinsics.d.ts                             │`);
lines.push(`// │  Generator: extension/scripts/generate-html-attributes.ts                  │`);
lines.push(`// │  Run:  cd extension && pnpm exec tsx scripts/generate-html-attributes.ts  │`);
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

// Common attributes
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

const commonCount = commonProps.size;
const elementCount = elementSpecific.size;
const totalSpecific = [...elementSpecific.values()].reduce((n, m) => n + m.size, 0);
console.log(`Generated ${OUTPUT}`);
console.log(`  ${commonCount} common attributes`);
console.log(`  ${elementCount} elements with ${totalSpecific} element-specific attributes`);
