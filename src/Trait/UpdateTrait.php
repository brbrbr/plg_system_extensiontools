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
use Brambring\Plugin\System\Extensiontools\Table\Transient;
use Joomla\CMS\Mail\Exception\MailDisabledException;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
use Joomla\Utilities\ArrayHelper;
use PHPMailer\PHPMailer\Exception as phpMailerException;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\Access\Access;
use Joomla\Database\ParameterType;
use Joomla\CMS\Factory;


// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Bit of abuse of traits
 *
 * @since  4.0.0
 */
trait UpdateTrait
{
    private ?array $updateList       = null;
    private array $successInfo       = [];
    private array $failInfo          = [];
    private array $skipInfo          = [];
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



    private function loadLanguages($forcedLanguage)
    {
        $jLanguage = $this->getApplication()->getLanguage();
        $jLanguage->load('lib_joomla', JPATH_ADMINISTRATOR, 'en-GB', true, true);
        $jLanguage->load('lib_joomla', JPATH_ADMINISTRATOR, null, true, true);
        $jLanguage->load('com_installer', JPATH_ADMINISTRATOR, 'en-GB', true, true);
        $jLanguage->load('com_installer', JPATH_ADMINISTRATOR, null, true, true);
        $jLanguage->load('plg_system_extensiontools', JPATH_ADMINISTRATOR, 'en-GB', true, true);
        $jLanguage->load('plg_system_extensiontools', JPATH_ADMINISTRATOR, null, true, false);

        // Then try loading the preferred (forced) language
        if (!empty($forcedLanguage)) {
            $jLanguage->load('lib_joomla', JPATH_ADMINISTRATOR, $forcedLanguage, true, false);
            $jLanguage->load('com_installer', JPATH_ADMINISTRATOR, $forcedLanguage, true, false);
            $jLanguage->load('plg_system_extensiontools', JPATH_ADMINISTRATOR, $forcedLanguage, true, false);
        }
    }

    private function sendMail($superUsers, $subject, $body, string | bool $sendOnce = false) : int
    {
        try {
            $mail             = clone Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $transientManager = new Transient($this->getDatabase());
            $transientData = [
                'body'    => $body,
                'subject' => $subject,
            ];
            $sha1 = $transientManager->getSha1($transientData);

            $hasRecipient = false;
            foreach ($superUsers as $superUser) {
                $itemId = 'ExtensionTools.' .($sendOnce?:'sendonce'). '-' . $superUser->id;
                if ($sendOnce === false || !$transientManager->getHashMatch($itemId, $sha1)) {
                    $hasRecipient = true;
                    $mail->addBcc($superUser->email, $superUser->name);
                  
                    if ($sendOnce !== false) {
                        $transientManager->bind([
                            'sha1_hash'      => $sha1,
                            'item_id'        => $itemId,
                            'editor_user_id' => $superUser->id,
                        ]);
                        $transientManager->storeTransient($transientData, 'transient');
                        $transientManager->deleteOldVersions(1);
                    }
                }
            }

            if ($hasRecipient) {
                $mailfrom =   $this->getApplication()->get('mailfrom');
                $fromname = $this->getApplication()->get('fromname');

                if (MailHelper::isEmailAddress($mailfrom)) {
                    $mail->setSender(MailHelper::cleanLine($mailfrom), MailHelper::cleanLine($fromname), false);
                }
                $mail->setBody($body);
                $mail->setSubject($subject);
                $mail->SMTPDebug   = false;
                $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                $mail->isHtml(false);
                $mail->send();
            }
        } catch (MailDisabledException | phpMailerException $exception) {
            try {
                $this->logTask($exception->getMessage(), 'error');
            } catch (\RuntimeException $exception) {
                return Status::KNOCKOUT;
            }
        }
        return Status::OK;
    }

    private function usersToEmail(object|array $recipients)
    {
        if (!\is_array($recipients)) {
            $recipients = ArrayHelper::fromObject($recipients, false);
        }
        $specificIds = array_map(function ($item) {
            return $item->user;
        }, $recipients);


        $superUsers = [];

        if (!empty($specificIds)) {
            $superUsers = $this->getSuperUsers($specificIds);
        }

        if (empty($superUsers)) {
            $superUsers = $this->getSuperUsers();
        }
        return $superUsers;
    }
      /**
     * Returns the Super Users email information. If you provide a comma separated $email list
     * we will check that these emails do belong to Super Users
     * this version overrides the sendemail parameter in the user settings
     *
     * @param   null|array  $userIds  A list of Super Users to email
     *
     * @return  array  The list of Super User emails
     *
     * @since   1.0.1
     */
    private function getSuperUsers(?array $userIds = null)
    {
        $db     = $this->getDatabase();

        // Get a list of groups which have Super User privileges
        $ret = [];

        try {
            $rootId    = (new Asset($db))->getRootId();
            $rules     = Access::getAssetRules($rootId)->getData();
            $rawGroups = $rules['core.admin']->getData();
            $groups    = [];

            if (empty($rawGroups)) {
                return $ret;
            }

            foreach ($rawGroups as $g => $enabled) {
                if ($enabled) {
                    $groups[] = $g;
                }
            }

            if (empty($groups)) {
                return $ret;
            }
        } catch (\Exception $exc) {
            return $ret;
        }


        // Get the user information for the Super Administrator users
        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'name', 'email']))
                ->from($db->quoteName('#__users', 'u'))
                ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm'), $db->quoteName('u.id') . ' = ' . $db->quoteName('m.user_id'))
                ->whereIn($db->quoteName('m.group_id'), $groups, ParameterType::INTEGER)
                ->where($db->quoteName('block') . ' = 0');

            if (!empty($userIds)) {
                $query->whereIn($db->quoteName('id'), $userIds, ParameterType::INTEGER);
            } else {
                $query->where($db->quoteName('sendEmail') . ' = 1');
            }

            $db->setQuery($query);
            $ret = $db->loadObjectList();
        } catch (\Exception $exc) {
            return $ret;
        }

        return $ret;
    }

        /**
     * Method to replace tags like in MailTemplate
     *
     * @param   string  $text  The 'language string'.
     * @param   array  $tags  key replacment pairs
     *
     * @return string  The text with replaces tags
     *
     * @since  1.0.1
     */

     protected function replaceTags(string $text, array $tags)
     {
         foreach ($tags as $key => $value) {
             // If the value is NULL, replace with an empty string. NULL itself throws notices
             if (\is_null($value)) {
                 $value = '';
             }
 
             if (\is_array($value)) {
                 $matches = [];
                 $pregKey = preg_quote(strtoupper($key), '/');
 
                 if (preg_match_all('/{' . $pregKey . '}(.*?){\/' . $pregKey . '}/s', $text, $matches)) {
                     foreach ($matches[0] as $i => $match) {
                         $replacement = '';
 
                         foreach ($value as $name => $subvalue) {
                             if (\is_array($subvalue) && $name == $matches[1][$i]) {
                                 $replacement .= implode("\n", $subvalue);
                             } elseif (\is_array($subvalue)) {
                                 $replacement .= $this->replaceTags($matches[1][$i], $subvalue);
                             } elseif (\is_string($subvalue) && $name == $matches[1][$i]) {
                                 $replacement .= $subvalue;
                             }
                         }
 
                         $text = str_replace($match, $replacement, $text);
                     }
                 }
             } else {
                 $text = str_replace('{' . strtoupper($key) . '}', $value, $text);
             }
         }
 
         return $text;
     }
}
