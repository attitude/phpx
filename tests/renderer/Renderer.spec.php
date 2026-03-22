<?php declare(strict_types=1);

use Attitude\PHPX\Renderer\Renderer;

require_once __DIR__.'/../../src/index.php';

class PhpxTestStaticComponent {
  public static function render(): array {
    return ['$', 'b', null, 'static zero'];
  }
  public static function renderWithProps(array $props): array {
    return ['$', 'b', null, $props['text']];
  }
}

class PhpxTestInstanceComponent {
  public function render(): array {
    return ['$', 'i', null, 'instance zero'];
  }
  public function renderWithProps(array $props): array {
    return ['$', 'i', null, $props['text']];
  }
}

function phpx_test_two_arg_component(array $props, string $extra): array {
  return ['$', 'span', null, $extra];
}

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

  it('maps className to class attribute', function () {
    $html = ['$', 'div', ['className' => 'my-class'], 'content'];

    expect((new Renderer)($html))->toBe('<div class="my-class">content</div>');
  });

  it('maps htmlFor to for attribute', function () {
    $html = ['$', 'label', ['htmlFor' => 'input-id'], 'Label'];

    expect((new Renderer)($html))->toBe('<label for="input-id">Label</label>');
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
    $html = ['$', 'div', ['dangerouslySetInnerHTML' => ['__html' => '<h1>Hello World!</h1>']]];

    $expected = '<div><h1>Hello World!</h1></div>';

    expect((new Renderer)($html))->toBe($expected);
  });

  it('throws when dangerouslySetInnerHTML and children are both present', function () {
    $html = ['$', 'div', ['dangerouslySetInnerHTML' => ['__html' => '<b>raw</b>']], 'child text'];

    expect(fn() => (new Renderer)($html))->toThrow(\Exception::class);
  });

  it('throws when dangerouslySetInnerHTML is not an array with __html key', function () {
    $html = ['$', 'div', ['dangerouslySetInnerHTML' => '<b>raw</b>']];

    expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
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
        ['$', 'main', ['dangerouslySetInnerHTML' => ['__html' => '<h2>Recent Articles</h2>']]],
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
              [[['$', 'main', ['dangerouslySetInnerHTML' => ['__html' => '<h2>Recent Articles</h2>']]]]],
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

    it('escapes HTML special chars in a string returned by a closure', function () {
      $html = ['$', fn() => '<b>bold</b> & "quoted"', null];

      expect((new Renderer)($html))->toBe('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;');
    });

    it('escapes HTML special chars in a string returned by a named component', function () {
      $html = ['$', 'Dangerous', null];

      expect((new Renderer)($html, [
        'Dangerous' => fn() => '<script>alert(1)</script>',
      ]))->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
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

  describe('Non-Closure callable components', function () {
    it('calls a 0-arg named function component without props', function () {
      function phpx_test_zero_arg_component(): array {
        return ['$', 'span', null, 'from named function'];
      }

      $html = ['$', 'Foo', null];

      expect((new Renderer)($html, ['Foo' => 'phpx_test_zero_arg_component']))->toBe('<span>from named function</span>');
    });

    it('calls a 1-arg named function component with props', function () {
      function phpx_test_one_arg_component(array $props): array {
        return ['$', 'span', null, $props['label']];
      }

      $html = ['$', 'Foo', ['label' => 'from props']];

      expect((new Renderer)($html, ['Foo' => 'phpx_test_one_arg_component']))->toBe('<span>from props</span>');
    });

    it('calls a 0-arg static method component', function () {
      $html = ['$', 'Foo', null];

      expect((new Renderer)($html, ['Foo' => ['PhpxTestStaticComponent', 'render']]))->toBe('<b>static zero</b>');
    });

    it('calls a 1-arg static method component with props', function () {
      $html = ['$', 'Foo', ['text' => 'hello']];

      expect((new Renderer)($html, ['Foo' => ['PhpxTestStaticComponent', 'renderWithProps']]))->toBe('<b>hello</b>');
    });

    it('calls a 0-arg instance method component', function () {
      $html = ['$', 'Foo', null];
      $obj = new PhpxTestInstanceComponent();

      expect((new Renderer)($html, ['Foo' => [$obj, 'render']]))->toBe('<i>instance zero</i>');
    });

    it('calls a 1-arg instance method component with props', function () {
      $html = ['$', 'Foo', ['text' => 'world']];
      $obj = new PhpxTestInstanceComponent();

      expect((new Renderer)($html, ['Foo' => [$obj, 'renderWithProps']]))->toBe('<i>world</i>');
    });

    it('calls a static method via Class::method string syntax', function () {
      $html = ['$', 'Foo', null];

      expect((new Renderer)($html, ['Foo' => 'PhpxTestStaticComponent::render']))->toBe('<b>static zero</b>');
    });

    it('throws when a non-Closure callable has more than 1 parameter', function () {
      $html = ['$', 'Foo', null];

      expect(fn() => (new Renderer)($html, ['Foo' => 'phpx_test_two_arg_component']))->toThrow(\InvalidArgumentException::class);
    });
  });

  describe('XSS prevention', function () {
    it('escapes HTML special characters in text node strings', function () {
      $html = ['$', 'p', null, '<script>alert("xss")</script>'];

      expect((new Renderer)($html))->toBe('<p>&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;</p>');
    });

    it('escapes entities in bare string nodes', function () {
      expect((new Renderer)('<b>bold</b> & "quoted"'))->toBe('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;');
    });

    it('escapes HTML tags injected into attribute values', function () {
      $html = ['$', 'div', ['title' => '<img src=x onerror=alert(1)>']];

      expect((new Renderer)($html))->toBe('<div title="&lt;img src=x onerror=alert(1)&gt;"></div>');
    });

    it('escapes double-quote injection in attribute values to prevent attribute break-out', function () {
      $html = ['$', 'div', ['class' => '" onmouseover="alert(1)']];

      expect((new Renderer)($html))->toBe('<div class="&quot; onmouseover=&quot;alert(1)"></div>');
    });

    it('escapes ampersands in attribute values', function () {
      $html = ['$', 'a', ['href' => '/search?q=foo&bar=baz']];

      expect((new Renderer)($html))->toBe('<a href="/search?q=foo&amp;bar=baz"></a>');
    });

    it('escapes single-quote injection in attribute values', function () {
      $html = ['$', 'div', ['data-value' => "' onmouseover='alert(1)"]];

      expect((new Renderer)($html))->toBe('<div data-value="&apos; onmouseover=&apos;alert(1)"></div>');
    });

    it('escapes double-quote injection in style attribute values', function () {
      $html = ['$', 'div', ['style' => (object)['background' => 'red"} *{color:red}']]];

      expect((new Renderer)($html))->toBe('<div style="background:red&quot;} *{color:red}"></div>');
    });

    it('escapes double-quote injection in data-* attribute values', function () {
      $html = ['$', 'div', ['data' => (object)['value' => '" onxss="1']]];

      expect((new Renderer)($html))->toBe('<div data-value="&quot; onxss=&quot;1"></div>');
    });

    it('escapes ampersands in data-* attribute values', function () {
      $html = ['$', 'div', ['data' => (object)['query' => 'foo&bar']]];

      expect((new Renderer)($html))->toBe('<div data-query="foo&amp;bar"></div>');
    });

    it('escapes HTML special chars in text node array fragments', function () {
      $html = ['$', 'p', null, ['<b>bold</b>', ' & ', '"quoted"']];

      expect((new Renderer)($html))->toBe('<p>&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;</p>');
    });

    it('does not escape dangerouslySetInnerHTML content', function () {
      $html = ['$', 'div', ['dangerouslySetInnerHTML' => ['__html' => '<b>intentional &amp; safe</b>']]];

      expect((new Renderer)($html))->toBe('<div><b>intentional &amp; safe</b></div>');
    });

    it('escapes HTML special chars in array-valued attributes (e.g. class arrays)', function () {
      $html = ['$', 'div', ['class' => ['container', '" onxss="1']]];

      expect((new Renderer)($html))->toBe('<div class="container &quot; onxss=&quot;1"></div>');
    });

    it('escapes script injection passed through a named component prop', function () {
      $html = ['$', 'Wrapper', ['label' => '<script>alert(1)</script>']];

      expect((new Renderer)($html, [
        'Wrapper' => fn(array $props) => ['$', 'div', ['title' => $props['label']], null],
      ]))->toBe('<div title="&lt;script&gt;alert(1)&lt;/script&gt;"></div>');
    });

    it('throws InvalidArgumentException for an attribute name containing whitespace', function () {
      $html = ['$', 'div', ['bad name' => 'value']];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for an attribute name containing quotes', function () {
      $html = ['$', 'div', ['"evil"' => 'value']];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for an invalid data attribute key', function () {
      $html = ['$', 'div', ['data' => ['"injected' => 'value']]];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for an invalid data key containing whitespace', function () {
      $html = ['$', 'div', ['data' => ['bad key' => 'value']]];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('renders onclick attribute with escaped value', function () {
      $html = ['$', 'div', ['onclick' => 'doSomething()']];

      expect((new Renderer)($html))->toBe('<div onclick="doSomething()"></div>');
    });

    it('escapes double-quote injection in onclick attribute value', function () {
      $html = ['$', 'div', ['onclick' => '"injected"']];

      expect((new Renderer)($html))->toBe('<div onclick="&quot;injected&quot;"></div>');
    });

    it('should never convert camelCase onClick to kebab-case on-click', function () {
      $html = ['$', 'div', ['onClick' => 'go()']];

      expect((new Renderer)($html))->toBe('<div onclick="go()"></div>');
    });

    it('formats DateTime as datetime-local value (no timezone offset)', function () {
      $html = ['$', 'input', ['type' => 'datetime-local', 'value' => new \DateTime('2026-03-22 14:30:00')]];

      expect((new Renderer)($html))->toBe('<input type="datetime-local" value="2026-03-22T14:30:00" />');
    });

    it('formats DateTimeImmutable as datetime-local value (no timezone offset)', function () {
      $html = ['$', 'input', ['type' => 'datetime-local', 'value' => new \DateTimeImmutable('2026-03-22 09:05:07')]];

      expect((new Renderer)($html))->toBe('<input type="datetime-local" value="2026-03-22T09:05:07" />');
    });
  });

  describe('fragment child validation', function () {
    it('throws InvalidArgumentException for an empty array child inside a fragment', function () {
      $html = ['$', 'div', null, [
        [],
      ]];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for an associative array child (no index 0) inside a fragment', function () {
      $html = ['$', 'div', null, [
        ['key' => 'value'],
      ]];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });
  });

  describe('tag name validation', function () {
    it('throws InvalidArgumentException for a tag name containing whitespace', function () {
      $html = ['$', "div onmouseover=alert(1) x", null];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for a tag name containing angle brackets', function () {
      $html = ['$', "div><script>alert(1)</script><div", null];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for a tag name containing a quote', function () {
      $html = ['$', 'div"evil', null];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for a tag name containing a slash', function () {
      $html = ['$', 'div/script', null];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('accepts valid custom element names with hyphens', function () {
      $html = ['$', 'my-element', null, 'content'];

      expect((new Renderer)($html))->toBe('<my-element>content</my-element>');
    });
  });

  describe('props validation', function () {
    it('throws InvalidArgumentException when props is a string', function () {
      $html = ['$', 'div', 'not-an-array'];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException when props is an integer', function () {
      $html = ['$', 'div', 42];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('treats null props the same as an empty array', function () {
      $html = ['$', 'div', null, 'hello'];

      expect((new Renderer)($html))->toBe('<div>hello</div>');
    });
  });

  describe('node type validation', function () {
    it('throws InvalidArgumentException for a node missing the type at index 1', function () {
      $html = ['$'];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for a non-string non-Closure node type', function () {
      $html = ['$', 42];

      expect(fn() => (new Renderer)($html))->toThrow(\InvalidArgumentException::class);
    });
  });
});
