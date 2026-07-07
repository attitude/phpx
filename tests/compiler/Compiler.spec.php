<?php declare(strict_types = 1);

namespace Attitude\PHPX;
use Attitude\PHPX\Compiler\Compiler;
use Attitude\PHPX\Compiler\FormatterInterface;
use Attitude\PHPX\Compiler\PragmaFormatter;
use Attitude\PHPX\Parser\Parser;
use Attitude\PHPX\Parser\TokensList;

require_once __DIR__.'/../../src/index.php';

function newParser(bool $withLogger = false): Parser {
  return new Parser(logger: $withLogger ? new Logger() : null);
}

function newPragmaFormatter(): PragmaFormatter {
  return new PragmaFormatter();
}

function newCompiler(?Parser $parser = null, ?FormatterInterface $formatter = null, bool $withLogger = false): Compiler {
  return new Compiler(parser: $parser, formatter: $formatter, logger: $withLogger ? new Logger() : null);
}


describe('compile', function () {
  it('compiles valid PHP code', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<?php echo "Hello, World!";');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe('<?php echo "Hello, World!";');

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<?php echo "Hello, World!";');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe('<?php echo "Hello, World!";');
  });

  it('compiles a simple string template', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<>Hello, {$name ?? \'unnamed\'}!</>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
<<<'PHP'
['Hello, ', ($name ?? 'unnamed'), '!']
PHP
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<>Hello, {$name ?? \'unnamed\'}!</>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
<<<'PHP'
fragment(['Hello, ', ($name ?? 'unnamed'), '!'])
PHP
    );
  });

  it('compiles a template literal', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(
      '`Hello, my name is ${$name ?? \'yet to be defined\'}, and I come from ${$country ?? \'Earth\'}!`'
    );
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "'Hello, my name is '.(\$name ?? 'yet to be defined').', and I come from '.(\$country ?? 'Earth').'!'"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(
      '`Hello, my name is ${$name ?? \'yet to be defined\'}, and I come from ${$country ?? \'Earth\'}!`'
    );
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "'Hello, my name is '.(\$name ?? 'yet to be defined').', and I come from '.(\$country ?? 'Earth').'!'"
    );
  });

  it('compiles a template literal with a function call', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('`Hello, ${ucfirst($name ?? \'unnamed\')}!`');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "'Hello, '.(ucfirst(\$name ?? 'unnamed')).'!'"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('`Hello, ${ucfirst($name ?? \'unnamed\')}!`');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "'Hello, '.(ucfirst(\$name ?? 'unnamed')).'!'"
    );
  });

  it('compiles a template literal inside of element', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<p>{`Hello, my name is ${$name ?? \'yet to be defined\'}, and I come from ${$country ?? \'Earth\'}!`}</p>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'p', null, ['Hello, my name is '.(\$name ?? 'yet to be defined').', and I come from '.(\$country ?? 'Earth').'!']]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<p>{`Hello, my name is ${$name ?? \'yet to be defined\'}, and I come from ${$country ?? \'Earth\'}!`}</p>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('p', null, ['Hello, my name is '.(\$name ?? 'yet to be defined').', and I come from '.(\$country ?? 'Earth').'!'])"
    );
  });

  it('compile an element with a less than condition', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<>{$count < 0 ? <p>Hello, {$name}!</p> : null}</>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "[(\$count < 0 ? ['$', 'p', null, ['Hello, ', (\$name), '!']] : null)]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<>{$count < 0 ? <p>Hello, {$name}!</p> : null}</>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "fragment([(\$count < 0 ? html('p', null, ['Hello, ', (\$name), '!']) : null)])"
    );
  });

  it('compiles a template with a function call', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<>Hello, {$name ?? ucfirst($type)}!</>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['Hello, ', (\$name ?? ucfirst(\$type)), '!']"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<>Hello, {$name ?? ucfirst($type)}!</>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "fragment(['Hello, ', (\$name ?? ucfirst(\$type)), '!'])"
    );
  });

  it('compiles a template with a function call and spread operator', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<>Hello, {$name ?? ucfirst(...$type)}!</>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['Hello, ', (\$name ?? ucfirst(...\$type)), '!']"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<>Hello, {$name ?? ucfirst(...$type)}!</>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "fragment(['Hello, ', (\$name ?? ucfirst(...\$type)), '!'])"
    );
  });

  it('compiles a template with arrow function', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<>Hello, {$name ?? fn($it) => ucfirst($it))($type)}!</>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['Hello, ', (\$name ?? fn(\$it) => ucfirst(\$it))(\$type)), '!']"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<>Hello, {$name ?? fn($it) => ucfirst($it))($type)}!</>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "fragment(['Hello, ', (\$name ?? fn(\$it) => ucfirst(\$it))(\$type)), '!'])"
    );
  });

  it('compiles a template with void element', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<img src={$src} alt="An image of PHPX" />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'img', ['src'=>(\$src), 'alt'=>'An image of PHPX']]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<img src={$src} alt="An image of PHPX" />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('img', ['src'=>(\$src), 'alt'=>'An image of PHPX'])"
    );
  });

  it('compiles a template with fragment and children', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<><p>{$name} is a {$type}!</p><p>{$phone} is not a {$type}</p></>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "[['$', 'p', null, [(\$name), ' is a ', (\$type), '!']], ['$', 'p', null, [(\$phone), ' is not a ', (\$type)]]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<><p>{$name} is a {$type}!</p><p>{$phone} is not a {$type}</p></>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "fragment([html('p', null, [(\$name), ' is a ', (\$type), '!']), html('p', null, [(\$phone), ' is not a ', (\$type)])])"
    );
  });

  it('compiles a template with empty element and no attributes', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<br />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'br']"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<br />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('br')"
    );
  });

  it('compiles a template with short boolean attribute', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<input type="checkbox" checked={$checked} disabled />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'input', ['type'=>'checkbox', 'checked'=>(\$checked), 'disabled'=>true]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<input type="checkbox" checked={$checked} disabled />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('input', ['type'=>'checkbox', 'checked'=>(\$checked), 'disabled'=>true])"
    );
  });

  it('compiles a template with kebab attribute name', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<div data-foo-bar={$data instanceof \DateTime} />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'div', ['data-foo-bar'=>(\$data instanceof \DateTime)]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<div data-foo-bar={$data instanceof \DateTime} />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('div', ['data-foo-bar'=>(\$data instanceof \DateTime)])"
    );
  });

  it('compiles complicated kebab attribute name', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<li className="meals-item" data-as-table={$meal->priceFormatted instanceof StringList} />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'li', ['className'=>'meals-item', 'data-as-table'=>(\$meal->priceFormatted instanceof StringList)]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<li className="meals-item" data-as-table={$meal->priceFormatted instanceof StringList} />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('li', ['className'=>'meals-item', 'data-as-table'=>(\$meal->priceFormatted instanceof StringList)])"
    );
  });

  it('compiles true dataAttribute', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<div data-foo></div>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'div', ['data-foo'=>true]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<div data-foo></div>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('div', ['data-foo'=>true])"
    );
  });

  it('compiles a template with void element and spread operator', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<img {$loading} src="about:blank" {...$props} alt=\'Never overridden alt\' />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'img', ['loading'=>\$loading, 'src'=>'about:blank', ...\$props, 'alt'=>'Never overridden alt']]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<img {$loading} src="about:blank" {...$props} alt=\'Never overridden alt\' />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('img', ['loading'=>\$loading, 'src'=>'about:blank', ...\$props, 'alt'=>'Never overridden alt'])"
    );
  });

  it('compiles a template with element', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(
<<<'PHPX'
  <h1 className="title">Hello, {$name ?? ucfirst($type)}!</h1>
PHPX
    );
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
<<<'PHP'
  ['$', 'h1', ['className'=>'title'], ['Hello, ', ($name ?? ucfirst($type)), '!']]
PHP
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(
<<<'PHPX'
  <h1 className="title">Hello, {$name ?? ucfirst($type)}!</h1>
PHPX
    );
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
<<<'PHP'
  html('h1', ['className'=>'title'], ['Hello, ', ($name ?? ucfirst($type)), '!'])
PHP
    );
  });

  it('compiles a template with nested elements', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(
<<<'PHPX'
<>
  <h1 className="title">Hello, {$name ?? ucfirst($type)}!</h1>
  <p>
    Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.
    <img src="about:blank" alt="Happy coding!" /> forever!
  </p>
</>
PHPX
    );

    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
<<<'PHP'
[
  ['$', 'h1', ['className'=>'title'], ['Hello, ', ($name ?? ucfirst($type)), '!']],
  ['$', 'p', null, [
    'Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.',
    ['$', 'img', ['src'=>'about:blank', 'alt'=>'Happy coding!']], ' forever!',
  ]],
]
PHP
    );


    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(
<<<'PHPX'
<>
  <h1 className="title">Hello, {$name ?? ucfirst($type)}!</h1>
  <p>
    Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.
    <img src="about:blank" alt="Happy coding!" /> forever!
  </p>
</>
PHPX
    );

    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
