--TEST--
test1() Basic test
--EXTENSIONS--
php_auto_instr_cfg
--FILE--
<?php
$ret = test1();

var_dump($ret);
?>
--EXPECT--
The extension php_auto_instr_cfg is loaded and working!
NULL
