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

Scalar values pass through unchanged; `null` and `bool` values render as empty strings (matching React behaviour):

```php
echo $renderer('plain text'); // plain text
echo $renderer(42);           // 42
echo $renderer(null);         // (empty)
echo $renderer(true);         // (empty)
```

#### Named components

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

A closure that declares no parameters is called without arguments:

```php
$node = ['$', 'Timestamp', null];

echo $renderer($node, [
    'Timestamp' => function(): array {
        return ['$', 'time', null, date('Y-m-d')];
    },
]);
// <time>2026-03-22</time>
```

Inline closures work too — pass a `\Closure` directly as the element type:

```php
$greet = fn(array $props): array => ['$', 'span', null, "Hi, {$props['name']}!"];

echo $renderer(['$', $greet, ['name' => 'Alice']]);
// <span>Hi, Alice!</span>
```

#### Prop conventions

| Prop | Behaviour |
|---|---|
| `className` | Rendered as `class` (or kept as-is when `$react = true`) |
| `class` (array) | Array values joined with a space: `['a', 'b']` → `class="a b"` |
| `style` (object) | `stdClass` properties serialised to inline CSS: `color:red;font-size:16px` |
| `dangerouslySetInnerHTML` | Raw HTML injected without escaping (cannot be combined with `children`) |
| `htmlFor` | Rendered as `for` |

#### Pretty-printing

```php
$renderer->pretty = true;
$renderer->indentation = '  '; // default: "\t"
```

#### Renderer options

| Property | Type | Default | Description |
|---|---|---|---|
| `$pretty` | `bool` | `false` | Enable indented output |
| `$indentation` | `string` | `"\t"` | Indentation string used when `$pretty` is `true` |
| `$void` | `bool` | `false` | Self-close all elements (e.g. for XML output) |
| `$react` | `bool` | `false` | React-compatible output (`className` kept as-is) |
| `$encoding` | `string` | `'UTF-8'` | Character encoding for `htmlspecialchars` escaping |

---

## Security

### XSS prevention

The `Renderer` automatically escapes all untrusted output using `htmlspecialchars` with `ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE`. This applies to every output path:

| Context | Escaped |
|---|---|
| Text node strings | Yes — `<`, `>`, `&`, `"`, `'` all encoded |
| Attribute values (string/numeric) | Yes — attribute break-out via `"` or `'` is prevented |
| `style` object values | Yes — CSS value injection is prevented |
| `data-*` attribute values | Yes — same encoding as regular attributes |
| `dangerouslySetInnerHTML` | **No** — raw HTML is intentionally injected; only use with trusted content |

**Safe by default:**

```php
// User-supplied content is automatically escaped in text nodes:
$renderer(['$', 'p', null, $userInput]);
// <script>alert(1)</script>  →  &lt;script&gt;alert(1)&lt;/script&gt;

// Attribute values are also escaped:
$renderer(['$', 'div', ['title' => $userInput]]);
// " onxss="1  →  title="&quot; onxss=&quot;1"
```

**`dangerouslySetInnerHTML` bypasses all escaping — only use with content you fully control:**

```php
// Raw HTML — you are responsible for sanitising $trustedHtml:
$renderer(['$', 'div', ['dangerouslySetInnerHTML' => $trustedHtml]]);
```

---

## Running tests

```shell
composer test           # run the test suite
composer test:watch     # re-run on file changes
composer test:coverage  # generate a coverage report
```

---

Created by [martin_adamko](https://www.threads.net/@martin_adamko)
