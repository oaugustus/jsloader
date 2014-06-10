<?php
namespace JsLoader\Provider;


use JsLoader\Loader;
use Silex\Application;
use Silex\ServiceProviderInterface;

class JsLoaderServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        $app['jsloader'] = $app->share(function() use ($app){
            $keys = $app->keys();
            $defs = array();

            foreach ($keys as $key){
                if (strpos($key,'jsloader.') !== false){
                    $defs[$key] = $app[$key];
                }
            }

            if (!$app['js_dir']) {
                throw new \Exception('Não foi definido o diretório de scripts da aplicação ($app["js_dir"])!');
            }

            if (!$app['web_dir']) {
                throw new \Exception('Não foi definido o diretório web da aplicação ($app["web_dir"])!');
            }

            if (!$app['deploy_path']) {
                throw new \Exception('Não foi definido o caminho da geração dos builds da aplicação ($app["deploy_path"])!');
            }

            return new Loader($app['js_dir'], $app['web_dir'], $app['deploy_path'], $defs, $app['debug']);
        });
    }

    public function boot(Application $app)
    {

    }

}