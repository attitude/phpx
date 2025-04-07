<?php declare(strict_types = 1);

namespace Attitude\PHPX;
use Attitude\PHPX\Compiler\Compiler;
use Attitude\PHPX\Compiler\FormatterInterface;
use Attitude\PHPX\Compiler\PragmaFormatter;
use Attitude\PHPX\Parser\Parser;

require_once __DIR__.'/../../src/index.php';

function newParser(bool $withLogger = false): Parser {
  $parser = new Parser();

  if ($withLogger) {
    $parser->logger = new Logger();
  }

  return $parser;
}

function newPragmaFormatter(): PragmaFormatter {
  return new PragmaFormatter();
}

function newCompiler(?Parser $parser = null, ?FormatterInterface $formatter = null, bool $withLogger = false): Compiler {
  $compiler = new Compiler(parser: $parser, formatter: $formatter);

  if ($withLogger) {
    $compiler->logger = new Logger();
  }

  return $compiler;
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
      "['$', 'img', ['src'=>(\$src), 'alt'=>\"An image of PHPX\"]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<img src={$src} alt="An image of PHPX" />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('img', ['src'=>(\$src), 'alt'=>\"An image of PHPX\"])"
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
      "['$', 'input', ['type'=>\"checkbox\", 'checked'=>(\$checked), 'disabled'=>true]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<input type="checkbox" checked={$checked} disabled />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('input', ['type'=>\"checkbox\", 'checked'=>(\$checked), 'disabled'=>true])"
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
      "['$', 'li', ['className'=>\"meals-item\", 'data-as-table'=>(\$meal->priceFormatted instanceof StringList)]]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<li className="meals-item" data-as-table={$meal->priceFormatted instanceof StringList} />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('li', ['className'=>\"meals-item\", 'data-as-table'=>(\$meal->priceFormatted instanceof StringList)])"
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
      "['$', 'img', ['loading'=>\$loading, 'src'=>\"about:blank\", ...\$props, 'alt'=>'Never overridden alt']]"
    );

    $pragmaCompiler = newCompiler(
      withLogger: false,
      parser: newParser(withLogger: false),
      formatter: newPragmaFormatter(),
    );
    $pragmaCompiler->compile('<img {$loading} src="about:blank" {...$props} alt=\'Never overridden alt\' />');
    expect($pragmaCompiler->getAST())->toMatchSnapshot();
    expect($pragmaCompiler->getCompiled())->toBe(
      "html('img', ['loading'=>\$loading, 'src'=>\"about:blank\", ...\$props, 'alt'=>'Never overridden alt'])"
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
  ['$', 'h1', ['className'=>"title"], ['Hello, ', ($name ?? ucfirst($type)), '!']]
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
  html('h1', ['className'=>"title"], ['Hello, ', ($name ?? ucfirst($type)), '!'])
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
  ['$', 'h1', ['className'=>"title"], ['Hello, ', ($name ?? ucfirst($type)), '!']],
  ['$', 'p', null, [
    'Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.',
    ['$', 'img', ['src'=>"about:blank", 'alt'=>"Happy coding!"]], ' forever!',
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
  html('h1', ['className'=>"title"], ['Hello, ', ($name ?? ucfirst($type)), '!']),
  html('p', null, [
    'Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.',
    html('img', ['src'=>"about:blank", 'alt'=>"Happy coding!"]), ' forever!',
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
['$', 'p', null, ['©', ($year), ' ', ['$', 'a', ['href'=>"https://threads.com/@martin_adamko"], ['@martin_adamko']]]]
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
html('p', null, ['©', ($year), ' ', html('a', ['href'=>"https://threads.com/@martin_adamko"], ['@martin_adamko'])])
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
    These\'re quotes in a string and that\'s okay too!',
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
    These\'re quotes in a string and that\'s okay too!',
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
    'className'=>"text-center",
    'style'=>"color: red;",
    'data-foo'=>"bar",
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
    'className'=>"text-center",
    'style'=>"color: red;",
    'data-foo'=>"bar",
  ], [
    'Hello, ', ($name ?? 'unnamed'), '!',
  ]),
])
PHP
    );
  });

  it('throws an error when a using PHP opening tags', function () {
    $defaultCompiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    expect(fn() => $defaultCompiler->compile('<title>[<?=$todayFormatted?>]</title>'))->toThrow(\ParseError::class, 'Unexpected PHP opening tag on line 1');
  });
});
