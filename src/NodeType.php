<?php declare(strict_types = 1);

namespace PHPX\PHPX;

enum NodeType: string {
	case BLOCK = 'Block';
	case EXPRESSION = 'Expression';
	case TEMPLATE_LITERAL = 'TemplateLiteral';

	case PHPX_ELEMENT = 'PHPXElement';
	case PHPX_FRAGMENT = 'PHPXFragment';
	case PHPX_ATTRIBUTE = 'PHPXAttribute';
	case PHPX_EXPRESSION_CONTAINER = 'PHPXExpressionContainer';
	case PHPX_TEXT = 'PHPXText';
}
