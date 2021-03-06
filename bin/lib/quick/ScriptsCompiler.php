<?php

require_once(__DIR__ . '/init.php');
require_once(__DIR__ . '/xxtea.php');

class ScriptsCompiler
{
    const COMPILE_ZIP = 'zip';
    const COMPILE_FILES = 'files';
    const ENCRYPT_XXTEA_ZIP = 'xxtea_zip';
    const ENCRYPT_XXTEA_CHUNK = 'xxtea_chunk';
    const ENCRYPT_XXTEA_DEFAULT_SIGN = 'XXTEA';

    private $config;
    private $options;
    private $validated = false;

    function __construct($config, $options)
    {
        $this->config = $config;
        $this->options = $options;
    }

    function validateConfig()
    {
        if (empty($this->config['src']))
        {
            printf("ERR: not specifies source files directory\n");
            return false;
        }

        if (empty($this->config['output']))
        {
            printf("ERR: not output filename or output directory\n");
            return false;
        }

        if (!empty($this->config['prefix']))
        {
            $this->config['prefix'] = $this->config['prefix'] . '.';
        }
        else
        {
            $this->config['prefix'] = '';
        }

        if (!empty($this->config['excludes']))
        {
            $excludes = explode(',', $this->config['excludes']);
            array_walk($excludes, function($value) {
                return trim($value);
            });
            $this->config['excludes'] = array_filter($excludes, function($value) {
                return !empty($value);
            });
        }
        else
        {
            $this->config['excludes'] = array();
        }

        if ($this->config['compile'] != self::COMPILE_ZIP
            && $this->config['compile'] != self::COMPILE_FILES)
        {
            printf("ERR: invalid compile mode %s\n", $this->config['compile']);
            return false;
        }

        if (!empty($this->config['encrypt'])
            && $this->config['encrypt'] != self::ENCRYPT_XXTEA_ZIP
            && $this->config['encrypt'] != self::ENCRYPT_XXTEA_CHUNK)
        {
            printf("ERR: invalid encrypt mode %s\n", $this->config['encrypt']);
            return false;
        }

        if (!empty($this->config['encrypt']) && empty($this->config['key']))
        {
            print("ERR: not set encrypt key\n");
            return false;
        }

        if ($this->config['encrypt'] == self::ENCRYPT_XXTEA_ZIP
            || $this->config['encrypt'] == self::ENCRYPT_XXTEA_CHUNK)
        {
            if ($this->config['compile'] == self::COMPILE_FILES && $this->config['encrypt'] != self::ENCRYPT_XXTEA_CHUNK)
            {
                print("ERR: compile mode \"files\" must use encrypt mode \"xxtea_chunk\"\n");
                return false;
            }

            if (empty($this->config['sign']))
            {
                $this->config['sign'] = self::ENCRYPT_XXTEA_DEFAULT_SIGN;
            }
        }

        if (!empty($this->config['encrypt']) && empty($this->config['sign']))
        {
            print("ERR: not set encrypt sign\n");
            return false;
        }

        if (empty($this->config['extname']))
        {
            print("ERR: not specifies encrypted file extension name\n");
            return false;
        }

        if (!$this->config['quiet'])
        {
            dumpConfig($this->config, $this->options);
        }

        // check src path
        $srcpath = realpath($this->config['src']);
        if (!is_dir($srcpath))
        {
            printf("ERR: invalid src dir %s\n", $srcpath);
            return false;
        }
        $this->config['srcpath'] = $srcpath;
        $this->config['srcpathLength'] = strlen($srcpath) + 1;

        if ($this->config['compile'] == self::COMPILE_ZIP)
        {
            if (is_dir($this->config['output']))
            {
                printf("ERR: output file is dir %s\n", $this->config['output']);
                return false;
            }
        }
        else if ($this->config['compile'] == self::COMPILE_FILES)
        {
            @mkdir($this->config['output'], 0777, true);
            $this->config['output'] = realpath($this->config['output']);
            if (empty($this->config['output']) || !is_dir($this->config['output']))
            {
                printf("ERR: invalid output dir %s\n", $this->config['output']);
                return false;
            }
        }

        $this->validated = true;
        return true;
    }

