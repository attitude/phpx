<?php declare(strict_types = 1);

return function(array $props): array {
  $count = 0;

  return (
		[
			['$', 'h1', null, [
				($props['children']), ' ', ($props['name']),
			]],
			['$', 'span', null, [
				'Count: ', ($count),
				['$', 'button', null, ['Increase']],
			]],
		]
	);
};
