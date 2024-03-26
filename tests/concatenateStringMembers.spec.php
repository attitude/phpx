<?php

require_once __DIR__.'/../src/concatenateStringMembers.php';

describe('concatenateStringMembers', function() {
  it('should combine string array members', function() {
    $array = ['Hello', ' ', 'World', '!', ['span', null, ' '], 'How', ' ', 'are', ' ', 'you', '?'];
    $expected = ['Hello World!', ['span', null, ' '], 'How are you?'];
    $actual = concatenateStringMembers($array);
    expect($actual)->toBe($expected);
  });
});
