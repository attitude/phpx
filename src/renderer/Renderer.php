<?php

namespace Attitude\PHPX\Renderer;

include_once __DIR__.'/../concatenateStringMembers.php';

final class Renderer {
  public bool $void = false;
  public bool $pretty = false;
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
    static $iteration = 0;

    $iteration++;

    if ($this->pretty) {
      return str_repeat($this->indentation, $nesting).$rendered;
    } else {
      return $rendered;
    }
  }

  protected function renderNode(bool|int|float|string|array|null $node, int $nesting): string {
    if (is_string($node)) {
      return $node;
    } elseif (is_numeric($node)) {
      return (string) $node;
    } elseif (is_bool($node)) {
      return '';
    } elseif (is_null($node)) {
      return '';
    } elseif (is_array($node)) {
      if (empty($node)) {
        return '';
      } else if (is_array($node[0])) {
        $html = [];

        foreach ($node as $child) {
          $html[] = $this->renderNode($child, $nesting);
        }

        if ($this->pretty) {
          if (count($html) === 1) {
            return $html[0];
          } else {
            return implode("\n", $html);
          }
        } else {
          return implode('', $html);
        }
      } else if ($node[0] !== '$') {
        if ($this->pretty && count($node) > 1) {
          return "\n".$this->format(trim($this->renderNode($node, $nesting + 1)), $nesting + 1);
        } else {
          return $this->renderNode($node, $nesting + 1);
        }
      }

      try {
        $type = $this->getNodeType($node);
      } catch (\Throwable $e) {
        debug_print_backtrace();
        throw $e;
      }
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
          // Inline handleSpecialAttributes
          $key = ($key === "htmlFor") ? "for" : (($key === "className") ? "class" : $key);

          if (is_bool($value)) {
            if ($value === true) {
              $attributeString[] = $key;
            }

            continue;
          } elseif ($key !== 'data' && is_array($value)) {
            $_flattened = [];

            // Flatten array
            array_walk_recursive($value, function ($a) use (&$_flattened) {
              $_flattened[] = $a;
            });

            $value = implode(" ", $value);
          } elseif ($key === "style" && (is_object($value) || is_array($value))) {
            $styleString = "";
            foreach ((array) $value as $styleKey => $styleValue) {
              // Transform key from camelCase to kebab-case
              $styleKey = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $styleKey));
              $styleString .= "$styleKey:$styleValue;";
            }
            $value = rtrim($styleString, ';');
          } elseif (is_string($value) || is_numeric($value)) {
            $value = (string) $value;
          } elseif ($key === "data" && (is_object($value) || is_array($value))) {
            foreach ((array) $value as $dataKey => $dataValue) {
              if (is_bool($dataValue)) {
                if ($dataValue === true) {
                  $attributeString[] = "data-$dataKey";
                }

                continue;
              } elseif (is_string($dataValue) || is_numeric($dataValue)) {
                $dataValue = (string) $dataValue;
              } else if (is_null($dataValue)) {
                continue;
              } else {
                throw new \Exception("Invalid prop value type: `" . gettype($dataValue) . "``");
              }

              $attributeString[] = "data-$dataKey=\"$dataValue\"";
            }

            continue;
          } else {
            throw new \Exception("Invalid prop value type: `" . gettype($value) . "``");
          }

          $attributeString[] = "$key=\"$value\"";
        }
      }

      $attributeString = implode(" ", $attributeString);

      $childrenRendered = '';

      if (is_array($children)) {
        $children = concatenateStringMembers($children, allowNumeric: true);
        $childrenCount = count($children);

        $elements = 0;
        $childrenRendered = [];

        foreach ($children as $i => $child) {
          if (is_array($child)) {
            $elements++;

            if ($this->pretty) {
              $childrenRendered[] = "\n".$this->renderNode($child, $nesting + 1);
            } else {
              $childrenRendered[] = $this->renderNode($child, $nesting + 1);
            }
          } else if (is_string($child) || is_numeric($child)) {
            if ($this->pretty && $childrenCount > 1) {
              $childrenRendered[] = "\n".$this->format(trim($this->renderNode($child, $nesting + 1)), $nesting + 1);
            } else {
              $childrenRendered[] = $this->renderNode($child, $nesting + 1);
            }
          } else {
            continue;
          }
        }

        if ($this->pretty) {
          if ($elements === 0) {
            $childrenRendered = implode('', $childrenRendered);
          } else {
            $childrenRendered = implode('', $childrenRendered)."\n".str_repeat($this->indentation, $nesting);
          }
        } else {
          $childrenRendered = implode('', $childrenRendered);
        }
      } else {
        if (is_string($children)) {
          $childrenRendered = $shouldEscapeHtml ? htmlspecialchars($children, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $this->encoding) : $children;
        }
      }

      // Handle void elements:
      if (in_array($type, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'])) {
        return $this->format("<$type".(empty($attributeString) ? '' : ' ' . $attributeString).($this->void ? ">" : " />"), $nesting);
      } else {
        return $this->format("<$type".(empty($attributeString) ? '' : ' ' . $attributeString).">$childrenRendered</$type>", $nesting);
      }
    } else {
      throw new \Exception("Invalid node type: `" . gettype($node) . "``");
    }
  }
}
