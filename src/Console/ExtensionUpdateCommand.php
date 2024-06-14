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

namespace Brambring\Plugin\System\Extensiontools\Console;

use Brambring\Plugin\System\Extensiontools\Joomla\UpdateModel;
use Brambring\Plugin\System\Extensiontools\Trait\UpdateTrait;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Updater\Updater;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Registry\Registry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Language\Text;
// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Console command for installing extensions
 *
 * @since  4.0.0
 */
class ExtensionUpdateCommand extends AbstractCommand
{
    use UpdateTrait;
    use DatabaseAwareTrait;
    /**
     * The default command name
     *
     * @var    string
     * @since  4.0.0
     */
    protected static $defaultName = 'update:extensions:update';


    /* used to mimick a normal plugin for in the trait */
    private Registry $params;

    /**
     * Stores the Input Object
     * @var InputInterface
     * @since 4.0.0
     */
    private $cliInput;

    /**
     * SymfonyStyle Object
     * @var SymfonyStyle
     * @since 4.0.0
     */
    private $ioStyle;
    private bool $email = false;

    /**
     * Exit Code For installation failure
     * @since 4.0.0
     */
    public const INSTALLATION_FAILED = 1;

    /**
     * Exit Code For installation Success
     * @since 4.0.0
     */
    public const INSTALLATION_SUCCESSFUL = 0;

    /**
     * Configures the IO
     *
     * @param   InputInterface   $input   Console Input
     * @param   OutputInterface  $output  Console Output
     *
     * @return void
     *
     * @since 4.0.0
     *
     */
    private function configureIO(InputInterface $input, OutputInterface $output): void
    {
        $this->params   = Factory::getApplication()->bootPlugin('extensiontools', 'system')->params;
        $this->cliInput = $input;
        $this->ioStyle  = new SymfonyStyle($input, $output);
        $this->getApplication()->getLanguage()->load('lib_joomla', JPATH_ADMINISTRATOR, null, true, true);
        $this->setDatabase(Factory::getContainer()->get(DatabaseInterface::class));
    }

    /**
     * Initialise the command.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'The path to the extension');
        $this->addOption('url', null, InputOption::VALUE_REQUIRED, 'The url to the extension');
        $this->addOption('eid', null, InputOption::VALUE_REQUIRED, 'The extension ID');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Update all configured extensions');
        $this->addOption('email', null, InputOption::VALUE_NONE, 'Email results');


        $help = "<info>%command.name%</info> is used to install extensions
		\nYou must provide one of the following options to the command:
		\n  --path: The path on your local filesystem to the install package
		\n  --url: The URL from where the install package should be downloaded
        \n  --eid: The Extension ID of the extension to be updated
        \n  --all: Update all extension with pending update
        \n
        \n --email: This will ommit the output and send an email to configured recipeints (plugin configuration). This will also email extentions that are not updated. So it works as an update notification as well.
		\nUsage:
		\n  <info>php %command.full_name% --path=<path_to_file></info>
        \n  <info>php %command.full_name% --eid=<exention id></info>
		\n  <info>php %command.full_name% --url=<url_to_file></info>
        ";

        $this->setDescription('Install an extension from a URL, a path or using Joomla\'s Update System');
        $this->setHelp($help);
    }

    /**
     * Used for installing extension from a path
     *
     * @param   string  $path  Path to the extension zip file
     *
     * @return boolean
     *
     * @since 4.0.0
     *
     * @throws \Exception
     */
    public function processPathInstallation($path): bool
    {
        if (!file_exists($path)) {
            $this->ioStyle->warning('The file path specified does not exist.');

            return false;
        }

        $tmpPath  = $this->getApplication()->get('tmp_path');
        $tmpPath .= '/' . basename($path);
        $package  = InstallerHelper::unpack($path, true);

        if ($package['type'] === false) {
            return false;
        }

        $jInstaller = Installer::getInstance();
        $result     = $jInstaller->install($package['extractdir']);
        InstallerHelper::cleanupInstall($tmpPath, $package['extractdir']);

        return $result;
    }



