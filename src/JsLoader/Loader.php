<?php
namespace JsLoader;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use JShrink\Minifier;

class Loader
{
    protected $sourceDir = null;
    protected $webDir = null;
    protected $deployDir = null;
    protected $defs = array();
    protected $debug;
    protected $scripts = array();

    private $moduleList = array();

    /**
     * Inicializa o loader de scripts.
     *
     * @param $sourceDir
     * @param $webDir
     */
    public function __construct($sourceDir, $webDir, $deployDir, $defs, $debug = false)
    {
        $this->sourceDir = $sourceDir;
        $this->webDir = $webDir;
        $this->deployDir = $deployDir;
        $this->defs = $defs;
        $this->debug = $debug;
    }

    /**
     * Carrega um módulo de scripts.
     *
     * @param string  $module
     *
     * @return string
     *
     * @throws \Exception
     */
    public function load($module)
    {
        $paramKey = 'jsloader.'.$module;
        $this->moduleList = array();

        if (!isset($this->defs[$paramKey])) {
            throw new \Exception('O módulo '.$module.' não foi definido no JsLoader!');
        } else {
            return $this->generateJs($paramKey);
        }

    }

    /**
     * Gera os scripts dos módulos de acordo com a definição de configuração.
     *
     * @param string  $paramKey
     *
     * @return string
     */
    protected function generateJs($paramKey)
    {
        $key = explode('.',$paramKey);
        $buildFileName = end($key).".build.js";
        $buildFileFullname = $this->deployDir."/".$buildFileName;

        if (!$this->debug && file_exists($buildFileFullname)) {
            printf("<script type='text/javascript' src='%s'></script>\n","./".$this->deployDir.$buildFileName);
        } else {

            $scripts = $this->buildScriptList($paramKey);

            $include = '';

            foreach ($scripts as $module => $list) {

                foreach ($list as $script){
                    $script = str_replace('\\','/', $script);
                    $path = $this->defs[$paramKey]['path'];
                    $script = str_replace($path,'',$script);
                    if ($this->debug) {
                        $include.= sprintf("<script type='text/javascript' src='%s%s'></script>\n",$path, $script);
                    } else {
                        @$include.= "\n".file_get_contents($this->webDir."/".$path.$script)."\n";
                    }
                }
            }

            if ($this->debug) {
                echo $include;
            } else {
                file_put_contents($this->webDir."/".$this->deployDir.$buildFileName,Minifier::minify($include));

                printf("<script type='text/javascript' src='%s%s'></script>\n",$this->deployDir,$buildFileName);

            }


        }

    }

    /**
     * Cria a lista de scripts a serem inseridos no carregamento.
     *
     * @param string $paramKey
     *
     * @return array
     */
    protected function buildScriptList($paramKey)
    {
        $def = $this->defs[$paramKey];

        if (isset($def['module'])) {
            $list = $this->buildModuleList($def, $paramKey);
            $list = array('module' => $list);
        } else {
            $list = $this->buildVendorsList($def['libs']);
        }
        return $list;
    }

    /**
     * Retorna a lista de scripts para módulos.
     *
     * @param array $def
     * @param string $paramsKey
     *
     * @return array
     */
    private function buildModuleList($def, $paramsKey)
    {
        $fs = new Filesystem();
        $mainFile = $def['path']."/main.js";
        $list = array();

        // verifica se existe o arquivo main no diretório informado
        if (!$fs->exists($mainFile)) {
            throw new \Exception("Não foi encontrado um script 'main.js' para o módulo '$paramsKey'");
        }

        $this->moduleList[$def['path']] = $this->findSubmodules($def['path']);

        foreach ($this->moduleList as $module => $excludes) {
            $list = array_merge($list, $this->getScriptList($module, $excludes));
        }

        return $list;
    }

    /**
     * Recupera a lista de scripts associado a um determinado módulo.
     *
     * @param string $path
     * @param array  $excludes
     *
     * @return array
     */
    private function getScriptList($path, $excludes)
    {
        $finder = new Finder();

        $files = $finder->files()->in($path)->name('*.js')->exclude($excludes);
        $list = array();

        $main = $path."/main.js";
        $list[] = str_replace("//","/",$main);

        foreach ($files as $script) {
            if ($script->getFilename() != 'main.js') {
                $list[] = $path."/".$script->getRelativePath()."/".$script->getFilename();
            }

        }

        return $list;
    }

    /**
     * Localiza e monta os submódulos de um módulo.
     *
     * @param $path
     *
     * @return array
     */
    private function findSubmodules($path)
    {
        $finder = new Finder();
        $fs = new Filesystem();
        $modulesDir = array();

        foreach ($finder->in($path)->directories()->depth('==0') as $directory) {
            $dir = $directory->getPath()."/".$directory->getFilename();


            if ($fs->exists($dir."/main.js")) {
                $this->moduleList[$dir] =  $this->findSubmodules($dir);
                $modulesDir[] = $directory->getFilename();
            }
        }

        return $modulesDir;
    }

    /**
     * Monta os arquivos scripts de um determinado módulo que serão carregados.
     *
     * @param $dir
     */
    private function mountModuleScripts($module, $paramsKey)
    {
        $finder = new Finder();

        $scripts = array();
        $dir = $module.'/';
        $files = $finder->files()->in($dir)->name('*.js');

        $main = '';
        $scripts[$paramsKey][] = &$main;

        foreach ($files as $file){
            if ($file->getFilename() == 'main.js'){
                $main = $file->getRelativePath().$file->getFilename();
            } else {
                $scripts[$paramsKey][] = $file->getRelativePath()."/".$file->getFilename();
            }
        }

        return $scripts;

    }

    /**
     * Retorna a lista de scripts para bibliotecas vendors.
     *
     * @param array $def
     *
     * @return array
     */
    private function buildVendorsList($def)
    {
        $scriptList = array();

        foreach ($def as $lib => $cfg){
            $this->mountDeps($lib, $cfg, $def, $scriptList);
        }

        return $scriptList;
    }

    /**
     * Monta a relação de dependências de scripts.
     *
     * @param string $lib
     * @param array  $cfg
     * @param array $def
     */
    private function mountDeps($lib, $cfg, $def, &$scriptList)
    {
        if (empty($cfg['deps'])) {
            $scriptList['vendor'][$lib] = $cfg['path'];
        } else {
            foreach ($cfg['deps'] as $dep) {
                if (isset($this->scripts[$dep])){
                    $scriptList['vendor'][$lib] = $cfg['path'];
                } else {
                    foreach ($cfg['deps'] as $dep){
                        $this->mountDeps($dep, $def[$dep], $def, $scriptList);
                    }
                    $scriptList['vendor'][$lib] = $cfg['path'];
                }
            }
        }
    }
}