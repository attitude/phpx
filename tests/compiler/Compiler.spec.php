<?php declare(strict_types = 1);

namespace Attitude\PHPX;
use Attitude\PHPX\Compiler\Compiler;
use Attitude\PHPX\Parser\Parser;

require_once __DIR__.'/../../src/index.php';

function newParser(bool $withLogger = false): Parser {
  $parser = new Parser();

  if ($withLogger) {
    $parser->logger = new Logger();
  }

  return $parser;
}

function newCompiler(Parser $parser = null, bool $withLogger = false): Compiler {
  $compiler = new Compiler(parser: $parser);

  if ($withLogger) {
    $compiler->logger = new Logger();
  }

  return $compiler;
}


describe('compile', function () {
  it('compiles valid PHP code', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<?php echo "Hello, World!";');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe('<?php echo "Hello, World!";');
  });

  it('compiles a simple string template', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<>Hello, {$name ?? \'unnamed\'}!</>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
<<<'PHP'
['Hello, ', ($name ?? 'unnamed'), '!']
PHP
    );
  });

  it('compiles a template literal', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(
      '`Hello, my name is ${$name ?? \'yet to be defined\'}, and I come from ${$country ?? \'Earth\'}!`'
    );
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "'Hello, my name is '.(\$name ?? 'yet to be defined').', and I come from '.(\$country ?? 'Earth').'!'"
    );
  });

  it('compiles a template literal with a function call', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('`Hello, ${ucfirst($name ?? \'unnamed\')}!`');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "'Hello, '.(ucfirst(\$name ?? 'unnamed')).'!'"
    );
  });

  it('compiles a template literal inside of element', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<p>{`Hello, my name is ${$name ?? \'yet to be defined\'}, and I come from ${$country ?? \'Earth\'}!`}</p>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['$', 'p', null, ['Hello, my name is '.(\$name ?? 'yet to be defined').', and I come from '.(\$country ?? 'Earth').'!']]"
    );
  });

  it('compile an element with a less than condition', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<>{$count < 0 ? <p>Hello, {$name}!</p> : null}</>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "[(\$count < 0 ? ['$', 'p', null, ['Hello, ', (\$name), '!']] : null)]"
    );
  });

  it('compiles a template with a function call', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<>Hello, {$name ?? ucfirst($type)}!</>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['Hello, ', (\$name ?? ucfirst(\$type)), '!']"
    );
  });

  it('compiles a template with a function call and spread operator', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<>Hello, {$name ?? ucfirst(...$type)}!</>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['Hello, ', (\$name ?? ucfirst(...\$type)), '!']"
    );
  });

  it('compiles a template with arrow function', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<>Hello, {$name ?? fn($it) => ucfirst($it))($type)}!</>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['Hello, ', (\$name ?? fn(\$it) => ucfirst(\$it))(\$type)), '!']"
    );
  });

  it('compiles a template with void element', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<img src={$src} alt="An image of PHPX" />');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['$', 'img', ['src'=>(\$src), 'alt'=>\"An image of PHPX\"]]"
    );
  });

  it('compiles a template with fragment and children', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<><p>{$name} is a {$type}!</p><p>{$phone} is not a {$type}</p></>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "[['$', 'p', null, [(\$name), ' is a ', (\$type), '!']], ['$', 'p', null, [(\$phone), ' is not a ', (\$type)]]]"
    );
  });

  it('compiles a template with empty element and no attributes', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<br />');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['$', 'br']"
    );
  });

  it('compiles a template with short boolean attribute', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<input type="checkbox" checked={$checked} disabled />');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['$', 'input', ['type'=>\"checkbox\", 'checked'=>(\$checked), 'disabled'=>true]]"
    );
  });

  it('compiles a template with kebab attribute name', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<div data-foo-bar={$data instanceof \DateTime} />');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['$', 'div', ['data-foo-bar'=>(\$data instanceof \DateTime)]]"
    );
  });

  it('compiles complicated kebab attribute name', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: true));
    $compiler->compile('<li className="meals-item" data-as-table={$meal->priceFormatted instanceof StringList} />');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['$', 'li', ['className'=>\"meals-item\", 'data-as-table'=>(\$meal->priceFormatted instanceof StringList)]]"
    );
  });

  it('compiles true dataAttribute', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<div data-foo></div>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['$', 'div', ['data-foo'=>true]]"
    );
  });

  it('compiles a template with void element and spread operator', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<img {$loading} src="about:blank" {...$props} alt=\'Never overridden alt\' />');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['$', 'img', ['loading'=>\$loading, 'src'=>\"about:blank\", ...\$props, 'alt'=>'Never overridden alt']]"
    );
  });

  it('compiles a template with element', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(
<<<'PHPX'
  <h1 className="title">Hello, {$name ?? ucfirst($type)}!</h1>
PHPX
    );
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
<<<'PHP'
  ['$', 'h1', ['className'=>"title"], ['Hello, ', ($name ?? ucfirst($type)), '!']]
PHP
    );
  });

  it('compiles a template with nested elements', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(
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

    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
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
  });

  it('compiles a html page template', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(file_get_contents(__DIR__.'/fixtures/html-page-template.phpx'));
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toMatchSnapshot();
  });

  it('compiles a mixed text, expression and tag', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(<<<'HTML'
<p> ©{$year} <a href="https://threads.com/@martin_adamko">@martin_adamko</a> </p>
HTML
    );
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
<<<'PHP'
['$', 'p', null, ['©', ($year), ' ', ['$', 'a', ['href'=>"https://threads.com/@martin_adamko"], ['@martin_adamko']]]]
PHP
    );
  });

  it('compiles a PHPX script to render Page layout', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(file_get_contents(__DIR__.'/fixtures/page.phpx'));
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toMatchSnapshot();
  });

  it('should ignore null children and null atribudes', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<div></div>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['$', 'div']"
    );
  });

  it('compiles `#` in the PHPXText', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<>Your # is {$number ?? \'not available\'}!</>');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['Your # is ', (\$number ?? 'not available'), '!']"
    );
  });

  it('compiles with `//` in the PHPXText', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(
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
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
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
  });

  it('compiles with `/* */` comment in the PHPXText', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(
<<<'PHPX'
<>
  {/* regular PHPX comment */}
  Lorem ipsum dolor sit amet, consectetur adipiscing elit.
</>
PHPX
    );
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
<<<'PHP'
[
  /* regular PHPX comment */
  'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
]
PHP
    );
  });

  it('compiles escaped \\\' (apostrophe) in the PHPXText', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(
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
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
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
  });

  it('compiles namespaced attributes', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(
<<<'PHPX'
<p xmlns:cc="http://creativecommons.org/ns#" >This work is licensed under <a href="http://creativecommons.org/licenses/by-nc/4.0/?ref=chooser-v1" target="_blank" rel="license noopener noreferrer" style="display:inline-block;">CC BY-NC 4.0<img style="height:22px!important;margin-left:3px;vertical-align:text-bottom;" src="https://mirrors.creativecommons.org/presskit/icons/cc.svg?ref=chooser-v1" /><img style="height:22px!important;margin-left:3px;vertical-align:text-bottom;" src="https://mirrors.creativecommons.org/presskit/icons/by.svg?ref=chooser-v1" /><img style="height:22px!important;margin-left:3px;vertical-align:text-bottom;" src="https://mirrors.creativecommons.org/presskit/icons/nc.svg?ref=chooser-v1" /></a></p>
PHPX
    );
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toMatchSnapshot();
  });

  it('compiles element with attributes on multiple lines', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(
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
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
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
  });
});