    function run()
    {
        if (!$this->validated)
        {
            print("ERR: invalid config\n");
            return false;
        }

        $files = $this->searchSourceFiles();
        $modules = $this->prepareForCompile($files);

        if ($this->config['encrypt'] == self::ENCRYPT_XXTEA_CHUNK)
        {
            $bytes = $this->compileModules($modules, $this->config['key'], $this->config['sign']);
        }
        else
        {
            $bytes = $this->compileModules($modules);
        }
        if (!is_array($bytes))
        {
            $this->cleanupTempFiles($modules);
            return false;
        }

        if (!$this->createOutput($modules, $bytes))
        {
            $this->cleanupTempFiles($modules);
            return false;
        }

        $this->cleanupTempFiles($modules);
        return true;
    }

    protected function searchSourceFiles()
    {
        if (!$this->config['quiet'])
        {
            printf("Compile Lua source files in path %s\n", $this->config['srcpath']);
        }
        $files = array();
        findFiles($this->config['srcpath'], $files);
        return $files;
    }

    protected function prepareForCompile(array $files)
    {
        $modules = array();
        // prepare for compile
        foreach ($files as $key => $path)
        {
            if (substr($path, -4) != '.lua')
            {
                unset($files[$key]);
            }
            else
            {
                $moduleName = substr(substr($path, $this->config['srcpathLength']), 0, -4);
                $tempFilePath = $this->config['srcpath'] . DS . $moduleName . '.bytes';
                $moduleName = str_replace(DS, '.', $moduleName);
                $modules[$path] = array(
                    'moduleName' => $moduleName,
                    'tempFilePath' => $tempFilePath,
                );
            }
        }
        return $modules;
    }

    protected function cleanupTempFiles(array $modules)
    {
        foreach ($modules as $module)
        {
            if (file_exists($module['tempFilePath']))
            {
                unlink($module['tempFilePath']);
            }
        }
    }

    protected function compileModules(array $modules, $key = null, $sign = null)
    {
        if (!empty($key))
        {
            $xxtea = new XXTEA();
            $xxtea->setKey($key);
        }

        $modulesBytes = array();
        foreach ($modules as $path => $module)
        {
            $bytes = getScriptFileBytecodes($path, $module['tempFilePath']);
            if (!empty($key))
            {
                $bytes = $sign . $xxtea->encrypt($bytes);
                file_put_contents($module['tempFilePath'], $bytes);
            }
            if (!$bytes)
            {
                print("\n");
                return false;
            }
            $modulesBytes[$path] = $bytes;
            if (!$this->config['quiet'])
            {
                printf("  > get bytes [% 3d KB] %s\n", ceil(strlen($bytes) / 1024), $module['moduleName']);
            }
        }
        return $modulesBytes;
    }

