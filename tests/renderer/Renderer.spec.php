<?php declare(strict_types=1);

use Attitude\PHPX\Renderer\Renderer;

require_once __DIR__.'/../../src/index.php';

describe('Attitude\ArrayRenderer\HTML', function () {
  it('generates a string', function () {
    $html = 'Hello World!';

    expect((new Renderer)($html))->toBe($html);
  });

  it('generates a numeric string', function () {
    $html = 42;

    expect((new Renderer)($html))->toBe('42');
  });

  it('skips a boolean', function () {
    $html = true;

    expect((new Renderer)($html))->toBe('');
  });

  it('skips a null', function () {
    $html = null;

    expect((new Renderer)($html))->toBe('');
  });

  it('generates a div', function () {
    $html = ['$', 'div', ['class' => 'container'], [
      ['$', 'h1', null, 'Hello World!'],
    ]];

    $expected = '<div class="container"><h1>Hello World!</h1></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('generates a div with non-element children', function () {
    $html = ['$', 'div', ['class' => 'container'], [
      null,
      ['$', 'h1', null, ['Hello World!', null, false, true]],
      false,
      true,
    ]];

    $expected = '<div class="container"><h1>Hello World!</h1></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('generates a div with multiple children', function () {
    $html = ['$', 'div', ['class' => 'container'], [
      ['$', 'h1', null, 'Hello World!'],
      ['$', 'p', null, 'This is a paragraph.'],
    ]];

    $expected = '<div class="container"><h1>Hello World!</h1><p>This is a paragraph.</p></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('generates a div with multiple children and attributes', function () {
    $html = ['$', 'div', ['class' => 'container',
    'id' => 'main-container'], [
      ['$', 'h1', null, 'Hello World!'],
      ['$', 'p', null, 'This is a paragraph.'],
    ]];

    $expected = '<div class="container" id="main-container"><h1>Hello World!</h1><p>This is a paragraph.</p></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('generates a div with multiple children and attributes and styles', function () {
    $html = ['$', 'div', ['class' => 'container',
    'id' => 'main-container',
    'style' => (object)['color' => 'red', 'font-size' => '16px']], [
      ['$', 'h1', null, 'Hello World!'],
      ['$', 'p', null, 'This is a paragraph.'],
    ]];

    $expected = '<div class="container" id="main-container" style="color:red;font-size:16px"><h1>Hello World!</h1><p>This is a paragraph.</p></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('generates a div with multiple children and attributes and styles and classes', function () {
    $html = ['$', 'div', [
      'class' => ['container', 'main-container'],
      'id' => 'main-container',
      'style' => (object)['color' => 'red', 'font-size' => '16px'],
    ], [
      ['$', 'h1', null, 'Hello World!'],
      ['$', 'p', null, 'This is a paragraph.'],
    ]];

    $expected = '<div class="container main-container" id="main-container" style="color:red;font-size:16px"><h1>Hello World!</h1><p>This is a paragraph.</p></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('generates a div with multiple children and attributes and styles and classes and data attributes', function () {
    $html = ['$', 'div', [
      'class' => ['container', 'main-container'],
      'id' => 'main-container',
      'style' => (object)['color' => 'red', 'font-size' => '16px'],
      'data-attribute' => null,
      'data-another' => 'value',
    ], [
      ['$', 'h1', null, 'Hello World!'],
      ['$', 'p', null, 'This is a paragraph.'],
    ]];

    $expected = '<div class="container main-container" id="main-container" style="color:red;font-size:16px" data-another="value"><h1>Hello World!</h1><p>This is a paragraph.</p></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('handles camelCase attributes', function () {
    $html = ['$',  'div', [
      'class' => ['container', 'main-container'],
      'id' => 'main-container',
      'style' => (object)['color' => 'red', 'fontSize' => '16px'],
      'data-attribute' => null,
      'data-another' => 'value',
    ], [
      ['$', 'h1', null, 'Hello World!'],
      ['$', 'p', null, 'This is a paragraph.'],
    ]];

    $expected = '<div class="container main-container" id="main-container" style="color:red;font-size:16px" data-another="value"><h1>Hello World!</h1><p>This is a paragraph.</p></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('handles empty string attributes', function () {
    $html = ['$',  'div', [
      'class' => ['container', 'main-container'],
      'id' => 'main-container',
      'style' => ['color' => 'red', 'fontSize' => '16px'],
      'data-attribute' => null,
      'data-another' => 'value',
      'empty-string' => '',
    ], [
      ['$', 'h1', null, 'Hello World!'],
      ['$', 'p', null, 'This is a paragraph.'],
    ]];

    $expected = '<div class="container main-container" id="main-container" style="color:red;font-size:16px" data-another="value" empty-string=""><h1>Hello World!</h1><p>This is a paragraph.</p></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('handles void elements', function () {
    $html = ['$', 'input', ['type' => 'text', 'name' => 'username']];

    $expected = '<input type="text" name="username" />';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('auto-closes void elements', function () {
    $html = ['$', 'input', ['type' => 'text', 'name' => 'username']];

    $expected = '<input type="text" name="username">';
    $renderer = new Renderer;
    $renderer->void = true;

    expect($renderer($html))->toBe($expected);
  });

  it('handles dangerouslySetInnerHTML', function () {
    $html = ['$', 'div', ['dangerouslySetInnerHTML' => '<h1>Hello World!</h1>']];

    $expected = '<div><h1>Hello World!</h1></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('handles nested fragments', function () {
    $html = ['$', 'div', ['class' => 'container'], [
      [
        ['$','h1', null, 'Hello World!'],
        ['$','p', null, 'This is a paragraph.'],
      ],
      [
        ['$','p', null, 'This is another paragraph.'],
      ],
    ]];

    $expected = '<div class="container"><h1>Hello World!</h1><p>This is a paragraph.</p><p>This is another paragraph.</p></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('renders data attributes passed as an object', function () {
    $html = ['$', 'div', [
      'data' => (object)[
        'attribute' => 'value',
        'bar' => null,
        'baz' => false,
        'active' => true,
      ],
    ]];

    $expected = '<div data-attribute="value" data-active></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('renders nested elements with fragments pretty-printed', function () {
    $html = ['$', 'div', ['class' => 'container'], [
      [
        ['$', 'h1', null, 'Hello World!'],
        ['$', 'p', null, 'This is a paragraph.'],
      ],
      [
        ['$', 'p', null, 'This is another paragraph.'],
      ],
      [
        ['$', 'p', null, [
          'This is a nested paragraph with ',
          ['$', 'a', ['href' => 'https://github.com/attitude/phpx'], [
            'a nested link with ',
            ['$', 'span', null, 'a nested span.'],
          ]],
          ' and end of a nested paragraph.',
        ]],
        ],
    ]];

    $expected = <<<'HTML'
<div class="container">
  <h1>Hello World!</h1>
  <p>This is a paragraph.</p>
  <p>This is another paragraph.</p>
  <p>
    This is a nested paragraph with <a href="https://github.com/attitude/phpx">
      a nested link with <span>a nested span.</span>
    </a> and end of a nested paragraph.
  </p>
</div>
HTML;

    $renderer = new Renderer;
    $renderer->pretty = true;
    $renderer->indentation = '  ';

    expect($renderer($html))->toBe($expected);
  });

  it('renders full HTML document', function () {
    $html = ['$', 'html', ['lang' => 'en'], [
      ['$', 'head', null, [
        ['$', 'meta', [ 'charset' => 'UTF-8']],
        ['$', 'meta', [
          'name' => 'viewport',
          'content' => 'width=device-width, initial-scale=1.0',
        ]],
        ['$', 'title', null, [
          'Blog',
          ' by @martin_adamko',
        ]],
        ['$', 'meta', [ 'name' => 'description', 'content' => null]],
        ['$', 'link', [ 'rel' => 'stylesheet', 'href' => '/_assets/css/styles.css']],
      ]],
      ['$', 'body', null, [
        ['$', 'header',null, [
          ['$', 'Navigation', ['url' => '/', 'title' => 'Martin Adamko'], [
            ['$', 'a', ['href' => '/'], ['Home']],
          ]],
          ['$', 'h1', null, [['Blog']]],
          null,
        ]],
        ['$', 'main', ['dangerouslySetInnerHTML' => '<h2>Recent Articles</h2>']],
        ['$', 'aside', null, [
          ['$', 'ul', null, [[
            ['$', 'li', null, [['$', 'a', ['href' => '/blog/2024-03-12/index.html'],
                ['2024-03-12'],
              ]],
            ],
            ['$', 'li', null, [['$', 'a', ['href' => '/blog/2024-03-14/index.html'],
                ['2024-03-14'],
              ]],
            ],
          ]]],
        ]],
        ['$', 'footer',null, [
          ['$', 'p', null, ['©', 2024, ' ', ['$', 'a', ['href' => 'https://threads.com/@martin_adamko'], '@martin_adamko']]],
        ]],
      ]],
    ]];

    $expected = <<<'HTML'
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Blog by @martin_adamko</title>
    <meta name="description" />
    <link rel="stylesheet" href="/_assets/css/styles.css" />
  </head>
  <body>
    <header>
      <nav class="navigation">
        <a href="/" class="navigation-link">
          <strong class="navigation-title">Martin Adamko</strong>
        </a>
        <a href="/">Home</a>
      </nav>
      <h1>Blog</h1>
    </header>
    <main><h2>Recent Articles</h2></main>
    <aside>
      <ul>
        <li>
          <a href="/blog/2024-03-12/index.html">2024-03-12</a>
        </li>
        <li>
          <a href="/blog/2024-03-14/index.html">2024-03-14</a>
        </li>
      </ul>
    </aside>
    <footer>
      <p>
        ©2024 <a href="https://threads.com/@martin_adamko">@martin_adamko</a>
      </p>
    </footer>
  </body>
</html>
HTML;

    $renderer = new Renderer();
    $renderer->pretty = true;
    $renderer->indentation = '  ';

    expect($renderer($html, [
      'Navigation' => function (array $props): array {
        [
          'url' => $url,
          'title' => $title,
          'children' => $children,
        ] = $props;

        return (
          ['$', 'nav', ['class' => 'navigation'], [
            ['$', 'a', ['href' => $url, 'class' => 'navigation-link'], [
              ['$', 'strong', ['class' => 'navigation-title'], $title],
            ]],
            [[$children]],
          ]]
        );
      },
    ]))->toBe($expected);
  });

  it('renders full HTML document with fragment', function() {
    $html = ['$', 'html', ['lang' => 'en'], [
      ['$', 'head', null, [
        ['$', 'meta', [ 'charset' => 'UTF-8']],
        ['$', 'meta', [
          'name' => 'viewport',
          'content' => 'width=device-width, initial-scale=1.0',
        ]],
        ['$', 'title', null, 'Blog'],
        ['$', 'meta', [ 'name' => 'description', 'content' => null]],
        ['$', 'link', [ 'rel' => 'stylesheet', 'href' => '/_assets/css/styles.css']],
      ]],
      ['$', 'body', null, [['$', 'Page']]],
    ]];

    $expected = <<<'HTML'
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Blog</title>
    <meta name="description" />
    <link rel="stylesheet" href="/_assets/css/styles.css" />
  </head>
  <body>
    <header>
      <nav class="navigation">
        <a href="/" class="navigation-link">
          <strong class="navigation-title">Martin Adamko</strong>
        </a>
        <a href="/">Home</a>
      </nav>
      <h1>Blog</h1>
    </header>
    <main><h2>Recent Articles</h2></main>
    <aside>
      <ul>
        <li>
          <a href="/blog/2024-03-12/index.html">2024-03-12</a>
        </li>
        <li>
          <a href="/blog/2024-03-14/index.html">2024-03-14</a>
        </li>
      </ul>
    </aside>
    <footer>
      <p>
        ©2024 <a href="https://threads.com/@martin_adamko">@martin_adamko</a>
      </p>
    </footer>
  </body>
</html>
HTML;

    $renderer = new Renderer();
    $renderer->pretty = true;
    $renderer->indentation = '  ';

    expect($renderer($html, [
      'Navigation' => function (array $props): array {
        [
          'url' => $url,
          'title' => $title,
          'children' => $children,
        ] = $props;

        return (
          ['$', 'nav', ['class' => 'navigation'], [
            ['$', 'a', ['href' => $url, 'class' => 'navigation-link'], [
              ['$', 'strong', ['class' => 'navigation-title'], $title],
            ]],
            [[[$children]]],
          ]]
        );
      },
      'Page' => function() {
        return (
          [
            [
              ['$', 'header',null, [
                ['$', 'Navigation', ['url' => '/', 'title' => 'Martin Adamko'], [
                  ['$', 'a', ['href' => '/'], ['Home']],
                ]],
                ['$', 'h1', null, [['Blog']]],
                null,
              ]],
              [[['$', 'main', ['dangerouslySetInnerHTML' => '<h2>Recent Articles</h2>']]]],
              ['$', 'aside', null, [
                ['$', 'ul', null, [[
                  ['$', 'li', null, [['$', 'a', ['href' => '/blog/2024-03-12/index.html'],
                      ['2024-03-12'],
                    ]],
                  ],
                  ['$', 'li', null, [['$', 'a', ['href' => '/blog/2024-03-14/index.html'],
                      ['2024-03-14'],
                    ]],
                  ],
                ]]],
              ]],
              ['$', 'footer',null, [
                ['$', 'p', null, ['©', 2024, ' ', ['$', 'a', ['href' => 'https://threads.com/@martin_adamko'], '@martin_adamko']]],
              ]],
            ],
          ]
        );
      },
    ]))->toBe($expected);
  });

  it('renders data attribute', function () {
    $html = ['$', 'div', ['data-foo' => true], [
      ['$', 'input', ['type'=>'checkbox', 'checked'=>true, 'disabled'=>true]],
    ]];

    $expected = '<div data-foo><input type="checkbox" checked disabled /></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('renders array of data attributes', function () {
    $html = ['$', 'div', ['data' => ['foo' => true, 'bar' => 2, 'baz' => null]], [
      ['$', 'input', ['type'=>'checkbox', 'checked'=>true, 'disabled'=>true]],
    ]];

    $expected = '<div data-foo data-bar="2"><input type="checkbox" checked disabled /></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('renders React compatible output', function () {
    $Component = include __DIR__ . '/react-18/components/Title.php';
    $expected = file_get_contents(__DIR__ . '/react-18/components/Title.tsx.html');
    $data = json_decode(file_get_contents(__DIR__ . '/react-18/components/Title.tsx.json'), true);

    $html = $Component($data);
    $renderer = new Renderer;
    $renderer->react = true;

    expect($renderer($html))->toBe($expected);
  });

  describe('Closure as node type', function () {
    it('renders a closure returning a string', function () {
      $html = ['$', fn() => 'Hello from closure!', null];

      expect((new Renderer)($html))->toBe('Hello from closure!');
    });

    it('renders a closure returning null', function () {
      $html = ['$', fn() => null, null];

      expect((new Renderer)($html))->toBe('');
    });

    it('renders a closure returning false', function () {
      $html = ['$', fn() => false, null];

      expect((new Renderer)($html))->toBe('');
    });

    it('renders a closure returning an HTML element', function () {
      $html = ['$', fn() => ['$', 'span', null, 'Closure span'], null];

      expect((new Renderer)($html))->toBe('<span>Closure span</span>');
    });

    it('renders a closure receiving props', function () {
      $component = fn(array $props) => ['$', 'p', ['class' => $props['class']], $props['text']];

      $html = ['$', $component, ['class' => 'greeting', 'text' => 'Hi!']];

      expect((new Renderer)($html))->toBe('<p class="greeting">Hi!</p>');
    });

    it('renders a closure receiving children via props', function () {
      $component = fn(array $props) => ['$', 'section', null, $props['children']];

      $html = ['$', $component, null, [
        ['$', 'h2', null, 'Title'],
        ['$', 'p', null, 'Body'],
      ]];

      expect((new Renderer)($html))->toBe('<section><h2>Title</h2><p>Body</p></section>');
    });

    it('renders a closure receiving both props and children', function () {
      $component = fn(array $props) => [
        '$', 'div', ['class' => $props['class']], $props['children'],
      ];

      $html = ['$', $component, ['class' => 'wrapper'], [
        ['$', 'span', null, 'Inside'],
      ]];

      expect((new Renderer)($html))->toBe('<div class="wrapper"><span>Inside</span></div>');
    });

    it('renders a closure that ignores props and returns a static element', function () {
      $component = fn() => ['$', 'hr', []];

      $html = ['$', $component, ['data-ignored' => 'yes']];

      expect((new Renderer)($html))->toBe('<hr />');
    });

    it('renders nested closures', function () {
      $inner = fn(array $props) => ['$', 'em', null, $props['text']];
      $outer = fn(array $props) => ['$', 'strong', null, ['$', $inner, ['text' => $props['label']]]];

      $html = ['$', $outer, ['label' => 'Nested']];

      expect((new Renderer)($html))->toBe('<strong><em>Nested</em></strong>');
    });

    it('renders a closure alongside named components', function () {
      $closure = fn(array $props) => ['$', 'b', null, $props['children']];

      $html = ['$', 'div', null, [
        ['$', $closure, null, 'Bold text'],
        ['$', 'Badge', null, 'Labeled'],
      ]];

      expect((new Renderer)($html, [
        'Badge' => fn(array $props) => ['$', 'span', ['class' => 'badge'], $props['children']],
      ]))->toBe('<div><b>Bold text</b><span class="badge">Labeled</span></div>');
    });

    it('renders a closure returning an array fragment', function () {
      $component = fn() => [
        ['$', 'li', null, 'Item 1'],
        ['$', 'li', null, 'Item 2'],
      ];

      $html = ['$', 'ul', null, [
        ['$', $component, null],
      ]];

      expect((new Renderer)($html))->toBe('<ul><li>Item 1</li><li>Item 2</li></ul>');
    });

    it('invokes closure node types directly without consulting components map', function () {
      $closureComponent = fn(array $props) => ['$', 'span', ['class' => 'from-closure'], $props['children']];

      $html = ['$', $closureComponent, null, 'Content'];

      expect((new Renderer)($html, [
        'Badge' => fn() => ['$', 'div', null, 'Alternative component'],
      ]))->toBe('<span class="from-closure">Content</span>');
    });
  });
});
