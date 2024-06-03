<?php

/**
 * @package     Brambring.Plugin
 * @subpackage  System.Extensiontools
 * @version    24.02.01
 * @copyright  2024 Bram Brambring
 * @license    GNU General Public License version 3 or later;
 */

namespace Brambring\Plugin\System\Extensiontools\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Brambring\Plugin\System\Extensiontools\Console\ExtensionUpdateCommand;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 *  SefPlus Plugin.
 *
 * @since  1.5
 */
final class PluginActor extends CMSPlugin  implements SubscriberInterface
{
    use DatabaseAwareTrait;
    protected $allowLegacyListeners = false;

    public static function getSubscribedEvents(): array
    {

        $events = [
            \Joomla\Application\ApplicationEvents::BEFORE_EXECUTE => 'registerCommands',

        ];

        return $events;
    }

    public function registerCommands($event): void
    {
        $app = $event->getApplication();
        //     $app->addCommand(new CheckCommand());
        $app->addCommand(new ExtensionUpdateCommand());
    }

    public function getNonCoreExtensionsWithUpdateSite()
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery();

        $query->select(
            [
                $db->quoteName('ex.name'),
                $db->quoteName('ex.extension_id'),
                $db->quoteName('ex.manifest_cache'),
                $db->quoteName('ex.type'),
                $db->quoteName('ex.folder'),
                $db->quoteName('ex.element'),
                $db->quoteName('ex.client_id'),
            ]
        )
            ->from($db->quoteName('#__extensions', 'ex'))
            ->where($db->quoteName('ex.package_id') . ' = 0')

            ->join(
                'INNER',
                $db->quoteName('#__update_sites_extensions', 'ue'),
                $db->quoteName('ue.extension_id') . ' = ' . $db->quoteName('ex.extension_id')
            )
            ->join(
                'INNER',
                $db->quoteName('#__update_sites', 'us'),
                $db->quoteName('ue.update_site_id') . ' = ' . $db->quoteName('us.update_site_id')
            )


            ->whereNotIn($db->quoteName('ex.extension_id'), ExtensionHelper::getCoreExtensionIds());

        $db->setQuery($query);
        $rows = $db->loadObjectList();

        foreach ($rows as $extension) {
            $decode = json_decode($extension->manifest_cache);

            // Remove unused fields so they do not cause javascript errors during pre-update check
            unset($decode->description);
            unset($decode->copyright);
            unset($decode->creationDate);

            $this->translateExtensionName($extension);
            $extension->version
                = $decode->version ?? Text::_('COM_JOOMLAUPDATE_PREUPDATE_UNKNOWN_EXTENSION_MANIFESTCACHE_VERSION');
            unset($extension->manifest_cache);
            $extension->manifest_cache = $decode;
        }

        return $rows;
    }


    protected function translateExtensionName(&$item)
    {
        // @todo: Cleanup duplicated code. from com_installer/src/Model/InstallerModel.php
        $lang = Factory::getLanguage();
        $path = $item->client_id ? JPATH_ADMINISTRATOR : JPATH_SITE;

        $extension = $item->element;
        $source    = JPATH_SITE;

        switch ($item->type) {
            case 'component':
                $extension = $item->element;
                $source    = $path . '/components/' . $extension;
                break;
            case 'module':
                $extension = $item->element;
                $source    = $path . '/modules/' . $extension;
                break;
            case 'file':
                $extension = 'files_' . $item->element;
                break;
            case 'library':
                $extension = 'lib_' . $item->element;
                break;
            case 'plugin':
                $extension = 'plg_' . $item->folder . '_' . $item->element;
                $source    = JPATH_PLUGINS . '/' . $item->folder . '/' . $item->element;
                break;
            case 'template':
                $extension = 'tpl_' . $item->element;
                $source    = $path . '/templates/' . $item->element;
        }

        $lang->load("$extension.sys", JPATH_ADMINISTRATOR)
            || $lang->load("$extension.sys", $source);
        $lang->load($extension, JPATH_ADMINISTRATOR)
            || $lang->load($extension, $source);

        // Translate the extension name if possible
        $item->name = strip_tags(Text::_($item->name));
    }
}
