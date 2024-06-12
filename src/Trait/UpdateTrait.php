<?php

/**
 * Based On libraries/src/Console/ExtensionInstallCommand.php
 * from the Joomla! Content Management System
 * (C) 2020 Open Source Matters, Inc. <https://www.joomla.org>
 * GNU General Public License version 2 or later; see LICENSE.txt
 *
 * @version   5.1
 * @author    Bram <bram@brokenlinkchecker.dev>
 * @copyright 2024 Bram Brambring (https://brambring.nl)
 * @license   GNU General Public License version 3 or later;
 */

namespace Brambring\Plugin\System\Extensiontools\Trait;

use Joomla\CMS\Extension\ExtensionHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Console command for installing extensions
 *
 * @since  4.0.0
 */
trait UpdateTrait
{
    private ?array $updateList = null;
    private array $successInfo      = [];
    private array $failInfo         = [];
    private array $skipInfo         = [];
    private ?array $allowedExtension = null;

    private function isValidUpdate(string $field, int $value): \stdClass|bool
    {

        $this->getUpdates();
        foreach ($this->updateList as $update) {
            if ($update->{$field} == $value) {
                if ($this->isJoomlaCore($update->extension_id)) {
                    $this->ioStyle->error('Use core:update to update Joomla! Core');
                    return false;
                }

                return $update;
            }
        }
        return false;
    }

    private function isJoomlaCore(string $eid): bool
    {
        // $coreEid = ExtensionHelper::getExtensionRecord('joomla', 'file')->extension_id;
        //retrun $coreEid == $eid
        $coreExtensionIds = ExtensionHelper::getCoreExtensionIds();
        return  \in_array($eid, $coreExtensionIds);
    }


    private function isAllowedToUpdate(object $update): bool
    {

        if ($this->allowedExtension === null) {
            $this->allowedExtension['all']   = $this->params->get('allowedAll', []);
            $this->allowedExtension['minor'] = $this->params->get('allowedMinor', []);
            $this->allowedExtension['patch'] = $this->params->get('allowedPatch', []);
            if (
                \count($this->allowedExtension['all']) == 0
                &&
                \count($this->allowedExtension['minor']) == 0
                &&
                \count($this->allowedExtension['patch']) == 0
            ) {
                $this->getApplication()->enqueueMessage('No extensions allowed for automatic updates', 'warning');
                return false;
            }
        }

        if (\in_array($update->extension_id, $this->allowedExtension['all'])) {
            return true;
        }

        $currentVersion = explode('.', $update->current_version);
        $newVersion     = explode('.', $update->version);
        //count may differ 2.0.0 -> 2.0.0.1
        if (
            \count($currentVersion) == 1
            || \count($newVersion) == 1
        ) {
            //no minor version

            return false;
        }
        if ($currentVersion[0] != $newVersion[0]) {
            return false;
        }


        if (\in_array($update->extension_id, $this->allowedExtension['minor'])) {
            return true;
        }

        if ($currentVersion[1] != $newVersion[1]) {
            return false;
        }


        if (
            \count($currentVersion) < 3
            || \count($newVersion) < 3
        ) {
            //no patch level

            return false;
        }

        if (\in_array($update->extension_id, $this->allowedExtension['patch'])) {
            return true;
        }

        return false;
    }
    private function getAllowedUpdates(): array
    {
        $this->getUpdates(true);
        $uids = [];
        foreach ($this->updateList as $update) {
            //Joomla core should not show up here. Just to be sure
            if ($this->isJoomlaCore($update->extension_id)) {
                $this->skipInfo[] = $this->updateToRow($update);
                continue;
            }

            if (!$this->isAllowedToUpdate($update)) {
                $this->skipInfo[] = $this->updateToRow($update);
                continue;
            }
            $uids[$update->update_id] =   $update;
        }
        return $uids;
    }

    private function getUpdates($purge = false): array | null
    {
        if ($this->updateList === null) {
            $this->getApplication()->getLanguage()->load('com_installer', JPATH_ADMINISTRATOR, null, true, true);
            // Find updates.
            /** @var UpdateModel $model */
            $model = $this->getApplication()->bootComponent('com_installer')
                ->getMVCFactory()->createModel('Update', 'Administrator', ['ignore_request' => true]);

            //here we assume that the list of updates is fresh
            //since most likely a update:extensions:check or extensiontools:check has been executed first.
            if ($purge) {
                $model->purge();
            }
            $model->findUpdates();
            $this->updateList = $model->getItems();
        }
        return  $this->updateList;
    }

    private function updateToRow($update): array
    {
        return    [
            $update->extension_id,
            $update->name,
            $update->client_translated,
            $update->type,
            $update->current_version,
            $update->version,
            $update->folder_translated,
        ];
    }
}
