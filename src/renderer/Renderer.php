<?php

namespace Attitude\PHPX\Renderer;

final class Renderer {
  public bool $void = false;
  public bool $pretty = false;
  public bool $react = false;
  public string $indentation = "\t";
  private array $components = [];
  private \WeakMap $arityCache;

  public function __construct(private string $encoding = 'UTF-8') {
    $this->arityCache = new \WeakMap();
  }

  public function __invoke(bool|int|float|string|array|null $node, array $components = []): string {
    return $this->render($node, $components);
  }

  private function getNodeType(array $node): string|\Closure {
    if (!array_key_exists(0, $node) || $node[0] !== '$') {
      throw new \InvalidArgumentException("Serialized node requires first element to be '$', got `" . (array_key_exists(0, $node) ? $node[0] : 'undefined') . "` instead.");
    }
    if (!array_key_exists(1, $node)) {
      throw new \InvalidArgumentException("Serialized node is missing the type at index 1.");
    }
    if (!is_string($node[1]) && !($node[1] instanceof \Closure)) {
      throw new \InvalidArgumentException("Serialized node type must be a string or Closure, got `" . gettype($node[1]) . "` instead.");
    }
    return $node[1];
  }

  private function getNodeProps(array $node): array {
    if (!array_key_exists(2, $node) || $node[2] === null) {
      return [];
    }
    if (!is_array($node[2])) {
      throw new \InvalidArgumentException("Serialized node props (index 2) must be an array or null, got `" . gettype($node[2]) . "` instead.");
    }
    return $node[2];
  }

  public function render(bool|int|float|string|array|null $node, array $components = []): string {
    $this->components = $components;
    $html = $this->renderNode($node, 0);
    $this->components = [];

    return $html;
  }

  private function callComponent(callable $component, array $props): mixed {
    if ($component instanceof \Closure) {
      $arity = $this->arityCache[$component] ??= (new \ReflectionFunction($component))->getNumberOfParameters();
    } else {
      $arity = (new \ReflectionFunction(\Closure::fromCallable($component)))->getNumberOfParameters();
    }
    if ($arity > 1) {
      throw new \InvalidArgumentException("Component must accept 0 or 1 parameter (\$props). Got {$arity} parameters. Pass children via \$props['children'] instead.");
    }
    return $arity === 0 ? $component() : $component($props);
  }

  private function resolveDangerouslySetInnerHTML(array $props): string {
    if (array_key_exists('children', $props)) {
      throw new \InvalidArgumentException("Can't use children and dangerouslySetInnerHTML at the same time");
    }
    $raw = $props['dangerouslySetInnerHTML'];
    if (!is_array($raw) || !array_key_exists('__html', $raw) || !is_string($raw['__html'])) {
      throw new \InvalidArgumentException("dangerouslySetInnerHTML must be an array with an '__html' key containing a string.");
    }
    return (string) $raw['__html'];
  }

  private function format(string $rendered, int $nesting): string {
    return str_repeat($this->indentation, max(0, $nesting)).$rendered;
  }

