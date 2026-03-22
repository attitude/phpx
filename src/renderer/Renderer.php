<?php

namespace Attitude\PHPX\Renderer;

final class Renderer {
  public bool $void = false;
  public bool $pretty = false;
  public bool $react = false;
  public string $indentation = "\t";
  protected array $components = [];
  private \WeakMap $arityCache;

  public function __construct(protected string $encoding = 'UTF-8') {
    $this->arityCache = new \WeakMap();
  }

  public function __invoke(bool|int|float|string|array|null $node, array $components = []): string {
    return $this->render($node, $components);
  }

  public function getNodeType(array $node): string|\Closure {
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

  public function getNodeProps(array $node): array {
    if (!array_key_exists(2, $node) || $node[2] === null) {
      return [];
    }
    if (!is_array($node[2])) {
      throw new \InvalidArgumentException("Serialized node props (index 2) must be an array or null, got `" . gettype($node[2]) . "` instead.");
    }
    return $node[2];
  }

  /**
   * @deprecated Use getNodeChildrenProps instead.
   */
  public function getNodeChildren(array $node): bool|int|float|string|array|null {
    return $node[3] ?? $this->getNodeProps($node)['children'] ?? [];
  }

  public function getNodeChildrenProps(array $node): array {
    if (array_key_exists(3, $node)) {
      return ['children' => $node[3]];
    }
    $nodeProps = $this->getNodeProps($node);
    if (array_key_exists('children', $nodeProps)) {
      return ['children' => $nodeProps['children']];
    }
    return [];
  }

  public function render(bool|int|float|string|array|null $node, array $components = []): string {
    $this->components = $components;
    $html = $this->renderNode($node, 0);
    $this->components = [];

    return $html;
  }

  private function callComponent(\Closure|callable $component, array $props): mixed {
    if ($component instanceof \Closure) {
      $arity = $this->arityCache[$component] ?? ($this->arityCache[$component] = (new \ReflectionFunction($component))->getNumberOfParameters());
      if ($arity > 1) {
        throw new \InvalidArgumentException("Component must accept 0 or 1 parameter (\$props). Got {$arity} parameters. Pass children via \$props['children'] instead.");
      }
      return $arity === 0 ? $component() : $component($props);
    }
    return call_user_func($component, $props);
  }

  protected function format(string $rendered, int $nesting): string {
    return str_repeat($this->indentation, max(0, $nesting)).$rendered;
  }

  protected function renderNode(bool|int|float|string|array|null $node, int $nesting): string {
    if (is_string($node) || is_numeric($node)) {
      return htmlspecialchars((string) $node, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $this->encoding);
    } else if (is_bool($node) || is_null($node)) {
      return '';
    } else if (is_array($node)) {
      if (empty($node)) {
        return '';
      } else if ($node[0] === '$') {
        $type = $this->getNodeType($node);
        if (!is_string($type) && !($type instanceof \Closure)) {
          throw new \InvalidArgumentException("Type must be a string or closure, got " . gettype($type) . ".");
        }

        $nodeProps = $this->getNodeProps($node);

        $childrenProps = $this->getNodeChildrenProps($node);
        $props = array_merge($nodeProps, $childrenProps);

        if (!is_string($type) && $type instanceof \Closure) {
          return $this->renderNode($this->callComponent($type, $props), $nesting);
        }

        if (array_key_exists($type, $this->components)) {
          return $this->renderNode($this->callComponent($this->components[$type], $props), $nesting);
        }

        // Validate the tag name to prevent tag-name injection
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-\.]*$/', $type)) {
          throw new \InvalidArgumentException("Invalid HTML tag name: `{$type}`");
        }

        $shouldEscapeHtml = true;

        if (array_key_exists('dangerouslySetInnerHTML', $props)) {
          $children = $props['dangerouslySetInnerHTML'];
          unset($props['dangerouslySetInnerHTML']);
          $shouldEscapeHtml = false;

          if (array_key_exists('children', $props)) {
            // Throw error in development mode
            throw new \Exception("Can't use children and dangerouslySetInnerHTML at the same time");
          }
        } else {
          $children = $props['children'] ?? [];
        }

        unset($props['children']);

        $attributeString = [];

        foreach ($props as $key => $value) {
          if ($key === 'className') {
            $key = 'class';
          } else if ($key === 'htmlFor') {
            $key = 'for';
          }

          // Transform key from camelCase to kebab-case
          $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $key));

          // Validate attribute name to prevent name-injection attacks
          if (!preg_match('/^[a-z][a-z0-9\-:._]*$/', $key)) {
            throw new \InvalidArgumentException("Invalid attribute name: `{$key}`");
          }

          if ($value !== null) {
            if (is_bool($value)) {
              if ($value === true) {
                $attributeString[] = $key;
              }

              continue;
            } else if ($key === "style" && (is_object($value) || is_array($value))) {
              $styleString = "";
              foreach ((array) $value as $styleKey => $styleValue) {
                // Transform key from camelCase to kebab-case
                $styleKey = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $styleKey));
                $styleString .= "$styleKey:$styleValue;";
              }
              $value = htmlspecialchars(rtrim($styleString, ';'), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $this->encoding);
            } else if (is_string($value) || is_numeric($value)) {
              $value = htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $this->encoding);
            } else if ($key === "data" && (is_object($value) || is_array($value))) {
              foreach ((array) $value as $dataKey => $dataValue) {
                // Normalize data key to kebab-case and validate to prevent name-injection
                $dataKey = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', (string) $dataKey));
                if (!preg_match('/^[a-z][a-z0-9\-:._]*$/', $dataKey)) {
                  throw new \InvalidArgumentException("Invalid data attribute key: `{$dataKey}`");
                }

                if (is_bool($dataValue)) {
                  if ($dataValue === true) {
                    $attributeString[] = "data-$dataKey";
                  }

                  continue;
                } else if (is_string($dataValue) || is_numeric($dataValue)) {
                  $dataValue = htmlspecialchars((string) $dataValue, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $this->encoding);
                } else if (is_null($dataValue)) {
                  continue;
                } else {
                  throw new \Exception("Invalid prop value type: `" . gettype($dataValue) . "`");
                }

                $attributeString[] = "data-$dataKey=\"$dataValue\"";
              }

              continue;
            } else if ($key !== 'data' && is_array($value)) {
              $_flattened = [];

              // Flatten array
              array_walk_recursive($value, function ($a) use (&$_flattened) {
                $_flattened[] = $a;
              });

              $value = htmlspecialchars(implode(" ", $_flattened), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $this->encoding);
            } else if ($value instanceof \JsonSerializable) {
              $value = htmlspecialchars(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $this->encoding);
            } else if ($value instanceof \DateTime || $value instanceof \DateTimeImmutable) {
              $value = $value->format('c');
            } else if ($value instanceof \stdClass) {
              $value = htmlspecialchars(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
            } else {
              if (gettype($value) === 'object') {
                if (method_exists($value, '__toString')) {
                  $value = (string) $value;
                } else {
                  throw new \Exception("Invalid prop `{$key}` value type: `" . gettype($value) . "``");
                }
              } else {
                throw new \Exception("Invalid prop `{$key}` value type: `" . gettype($value) . "``");
              }
            }

            $attributeString[] = "$key=\"$value\"";
          }
        }

        $attributeString = implode(" ", $attributeString);

        if (is_string($children)) {
          $childrenRendered = $shouldEscapeHtml ? htmlspecialchars($children, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $this->encoding) : $children;
        } else {
          $childrenRendered = $this->renderNode($children, $nesting + 1);

          if ($this->pretty && (strstr($childrenRendered, "\n") || strstr($childrenRendered, "<"))) {
            $childrenRendered = "\n".$this->format($childrenRendered, $nesting + 1)."\n".$this->format('', $nesting);
          }
        }

        // Handle void elements:
        if (in_array($type, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'])) {
          return "<$type".(empty($attributeString) ? '' : ' ' . $attributeString).($this->void ? ">" : " />");
        } else {
          return "<$type".(empty($attributeString) ? '' : ' ' . $attributeString).">{$childrenRendered}</$type>";
        }
      } else {
        $children = self::concatenateStringMembers($node, $this->react, $this->encoding);

        $childrenRendered = [];

        $previousChildWasElement = false;

        foreach ($children as $child) {
          if (is_array($child)) {
            if (!array_key_exists(0, $child)) {
              throw new \InvalidArgumentException("Fragment child must be a serialized node array (starting with '\$') or a nested children array, got an associative or empty array instead.");
            }
            if ($child[0] === '$') {
              $_child = $this->renderNode($child, $nesting);

              if ($this->pretty) {
                if ($previousChildWasElement) {
                  $_child = "\n".$this->format($_child, $nesting);
                }
              }

              $childrenRendered[] = $_child;
              $previousChildWasElement = true;
            } else {
              throw new \Exception("Unexpected unflattened array in children");
            }
          } else if (is_string($child) || is_numeric($child)) {
            $childrenRendered[] = (string) $child;
            $previousChildWasElement = false;
          }
        }

        return implode('', $childrenRendered);
      }
    } else {
      throw new \Exception("Invalid node type: `" . gettype($node) . "`");
    }
  }

  protected static function concatenateStringMembers(array $array, bool $react, string $encoding = 'UTF-8'): array {
    $combinedArray = [];
    $currentString = '';

    $length = count($array);

    foreach ($array as $index => $item) {
      if (
        is_string($item) || is_numeric($item)
      ) {
        $escapedValue = htmlspecialchars((string) $item, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $encoding);
        if ($react) {
          $isFirst = $index === 0;
          $isLast = $index === $length - 1;
          $stringifiedValue = (string) $item;

          $canBeTrimmedFromStart = !$isFirst && ltrim($stringifiedValue) !== $stringifiedValue;
          $canBeTrimmedFromEnd = !$isLast && rtrim($stringifiedValue) !== $stringifiedValue;

          if ($canBeTrimmedFromStart && $canBeTrimmedFromEnd) {
            $currentString .= '<!-- -->' . $escapedValue . '<!-- -->';
          } else if ($canBeTrimmedFromEnd) {
            $currentString .= $escapedValue . '<!-- -->';
          } else if ($canBeTrimmedFromStart) {
            $currentString .= '<!-- -->' . $escapedValue;
          } else {
            $currentString .= $escapedValue;
          }
        } else {
          $currentString .= $escapedValue;
        }
      } else {
        if ($currentString !== '') {
          $combinedArray[] = $currentString;
          $currentString = '';
        }

        if (is_array($item)) {
          if (!array_key_exists(0, $item)) {
            throw new \InvalidArgumentException("Fragment child must be a serialized node array (starting with '\$') or a nested children array, got an associative or empty array instead.");
          }
          if ($item[0] === '$') {
            $combinedArray[] = $item;
          } else {
            $combinedArray = [...$combinedArray, ...self::concatenateStringMembers($item, $react, $encoding)];
          }
        } else {
          $combinedArray[] = $item;
        }
      }
    }

    if ($currentString !== '') {
      $combinedArray[] = $currentString;
    }

    return $combinedArray;
  }
}
