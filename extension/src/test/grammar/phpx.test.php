# SYNTAX TEST "source.phpx" "PHPX Grammar Tests"

# Simple element test
['$', 'div', null, ['Hello World']]
#<--- punctuation.definition.tag.begin.phpx
#^^^^ entity.name.tag.phpx
#    ^ punctuation.definition.tag.end.phpx
#     ^^^^^^^^^^^ string.unquoted.phpx.text
#                ^^ punctuation.definition.tag.end.phpx
#                  ^^^ entity.name.tag.phpx
#                     ^ punctuation.definition.tag.end.phpx

# Fragment test
['content']
#^^ punctuation.definition.tag.phpx.fragment.begin
#  ^^^^^^^ string.unquoted.phpx.text
#         ^^^ punctuation.definition.tag.phpx.fragment.end

# Self-closing element
['$', 'br']
#<--- punctuation.definition.tag.begin.phpx
#^^ entity.name.tag.phpx
#   ^^ punctuation.definition.tag.end.phpx

# Element with string attribute
['$', 'div', ['className'=>"container"], ['content']]
#    ^^^^^^^^^ entity.other.attribute-name.phpx
#             ^ punctuation.separator.key-value.phpx
#              ^^^^^^^^^^^ string.quoted.double.phpx

# Element with expression attribute
['$', 'div', ['id'=>($variable)], ['content']]
#    ^^ entity.other.attribute-name.phpx
#      ^ punctuation.separator.key-value.phpx
#       ^ punctuation.definition.brace.begin.phpx
#                 ^ punctuation.definition.brace.end.phpx

# Element with boolean attribute
['$', 'input', ['disabled'=>true]]
#      ^^^^^^^^ entity.other.attribute-name.phpx

# Spread attribute
['$', 'div', [...$props]]
#    ^ punctuation.definition.brace.begin.phpx
#     ^^^ keyword.operator.spread.phpx
#        ^^^^^^ variable.other.php
#              ^ punctuation.definition.brace.end.phpx

# Shorthand attribute
['$', 'div', ['loading'=>$loading]]
#    ^ punctuation.definition.brace.begin.phpx
#     ^^^^^^^^ variable.other.php
#             ^ punctuation.definition.brace.end.phpx

# Expression container in children
['$', 'div', null, ['Hello, ', ($name), '!']]
#           ^ punctuation.definition.brace.begin.phpx
#                 ^ punctuation.definition.brace.end.phpx

# PHPX comment
{/* This is a comment */}
#^ punctuation.definition.brace.begin.phpx
# ^^ punctuation.definition.comment.begin.phpx
#                     ^^ punctuation.definition.comment.end.phpx
#                       ^ punctuation.definition.brace.end.phpx

# Template literal
'Hello, '.($name).'!'
#^ punctuation.definition.string.template.begin.phpx
#       ^^ punctuation.definition.interpolation.begin.phpx
#               ^ punctuation.definition.interpolation.end.phpx
#                ^ punctuation.definition.string.template.end.phpx