  private function escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $this->encoding);
  }

  private function formatAttribute(string $key, mixed $value): string {
    return "$key=\"" . $this->escape($value) . "\"";
  }

  private function toKebabCase(string $value): string {
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $value));
  }

  private function renderNode(bool|int|float|string|array|null $node, int $nesting): string {
    if (is_string($node) || is_numeric($node)) {
      return $this->escape($node);
    }
    if (is_bool($node) || is_null($node)) {
      return '';
    }
    if (empty($node)) {
      return '';
    }
    if (($node[0] ?? null) !== '$') {
      return $this->renderFragment($node, $nesting);
    }

    $type = $this->getNodeType($node);
    $props = $this->getNodeProps($node);
    if (array_key_exists(3, $node)) {
      $props['children'] = $node[3];
    }

    if ($type instanceof \Closure || array_key_exists($type, $this->components)) {
      $component = $type instanceof \Closure ? $type : $this->components[$type];
      return $this->renderNode($this->callComponent($component, $props), $nesting);
    }

    if ($type === 'Fragment') {
      if (array_key_exists('dangerouslySetInnerHTML', $props)) {
        return $this->resolveDangerouslySetInnerHTML($props);
      }
      return $this->renderNode($props['children'] ?? [], $nesting);
    }

    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-\.]*$/', $type)) {
      throw new \InvalidArgumentException("Invalid HTML tag name: `{$type}`");
    }

    if (array_key_exists('dangerouslySetInnerHTML', $props)) {
      $children = $this->resolveDangerouslySetInnerHTML($props);
      $escapeChildren = false;
      unset($props['dangerouslySetInnerHTML']);
    } else {
      $children = $props['children'] ?? [];
      $escapeChildren = true;
    }

    unset($props['children']);

    $attrs = $this->renderAttributes($props);

    if (is_string($children)) {
      $childrenHtml = $escapeChildren
        ? $this->escape($children)
        : $children;
    } else {
      $childrenHtml = $this->renderNode($children, $nesting + 1);
      if ($this->pretty && (strstr($childrenHtml, "\n") || strstr($childrenHtml, "<"))) {
        $childrenHtml = "\n" . $this->format($childrenHtml, $nesting + 1) . "\n" . $this->format('', $nesting);
      }
    }

    $open = "<$type" . ($attrs !== '' ? " $attrs" : '');
    if (in_array($type, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'])) {
      return $open . ($this->void ? '>' : ' />');
    }
    return "$open>$childrenHtml</$type>";
  }

  /**
   * Recursively normalizes attribute/prop-like values into a flat associative array of key/value pairs.
   * Accepts stdClass, associative arrays, or indexed arrays of those (nested arbitrarily), as used for
   * style, class, data-/aria- attributes and other namespaced attributes. When processing indexed arrays,
   * null, false and empty string items are skipped.
   */
  private function resolveProps(mixed $value): array {
    if ($value === null || $value === false || $value === '') {
      return [];
    }
    if ($value instanceof \stdClass) {
      $value = (array) $value;
    }
    if (!is_array($value)) {
      return [];
    }
    if (!empty($value) && is_string(array_key_first($value))) {
      return $value;
    }

    $result = [];
    foreach ($value as $item) {
      foreach ($this->resolveProps($item) as $k => $v) {
        $result[$k] = $v;
      }
    }
    return $result;
  }

  private function renderAttributes(array $props): string {
    $parts = [];

    foreach ($props as $key => $value) {
      $key = match ($key) {
        'className' => 'class',
        'htmlFor' => 'for',
        default => strtolower($key),
      };

      if (!preg_match('/^[a-z][a-z0-9\-:._]*$/', $key)) {
        throw new \InvalidArgumentException("Invalid attribute name: `{$key}`");
      }

      if ($value === null) {
        continue;
      }

      if (is_bool($value)) {
        if (str_contains($key, '-')) {
          $parts[] = $this->formatAttribute($key, $value ? 'true' : 'false');
        } elseif ($value) {
          $parts[] = $key;
        }
        continue;
      }

      if ($value instanceof \DateTimeInterface) {
        $parts[] = $this->formatAttribute($key, $value->format('Y-m-d\TH:i:s'));
        continue;
      }

      if ($value instanceof \Stringable) {
        $parts[] = $this->formatAttribute($key, $value);
        continue;
      }

      if (is_array($value) || $value instanceof \stdClass) {
        $resolved = $this->resolveProps($value);

        if ($key === 'style') {
          $css = '';
          foreach ($resolved as $prop => $sv) {
            if ($sv === null || $sv === false)
              continue;
            if ($sv === true)
              throw new \InvalidArgumentException("Invalid style value for `{$prop}`: boolean true is not allowed.");
            if ($sv === '')
              throw new \InvalidArgumentException("Invalid style value for `{$prop}`: empty string is not allowed.");
            $css .= $this->toKebabCase((string) $prop) . ":$sv;";
          }
          if ($css !== '')
            $parts[] = $this->formatAttribute('style', rtrim($css, ';'));
          continue;
        }

        if ($key === 'class' && (!empty($resolved) || $value instanceof \stdClass || (is_array($value) && !array_is_list($value)))) {
          $classes = array_keys(array_filter($resolved));
          if (!empty($classes))
            $parts[] = $this->formatAttribute('class', implode(' ', $classes));
          continue;
        }

        if (!empty($resolved) && preg_match('/^[a-z][a-z0-9]*$/', $key)) {
          $count = count($parts);
          foreach ($resolved as $subKey => $subValue) {
            $subKey = strtolower((string) $subKey);
            if (!preg_match('/^[a-z][a-z0-9\-:._]*$/', $subKey)) {
              throw new \InvalidArgumentException("Invalid `{$key}-*` attribute key: `{$subKey}`");
            }
            if ($subValue === null)
              continue;
            if (is_bool($subValue))
              $subValue = $subValue ? 'true' : 'false';
            $parts[] = $this->formatAttribute("$key-$subKey", $subValue);
          }
          if (count($parts) > $count)
            continue;
        }

        if (is_array($value)) {
          $flat = [];
          array_walk_recursive($value, function ($item) use (&$flat) {
            if ($item !== null && $item !== false && $item !== '')
              $flat[] = $item;
          });
          if (!empty($flat))
            $parts[] = $this->formatAttribute($key, implode(' ', $flat));
        }
        continue;
      }

      $parts[] = $this->formatAttribute($key, $value);
    }

    return implode(' ', $parts);
  }

  private function renderFragment(array $node, int $nesting): string {
    $parts = [];
    $previousWasElement = false;

    foreach ($this->concatenateStringMembers($node) as $child) {
      if (is_array($child)) {
        $rendered = $this->renderNode($child, $nesting);
        if ($this->pretty && $previousWasElement) {
          $rendered = "\n" . $this->format($rendered, $nesting);
        }
        $parts[] = $rendered;
        $previousWasElement = true;
      } else if (is_string($child)) {
        $parts[] = $child;
        $previousWasElement = false;
      }
    }

    return implode('', $parts);
  }

  private function concatenateStringMembers(array $array): array {
    $combinedArray = [];
    $currentString = '';
    $length = count($array);

    foreach ($array as $index => $item) {
      if (is_string($item) || is_numeric($item)) {
        $escapedValue = $this->escape($item);
        if ($this->react) {
          $stringifiedValue = (string) $item;
          $prefix = $index !== 0 && ltrim($stringifiedValue) !== $stringifiedValue ? '<!-- -->' : '';
          $suffix = $index !== $length - 1 && rtrim($stringifiedValue) !== $stringifiedValue ? '<!-- -->' : '';
          $currentString .= $prefix . $escapedValue . $suffix;
        } else {
          $currentString .= $escapedValue;
        }
      } else if (is_array($item)) {
        if ($currentString !== '') {
          $combinedArray[] = $currentString;
          $currentString = '';
        }
        if (!array_key_exists(0, $item)) {
          throw new \InvalidArgumentException("Fragment child must be a serialized node array (starting with '\$') or a nested children array, got an associative or empty array instead.");
        }
        if ($item[0] === '$') {
          $combinedArray[] = $item;
        } else {
          $combinedArray = [...$combinedArray, ...$this->concatenateStringMembers($item)];
        }
      }
    }

    if ($currentString !== '') {
      $combinedArray[] = $currentString;
    }

    return $combinedArray;
  }
}