    private function updataAll(): bool
    {
        $updates = $this->getAllowedUpdates();
        foreach ($updates as $update) {
            //Let's do it one by one for some nice log
            $this->updateUID($update);
        }
        return true;
    }
    private function updatesToMailRows($updates) {
        $body=[];
        foreach ($updates as $updateValue) {
           
            // Replace merge codes with their values
            $extensionSubstitutions = [
                'newversion'    => $updateValue[5],
                'curversion'    => $updateValue[4],
                'extensiontype' => $updateValue[3],
                'extensionname' => $updateValue[1],
            ];
        
            $body[] = $this->replaceTags(Text::_('PLG_SYSTEM_EXTENSIONTOOLS_AUTOUPDATE_MAIL_SINGLE'), $extensionSubstitutions) . "\n";
        }
        return $body;
    }
    
    private function emailUpdateResults()
    {
        $app=$this->getApplication();
        $this->loadLanguages($this->params->get('language_override', ''));
        $superUsers = $this->usersToEmail($this->params->get('recipients',  []));

        if (empty($superUsers)) {
            $this->logTask('No recipients found', 'warning');
            return true;
        }
        $body = [];

        if (\count($this->successInfo)) {
            $body[] = '==== Successful updates:';
            $body=array_merge($body,$this->updatesToMailRows($this->successInfo));
           
        }


        if (\count($this->skipInfo)) {
            $body[]='';
            $body[] = '==== Skipped updates (auto update not allowed):';
            $body=array_merge($body,$this->updatesToMailRows($this->skipInfo));
        }
        if (\count($this->failInfo)) {
            $body[]='';
            $body[] = '==== Failed :';
            $body=array_merge($body,$this->updatesToMailRows($this->failInfo));
        }

        $updateCount       = \count($body);
        if ($updateCount == 0) {
            return;
        }

        $baseSubstitutions = [
            'sitename' => $this->getApplication()->get('sitename'),
            'count'    => $updateCount,
        ];

        array_unshift($body, $this->replaceTags(Text::_('PLG_SYSTEM_EXTENSIONTOOLS_AUTOUPDATECLI_MAIL_HEADER', $updateCount), $baseSubstitutions) . "\n\n");
        $subject = $this->replaceTags(Text::plural('PLG_SYSTEM_EXTENSIONTOOLS_AUTOUPDATECLI_MAIL_SUBJECT', $updateCount), $baseSubstitutions);

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
       
        $this->sendMail($superUsers, $subject, $body, 'emailUpdateResults');
    }

    private function showUpdateResutls()
    {
        if (\count($this->successInfo)) {
            $this->ioStyle->success('Successful updates');
            $this->ioStyle->table(['Extension ID', 'Name', 'Location', 'Type', 'Old Version', 'Installed', 'Folder'], $this->successInfo);
        }

        if (\count($this->skipInfo)) {
            $this->ioStyle->warning('Skipped updates (auto update not allowed)');
            $this->ioStyle->table(['Extension ID', 'Name', 'Location', 'Type', 'Old Version', 'Available', 'Folder'], $this->skipInfo);
        }

        if (\count($this->failInfo)) {
            $this->ioStyle->error('Failed updates');
            $this->ioStyle->table(['Extension ID', 'Name', 'Location', 'Type', 'Installed', 'Available', 'Folder'], $this->failInfo);
        }
    }

    /*
    * Single update
    */

