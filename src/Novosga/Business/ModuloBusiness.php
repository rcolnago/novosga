<?php
namespace Novosga\Business;

use Exception;
use Novosga\Context;
use Novosga\Model\Modulo;
use Novosga\Model\Util\ModuleManifest;
use Novosga\Util\FileUtils;
use ZipArchive;

/**
 * ModuloBusiness
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class ModuloBusiness extends ModelBusiness {
    
    /**
     * @var Context
     */
    private $context;
    
    function __construct(Context $context) {
        parent::__construct($context->database()->createEntityManager());
        $this->context = $context;
    }
    
    /**
     * 
     * @param string $moduleDir
     * @return Modulo
     */
    public function install($moduleDir, $name) {
        $this->verify($moduleDir);
        $manifest = $this->parseManifest($moduleDir, $name);
        
        $this->invokeScripts($manifest, 'pre-install');
        
        $this->em->persist($manifest->getModule());
        $this->em->flush();
        
        $this->invokeScripts($manifest, 'post-install');
        
        return $manifest->getModule();
    }
    
    /**
     * @param string $zipname
     * @param string $ext
     * @return Modulo
     */
    public function extractAndInstall($zipname, $ext = 'zip') {
        $moduleDir = $this->extract($zipname, $ext);
        return $this->install($moduleDir, $this->key($zipname, $ext));
    }
    
    /**
     * 
     * @param string $key
     * @throws Exception
     */
    public function uninstall($key) {
        $module = $this->em->createQuery('SELECT e FROM NovoSGA\Model\Modulo e WHERE e.chave = :key')
                ->setParameter('key', $key)
                ->getOneOrNullResult();
        if (!$module) {
            throw new Exception(sprintf(_('Módulo %s não instalado'), $key));
        }
        $this->em->remove($module);
        $this->em->flush();
        
        $manifest = $this->parseManifest($module->getRealPath(), $module->getChave());
        $this->invokeScripts($manifest, 'post-remove');
        
        FileUtils::rmdir($module->getRealPath());
    }
    
    /**
     * 
     * @param string $zipname
     * @param string $ext
     * @return string
     */
    public function key($zipname, $ext = 'zip') {
        return str_replace(".$ext", "", basename($zipname));
    }
    
    /**
     * 
     * @param string $zipname
     * @param string $ext file extension
     * @return string The module dir 
     * @throws Exception
     */
    public function extract($zipname, $ext) {
        $name = $this->key($zipname, $ext);
        $path = explode(".", $name);

        if (sizeof($path) !== 2) {
            FileUtils::rm($zipname);
            throw new Exception(sprintf(_('Formato inválido do nome do módulo: %s. Era esperado {vendorName}.{moduleName}.%s'), basename($zipname), $ext));
        }

        $dir = MODULES_PATH . DS . $path[0];
        $moduleDir = $dir . DS . $path[1];

        if (file_exists($moduleDir)) {
            throw new Exception(_('Já possui um módulo com o mesmo nome'));
        }

        // zip extract
        $zip = new ZipArchive(); 
        $zip->open($zipname);
        $zip->extractTo(NOVOSGA_CACHE);
        $zip->close();
        
        FileUtils::rm($zipname);

        // vendor dir
        if (!is_dir($dir) && !@mkdir($dir)) {
            FileUtils::rm(NOVOSGA_CACHE . DS . $name);
            throw new Exception(_('Não foi possível criar o diretório do módulo'));
        }

        if (!@rename(NOVOSGA_CACHE . DS . $name, $moduleDir)) {
            FileUtils::rm(NOVOSGA_CACHE . DS . $name);
            throw new Exception(_('Não foi possível mover os arquivos para o diretório dos módulos'));
        }
        FileUtils::rm(NOVOSGA_CACHE . DS . $name);
        return $moduleDir;
    }
    
    /**
     * Verifica se o módulo possui os arquivos necessários
     * @param string $moduleDir
     * @throws Exception
     */
    public function verify($moduleDir) {
        $moduleName = basename($moduleDir);
        // module structure
        $files = array(
            "manifest.json",
            ucfirst($moduleName) . "Controller.php",
            "public" . DS . "images" . DS . "icon.png",
            "public" . DS . "css" . DS . "style.css",
            "public" . DS . "js" . DS . "script.js",
            "views" . DS . "index.html.twig",
        );
        foreach ($files as $file) {
            if (!file_exists($moduleDir . DS . $file)) {
                throw new Exception(sprintf(_('Arquivo %s não encontrado'), $file));
            }
        }
    }
    
    /**
     * 
     * @param type $moduleDir
     * @param type $key
     * @return ModuleManifest
     * @throws Exception
     */
    public function parseManifest($moduleDir, $key) {
        $data = file_get_contents($moduleDir . DS . "manifest.json");
        $json = json_decode($data, true);
        if (!$json) {
            throw new Exception(_('O Manifest não contém um JSON válido'));
        }
        return new ModuleManifest($key, $json);
    }
    
    private function invokeScripts(ModuleManifest $manifest, $name) {
        $scripts = $manifest->getScript($name);
        if (is_array($scripts) && sizeof($scripts)) {
            foreach ($scripts as $script) {
                $tokens = explode("::", $script);
                if (sizeof($tokens) !== 2) {
                    throw new Exception(_('Formato do nome do script inválido. Era experado <ClassName>::<methodName>.'));
                }
                $namespace = "modules\\" . join("\\", explode(".", $manifest->getModule()->getChave()));
                $className = "$namespace\\" . ucfirst($tokens[0]);

                $obj = new $className;
                $method = new \ReflectionMethod($obj, $tokens[1]);
                $method->invokeArgs($obj, array($this->context));
            }
        }
    }
    
}