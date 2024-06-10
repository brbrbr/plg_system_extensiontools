<?php

/**
 * @package  System.Extensiontools
 * @version    24.51
 * @copyright  2024 Bram Brambring
 * @license    GNU General Public License version 3 or later;
 */

namespace Brambring\Plugin\System\Extensiontools\Extension;

use Blc\Component\Blc\Administrator\Field\InfoField;
use Brambring\Plugin\System\Extensiontools\Console\ExtensionUpdateCommand;
use Brambring\Plugin\System\Extensiontools\Table\Transient;
use Brambring\Plugin\System\Extensiontools\Trait\UpdateTrait;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\Exception\MailDisabledException;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\Updater\Updater;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;
use PHPMailer\PHPMailer\Exception as phpMailerException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 *  SefPlus Plugin.
 *
 * @since  1.5
 */
final class PluginActor extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use TaskPluginTrait;
    use UpdateTrait;

    protected $allowLegacyListeners = false;
    /**
     * @var string[]
     * @since 1.0.0
     */
    private const TASKS_MAP = [
        'update.extensions' => [
            'langConstPrefix' => 'PLG_SYSTEM_EXTENSIONTOOLS_SEND',
            'method'          => 'checkExtensionUpdates',
            'form'            => 'sendForm',
        ],
        'update.allextensions' => [
            'langConstPrefix' => 'PLG_SYSTEM_EXTENSIONTOOLS_ALL',
            'method'          => 'updateAllExtensions',
            'form'            => 'updateForm',
        ],
    ];
    private $NonCoreExtensionsWithUpdateSite;

    /**
     * @var boolean
     * @since 1.0.0
     */
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        $events = [
            \Joomla\Application\ApplicationEvents::BEFORE_EXECUTE => 'registerCommands',
            'onTaskOptionsList'                                   => 'advertiseRoutines',
            'onExecuteTask'                                       => 'standardRoutineHandler',
            'onContentPrepareForm'                                => 'enhanceTaskItemForm',
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
        if ($this->NonCoreExtensionsWithUpdateSite === null) {
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
                    'LEFT',
                    $db->quoteName('#__update_sites_extensions', 'ue'),
                    $db->quoteName('ue.extension_id') . ' = ' . $db->quoteName('ex.extension_id')
                )
                ->join(
                    'LEFT',
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
            $this->NonCoreExtensionsWithUpdateSite = $rows;
        }

        return $this->NonCoreExtensionsWithUpdateSite;
    }

    //need a copy since the function is protected in
    protected function translateExtensionName(&$item)
    {
        // @todo: Cleanup duplicated code. from com_installer/src/Model/InstallerModel.php
        $lang = Factory::getApplication()->getLanguage();
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

    /**
     * Method to get the updates.
     * From update:extensions:check
     *
     * @return array  List of updates
     *
     * @since  1.0.8
     * @throws \Exception
     */


    private function getExtensionsWithUpdate($core = false)
    {
        // Find updates.
        /** @var UpdateModel $model */
        $model = $this->getApplication()->bootComponent('com_installer')
            ->getMVCFactory()->createModel('Update', 'Administrator', ['ignore_request' => true]);

        // Purge the table before checking
        // $model->purge();
        if ($core) {
            $coreEid = ExtensionHelper::getExtensionRecord('joomla', 'file')->extension_id;
            $model->setState('filter.extension_id', $coreEid);
        } else {
            $model->setState('filter.extension_id', null);
        }

        $model->findUpdates();

        return $model->getItems();
    }


    private function updateAllExtensions(ExecuteTaskEvent $event): int
    {
        $updates = $this->getAllowedUpdates();
        if (\count($updates) > 0) {

            $app = $this->getApplication();
            //  $app->getInput()->set('ignoreMessages',false);
            $mvcFactory        = $app->bootComponent('com_installer')->getMVCFactory();
            $model             = $mvcFactory->createModel('update', 'administrator', ['ignore_request' => true]);
            $minimum_stability = ComponentHelper::getComponent('com_installer')->getParams()->get('minimum_stability', Updater::STABILITY_STABLE);
            $model->update(array_keys($updates), $minimum_stability);


            // Load the parameters.
            $params     = $event->getArgument('params');
            $recipients = ArrayHelper::fromObject($params->recipients ?? [], false);

            $specificIds = array_map(function ($item) {
                return $item->user;
            }, $recipients);
            $forcedLanguage = $params->language_override ?? '';
            /*
           * Load the appropriate language. We try to load English (UK), the current user's language and the forced
           * language preference, in this order. This ensures that we'll never end up with untranslated strings in the
           * update email which would make Joomla! seem bad. So, please, if you don't fully understand what the
           * following code does DO NOT TOUCH IT. It makes the difference between a hobbyist CMS and a professional
           * solution!
           */
            $jLanguage = $this->getApplication()->getLanguage();
            $jLanguage->load('lib_joomla', JPATH_ADMINISTRATOR, 'en-GB', true, true);
            $jLanguage->load('lib_joomla', JPATH_ADMINISTRATOR, null, true, true);
            $jLanguage->load('com_installer', JPATH_ADMINISTRATOR, 'en-GB', true, true);
            $jLanguage->load('com_installer', JPATH_ADMINISTRATOR, null, true, true);
            $jLanguage->load('PLG_SYSTEM_EXTENSIONTOOLS', JPATH_ADMINISTRATOR, 'en-GB', true, true);
            $jLanguage->load('PLG_SYSTEM_EXTENSIONTOOLS', JPATH_ADMINISTRATOR, null, true, false);

            // Then try loading the preferred (forced) language
            if (!empty($forcedLanguage)) {
                $jLanguage->load('lib_joomla', JPATH_ADMINISTRATOR, $forcedLanguage, true, false);
                $jLanguage->load('com_installer', JPATH_ADMINISTRATOR, $forcedLanguage, true, false);
                $jLanguage->load('PLG_SYSTEM_EXTENSIONTOOLS', JPATH_ADMINISTRATOR, $forcedLanguage, true, false);
            }
            $superUsers = [];

            if (!empty($specificIds)) {
                $superUsers = $this->getSuperUsers($specificIds);
            }

            if (empty($superUsers)) {
                $superUsers = $this->getSuperUsers();
            }


            if (\is_callable([$app, 'getMessageQueue'])) {
                $messages = $app->getMessageQueue();
            }


            if (empty($superUsers)) {
                $this->logTask('No recipients found','warning');
                return Status::OK;
            }

            $baseSubstitutions = [
                'sitename' => $this->getApplication()->get('sitename'),

            ];

            $body    = [$this->replaceTags(Text::plural('PLG_SYSTEM_EXTENSIONTOOLS_AUTOUPDATE_MAIL_HEADER', \count($updates)), $baseSubstitutions) . "\n\n"];
            $subject = $this->replaceTags(Text::plural('PLG_SYSTEM_EXTENSIONTOOLS_AUTOUPDATE_MAIL_SUBJECT', \count($updates)), $baseSubstitutions);

            foreach ($updates as $updateValue) {
                // Replace merge codes with their values
                $extensionSubstitutions = [
                    'newversion'    => $updateValue->version,
                    'curversion'    => $updateValue->current_version,
                    'extensiontype' => $updateValue->type,
                    'extensionname' => $updateValue->name,
                ];

                $body[] = $this->replaceTags(Text::_('PLG_SYSTEM_EXTENSIONTOOLS_AUTOUPDATE_MAIL_SINGLE'), $extensionSubstitutions) . "\n";
            }

            $messages = $app->getMessageQueue();
            $lists    = [];

            // Build the sorted messages list
            if (\is_array($messages) && \count($messages)) {
                foreach ($messages as $message) {
                    if (isset($message['type']) && isset($message['message'])) {
                        $lists[$message['type']][] = $message['message'];
                    }
                }
            }

            // If messages exist add them to the output
            if (\count($lists)) {
                foreach ($lists as $type => $messages) {
                    $body[] = "\n$type:\n";
                    $body[] = join("\n", $messages);
                }
            }
            $body = join("\n", $body);
            // Send the emails to the Super Users
            try {
                $mail = clone Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
                foreach ($superUsers as $superUser) {
                    $mail->addBcc($superUser->email, $superUser->name);
                }
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
            } catch (MailDisabledException | phpMailerException $exception) {
                try {
                    $this->logTask($jLanguage->_($exception->getMessage()),'error');
                } catch (\RuntimeException $exception) {
                    return Status::KNOCKOUT;
                }
            }
        }
        return Status::OK;
    }
    /**
     * Method to send the update notification.
     *
     * @param   ExecuteTaskEvent  $event  The `onExecuteTask` event.
     *
     * @return integer  The routine exit code.
     *
     * @since  1.0.0
     * @throws \Exception
     */
    private function checkExtensionUpdates(ExecuteTaskEvent $event): int
    {

        $this->logTask('check Extension Updates start','info');

        // Load the parameters.
        $params      = $event->getArgument('params');
        $recipients  = ArrayHelper::fromObject($params->recipients ?? [], false);
        $sendOnce    = (bool)($params->send_once ?? true);
        $specificIds = array_map(function ($item) {
            return $item->user;
        }, $recipients);
        $forcedLanguage = $params->language_override ?? '';
        /*
         * Load the appropriate language. We try to load English (UK), the current user's language and the forced
         * language preference, in this order. This ensures that we'll never end up with untranslated strings in the
         * update email which would make Joomla! seem bad. So, please, if you don't fully understand what the
         * following code does DO NOT TOUCH IT. It makes the difference between a hobbyist CMS and a professional
         * solution!
         */
        $jLanguage = $this->getApplication()->getLanguage();
        $jLanguage->load('lib_joomla', JPATH_ADMINISTRATOR, 'en-GB', true, true);
        $jLanguage->load('lib_joomla', JPATH_ADMINISTRATOR, null, true, true);
        $jLanguage->load('PLG_SYSTEM_EXTENSIONTOOLS', JPATH_ADMINISTRATOR, 'en-GB', true, true);
        $jLanguage->load('PLG_SYSTEM_EXTENSIONTOOLS', JPATH_ADMINISTRATOR, null, true, false);

        // Then try loading the preferred (forced) language
        if (!empty($forcedLanguage)) {
            $jLanguage->load('lib_joomla', JPATH_ADMINISTRATOR, $forcedLanguage, true, false);
            $jLanguage->load('PLG_SYSTEM_EXTENSIONTOOLS', JPATH_ADMINISTRATOR, $forcedLanguage, true, false);
        }


        $extensionUpdates = $this->getExtensionsWithUpdate();
        $coreUpdates      = $this->getExtensionsWithUpdate(true);
        $allUpdates       = array_merge($coreUpdates, $extensionUpdates);

        if (\count($allUpdates) == 0) {
            $this->logTask('No Updates found','info');
            return Status::OK;
        }

        $baseURL = Route::link('administrator', 'index.php?option=com_cpanel&view=cpanel&dashboard=system', xhtml: false, absolute: true);

        //TODO
        /**
         * Some third party security solutions require a secret query parameter to allow log in to the administrator
         * backend of the site. The link generated above will be invalid and could probably block the user out of their
         * site, confusing them (they can't understand the third party security solution is not part of Joomla! proper).
         * So, we're calling the onBuildAdministratorLoginURL system plugin event to let these third party solutions
         * add any necessary secret query parameters to the URL. The plugins are supposed to have a method with the
         * signature:
         *
         * public function onBuildAdministratorLoginURL(Uri &$uri);
         *
         * The plugins should modify the $uri object directly and return null.
         */
        //really depricated code in a new plugin
        // $this->getApplication()->triggerEvent('onBuildAdministratorLoginURL', [&$uri]);

        // Let's find out the email addresses to notify
        $superUsers = [];

        if (!empty($specificIds)) {
            $superUsers = $this->getSuperUsers($specificIds);
        }

        if (empty($superUsers)) {
            $superUsers = $this->getSuperUsers();
        }

        if (empty($superUsers)) {
            $this->logTask('No recipients found','error');
            return Status::KNOCKOUT;
        }




        $baseSubstitutions = [
            'sitename'   => $this->getApplication()->get('sitename'),
            'updatelink' => $baseURL,
        ];


        $body    = [$this->replaceTags(Text::plural('PLG_SYSTEM_EXTENSIONTOOLS_UPDATE_MAIL_HEADER', \count($allUpdates)), $baseSubstitutions) . "\n\n"];
        $subject = $this->replaceTags(Text::plural('PLG_SYSTEM_EXTENSIONTOOLS_UPDATE_MAIL_SUBJECT', \count($allUpdates)), $baseSubstitutions);

        foreach ($allUpdates as $updateValue) {
            // Replace merge codes with their values
            $extensionSubstitutions = [
                'newversion'    => $updateValue->version,
                'curversion'    => $updateValue->current_version,
                'extensiontype' => $updateValue->type,
                'extensionname' => $updateValue->name,
            ];

            $body[] = $this->replaceTags(Text::_('PLG_SYSTEM_EXTENSIONTOOLS_UPDATE_MAIL_SINGLE'), $extensionSubstitutions) . "\n";
        }

        $body[] = $this->replaceTags(Text::_('PLG_SYSTEM_EXTENSIONTOOLS_UPDATE_MAIL_FOOTER'), $baseSubstitutions);

        $body = join("\n", $body);

        // Send the emails to the Super Users

        try {
            $mail             = clone Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $transientManager = new Transient($this->getDatabase(), $this->getDispatcher());

            $transientData = [
                'body'    => $body,
                'subject' => $subject,
            ];
            $sha1 = $transientManager->getSha1($transientData);

            $hasRecipient = false;
            foreach ($superUsers as $superUser) {
                $itemId = 'ExtensionTools.email.' . $superUser->id;
              
                if ($sendOnce === false || !$transientManager->getHashMatch($itemId, $sha1)) {
                    $hasRecipient = true;
                    $mail->addBcc($superUser->email, $superUser->name);
                    $transientManager->bind([
                        'sha1_hash'      => $sha1,
                        'item_id'        => $itemId,
                        'editor_user_id' => $superUser->id,
                    ]);
                    $transientManager->storeTransient($transientData, 'transient');
                    $transientManager->deleteOldVersions(1);
                
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
                $this->logTask($jLanguage->_($exception->getMessage()),'error');
            } catch (\RuntimeException $exception) {
                return Status::KNOCKOUT;
            }
        }


        $this->logTask('check Extension Updates end','info');

        return Status::OK;
    }

    /**
     * Method to replace tags like in MailTemplate
     *
     * @param   string  $text  The `language string`.
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
            $query = $db->createQuery()
                ->select($db->quoteName(['id', 'name', 'email']))
                ->from($db->quoteName('#__users', 'u'))
                ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm'), '`u`.`id` = `m`.`user_id`')
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
}
