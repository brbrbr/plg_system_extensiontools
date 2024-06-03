<?php

/**
 * @package     Brambring.Plugin
 * @subpackage  System.Extensiontools
 * @version    24.02.01
 * @copyright  2024 Bram Brambring
 * @license    GNU General Public License version 3 or later;
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Brambring\Plugin\System\Extensiontools\Extension\PluginActor;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Factory;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   4.4.0
     */
    public function register(Container $container): void
    {
	  
        $container->set(
            PluginInterface::class,
            function (Container $container) {

                $plugin     = new PluginActor (
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'extensiontools')
                );
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));
                return $plugin;
            }
        );
    }
};
