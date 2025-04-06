<?php

namespace Attitude\PHPX\Compiler;

interface FormatterInterface {
    public function formatElement(string $type, ?string $config, ?string $children): string;
    public function formatFragment(string $children): string;
    public function formatAttributeExpression(string $name, string $value): string;
}
