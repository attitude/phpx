['$', 'html', ['lang'=>"en"], [
	['$', 'head', null, [
		['$', 'meta', ['charset'=>"UTF-8"]],
		['$', 'meta', ['name'=>"viewport", 'content'=>"width=device-width, initial-scale=1.0"]],
		['$', 'title', null, [($title ?? 'None'), ' by Attitude']],
		['$', 'meta', ['name'=>"description", 'content'=>($description)]],
		['$', 'link', ['rel'=>"stylesheet", 'href'=>($css)]],
	]],
	['$', 'body', null, [
		['$', 'header', null, [
			['$', 'a', ['href'=>"/"], ['Home']],
			['$', 'h1', null, [($title)]],
			['$', 'ul', null, [($tagsHTML)]],
		]],
		['$', 'main', null, [
			['$', 'ul', null, [
				($items->map(fn($item) => (
					['$', 'li', null, [($item)]]
				))),
			]],
			($content),
		]],
		['$', 'footer', null, [
			['$', 'p', null, ['©', ($year), ' ', ['$', 'a', ['href'=>"https://threads.com/@martin_adamko"], ['@martin_adamko']]]],
		]],
	]],
]]