<<<'PHP'
fragment([
  html('h1', ['className'=>'title'], ['Hello, ', ($name ?? ucfirst($type)), '!']),
  html('p', null, [
    'Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.',
    html('img', ['src'=>'about:blank', 'alt'=>'Happy coding!']), ' forever!',
  ]),
])
PHP
    );
  });

  it('compiles a html page template', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(file_get_contents(__DIR__.'/fixtures/html-page-template.phpx'));
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toMatchSnapshot();

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(file_get_contents(__DIR__.'/fixtures/html-page-template.phpx'));
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toMatchSnapshot();
  });

  it('compiles a mixed text, expression and tag', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(<<<'HTML'
<p> ©{$year} <a href="https://threads.com/@martin_adamko">@martin_adamko</a> </p>
HTML
    );
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
<<<'PHP'
['$', 'p', null, ['©', ($year), ' ', ['$', 'a', ['href'=>'https://threads.com/@martin_adamko'], ['@martin_adamko']]]]
PHP
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(<<<'HTML'
<p> ©{$year} <a href="https://threads.com/@martin_adamko">@martin_adamko</a> </p>
HTML
    );
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
<<<'PHP'
html('p', null, ['©', ($year), ' ', html('a', ['href'=>'https://threads.com/@martin_adamko'], ['@martin_adamko'])])
PHP
    );
  });

  it('compiles a PHPX script to render Page layout', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(file_get_contents(__DIR__.'/fixtures/page.phpx'));
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toMatchSnapshot();

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(file_get_contents(__DIR__.'/fixtures/page.phpx'));
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toMatchSnapshot();
  });

  it('compiles the React-18 Title component fixture', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(file_get_contents(__DIR__.'/../renderer/react-18/components/Title.phpx'));
    expect($compiler->getCompiled())->toBe(
      file_get_contents(__DIR__.'/../renderer/react-18/components/Title.php')
    );
  });

  it('produces correct output with a logger attached', function () {
    $compiler = newCompiler(withLogger: true, parser: newParser(withLogger: true));
    ob_start();
    $compiler->compile('<>Hello, {$name ?? \'unnamed\'}!</>');
    ob_end_clean();
    expect($compiler->getCompiled())->toBe("['Hello, ', (\$name ?? 'unnamed'), '!']");
  });

  it('__toString returns the compiled output', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<p>Hello, {$name}!</p>');
    expect((string) $compiler)->toBe($compiler->getCompiled());
  });

  it('getSource returns the original source passed to compile()', function () {
    $source = '<p>Hello, {$name}!</p>';
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile($source);
    expect($compiler->getSource())->toBe($source);
  });

  it('getTokens returns the TokensList for the compiled source', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<p>Hello</p>');
    expect($compiler->getTokens())->toBeInstanceOf(TokensList::class);
    expect((string) $compiler->getTokens())->toBe('<p>Hello</p>');
  });

  it('should ignore null children and null atribudes', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<div></div>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'div']"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<div></div>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('div')"
    );
  });

  it('compiles `#` in the PHPXText', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<>Your # is {$number ?? \'not available\'}!</>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['Your # is ', (\$number ?? 'not available'), '!']"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<>Your # is {$number ?? \'not available\'}!</>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "fragment(['Your # is ', (\$number ?? 'not available'), '!'])"
    );
  });

  it('compiles with `//` in the PHPXText', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(
<<<'PHPX'
<?php
// A normal comment
$message = (
  <p>
    URL address should start with https:// prefix
  </p>
);
PHPX
    );
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
<<<'PHP'
<?php
// A normal comment
$message = (
  ['$', 'p', null, [
    'URL address should start with https:// prefix',
  ]]
);
PHP
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(
<<<'PHPX'
<?php
// A normal comment
$message = (
  <p>
    URL address should start with https:// prefix
  </p>
);
PHPX
    );
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
<<<'PHP'
<?php
// A normal comment
$message = (
  html('p', null, [
    'URL address should start with https:// prefix',
  ])
);
PHP
    );
  });

  it('compiles with `/* */` comment in the PHPXText', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(
<<<'PHPX'
<>
  {/* regular PHPX comment */}
  Lorem ipsum dolor sit amet, consectetur adipiscing elit.
</>
PHPX
    );
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
<<<'PHP'
[
  /* regular PHPX comment */
  'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
]
PHP
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(
<<<'PHPX'
<>
  {/* regular PHPX comment */}
  Lorem ipsum dolor sit amet, consectetur adipiscing elit.
</>
PHPX
    );
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
<<<'PHP'
fragment([
  /* regular PHPX comment */
  'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
])
PHP
    );
  });

  it('compiles escaped \\\' (apostrophe) in the PHPXText', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(
<<<'PHPX'
$a = 'These\'are quotes in a string and that\'s okay.';
$b = (
  <>
    Hello, {$name ?? 'unnamed'}!
    These\'re quotes in a string and that\'s okay too!
  </>
);
PHPX
    );
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
<<<'PHP'
$a = 'These\'are quotes in a string and that\'s okay.';
$b = (
  [
    'Hello, ', ($name ?? 'unnamed'), '!
    These\\\'re quotes in a string and that\\\'s okay too!',
  ]
);
PHP
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(
<<<'PHPX'
$a = 'These\'are quotes in a string and that\'s okay.';
$b = (
  <>
    Hello, {$name ?? 'unnamed'}!
    These\'re quotes in a string and that\'s okay too!
  </>
);
PHPX
    );
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
<<<'PHP'
$a = 'These\'are quotes in a string and that\'s okay.';
$b = (
  fragment([
    'Hello, ', ($name ?? 'unnamed'), '!
    These\\\'re quotes in a string and that\\\'s okay too!',
  ])
);
PHP
    );
  });

  it('compiles namespaced attributes', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(
<<<'PHPX'
<p xmlns:cc="http://creativecommons.org/ns#" >This work is licensed under <a href="http://creativecommons.org/licenses/by-nc/4.0/?ref=chooser-v1" target="_blank" rel="license noopener noreferrer" style="display:inline-block;">CC BY-NC 4.0<img style="height:22px!important;margin-left:3px;vertical-align:text-bottom;" src="https://mirrors.creativecommons.org/presskit/icons/cc.svg?ref=chooser-v1" /><img style="height:22px!important;margin-left:3px;vertical-align:text-bottom;" src="https://mirrors.creativecommons.org/presskit/icons/by.svg?ref=chooser-v1" /><img style="height:22px!important;margin-left:3px;vertical-align:text-bottom;" src="https://mirrors.creativecommons.org/presskit/icons/nc.svg?ref=chooser-v1" /></a></p>
PHPX
    );
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toMatchSnapshot();
  });

  it('compiles element with attributes on multiple lines', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile(
<<<'PHPX'
<div>
  <p
    className="text-center"
    style="color: red;"
    data-foo="bar"
  >
    Hello, {$name ?? 'unnamed'}!
  </p>
</div>
PHPX
    );
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
<<<'PHP'
['$', 'div', null, [
  ['$', 'p', [
    'className'=>'text-center',
    'style'=>'color: red;',
    'data-foo'=>'bar',
  ], [
    'Hello, ', ($name ?? 'unnamed'), '!',
  ]],
]]
PHP
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile(
<<<'PHPX'
<div>
  <p
    className="text-center"
    style="color: red;"
    data-foo="bar"
  >
    Hello, {$name ?? 'unnamed'}!
  </p>
</div>
PHPX
    );
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
<<<'PHP'
html('div', null, [
  html('p', [
    'className'=>'text-center',
    'style'=>'color: red;',
    'data-foo'=>'bar',
  ], [
    'Hello, ', ($name ?? 'unnamed'), '!',
  ]),
])
PHP
    );
  });

  it('compiles a simple uppercase component as a variable reference', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<Button />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', \$Button]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<Button />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html(\$Button)"
    );
  });

  it('compiles an uppercase component with props and children', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<Button type="submit">Submit</Button>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', \$Button, ['type'=>'submit'], ['Submit']]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<Button type="submit">Submit</Button>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html(\$Button, ['type'=>'submit'], ['Submit'])"
    );
  });

  it('compiles mixed uppercase and lowercase elements', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<div><Button onClick={$handleClick}>Click me</Button></div>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'div', null, [['$', \$Button, ['onClick'=>(\$handleClick)], ['Click me']]]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<div><Button onClick={$handleClick}>Click me</Button></div>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('div', null, [html(\$Button, ['onClick'=>(\$handleClick)], ['Click me'])])"
    );
  });

  it('throws an error when a using PHP opening tags', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    expect(fn() => $defaultCompiler->compile('<title>[<?=$todayFormatted?>]</title>'))->toThrow(\ParseError::class, 'Unexpected PHP opening tag on line 1');
  });

  it('compiles a self-closing custom element', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<my-element />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'my-element']"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<my-element />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('my-element')"
    );
  });

  it('compiles a custom element with children', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<web-component>Hello</web-component>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'web-component', null, ['Hello']]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<web-component>Hello</web-component>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('web-component', null, ['Hello'])"
    );
  });

  it('compiles a custom element with attributes', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<my-el data-value="42" />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'my-el', ['data-value'=>'42']]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<my-el data-value="42" />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('my-el', ['data-value'=>'42'])"
    );
  });

  it('compiles a multi-dash custom element', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<x-my-component />');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'x-my-component']"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<x-my-component />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('x-my-component')"
    );
  });

  it('compiles nested custom elements', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $defaultCompiler->compile('<my-list><my-item>First</my-item></my-list>');
    expect($defaultCompiler->getAST())->toMatchSnapshot();
    expect($defaultCompiler->getCompiled())->toBe(
      "['$', 'my-list', null, [['$', 'my-item', null, ['First']]]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<my-list><my-item>First</my-item></my-list>');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('my-list', null, [html('my-item', null, ['First'])])"
    );
  });
});

