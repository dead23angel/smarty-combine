Smarty Combine
==============

Combine plugin allows concatenating several js or css files into one. 
It can be useful for big projects with a lot of several small CSS and JS files.

### Usage examples

**Template inline example Smarty 3 or 4.1.0**

```{combine input=array('/bm.js','/bm2.js') output='/cache/big.js' use_true_path=false age='30' debug=false}```

**Smarty 2 example**

**PHP code**

```$js_filelist = array('/js/core.js','/js/slideviewer.js');```

```$smarty_object->assign('js_files', $js_filelist);```

**Template code**

```{combine input=$js_files output='/cache/big.js' use_true_path=false age='30' debug=false}```

**The plugin has 4 parameters:**
* **input** - must be an array, containing list with absolute pathes to files. In Smarty 3 it can be inline array, for Smarty 2 you will need to pass from yours controller a variable, which will contains this array.
* **output** - absolute path to output file. Directory must be writable to www daemon (Usually chmod 777 resolve this problem :)
* **use_true_path** - value is a boolean. If it is set to true the plugin will use the paths of the asset files as they are but if set to false it will assume the files have relative paths. By default it is set to false. You can omit this parameter.
* **age** - value of seconds between checks when original files were changed. By default it is 3600 - one hour. You can omit this parameter.
* **debug** - parameter in the value of TRUE, disable compilation useful for debugging when developing a site.. By default it is FALSE. You can omit this parameter.

Basedir of css-file is added to relative paths in url()

A semicolon is added at the end of .js-files, as it is not needed at the end
of a file, but is needed between concatenated files.
