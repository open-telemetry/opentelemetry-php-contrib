/* hookgen extension for PHP */

#ifndef PHP_HOOKGEN_H
#define PHP_HOOKGEN_H

extern zend_module_entry hookgen_module_entry;
#define phpext_hookgen_ptr &hookgen_module_entry

#define PHP_HOOKGEN_VERSION "0.1.0"

#if defined(ZTS) && defined(COMPILE_DL_HOOKGEN)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif /* PHP_HOOKGEN_H */
