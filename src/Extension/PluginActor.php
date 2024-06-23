<?php

/**
 * @package    plg_system_extensiontools
 * @version    5.1
 * @copyright 2024 Bram Brambring (https://brambring.nl)
 * @license   GNU General Public License version 3 or later;
 */

namespace Brambring\Plugin\System\Extensiontools\Extension;

use Brambring\Plugin\System\Extensiontools\Console\ExtensionUpdateCommand;
use Brambring\Plugin\System\Extensiontools\Trait\UpdateTrait;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Updater\Updater;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\Path;
use Joomla\Utilities\ArrayHelper;
use Joomla\CMS\Log\Log;

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
            'onError'                                             => ['onError', Event\Priority::MAX],
            \Joomla\Application\ApplicationEvents::BEFORE_EXECUTE => 'registerCommands',
            'onTaskOptionsList'                                   => 'advertiseRoutines',
            'onExecuteTask'                                       => 'standardRoutineHandler',
            'onContentPrepareForm'                                => 'enhanceTaskItemForm',
        ];

        return $events;
    }

    public function onError(ErrorEvent $event)
    {

        //params is not aviaible in subscribe events
        if (!(bool)$this->params->get('emailonerror', false)) {
            return;
        }

        $error = $event->getError();
        if ($error instanceof RouteNotFoundException) {
            return;
        }

        $app       = $event->getApplication();
        if ($app->isClient('administrator') || ((int) $error->getCode() !== 404)) {
            return;
        }

        $recipients = ArrayHelper::fromObject($this->params->get('recipients', []), false);

        $specificIds = array_map(function ($item) {
            return $item->user;
        }, $recipients);

        $this->loadLanguages($this->params->get('language_override', ''));

        $superUsers = [];

        if (!empty($specificIds)) {
            $superUsers = $this->getSuperUsers($specificIds);
        }

        if (empty($superUsers)) {
            $superUsers = $this->getSuperUsers();
        }
        $app = $this->getApplication();

        if (empty($superUsers)) {
            return;
        }
        $error             = $event->getError();
        $baseSubstitutions = [
            'sitename' => $this->getApplication()->get('sitename'),

        ];

        $body    = [$this->replaceTags(Text::_('PLG_SYSTEM_EXTENSIONTOOLS_ERROR_MAIL_HEADER'), $baseSubstitutions) . "\n\n"];
        $subject = $this->replaceTags(Text::_('PLG_SYSTEM_EXTENSIONTOOLS_ERROR_MAIL_SUBJECT'), $baseSubstitutions);

        if (\is_callable([$app, 'getMessageQueue'])) {
            $messageQueue = $app->getMessageQueue();
            if ($messageQueue) {
                $body[] = json_encode($messageQueue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        $body[] = "\n";


        $body[] = $error?->getMessage() ?? '';
        $body[] =   Path::removeRoot($error?->getTraceAsString() ?? '');
        $body   = implode("\n", $body);

        return   $this->sendMail($superUsers, $subject, $body, 'onError');
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
            $query = $db->createQuery;

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
        $lang = $this->getApplication()->getLanguage();
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
        if (\count($updates) == 0) {
            return Status::OK;
        }
        $params     = $event->getArgument('params');
          //load early otherwise failures in Joomla's core will not be translated
          $this->loadLanguages($params->language_override ?? '');
        $app = $this->getApplication();
        //  $app->getInput()->set('ignoreMessages',false);
        $mvcFactory        = $app->bootComponent('com_installer')->getMVCFactory();
        $model             = $mvcFactory->createModel('update', 'administrator', ['ignore_request' => true]);
        $minimum_stability = ComponentHelper::getComponent('com_installer')->getParams()->get('minimum_stability', Updater::STABILITY_STABLE);
        $model->update(array_keys($updates), $minimum_stability);

        // Load the parameters.
   

      

        $superUsers = $this->usersToEmail($params->recipients ?? []);

        if (empty($superUsers)) {
            $this->logTask('No recipients found', 'warning');
            return Status::OK;
        }

        $updateCount       = \count($updates);
        $baseSubstitutions = [
            'sitename' => $this->getApplication()->get('sitename'),
            'count'    => $updateCount,
        ];

        $body    = [$this->replaceTags(Text::plural('PLG_SYSTEM_EXTENSIONTOOLS_AUTOUPDATE_MAIL_HEADER', $updateCount), $baseSubstitutions) . "\n\n"];
        $subject = $this->replaceTags(Text::plural('PLG_SYSTEM_EXTENSIONTOOLS_AUTOUPDATE_MAIL_SUBJECT', $updateCount), $baseSubstitutions);

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

        $lists    = [];
        if (\is_callable([$app, 'getMessageQueue'])) {
            $messages = $app->getMessageQueue();
            // Build the sorted messages list
            if (\is_array($messages) && \count($messages)) {
                foreach ($messages as $message) {
                    if (isset($message['type']) && isset($message['message'])) {
                        $lists[$message['type']][] = $message['message'];
                    }
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
        //updates should only install once. So sendonce could be false.
        return   $this->sendMail($superUsers, $subject, $body, 'updateAllExtensions');
    }



    /**
     * Method to send the update notification.
     *
     * @param   ExecuteTaskEvent  $event  The 'onExecuteTask' event.
     *
     * @return integer  The routine exit code.
     *
     * @since  1.0.0
     * @throws \Exception
     */
    private function checkExtensionUpdates(ExecuteTaskEvent $event): int
    {
        $this->logTask('check Extension Updates start', 'info');
        // Load the parameters.
        $params      = $event->getArgument('params');
        //load early otherwise failures in Joomla's core will not be translated
        $this->loadLanguages($params->language_override ?? '');


        $sendOnce    = (bool)($params->send_once ?? true);


        /*
         * Load the appropriate language. We try to load English (UK), the current user's language and the forced
         * language preference, in this order. This ensures that we'll never end up with untranslated strings in the
         * update email which would make Joomla! seem bad. So, please, if you don't fully understand what the
         * following code does DO NOT TOUCH IT. It makes the difference between a hobbyist CMS and a professional
         * solution!
         */


        $extensionUpdates = $this->getExtensionsWithUpdate();
        $coreUpdates      = $this->getExtensionsWithUpdate(true);
        $allUpdates       = array_merge($coreUpdates, $extensionUpdates);

        if (\count($allUpdates) == 0) {
            $this->logTask('No Updates found', 'info');
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
        $superUsers = $this->usersToEmail($params->recipients ?? []);


        if (empty($superUsers)) {
            $this->logTask('No recipients found', 'error');
            return Status::KNOCKOUT;
        }
        $updateCount       = \count($allUpdates);
        $baseSubstitutions = [
            'sitename'   => $this->getApplication()->get('sitename'),
            'count'      => $updateCount,
            'updatelink' => $baseURL,
        ];



        $body    = [$this->replaceTags(Text::plural('PLG_SYSTEM_EXTENSIONTOOLS_UPDATE_MAIL_HEADER', $updateCount), $baseSubstitutions) . "\n\n"];
        $subject = $this->replaceTags(Text::plural('PLG_SYSTEM_EXTENSIONTOOLS_UPDATE_MAIL_SUBJECT', $updateCount), $baseSubstitutions);

        foreach ($allUpdates as $updateValue) {
            // Replace merge codes with their values
            $extensionSubstitutions = [
                'newversion'    => $updateValue->version,
                'curversion'    => $updateValue->current_version,
                'extensiontype' => $updateValue->type,
                'extensionname' => $updateValue->name,
            ];

            $body[] = $this->replaceTags(Text::_('PLG_SYSTEM_EXTENSIONTOOLS_UPDATE_FOUND_SINGLE'), $extensionSubstitutions) . "\n";
        }

        $body[] = $this->replaceTags(Text::_('PLG_SYSTEM_EXTENSIONTOOLS_UPDATE_MAIL_FOOTER'), $baseSubstitutions);

        $body = join("\n", $body);

        // Send the emails to the Super Users
        $status = $this->sendMail($superUsers, $subject, $body, $sendOnce ? 'checkExtensionUpdates' : false);

        $this->logTask('check Extension Updates end', 'info');

        return $status;
    }
}
