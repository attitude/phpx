<?php declare(strict_types=1);

namespace Attitude\PHPX\Parser;

// Synthetic token ids and sequences used across Token, TokensList and Parser.
// These live in a dedicated, always-autoloaded file (composer "files" + the
// src/parser/index.php require chain) because PSR-4 only autoloads classes:
// calling Token::tokenize() before TokensList is referenced would otherwise
// fatal on an undefined TX_* constant.

/** Token for {, value of ord('{'); */
const TX_CURLY_BRACKET_OPEN = 123;
/** Token for }, value of ord('}'); */
const TX_CURLY_BRACKET_CLOSE = 125;
/** Token for (, value of ord('('); */
const TX_PARENTHESIS_OPEN = 40;
/** Token for ), value of ord(')'); */
const TX_PARENTHESIS_CLOSE = 41;
/** Token for [, value of ord('['); */
const TX_SQUARE_BRACKET_OPEN = 91;
/** Token for ], value of ord(']'); */
const TX_SQUARE_BRACKET_CLOSE = 93;
/** Synthetic token id for the PHPX fragment opener `<>`. PHP tokenizes `<>` as T_IS_NOT_EQUAL;
 *  Token::tokenize() replaces that id with this constant so the parser treats `<>` as a
 *  fragment open rather than a not-equal operator. Value = (ord('>') << 8) | ord('<') = 0x3E3C. */
const TX_FRAGMENT_ELEMENT_OPEN = 15932;
/** Token sequence for </>, value of ['<', '/', '>']; */
const TX_FRAGMENT_ELEMENT_CLOSING_SEQUENCE = ['<', '/', '>'];
/** Token for <, value of ord('<'); */
const TX_ELEMENT_OPENING_OPEN = 60;
/** Token for <T_STRING sequence, value of ['<',T_STRING]; */
const TX_ELEMENT_OPENING_OPEN_SEQUENCE = ['<', T_STRING];
/** Token for <?, value of ['<', '?']; */
const TX_PHP_OPEN_SEQUENCE = ['<', '?'];
/** Token sequence for />, value of ['/', '>']; */
const TX_ELEMENT_SELF_CLOSING_SEQUENCE = ['/', '>'];
/** Token for `>` closing an opening tag — the `>` in `<div>`; value of ord('>') = 62. */
const TX_ELEMENT_OPENING_CLOSE = 62;
/** Token sequence for </T_STRING, value of ['<', '/', T_STRING]; */
const TX_ELEMENT_CLOSING_OPEN_SEQUENCE = ['<', '/', T_STRING];
/** Token for `>` closing a closing tag — the `>` in `</div>`; value of ord('>') = 62. */
const TX_ELEMENT_CLOSING_CLOSE = 62;
/** Token for Template Literal backtick, value of ord('`'); */
const TX_TEMPLATE_LITERAL = 96;