describe('unexpected end of input', function () {
  it('throws ParseError for unclosed element', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('<div>'))
      ->toThrow(\ParseError::class, "Unexpected end of input, expected closing tag for '<div>'");
  });

  it('throws ParseError for missing closing > in closing tag', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('<div></div'))
      ->toThrow(\ParseError::class, "Unexpected end of input, expected '>' for closing tag '</div>'");
  });

  it('throws ParseError for unclosed template literal', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('`hello'))
      ->toThrow(\ParseError::class, "Unexpected end of input, expected closing template literal");
  });

  it('throws ParseError for unclosed parenthesis', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('(1 + 2'))
      ->toThrow(\ParseError::class, "expected closing ')'");
  });

  it('throws ParseError for unclosed curly bracket', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('{$a'))
      ->toThrow(\ParseError::class, "expected closing '}'");
  });

  it('throws ParseError for unclosed square bracket', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('[$a'))
      ->toThrow(\ParseError::class, "expected closing ']'");
  });

  it('throws ParseError for truncated opening tag with no element name', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('<'))
      ->toThrow(\ParseError::class, "expected element name");
  });

  it('throws ParseError for truncated opening tag missing closing bracket', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('<div'))
      ->toThrow(\ParseError::class, "expected '>' or '/>' for '<div>'");
  });

  it('throws ParseError for truncated attribute without value', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('<div attr'))
      ->toThrow(\ParseError::class, 'expected "=" (attribute assignment)');
  });

  it('throws ParseError for truncated closing tag with no name', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('<div></'))
      ->toThrow(\ParseError::class, "expected closing tag for '<div>'");
  });

  it('throws ParseError for unexpected token in closing tag', function () {
    $compiler = newCompiler(parser: newParser());
    expect(fn() => $compiler->compile('<div></div attr'))
      ->toThrow(\ParseError::class, "expected '>'");
  });
});

