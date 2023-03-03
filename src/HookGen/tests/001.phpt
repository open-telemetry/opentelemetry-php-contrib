--TEST--
Check if php_auto_instr_cfg is loaded
--EXTENSIONS--
php_auto_instr_cfg
--FILE--
<?php
echo 'The extension "php_auto_instr_cfg" is available';
?>
--EXPECT--
The extension "php_auto_instr_cfg" is available
