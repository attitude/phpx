<?php

$GLOBALS['phpx_modules_test_eval_count'] = ($GLOBALS['phpx_modules_test_eval_count'] ?? 0) + 1;

return ['count' => $GLOBALS['phpx_modules_test_eval_count']];
