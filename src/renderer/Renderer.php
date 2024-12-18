<?php

namespace Attitude\PHPX\Renderer;

final class Renderer {
  public bool $void = false;
  public bool $pretty = false;
  public bool $react = false;
  public string $indentation = "\t";
  protected array $components = [];

  public function __construct(protected string $encoding = 'UTF-8') {
  }

  public function __invoke(bool|int|float|string|array|null $node, array $components = []): string {
    return $this->render($node, $components);
  }

  public function getNodeType(array $node): string {
    assert($node[0] === '$', "Serialized node requires first element to be '$', got `{$node[0]}` instead.");
    return $node[1];
  }

  public function getNodeProps(array $node): array {
    return $node[2] ?? [];
  }

  public function getNodeChildren(array $node): bool|int|float|string|array|null {
    return $node[3] ?? $this->getNodeProps($node)['children'] ?? [];
  }

  public function render(bool|int|float|string|array|null $node, array $components = []): string {
    $this->components = $components;
    $html = $this->renderNode($node, 0);
    $this->components = [];

    return $html;
  }

  protected function format(string $rendered, int $nesting): string {
    return str_repeat($this->indentation, max(0, $nesting)).$rendered;
  }

  protected function renderNode(bool|int|float|string|array|null $node, int $nesting): string {
    if (is_string($node) || is_numeric($node)) {
      return (string) $node;
    } else if (is_bool($node) || is_null($node)) {
      return '';
    } else if (is_array($node)) {
      if (empty($node)) {
        return '';
      } else if ($node[0] === '$') {
        $type = $this->getNodeType($node);
        assert(is_string($type), "Type must be a string");

        $props = $this->getNodeProps($node);
        $children = $this->getNodeChildren($node);

        if (array_key_exists($type, $this->components)) {
          return $this->renderNode(
            call_user_func(
              $this->components[$type],
              array_merge(
                $props ?? [],
                ($children ?? null) ? ['children' => $children] : []
              )
            ), $nesting);
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
              $value = rtrim($styleString, ';');
            } else if (is_string($value) || is_numeric($value)) {
              $value = (string) $value;
            } else if ($key === "data" && (is_object($value) || is_array($value))) {
              foreach ((array) $value as $dataKey => $dataValue) {
                if (is_bool($dataValue)) {
                  if ($dataValue === true) {
                    $attributeString[] = "data-$dataKey";
                  }

                  continue;
                } else if (is_string($dataValue) || is_numeric($dataValue)) {
                  $dataValue = (string) $dataValue;
                } else if (is_null($dataValue)) {
                  continue;
                } else {
                  throw new \Exception("Invalid prop value type: `" . gettype($dataValue) . "``");
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

              $value = implode(" ", $value);
            } else if ($value instanceof \JsonSerializable) {
              $value = htmlspecialchars(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
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

        $childrenRendered = '';

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
        $children = self::concatenateStringMembers($node, $this->react);

        $elements = 0;
        $childrenRendered = [];

        $previousChildWasElement = false;

        foreach ($children as $i => $child) {
          if (is_array($child)) {
            if ($child[0] === '$') {
              $elements++;
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
            $childrenRendered[] = $this->renderNode($child, $nesting + 1);
            $previousChildWasElement = false;
          } else {
            continue;
          }
        }

        return implode('', $childrenRendered);
      }
    } else {
      throw new \Exception("Invalid node type: `" . gettype($node) . "``");
    }
  }

  protected static function concatenateStringMembers(array $array, bool $react): array {
    $combinedArray = [];
    $currentString = '';

    $length = count($array);

    foreach ($array as $index => $item) {
      if (
        is_string($item) || is_numeric($item)
      ) {
        if ($react) {
          $isFirst = $index === 0;
          $isLast = $index === $length - 1;
          $stringifiedValue = (string) $item;

          $canBeTrimmedFromStart = !$isFirst && ltrim($stringifiedValue) !== $stringifiedValue;
          $canBeTrimmedFromEnd = !$isLast && rtrim($stringifiedValue) !== $stringifiedValue;

          if ($canBeTrimmedFromStart && $canBeTrimmedFromEnd) {
            $currentString .= '<!-- -->' . $stringifiedValue . '<!-- -->';
          } else if ($canBeTrimmedFromEnd) {
            $currentString .= $stringifiedValue . '<!-- -->';
          } else if ($canBeTrimmedFromStart) {
            $currentString .= '<!-- -->' . $stringifiedValue;
          } else {
            $currentString .= $stringifiedValue;
          }
        } else {
          $currentString .= (string) $item;
        }
      } else {
        if ($currentString !== '') {
          $combinedArray[] = $currentString;
          $currentString = '';
        }

        if (is_array($item)) {
          if ($item[0] === '$') {
            $combinedArray[] = $item;
          } else {
            $combinedArray = [...$combinedArray, ...self::concatenateStringMembers($item, $react)];
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
