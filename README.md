# PHPX — JSX-like syntax for PHP

> [!WARNING]
> **Experimental project – give it a try**

Write XML/HTML-like markup directly in PHP files. PHPX compiles it to plain PHP arrays that a lightweight `Renderer` turns into HTML strings — no template engine, no magic.

## How it works

**Source (PHPX):**

```jsx
<>
  <h1 className="title">Hello, {$name ?? ucfirst($type)}!</h1>
  <p>
    Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.
    <img src="about:blank" alt="Happy coding!" /> forever!
  </p>
</>
```

**Compiled output (PHP):**

```php
[
  ['$', 'h1', ['className'=>"title"], ['Hello, ', ($name ?? ucfirst($type)), '!']],
  ['$', 'p', null, [
    'Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.',
    ['$', 'img', ['src'=>"about:blank", 'alt'=>"Happy coding!"]], ' forever!',
  ]],
]
```

Each element is a tuple `['$', tag, props|null, children]`. PHP expressions inside `{ }` are preserved verbatim, so the compiled output is valid, executable PHP.

---

## Installation

> [!IMPORTANT]
> This project is not yet published to Packagist. Add the repository manually or include it as a submodule.

### Option 1: Git submodule

```shell
git submodule add git@github.com:attitude/phpx.git path/to/phpx
```

### Option 2: Composer (VCS repository)

Add to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/attitude/phpx"
        }
    ],
    "require": {
        "attitude/phpx": "dev-main"
    }
}
```

```shell
composer install
```

### Option 3: Download ZIP

Download and extract the repository, then `require_once 'path/to/phpx/src/index.php'` in your project.

---

## Usage

### Compiler

The `Compiler` transforms PHPX source strings into PHP code strings.

```php
<?php

require_once 'path/to/phpx/src/index.php';

$compiler = new \Attitude\PHPX\Compiler\Compiler();

$phpCode = $compiler->compile(<<<'PHPX'
<>
  <h1 className="title">Hello, {$name ?? ucfirst($type)}!</h1>
  <p>
    Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.
    <img src="about:blank" alt="Happy coding!" /> forever!
  </p>
</>
PHPX);

echo $phpCode;
```

#### Pragma formatter

By default the compiler emits `['$', 'tag', ...]` arrays. Use `PragmaFormatter` to emit function-call style output instead:

```php
$compiler = new \Attitude\PHPX\Compiler\Compiler(
    formatter: new \Attitude\PHPX\Compiler\PragmaFormatter(pragma: 'html', fragment: 'fragment'),
);

$compiler->compile('<h1 className="title">Hello!</h1>');
// html('h1', ['className'=>"title"], ['Hello!'])

$compiler->compile('<>Hello, {$name}!</>');
// fragment(['Hello, ', ($name), '!'])
```

#### Template literals

Backtick template literals with `${ }` interpolation are compiled to PHP string concatenation:

```phpx
`Hello, my name is ${$name}, and I come from ${$country}!`
```

```php
'Hello, my name is '.($name).', and I come from '.($country).'!'
```

---

### Renderer

The `Renderer` converts the compiled array format into an HTML string.

```php
<?php

require_once 'path/to/phpx/src/index.php';

$renderer = new \Attitude\PHPX\Renderer\Renderer();

$node = ['$', 'h1', ['className' => 'title'], 'Hello, World!'];

echo $renderer($node); // <h1 class="title">Hello, World!</h1>
```

Strings and numbers are HTML-escaped; `null` and `bool` render as empty strings (matching React behaviour):

```php
echo $renderer('<b>hi</b>'); // &lt;b&gt;hi&lt;/b&gt;
echo $renderer(null);        // (empty)
echo $renderer(true);        // (empty)
```

#### Components

Pass an associative array of named components as closures. Each component receives a `$props` array (including `children`):

```php
$node = ['$', 'Greeting', ['name' => 'World']];

echo $renderer($node, [
    'Greeting' => function(array $props): array {
        return ['$', 'p', null, "Hello, {$props['name']}!"];
    },
]);
// <p>Hello, World!</p>
```

Pass a `\Closure` directly as the element type to skip the components map:

```php
$greet = fn(array $props): array => ['$', 'span', null, "Hi, {$props['name']}!"];