/** Compile, `php -l` the emitted PHP, then eval it — proving the output is valid PHP. */
function compileAndEval(string $source, array $scope = []): mixed {
  $out = newCompiler(parser: newParser())->compile($source);

  $tmp = tempnam(sys_get_temp_dir(), 'phpx_lint_');
  expect($tmp)->toBeString();
  file_put_contents($tmp, "<?php \$v = {$out};\n");
  exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $rc);
  unlink($tmp);
  expect($rc)->toBe(0, "emitted PHP failed to lint: {$out}\n" . implode("\n", $lint));

  extract($scope);
  return eval("return {$out};");
}

describe('escaping (output must evaluate to the literal characters written)', function () {
  it('keeps a trailing backslash in text', function () {
    // <p>foo\</p> — the character after "foo" is a single backslash.
    expect(compileAndEval('<p>foo\\</p>'))->toBe(['$', 'p', null, ['foo\\']]);
  });

  it('keeps interior double backslashes in text without collapsing them', function () {
    // C:\\Users\\me — two backslashes before Users and before me.
    expect(compileAndEval('<p>C:\\\\Users\\\\me</p>'))
      ->toBe(['$', 'p', null, ['C:\\\\Users\\\\me']]);
  });

  it('keeps a backslash in text mixed with an expression', function () {
    // path\{$x} — a backslash immediately before the expression container.
    expect(compileAndEval('<p>path\\{$x}</p>', ['x' => 'X']))
      ->toBe(['$', 'p', null, ['path\\', 'X']]);
  });

  it('keeps a trailing backslash in multi-line text', function () {
    $out = compileAndEval("<p>line one\\\nline two</p>");
    expect($out)->toBe(['$', 'p', null, ["line one\\\nline two"]]);
  });

  it('treats a double-quoted attribute value as literal characters', function () {
    // data-x="a\tb" — the value is 4 characters: a, backslash, t, b (not a TAB).
    $out = compileAndEval('<div data-x="a\tb"></div>');
    expect($out[2]['data-x'])->toBe('a\tb');
    expect(strlen($out[2]['data-x']))->toBe(4);
  });

  it('does not collapse a double backslash in an attribute value', function () {
    // alt="C:\\x" — two backslashes.
    expect(compileAndEval('<img alt="C:\\\\x" />')[2]['alt'])->toBe('C:\\\\x');
  });

  it('leaves an octal-looking attribute value untouched', function () {
    // style="--x:\2014" must not become a PHP octal escape / corrupted bytes.
    expect(compileAndEval('<div style="--x:\2014"></div>')[2]['style'])->toBe('--x:\2014');
  });

  it('treats a single-quoted attribute value as literal characters', function () {
    expect(compileAndEval("<div data-x='a\\tb'></div>")[2]['data-x'])->toBe('a\tb');
    expect(compileAndEval("<img alt='C:\\\\x' />")[2]['alt'])->toBe('C:\\\\x');
  });

  it('does not collapse a double backslash in a template literal', function () {
    // `C:\\x` — two backslashes.
    expect(compileAndEval('`C:\\\\x`'))->toBe('C:\\\\x');
  });
});

