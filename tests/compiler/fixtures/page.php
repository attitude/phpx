<?php declare(strict_types = 1);

function PageFooter(array $props): array {
  $year = $props['year'] ?? date('Y');

  return (
    ['$', 'footer', null, [
      ['$', 'p', null, ['© ', ($year), ' @martin_adamko']],
    ]]
  );
}

function PageMain(array $items): array {
  ['items' => $items, 'content' => $content] = $items ?? ['items' => [], 'content' => null ];

  return (
    ['$', 'main', null, [
      ['$', 'ul', null, [
        (array_map(fn($item) => (
          ['$', 'li', null, [($item)]]
        ), $items)),
      ]],
      ($content),
    ]]
  );
}

function PageHeader(array $props): array {
  $title = $props['title'] ?? 'Untitled';
  $tags = $props['tags'] ?? [];

  return (
    ['$', 'header', null, [
      ['$', 'a', ['href'=>"/"], ['Home']],
      ['$', 'h1', null, [($title)]],
      ['$', 'ul', null, [
        (array_map(fn($tag) => (
          ['$', 'li', null, [($tag)]]
        ), $tags)),
      ]],
    ]]
  );
}

function Page(): array {
  $title = 'Hello World';
  $description = 'This is a test page';
  $css = '/styles.css';

  return (
    ['$', 'html', ['lang'=>"en"], [
      ['$', 'head', null, [
        ['$', 'meta', ['charset'=>"UTF-8"]],
        ['$', 'meta', ['name'=>"viewport", 'content'=>"width=device-width, initial-scale=1.0"]],
        ['$', 'title', null, [($title ?? 'None'), ' by Attitude']],
        ['$', 'meta', ['name'=>"description", 'content'=>($description)]],
        ['$', 'link', ['rel'=>"stylesheet", 'href'=>($css)]],
      ]],
      ['$', 'body', null, [
        (PageHeader(['title' => $title, 'tags' => ['test', 'example']])),
        (PageMain(['items' => ['Item 1', 'Item 2', 'Item 3']])),
        (PageFooter(['year' => 2021])),
      ]],
    ]]
  );
}

return Page();
