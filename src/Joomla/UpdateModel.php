<?php

/**
 * @package    plg_system_extensiontools
 * @subpackage  com_installer
 *
 * @copyright 2024 Bram Brambring (https://brambring.nl)
 * @license   GNU General Public License version 3 or later;
 *
 * This clone as the install function without setUserState
 */

namespace Brambring\Plugin\System\Extensiontools\Joomla;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Updater\Update;
use Joomla\CMS\Updater\Updater;
use Joomla\Component\Installer\Administrator\Model\UpdateModel as JoomlaUpdateModel;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Installer Update Model
 *
 * @since  1.6
 */
class UpdateModel extends JoomlaUpdateModel
{
    /**
     * Update function.
     *
     * Sets the "result" state with the result of the operation.
     *
     * @param   int[]  $uids              List of updates to apply
     * @param   int    $minimumStability  The minimum allowed stability for installed updates {@see Updater}
     *
     * @return  void
     *
     * @since   1.6
     */
    public function update($uids, $minimumStability = Updater::STABILITY_STABLE)
    {
        $result = true;

        foreach ($uids as $uid) {
            $update   = new Update();
            $instance = new \Joomla\CMS\Table\Update($this->getDatabase());

            if (!$instance->load($uid)) {
                // Update no longer available, maybe already updated by a package.
                continue;
            }

            $app   = Factory::getApplication();
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select('type')
                ->from('#__update_sites')
                ->where($db->quoteName('update_site_id') . ' = :id')
                ->bind(':id', $instance->update_site_id, ParameterType::INTEGER);

            $updateSiteType = (string) $db->setQuery($query)->loadResult();

            // TUF is currently only supported for Joomla core
            if ($updateSiteType === 'tuf') {
                $app->enqueueMessage(Text::_('JLIB_INSTALLER_TUF_NOT_AVAILABLE'), 'error');

                return;
            }

            $update->loadFromXml($instance->detailsurl, $minimumStability);

            // Find and use extra_query from update_site if available
            $updateSiteInstance = new \Joomla\CMS\Table\UpdateSite($this->getDatabase());
            $updateSiteInstance->load($instance->update_site_id);

            if ($updateSiteInstance->extra_query) {
                $update->set('extra_query', $updateSiteInstance->extra_query);
            }

            $this->preparePreUpdate($update, $instance);

            // Install sets state and enqueues messages
            $res = $this->install($update);

            if ($res) {
                $instance->delete($uid);
            }

            $result = $res & $result;
        }

        // Clear the cached extension data and menu cache
        $this->cleanCache('_system');
        $this->cleanCache('com_modules');
        $this->cleanCache('com_plugins');
        $this->cleanCache('mod_menu');

        // Set the final state
        $this->setState('result', $result);
    }

    /**
     * Handles the actual update installation.
     *
     * @param   Update  $update  An update definition
     *
     * @return  boolean   Result of install
     *
     * @since   1.6
     */
    private function install($update)
    {
        // Load overrides plugin.
        PluginHelper::importPlugin('installer');

        $app = Factory::getApplication();

        if (!isset($update->get('downloadurl')->_data)) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_INSTALLER_INVALID_EXTENSION_UPDATE'), 'error');

            return false;
        }

        $url     = trim($update->downloadurl->_data);
        $sources = $update->get('downloadSources', []);

        if ($extra_query = $update->get('extra_query')) {
            $url .= (strpos($url, '?') === false) ? '?' : '&amp;';
            $url .= $extra_query;
        }

        $mirror = 0;

        while (!($p_file = InstallerHelper::downloadPackage($url)) && isset($sources[$mirror])) {
            $name = $sources[$mirror];
            $url  = trim($name->url);

            if ($extra_query) {
                $url .= (strpos($url, '?') === false) ? '?' : '&amp;';
                $url .= $extra_query;
            }

            $mirror++;
        }

        // Was the package downloaded?
        if (!$p_file) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('COM_INSTALLER_PACKAGE_DOWNLOAD_FAILED', $url), 'error');

            return false;
        }

        $config   = $app->getConfig();
        $tmp_dest = $config->get('tmp_path');

        // Unpack the downloaded package file
        $package = InstallerHelper::unpack($tmp_dest . '/' . $p_file);

        if (empty($package)) {
            $app->enqueueMessage(Text::sprintf('COM_INSTALLER_UNPACK_ERROR', $p_file), 'error');

            return false;
        }

        // Get an installer instance
        $installer = Installer::getInstance();
        $update->set('type', $package['type']);

        // Check the package
        $check = InstallerHelper::isChecksumValid($package['packagefile'], $update);

        if ($check === InstallerHelper::HASH_NOT_VALIDATED) {
            $app->enqueueMessage(Text::_('COM_INSTALLER_INSTALL_CHECKSUM_WRONG'), 'error');

            return false;
        }

        if ($check === InstallerHelper::HASH_NOT_PROVIDED) {
            $app->enqueueMessage(Text::_('COM_INSTALLER_INSTALL_CHECKSUM_WARNING'), 'warning');
        }

        // Install the package
        if (!$installer->update($package['dir'])) {
            // There was an error updating the package
            $app->enqueueMessage(
                Text::sprintf(
                    'COM_INSTALLER_MSG_UPDATE_ERROR',
                    Text::_('COM_INSTALLER_TYPE_TYPE_' . strtoupper($package['type']))
                ),
                'error'
            );
            $result = false;
        } else {
            // Package updated successfully
            $app->enqueueMessage(
                Text::sprintf(
                    'COM_INSTALLER_MSG_UPDATE_SUCCESS',
                    Text::_('COM_INSTALLER_TYPE_TYPE_' . strtoupper($package['type']))
                ),
                'success'
            );
            $result = true;
        }

        // Quick change
        $this->type = $package['type'];

        $this->setState('name', $installer->get('name'));
        $this->setState('result', $result);


        // Cleanup the install files
        if (!is_file($package['packagefile'])) {
            $package['packagefile'] = $config->get('tmp_path') . '/' . $package['packagefile'];
        }

        InstallerHelper::cleanupInstall($package['packagefile'], $package['extractdir']);

        return $result;
    }
}