    protected function createOutput(array $modules, array $bytes)
    {
        if ($this->config['compile'] == self::COMPILE_ZIP)
        {
            // create ZIP archive
            $zipfile = $this->config['output'];
            $zip = new ZipArchive();
            if (!$zip->open($zipfile, ZIPARCHIVE::OVERWRITE | ZIPARCHIVE::CM_STORE))
            {
                return false;
            }

            if (!$this->config['quiet'])
            {
                printf("create ZIP archive file: %s\n", $zipfile);
            }
            foreach ($modules as $path => $module)
            {
                $zip->addFromString($this->config['prefix'] . $module['moduleName'], $bytes[$path]);
            }
            $zip->close();

            if ($this->config['encrypt'] == self::ENCRYPT_XXTEA_ZIP)
            {
                $xxtea = new XXTEA();
                $xxtea->setKey($this->config['key']);
                file_put_contents($zipfile, $this->config['sign'] . $xxtea->encrypt(file_get_contents($zipfile)));
            }
            if (!$this->config['quiet'])
            {
                printf("done.\n\n");
            }
        }
        else if ($this->config['compile'] == self::COMPILE_FILES)
        {
            foreach ($modules as $module)
            {
                $destPath = $this->config['output'] . DS . str_replace('.', DS, $this->config['prefix'] . $module['moduleName']) . '.' . $this->config['extname'];
                @mkdir(pathinfo($destPath, PATHINFO_DIRNAME), 0777, true);
                rename($module['tempFilePath'], $destPath);
            }

            printf("create output files in %s .\n", $this->config['output']);
            print("done.\n\n");
        }
        else
        {
            printf("ERR: compile mode %s not implemented\n", $this->config['compile']);
            return false;
        }
        return true;
    }

//    function dump($outputFileBasename)
//    {

//
//### HOW TO USE ###
//
//1. Add code to AppDelegate.cpp:
//
//    extern "C" {
//    #include "${outputFileBasename}.h"
//    }
//
//2. Add code to AppDelegate::applicationDidFinishLaunching()
//
//    CCScriptEngineProtocol* pEngine = CCScriptEngineManager::sharedManager()->getScriptEngine();
//    luaopen_${outputFileBasename}(pEngine->getLuaState());
//
//    pEngine->executeString("require(\"main\")");
//
//
//EOT;
//        }
//
//    }
//
//    private function compile()
//    {
//        if (file_exists($this->rootdir) && is_dir($this->rootdir))
//        {
//            $this->files = $this->getFiles($this->rootdir);
//        }
//
//        foreach ($this->files as $path)
//        {
//            $filename = substr($path, $this->rootdirLength);
//            $fi = pathinfo($filename);
//            if ($fi['extension'] != 'lua') continue;
//
//            $basename = ltrim($fi['dirname'] . DS . $fi['filename'], '/\\.');
//            $moduleName = $this->packageName . str_replace(DS, '.', $basename);
//            $found = false;
//            foreach ($this->excludes as $k => $v)
//            {
//                if (substr($moduleName, 0, strlen($v)) == $v)
//                {
//                    $found = true;
//                    break;
//                }
//            }
//            if ($found) continue;
//
//            if (!$this->quiet)
//            {
//                printf('  compile module: %s...', $moduleName);
//            }
//            $bytes = $this->compileFile($path);
//            if ($bytes == false)
//            {
//                print("error.\n");
//            }
//            else
//            {
//                if (!$this->quiet)
//                {
//                    print("ok.\n");
//                }
//                $bytesName = 'lua_m_' . strtolower(str_replace(array('.', '-'), '_', $moduleName));
//                $this->modules[] = array(
//                    'moduleName'    => $moduleName,
//                    'bytesName'     => $bytesName,
//                    'functionName'  => 'luaopen_' . $bytesName,
//                    'basename'      => $basename,
//                    'bytes'         => $bytes,
//                );
//            }
//        }
//    }
//
//
//    private function renderHeaderFile($outputFileBasename)
//    {
//        $headerSign = '__LUA_MODULES_' . strtoupper(md5($outputFileBasename . time())) . '_H_';
//        $outputFileBasename = basename($outputFileBasename);
//
//        $contents = array();
//        $contents[] = <<<EOT
//
///* ${outputFileBasename}.h */
//
//#ifndef ${headerSign}
//#define ${headerSign}
//
//#if __cplusplus
//extern "C" {
//#endif
//
//#include "lua.h"
//
//void luaopen_${outputFileBasename}(lua_State* L);
//
//EOT;
//
//        if (!$this->exportModuleFuntion)
//        {
//            $contents[] = '/*';
//        }
//
//        foreach ($this->modules as $module)
//        {
//            $contents[] = sprintf('int %s(lua_State* L);', $module['functionName']);
//        }
//
//        if (!$this->exportModuleFuntion)
//        {
//            $contents[] = '*/';
//        }
//
//        $contents[] = <<<EOT
//
//#if __cplusplus
//}
//#endif
//
//#endif /* ${headerSign} */
//
//EOT;
//
//        return implode("\n", $contents);
//    }
//
//    private function renderSourceFile($outputFileBasename)
//    {
//        $outputFileBasename = basename($outputFileBasename);
//
//        $contents = array();
//        $contents[] = <<<EOT
//
///* ${outputFileBasename}.c */
//
//#include "lua.h"
//#include "lauxlib.h"
//#include "${outputFileBasename}.h"
//
//EOT;
//
//        foreach ($this->modules as $module)
//        {
//            $contents[] = sprintf('/* %s, %s.lua */', $module['moduleName'], $module['basename']);
//            $contents[] = sprintf('static const char %s[] = {', $module['bytesName']);
//            $contents[] = $this->encodeBytes($module['bytes']);
//            // $contents[] = $this->encodeBytesFast($module['bytes']);
//            $contents[] = '};';
//            $contents[] = '';
//        }
//
//        $contents[] = '';
//
//        foreach ($this->modules as $module)
//        {
//            $functionName = $module['functionName'];
//            $bytesName    = $module['bytesName'];
//            $basename     = $module['basename'];
//
//            $contents[] = <<<EOT
//
//int ${functionName}(lua_State *L) {
//    luaL_loadbuffer(L,
//                    (const char*)${bytesName},
//                    sizeof(${bytesName}),
//                    "${basename}");
//    return 1;
//}
//
//EOT;
//        }
//
//        $contents[] = '';
//
//        $contents[] = "static luaL_Reg ${outputFileBasename}_modules[] = {";
//
//        foreach ($this->modules as $module)
//        {
//            $contents[] = sprintf('    {"%s", %s},',
//                                  $module["moduleName"],
//                                  $module["functionName"]);
//        }
//
//        $contents[] = <<<EOT
//    {NULL, NULL}
//};
//
//void luaopen_${outputFileBasename}(lua_State* L)
//{
//    luaL_Reg* lib = ${outputFileBasename}_modules;
//    lua_getglobal(L, "package");
//    lua_getfield(L, -1, "preload");
//    for (; lib->func; lib++)
//    {
//        lib->func(L);
//        lua_setfield(L, -2, lib->name);
//    }
//    lua_pop(L, 2);
//}
//
//EOT;
//
//        return implode("\n", $contents);
//    }
//
//    private function encodeBytes($bytes)
//    {
//        $len      = strlen($bytes);
//        $contents = array();
//        $offset   = 0;
//        $buffer   = array();
//
//        while ($offset < $len)
//        {
//            $buffer[] = ord(substr($bytes, $offset, 1));
//            if (count($buffer) == 16)
//            {
//                $contents[] = '    ' . $this->encodeBytesBlock($buffer);
//                $buffer = array();
//            }
//            $offset++;
//        }
//        if (!empty($buffer))
//        {
//            $contents[] = '    ' . $this->encodeBytesBlock($buffer);
//        }
//
//        return implode("\n", $contents);
//    }
//
//    private function encodeBytesFast($bytes)
//    {
//        $len = strlen($bytes);
//        $output = array();
//        for ($i = 0; $i < $len; $i++)
//        {
//            $output[] = sprintf('%d,', ord($bytes{$i}));
//        }
//        return implode('', $output);
//    }
//
//    private function encodeBytesBlock($buffer)
//    {
//        $output = array();
//        $len = count($buffer);
//        for ($i = 0; $i < $len; $i++)
//        {
//            $output[] = sprintf('0x%02x,', $buffer[$i]);
//        }
//        return implode('', $output);
//    }
}
