--TEST--
test2() Basic test
--EXTENSIONS--
php_auto_instr_cfg
--FILE--
<?php
var_dump(test2());
var_dump(test2('PHP'));
?>
--EXPECT--
string(11) "Hello World"
string(9) "Hello PHP"
