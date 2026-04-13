import * as vscode from 'vscode';
import * as fs from 'fs';
import * as path from 'path';

/**
 * TypeScript JSX Attribute Query
 *
 * Sets up a small virtual TypeScript project that uses phpx-intrinsics.d.ts
 * (the PHPX JSX type declarations) and queries VS Code's built-in TypeScript
 * language service for HTML element attribute completions and hover info.
 *
 * Architecture:
 *   1. At activation, write a query directory to globalStoragePath containing:
 *      • tsconfig.json  — enables jsx:"react", jsxFactory:"h", lib:["dom"]
 *      • phpx-intrinsics.d.ts  — copied from the extension's types/ folder
 *      • .query.tsx  — tiny JSX snippet used as the completion/hover target
 *   2. Per request, rewrite .query.tsx and call the appropriate
 *      vscode.execute*Provider command on it.
 *   3. Filter & cache results; return them to the PHPX providers.
 */

let queryDir: string | undefined;
let queryTsxPath: string | undefined;

/** Cache: tag name → completion items returned by TypeScript */
const completionCache = new Map<string, vscode.CompletionItem[]>();

/** Cache: `tagName:attrName` → hover markdown string */
const hoverCache = new Map<string, string | null>();

// ─── Initialization & disposal ────────────────────────────────────────────────

/**
 * Initialize the TypeScript query environment.
 * Must be called once during extension activation.
 */
export async function initTsxQuery(context: vscode.ExtensionContext): Promise<void> {
    queryDir = path.join(context.globalStorageUri.fsPath, 'tsx-query');
    queryTsxPath = path.join(queryDir, '.query.tsx');

    try {
        fs.mkdirSync(queryDir, { recursive: true });

        // Write tsconfig.json — enables JSX + DOM lib for the query file
        const intrinsicsPath = path.join(context.extensionPath, 'types', 'phpx-intrinsics.d.ts');
        const tsconfig = {
            compilerOptions: {
                target: 'ES2020',
                lib: ['ES2020', 'DOM'],
                jsx: 'react',
                jsxFactory: 'h',
                strict: false,
                noEmit: true,
                skipLibCheck: true,
                noUnusedLocals: false,
                noUnusedParameters: false,
            },
            // Reference the shipped phpx-intrinsics.d.ts from the extension
            files: [
                './.query.tsx',
                intrinsicsPath,
            ],
        };
        fs.writeFileSync(
            path.join(queryDir, 'tsconfig.json'),
            JSON.stringify(tsconfig, null, 2),
            'utf-8',
        );

        // Write an initial placeholder so TypeScript's language server indexes
        // the query file and warms up the project immediately.
        fs.writeFileSync(queryTsxPath, '/* @jsx h */\nconst _warmup = <div />;\n', 'utf-8');

        // Open the document so VS Code's TypeScript language server picks it up
        const doc = await vscode.workspace.openTextDocument(vscode.Uri.file(queryTsxPath));
        // Request completions once to warm up the TypeScript server project
        await vscode.commands.executeCommand(
            'vscode.executeCompletionItemProvider',
            doc.uri,
            new vscode.Position(1, 18), // inside `<div ` on line 1
        );
    } catch (err) {
        // Silently degrade — the PHPX LSP's own completions still work
        console.error('[PHPX] tsxQuery init failed:', err);
        queryDir = undefined;
        queryTsxPath = undefined;
    }
}

/**
 * Dispose of the query environment.
 * Called on extension deactivation.
 */
export function disposeTsxQuery(): void {
    completionCache.clear();
    hoverCache.clear();
    if (queryTsxPath) {
        try { fs.unlinkSync(queryTsxPath); } catch { /* ignore */ }
    }
    queryDir = undefined;
    queryTsxPath = undefined;
}

// ─── Internal helpers ─────────────────────────────────────────────────────────

/**
 * Write a query snippet to disk and open the document so TypeScript picks it up.
 * Returns the document, or undefined on failure.
 */
