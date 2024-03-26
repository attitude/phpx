<?php declare(strict_types = 1);

namespace Attitude\PHPX\Parser;

enum NodeType: string {
	case BLOCK = 'Block';
	case EXPRESSION = 'Expression';
	case TEMPLATE_LITERAL = 'TemplateLiteral';

	case PHPX_ELEMENT = 'PHPXElement';
	case PHPX_FRAGMENT = 'PHPXFragment';
	case PHPX_ATTRIBUTE = 'PHPXAttribute';
	case PHPX_EXPRESSION_CONTAINER = 'PHPXExpressionContainer';
	case PHPX_COMMENT = 'PHPXComment';
	case PHPX_TEXT = 'PHPXText';
}
