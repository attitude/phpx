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
  });;

  it('compiles a literal template', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile(
      '`Hello, my name is {$name ?? \'yet to be defined\'}, and I come from {$country ?? \'Earth\'}!`'
    );
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "'Hello, my name is '.(\$name ?? 'yet to be defined').', and I come from '.(\$country ?? 'Earth').'!'"
    );
  });;

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
      "['img', ['src'=>(\$src), 'alt'=>\"An image of PHPX\"]]"
    );
  });

  it('compiles a template with short boolean attribute', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<input type="checkbox" checked={$checked} disabled />');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['input', ['type'=>\"checkbox\", 'checked'=>(\$checked), 'disabled'=>true]]"
    );
  });

  it('compiles a template with data-non-printable', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: true));
    $compiler->compile('<div data-non-printable={$data} />');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['div', ['data-non-printable'=>(\$data)]]"
    );
  });

  it('compiles a template with void element and spread operator', function () {
    $compiler = newCompiler(withLogger: false, parser: newParser(withLogger: false));
    $compiler->compile('<img {$loading} src="about:blank" {...$props} alt=\'Never overridden alt\' />');
    expect($compiler->getAST())->toMatchSnapshot();
    expect($compiler->getCompiled())->toBe(
      "['img', ['loading'=>\$loading, 'src'=>\"about:blank\", ...\$props, 'alt'=>'Never overridden alt']]"
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
  ['h1', ['className'=>"title"], ['Hello, ', ($name ?? ucfirst($type)), '!']]
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
  ['h1', ['className'=>"title"], ['Hello, ', ($name ?? ucfirst($type)), '!']],
  ['p', null, [
    'Welcome to the world of PHPX, where you can write PHP code in a JSX-like syntax.',
    ['img', ['src'=>"about:blank", 'alt'=>"Happy coding!"]], ' forever!',
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
      "['div']"
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
  ['p', null, [
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
});