async function writeAndOpen(content: string): Promise<vscode.TextDocument | undefined> {
    if (!queryTsxPath) {
        return undefined;
    }
    try {
        fs.writeFileSync(queryTsxPath, content, 'utf-8');
        const uri = vscode.Uri.file(queryTsxPath);
        return await vscode.workspace.openTextDocument(uri);
    } catch {
        return undefined;
    }
}

// ─── Completions ──────────────────────────────────────────────────────────────

/**
 * Get TypeScript-powered attribute completions for an HTML element.
 *
 * Rewrites the query .tsx file to `<tagName ` and requests completions
 * from VS Code's built-in TypeScript language service. Results are cached
 * per tag name (they're static — DOM types don't change at runtime).
 */
export async function getTsxAttributeCompletions(
    tagName: string,
): Promise<vscode.CompletionItem[]> {
    if (!queryDir || !queryTsxPath) {
        return [];
    }

    const cached = completionCache.get(tagName);
    if (cached) {
        return cached;
    }

    try {
        // Write a minimal JSX snippet with the target tag name
        // Line 0: pragma comment
        // Line 1: `const _el = <tagName ` ← cursor here for attribute completions
        const snippet = `/* @jsx h */\nconst _el = <${tagName} `;
        const doc = await writeAndOpen(snippet);
        if (!doc) {
            return [];
        }

        // Cursor is at the end of line 1, after `<tagName `
        // Column = length of `const _el = <` (13) + tagName.length + 1 (space)
        const position = new vscode.Position(1, 13 + tagName.length);

        // Small delay to let TypeScript pick up the file change
        await new Promise<void>((resolve) => setTimeout(resolve, 150));

        const result = await vscode.commands.executeCommand<vscode.CompletionList>(
            'vscode.executeCompletionItemProvider',
            doc.uri,
            position,
            undefined,        // trigger character
            10,               // resolve up to N items for full detail
        );

        if (!result?.items?.length) {
            completionCache.set(tagName, []);
            return [];
        }

        // Keep only JSX attribute-style completions:
        //   CompletionItemKind.Property (10) — attribute props
        //   CompletionItemKind.Field    (4)  — may appear for some completions
        // Drop module imports, keywords, snippets, etc.
        const items = result.items.filter(
            (item) =>
                item.kind === vscode.CompletionItemKind.Property ||
                item.kind === vscode.CompletionItemKind.Field,
        );

        completionCache.set(tagName, items);
        return items;
    } catch {
        completionCache.set(tagName, []);
        return [];
    }
}

// ─── Hover ────────────────────────────────────────────────────────────────────

/**
 * Get TypeScript hover info for an HTML attribute on a given tag.
 *
 * Writes a query `.tsx` with the attribute used on the element and calls
 * `vscode.executeHoverProvider` on the attribute name position.
 * Returns markdown text or null.
 */
export async function getTsxAttributeHover(
    tagName: string,
    attrName: string,
): Promise<string | null> {
    if (!queryDir || !queryTsxPath) {
        return null;
    }

    const cacheKey = `${tagName}:${attrName}`;
    if (hoverCache.has(cacheKey)) {
        return hoverCache.get(cacheKey) ?? null;
    }

    try {
        // Build a snippet where the attribute appears with a valid value.
        // Use `attrName={null as any}` so TypeScript parses it without type errors
        // while still resolving the property type on hover.
        //
        // Line 0: `/* @jsx h */`
        // Line 1: `<tagName attrName={null as any} />`
        //                   ^ cursor here (col = 1 + tagName.length + 1)
        const attrCol = 1 + tagName.length + 1; // `<` + tagName + ` `
        const snippet = `/* @jsx h */\n<${tagName} ${attrName}={null as any} />`;

        const doc = await writeAndOpen(snippet);
        if (!doc) {
            return null;
        }

        const position = new vscode.Position(1, attrCol);

        await new Promise<void>((resolve) => setTimeout(resolve, 150));

        const hovers = await vscode.commands.executeCommand<vscode.Hover[]>(
            'vscode.executeHoverProvider',
            doc.uri,
            position,
        );

        if (!hovers?.length) {
            hoverCache.set(cacheKey, null);
            return null;
        }

        // Merge all hover contents into a single markdown string
        const parts: string[] = [];
        for (const hover of hovers) {
            for (const content of hover.contents) {
                if (typeof content === 'string') {
                    parts.push(content);
                } else if ('value' in content) {
                    parts.push(content.value);
                }
            }
        }

        const text = parts.join('\n\n') || null;
        hoverCache.set(cacheKey, text);
        return text;
    } catch {
        hoverCache.set(cacheKey, null);
        return null;
    }
}