    private function updateUID($update): bool
    {
        $uid = $update->update_id ?? false;
        if ($uid === false) {
            $this->ioStyle->error('Invalid update');
            return false;
        }

        $minimum_stability = ComponentHelper::getComponent('com_installer')->getParams()->get('minimum_stability', Updater::STABILITY_STABLE);

        $app        = $this->getApplication();
        $mvcFactory = $app->bootComponent('com_installer')->getMVCFactory();
        if (\is_callable([$app, 'setUserState'])) {
            $model  = $mvcFactory->createModel('Update', 'Administrator', ['ignore_request' => true]);
        } else {
            $model = new UpdateModel(['ignore_request' => true], $mvcFactory);
        }

        /* workaround 2
        //THe cli application will not install. So boot a AdministratorApplication
        $container = \Joomla\CMS\Factory::getContainer();
        $CLIApp = $this->getApplication();
        $app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
        $mvcFactory = $app->bootComponent('com_installer')->getMVCFactory();
        $model  = $mvcFactory->createModel('update', 'administrator', ['ignore_request' => true]);
        Factory::$application = $app;
     */

        $model->update([$uid], $minimum_stability);
        $result = $model->getState('result');
        if ($result) {
            $this->successInfo[] = $this->updateToRow($update);
        } else {
            $this->failInfo[] = $this->updateToRow($update);
        }
        /* workarond 2
        Factory::$application = $CLIApp;
*/

        return $result;
    }



    public function processEIDInstallation(int $eid): bool
    {
        $uid = $this->isValidUpdate('extension_id', $eid);
        if ($uid === false) {
            $this->ioStyle->error('There is no update for this Extension ID');
            return false;
        }
        $this->ioStyle->success('Found update');
        return $this->updateUID($uid);
    }


    /**
     * Used for installing extension from a URL
     *
     * @param   string  $url  URL to the extension zip file
     *
     * @return boolean
     *
     * @since 4.0.0
     *
     * @throws \Exception
     */
    public function processUrlInstallation($url): bool
    {
        $filename = InstallerHelper::downloadPackage($url);
        $tmpPath = $this->getApplication()->get('tmp_path');

        $path     = $tmpPath . '/' . basename($filename);
        $package  = InstallerHelper::unpack($path, true);

        if ($package['type'] === false) {
            return false;
        }

        $jInstaller = new Installer();
        $result     = $jInstaller->install($package['extractdir']);
        InstallerHelper::cleanupInstall($path, $package['extractdir']);
        return $result;
    }

    /**
     * Internal function to execute the command.
     *
     * @param   InputInterface   $input   The input to inject into the command.
     * @param   OutputInterface  $output  The output to inject into the command.
     *
     * @return  integer  The command exit code
     *
     * @throws \Exception
     * @since   4.0.0
     */

    protected function conditionalTitle($title)
    {
        if ($this->email) {
            return;
        }
        $this->ioStyle->title($title);
    }
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->configureIO($input, $output);
        $this->email = $this->cliInput->getOption('email');

        if ($this->cliInput->getOption('all')) {
            $this->conditionalTitle('Update all Extensions');
            $this->updataAll();
            if ($this->email) {
                $this->emailUpdateResults();
            } else {
                $this->showUpdateResutls();
            }

            //updateAll has it's own reporting
            return self::INSTALLATION_SUCCESSFUL;
        }

        if ($path = $this->cliInput->getOption('path')) {
            $this->conditionalTitle('Update/Install Extension From Path');
            $result = $this->processPathInstallation($path);

            if (!$result) {
                $this->ioStyle->error('Unable to install extension');

                return self::INSTALLATION_FAILED;
            }

            $this->ioStyle->success('Extension installed successfully.');


            return self::INSTALLATION_SUCCESSFUL;
        }

        if ($url = $this->cliInput->getOption('url')) {
            $this->conditionalTitle('Update/Install Extension From URL');
            $result = $this->processUrlInstallation($url);

            if (!$result) {
                $this->ioStyle->error('Unable to install extension');

                return self::INSTALLATION_FAILED;
            }

            $this->ioStyle->success('Extension installed successfully.');

            return self::INSTALLATION_SUCCESSFUL;
        }


        if ($eid = (int)$this->cliInput->getOption('eid')) {
            $this->conditionalTitle('Update Extension Using Extension ID');
            $result = $this->processEIDInstallation($eid);
            $this->showUpdateResutls();
            if (!$result) {
                $this->ioStyle->error('Unable to install extension');

                return self::INSTALLATION_FAILED;
            }

            $this->ioStyle->success('Extension installed successfully.');

            return self::INSTALLATION_SUCCESSFUL;
        }
        $this->ioStyle->error('Invalid argument supplied for command.');

        return self::INSTALLATION_FAILED;
    }
}