echo $renderer(['$', $greet, ['name' => 'Alice']]);
// <span>Hi, Alice!</span>
```

Any PHP callable is accepted — named functions, static methods (`'Class::method'` or `['Class', 'method']`), and instance methods (`[$obj, 'method']`). Components accept 0 or 1 parameter (0-param components are called without arguments); children are passed via `$props['children']`.

#### Prop conventions

| Prop | Behaviour |
|---|---|
| `className` | Rendered as `class` |
| `htmlFor` | Rendered as `for` |
| Any attribute with an array value | Recursively flattened and joined with a space: `['a', 'b']` → `"a b"` |
| `style` (array/object) | Properties serialised to inline CSS with camelCase → kebab-case conversion: `['fontSize' => '16px']` → `font-size:16px` |
| `data` (array/object) | Expanded to `data-*` attributes with camelCase → kebab-case conversion |
| `dangerouslySetInnerHTML` | Raw HTML injected without escaping; value must be `['__html' => '...']` (cannot be combined with `children`) |
| Boolean (`true`) | Rendered as a valueless attribute: `['checked' => true]` → `checked` |
| `null` value | Attribute is omitted from output |
| `DateTime` / `DateTimeImmutable` | Formatted as `Y-m-d\TH:i:s` (ISO 8601 without timezone offset) |

> **Note:** All attribute names are lowercased — camelCase names like `onClick` become `onclick`.

#### Renderer options

| Property | Type | Default | Description |
|---|---|---|---|
| `$pretty` | `bool` | `false` | Enable indented output (public property, set directly) |
| `$indentation` | `string` | `"\t"` | Indentation string used when `$pretty` is `true` (public property) |
| `$void` | `bool` | `false` | Use HTML5-style `>` instead of XHTML-style `/>` for void elements |
| `$react` | `bool` | `false` | React-compatible whitespace: inserts `<!-- -->` comment markers around leading/trailing whitespace in text nodes |
| `encoding` | `string` | `'UTF-8'` | Constructor argument: character encoding for `htmlspecialchars` — `new Renderer(encoding: 'ISO-8859-1')` |

---

## Security

### XSS prevention

The `Renderer` automatically escapes all untrusted output using `htmlspecialchars` with `ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE`. This applies to every output path:

| Context | Escaped |
|---|---|
| Text node strings | Yes — `<`, `>`, `&`, `"`, `'` all encoded |
| Attribute values (string/numeric) | Yes — attribute break-out via `"` or `'` is prevented |
| `style` object values | Yes — prevents HTML attribute break-out; CSS content itself is not sanitised |
| `data-*` attribute values | Yes — same encoding as regular attributes |
| `dangerouslySetInnerHTML` | **No** — raw HTML is intentionally injected; only use with trusted content |

In addition, the renderer validates:

- **Tag names** must match `^[a-zA-Z][a-zA-Z0-9\-\.]*$` — whitespace, angle brackets, quotes, and slashes are rejected
- **Attribute names** must match `^[a-z][a-z0-9\-:._]*$` — whitespace and quotes are rejected
- **Data attribute keys** are validated with the same pattern

```php
$renderer(['$', 'p', null, $userInput]);
// <script>alert(1)</script>  →  &lt;script&gt;alert(1)&lt;/script&gt;

$renderer(['$', 'div', ['title' => $userInput]]);
// " onxss="1  →  title="&quot; onxss=&quot;1"

// dangerouslySetInnerHTML bypasses all escaping — caller is responsible:
$renderer(['$', 'div', ['dangerouslySetInnerHTML' => ['__html' => $trustedHtml]]]);
```

---

## CLI compilation

Compile `.phpx` files to `.php` from the command line:

```shell
php scripts/compile.php path/to/component.phpx
```

This reads the `.phpx` file, compiles it, and writes the output to a `.php` file with the same name.

---

## Running tests

```shell
composer test                    # run the test suite
composer test:watch              # re-run on file changes
composer test:coverage           # generate a coverage report
composer test:update-snapshots   # update test snapshots
```

---

Created by [martin_adamko](https://www.threads.net/@martin_adamko)
