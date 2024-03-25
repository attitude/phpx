# PHPX is to PHP what JSX is to JS

> [!WARNING]
> **Experimental project – give it a try**

## Turn PHPX syntax into PHP code

**Source input:**


```jsx
<>
  <h1 className="title">Hello, {$name ?? ucfirst($type)}!</h1>
  <p>
    Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.
    <img src="about:blank" alt="Happy coding!" /> forever!
  </p>
</>
```

**Compiled output:**

```php
[
  ['h1', ['className'=>"title"], ['Hello, ', ($name ?? ucfirst($type)), '!']],
  ['p', null, [
    'Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.',
    ['img', ['src'=>"about:blank", 'alt'=>"Happy coding!"]], ' forever!',
  ]],
]
```


## Installation

> [!IMPORTANT]
> This project is not yet published to Packagist. You need to add the repository manually or clone the repository as a submodule.

### Option 1: Add as a Git submodule

```shell
$ git submodule add git@github.com:attitude/phpx.git path/to/phpx
```

### Option 2: Add as a dependency using Composer

Update `composer.json` of your project:

```json
{
    ...,
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
$ composer install
```

### Option 3: Download the repository as a ZIP

---

## Usage

```php
<?php

require_once 'path/to/phpx/src/Compiler.php';

$phpx = new \PHPX\PHPX\Compiler();

$phpx->compile(<<<'PHPX'
<>
  <h1 className="title">Hello, {$name ?? ucfirst($type)}!</h1>
  <p>
    Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.
    <img src="about:blank" alt="Happy coding!" /> forever!
  </p>
</>
PHPX);
```

---

*Enjoy!*

Created by [martin_adamko](https://www.threads.net/@martin_adamko)
