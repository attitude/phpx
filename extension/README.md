# PHPX Language Support for VS Code

Syntax highlighting and language support for PHPX - JSX-like syntax for PHP.

## Features

- **Syntax Highlighting** - Full colorization of PHPX elements within PHP code
- **Fragment Support** - Highlights `<>...</>` fragment syntax
- **Expression Containers** - PHP expressions within `{...}` braces
- **Template Literals** - JavaScript-style `` `template ${var}` `` strings
- **Attribute Support** - String, expression, spread, and boolean attributes
- **PHPX Comments** - `{/* comment */}` style comments
- **PHP Integration** - Works alongside PHP IntelliSense extensions

## PHPX Syntax Examples

### Elements and Fragments
```phpx
<div className="container">Hello World</div>
<>Multiple elements without wrapper</>
```

### Attributes
```phpx
<div 
  className="static-value"          // String attribute
  id={$dynamicValue}                // Expression attribute
  disabled                          // Boolean attribute
  {$shorthand}                      // Shorthand (variable name = attribute name)
  {...$props}                       // Spread operator
/>
```

### Expression Containers
```phpx
<ul>
  {array_map(fn($item) => (
    <li>{$item}</li>
  ), $items)}
</ul>
```

### Template Literals
```phpx
$greeting = `Hello, ${$name ?? 'World'}!`;
```

### PHPX Comments
```phpx
<div>
  {/* This is a PHPX comment */}
  Content here
</div>
```

## Installation

### From VSIX (Local Development)

1. Build the extension:
   ```bash
   cd extension
   pnpm install
   pnpm compile
   pnpm package
   ```

2. Install the `.vsix` file:
   - Open VS Code
   - Press `Cmd+Shift+P` (Mac) or `Ctrl+Shift+P` (Windows/Linux)
   - Type "Install from VSIX"
   - Select the generated `.vsix` file

### From Marketplace (Coming Soon)

Search for "PHPX Language Support" in the VS Code Extensions marketplace.

## Development

### Setup
```bash
cd extension
pnpm install
pnpm compile
```

### Watch Mode
```bash
pnpm watch
```

### Testing
```bash
pnpm test
```

### Running the Extension
1. Press `F5` in VS Code to launch the Extension Development Host
2. Open a `.phpx` file to see syntax highlighting in action

## Project Structure

```
extension/
├── package.json                 # Extension manifest
├── language-configuration.json  # Bracket matching, comments, etc.
├── tsconfig.json               # TypeScript configuration
├── syntaxes/
│   ├── phpx.tmLanguage.json    # Main TextMate grammar
│   └── phpx-embedded.tmLanguage.json  # Injection grammar for PHP files
├── src/
│   ├── extension.ts            # Extension entry point
│   └── test/
│       ├── extension.test.ts   # Extension tests
│       ├── grammar.test.ts     # Grammar validation tests
│       └── fixtures/           # Test PHPX files
└── out/                        # Compiled JavaScript
```

## Grammar Scopes

The extension defines the following TextMate scopes for theming:

| Scope | Description |
|-------|-------------|
| `punctuation.definition.tag.phpx.fragment.begin` | `<>` fragment open |
| `punctuation.definition.tag.phpx.fragment.end` | `</>` fragment close |
| `entity.name.tag.phpx` | Element tag names |
| `entity.other.attribute-name.phpx` | Attribute names |
| `string.quoted.double.phpx` | Double-quoted strings |
| `string.quoted.single.phpx` | Single-quoted strings |
| `punctuation.definition.brace.begin.phpx` | `{` expression open |
| `punctuation.definition.brace.end.phpx` | `}` expression close |
| `keyword.operator.spread.phpx` | `...` spread operator |
| `variable.other.php` | PHP variables |
| `comment.block.phpx` | PHPX comments |
| `string.template.phpx` | Template literal strings |
| `punctuation.definition.interpolation.begin.phpx` | `${` interpolation |
| `punctuation.definition.interpolation.end.phpx` | `}` in template literal |

## Integration with PHP Extensions

PHPX Language Support is designed to work alongside PHP language extensions:

- **PHP Intelephense** - Recommended for PHP IntelliSense
- **PHP IntelliSense** - Alternative PHP language support
- **IntelliPHP** - AI-powered PHP completions

The injection grammar (`phpx-embedded.tmLanguage.json`) provides PHPX highlighting within `.php` files when PHPX syntax is present.

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests: `npm test`
5. Submit a pull request

## License

MIT License - See [LICENSE](../LICENSE) file.