// ─── Tag/attribute detection ──────────────────────────────────────────────────

/**
 * Detect the HTML tag name that the cursor is inside (for completions).
 *
 * Looks at the text before the cursor on the current line for an opening
 * tag pattern like `<div ` or `<input type="text" `.  Returns the lower-case
 * tag name, or undefined if not inside a tag.
 */
export function detectTagAtCursor(
    document: vscode.TextDocument,
    position: vscode.Position,
): string | undefined {
    const line = document.lineAt(position).text;
    const prefix = line.substring(0, position.character);

    // Match the last opening tag before the cursor that hasn't been closed.
    // Only lowercase-first tags (HTML intrinsic elements).
    const match = prefix.match(/<([a-z][a-zA-Z0-9-]*)(?:\s[^>]*)?\s\w*$/);
    if (!match) {
        return undefined;
    }

    return match[1].toLowerCase();
}

/**
 * Detect the tag name and attribute name at the cursor position (for hover).
 *
 * Handles both single-line and multi-line tags by scanning backwards through
 * document lines from the cursor to find the opening `<tagName`.
 *
 * Returns { tagName, attrName, attrStart, attrEnd } or undefined.
 */
export function detectTagAndAttributeAtCursor(
    document: vscode.TextDocument,
    position: vscode.Position,
): { tagName: string; attrName: string; attrStart: number; attrEnd: number } | undefined {
    const line = document.lineAt(position).text;
    const char = position.character;

    // Find the word (attribute name) under the cursor
    let start = char;
    let end = char;
    while (start > 0 && isWordChar(line[start - 1])) {
        start--;
    }
    while (end < line.length && isWordChar(line[end])) {
        end++;
    }
    if (start === end) {
        return undefined;
    }
    const attrName = line.substring(start, end);

    // Must be followed by `=` or whitespace or `/` or `>` to look like an attribute
    // (not a tag name, text content, etc.)
    const afterAttr = line[end];
    if (afterAttr !== undefined && afterAttr !== '=' && afterAttr !== ' '
        && afterAttr !== '\t' && afterAttr !== '/' && afterAttr !== '>') {
        return undefined;
    }

    // Build text from document start up to the attribute position to find the tag.
    // Scan backwards through lines to find `<tagName`.
    let textBefore = line.substring(0, start);
    let tagName: string | undefined;

    // Try current line first (fast path)
    const tagMatch = textBefore.match(/<([a-z][a-zA-Z0-9-]*)(?:\s[^>]*)?$/);
    if (tagMatch) {
        tagName = tagMatch[1];
    } else {
        // Multi-line tag: scan backwards through preceding lines
        for (let ln = position.line - 1; ln >= 0 && ln >= position.line - 20; ln--) {
            textBefore = document.lineAt(ln).text + '\n' + textBefore;
            const multiMatch = textBefore.match(/<([a-z][a-zA-Z0-9-]*)(?:\s[^>]*)?$/s);
            if (multiMatch) {
                tagName = multiMatch[1];
                break;
            }
            // Stop if we encounter a `>` — we've left the tag
            if (document.lineAt(ln).text.includes('>')) {
                break;
            }
        }
    }

    if (!tagName) {
        return undefined;
    }

    // Make sure the word isn't the tag name itself (e.g., cursor on `img` in `<img`)
    if (attrName === tagName) {
        return undefined;
    }

    return {
        tagName: tagName.toLowerCase(),
        attrName,
        attrStart: start,
        attrEnd: end,
    };
}

function isWordChar(ch: string | undefined): boolean {
    if (!ch) {
        return false;
    }
    return /[a-zA-Z0-9_]/.test(ch);
}
