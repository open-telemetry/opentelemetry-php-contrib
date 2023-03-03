dnl config.m4 for extension hookgen

dnl Comments in this file start with the string 'dnl'.
dnl Remove where necessary.

dnl If your extension references something external, use 'with':

dnl PHP_ARG_WITH([hookgen],
dnl   [for hookgen support],
dnl   [AS_HELP_STRING([--with-hookgen],
dnl     [Include hookgen support])])

dnl Otherwise use 'enable':

PHP_ARG_ENABLE([hookgen],
  [whether to enable hookgen support],
  [AS_HELP_STRING([--enable-hookgen],
    [Enable hookgen support])],
  [no])

if test "$PHP_HOOKGEN" != "no"; then
  dnl Write more examples of tests here...

  dnl Remove this code block if the library does not support pkg-config.
  dnl PKG_CHECK_MODULES([LIBFOO], [foo])
  dnl PHP_EVAL_INCLINE($LIBFOO_CFLAGS)
  dnl PHP_EVAL_LIBLINE($LIBFOO_LIBS, HOOKGEN_SHARED_LIBADD)

  dnl If you need to check for a particular library version using PKG_CHECK_MODULES,
  dnl you can use comparison operators. For example:
  dnl PKG_CHECK_MODULES([LIBFOO], [foo >= 1.2.3])
  dnl PKG_CHECK_MODULES([LIBFOO], [foo < 3.4])
  dnl PKG_CHECK_MODULES([LIBFOO], [foo = 1.2.3])

  dnl Remove this code block if the library supports pkg-config.
  dnl --with-hookgen -> check with-path
  dnl SEARCH_PATH="/usr/local /usr"     # you might want to change this
  dnl SEARCH_FOR="/include/hookgen.h"  # you most likely want to change this
  dnl if test -r $PHP_HOOKGEN/$SEARCH_FOR; then # path given as parameter
  dnl   HOOKGEN_DIR=$PHP_HOOKGEN
  dnl else # search default path list
  dnl   AC_MSG_CHECKING([for hookgen files in default path])
  dnl   for i in $SEARCH_PATH ; do
  dnl     if test -r $i/$SEARCH_FOR; then
  dnl       HOOKGEN_DIR=$i
  dnl       AC_MSG_RESULT(found in $i)
  dnl     fi
  dnl   done
  dnl fi
  dnl
  dnl if test -z "$HOOKGEN_DIR"; then
  dnl   AC_MSG_RESULT([not found])
  dnl   AC_MSG_ERROR([Please reinstall the hookgen distribution])
  dnl fi

  dnl Remove this code block if the library supports pkg-config.
  dnl --with-hookgen -> add include path
  dnl PHP_ADD_INCLUDE($HOOKGEN_DIR/include)

  dnl Remove this code block if the library supports pkg-config.
  dnl --with-hookgen -> check for lib and symbol presence
  dnl LIBNAME=HOOKGEN # you may want to change this
  dnl LIBSYMBOL=HOOKGEN # you most likely want to change this

  dnl If you need to check for a particular library function (e.g. a conditional
  dnl or version-dependent feature) and you are using pkg-config:
  dnl PHP_CHECK_LIBRARY($LIBNAME, $LIBSYMBOL,
  dnl [
  dnl   AC_DEFINE(HAVE_HOOKGEN_FEATURE, 1, [ ])
  dnl ],[
  dnl   AC_MSG_ERROR([FEATURE not supported by your hookgen library.])
  dnl ], [
  dnl   $LIBFOO_LIBS
  dnl ])

  dnl If you need to check for a particular library function (e.g. a conditional
  dnl or version-dependent feature) and you are not using pkg-config:
  dnl PHP_CHECK_LIBRARY($LIBNAME, $LIBSYMBOL,
  dnl [
  dnl   PHP_ADD_LIBRARY_WITH_PATH($LIBNAME, $HOOKGEN_DIR/$PHP_LIBDIR, HOOKGEN_SHARED_LIBADD)
  dnl   AC_DEFINE(HAVE_HOOKGEN_FEATURE, 1, [ ])
  dnl ],[
  dnl   AC_MSG_ERROR([FEATURE not supported by your hookgen library.])
  dnl ],[
  dnl   -L$HOOKGEN_DIR/$PHP_LIBDIR -lm
  dnl ])
  dnl
  dnl PHP_SUBST(HOOKGEN_SHARED_LIBADD)

  dnl In case of no dependencies
  AC_DEFINE(HAVE_HOOKGEN, 1, [ Have hookgen support ])

  PHP_NEW_EXTENSION(hookgen, hookgen.c, $ext_shared)
fi
