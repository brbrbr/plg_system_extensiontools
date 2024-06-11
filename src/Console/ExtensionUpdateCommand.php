<?php

/**
 * Based On libraries/src/Console/ExtensionInstallCommand.php
 * from the Joomla! Content Management System
 * (C) 2020 Open Source Matters, Inc. <https://www.joomla.org>
 * GNU General Public License version 2 or later; see LICENSE.txt
 *
 * @version   24.51
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
        $this->addOption('all', null, InputOption::VALUE_NONE, 'The extension ID');


        $help = "<info>%command.name%</info> is used to install extensions
		\nYou must provide one of the following options to the command:
		\n  --path: The path on your local filesystem to the install package
		\n  --url: The URL from where the install package should be downloaded
        \n  --eid: The Extension ID of the extension to be updated
        \n  --all: Update all extension with pending update
		\nUsage:
		\n  <info>php %command.full_name% --path=<path_to_file></info>
        \n  <info>php %command.full_name% --eid=<exention id></info>
		\n  <info>php %command.full_name% --url=<url_to_file></info>";

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
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->configureIO($input, $output);


        if ($this->cliInput->getOption('all')) {
            $this->ioStyle->title('Update all Extensions');
            $this->updataAll();
            $this->showUpdateResutls();
            //updateAll has it's own reporting

            return self::INSTALLATION_SUCCESSFUL;
        }

        if ($path = $this->cliInput->getOption('path')) {
            $this->ioStyle->title('Update/Install Extension From Path');
            $result = $this->processPathInstallation($path);

            if (!$result) {
                $this->ioStyle->error('Unable to install extension');

                return self::INSTALLATION_FAILED;
            }

            $this->ioStyle->success('Extension installed successfully.');


            return self::INSTALLATION_SUCCESSFUL;
        }

        if ($url = $this->cliInput->getOption('url')) {
            $this->ioStyle->title('Update/Install Extension From URL');
            $result = $this->processUrlInstallation($url);

            if (!$result) {
                $this->ioStyle->error('Unable to install extension');

                return self::INSTALLATION_FAILED;
            }

            $this->ioStyle->success('Extension installed successfully.');

            return self::INSTALLATION_SUCCESSFUL;
        }


        if ($eid = (int)$this->cliInput->getOption('eid')) {
            $this->ioStyle->title('Update Extension Using Extension ID');
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