describe('Compiler production behaviour (assertions disabled)', function () {
  // With zend.assertions=-1 (the production default) an assert() is a no-op, so a
  // NodeVisitor returning a malformed node produced silent, empty output. The
  // shape checks are now explicit throws — prove they fire regardless of the setting.
  it('rejects a malformed node from a visitor with zend.assertions=-1', function () {
    $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
    expect($autoload)->not->toBeFalse();

    $script = '<?php require ' . var_export($autoload, true) . ';'
      . '$breaker = new class extends \\Attitude\\PHPX\\Compiler\\AbstractNodeVisitor {'
      . '  public function leaveNode(array $node): array|int|null {'
      . '    return $node[\'$$type\'] === \\Attitude\\PHPX\\Parser\\NodeType::PHPX_ELEMENT'
      . '      ? [\'$$type\' => \\Attitude\\PHPX\\Parser\\NodeType::PHPX_ELEMENT] : null;'
      . '  }'
      . '};'
      . 'try { (new \\Attitude\\PHPX\\Compiler\\Compiler(visitors: [$breaker]))->compile("<div />"); echo "NO_THROW"; }'
      . ' catch (\\InvalidArgumentException $e) { echo "INVALID_ARGUMENT"; }'
      . ' catch (\\Throwable $e) { echo "OTHER:" . get_class($e); }';

    $tmp = tempnam(sys_get_temp_dir(), 'phpx_prod_');
    expect($tmp)->toBeString();
    file_put_contents($tmp, $script);
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d zend.assertions=-1 ' . escapeshellarg($tmp));
    unlink($tmp);

    expect(trim((string) $output))->toBe('INVALID_ARGUMENT');
  });
});
