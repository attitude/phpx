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

When used directly as the element type (index 1 of the node array), only a `\Closure` is supported. In the components map, however, values may be any PHP callable (accepting 0 or 1 parameter); children are passed via `$props['children']`.

#### Prop conventions

`className` and `htmlFor` map to `class` and `for`. `style` and `data` accept arrays and are serialised to inline CSS and `data-*` attributes respectively, with camelCase keys converted to kebab-case. Array attribute values are flattened and space-joined. `null` omits the attribute; `true` renders it as a valueless boolean attribute.

Use `dangerouslySetInnerHTML` to inject raw HTML — value must be `['__html' => '...']` and is **not** escaped; only use with trusted content.

#### Renderer options

`$pretty` (bool) enables indented output; `$indentation` (string, default `"\t"`) sets the indent character. `$void` switches void elements to HTML5-style `>`. `$react` adds `<!-- -->` markers around leading and trailing whitespace in string children (even when the text also contains non-whitespace) for React-compatible output. Pass `encoding:` to the constructor to override the default `UTF-8`.

---

## Security

All text and attribute values are escaped via `htmlspecialchars` (`ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE`) using the renderer's configured encoding (default `UTF-8`). Tag and attribute names are validated against strict patterns. The only exception is `dangerouslySetInnerHTML`, which intentionally bypasses escaping — treat it like `innerHTML` and only pass trusted content.

---

## CLI compilation

Compile `.phpx` files to `.php` from the command line:

```shell
php scripts/compile.php path/to/component.phpx
```

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
