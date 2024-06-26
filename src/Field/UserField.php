<?php

/**
 * Joomla! Content Management System
 *
 * @copyright 2024 Bram Brambring (https://brambring.nl)
 * @license   GNU General Public License version 3 or later;
 */

namespace Brambring\Plugin\System\Extensiontools\Field;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Form\Field\UserField as JoomlaUserField;
use Joomla\CMS\Table\Asset;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Field to select a user ID from a modal list.
 *
 * @since  1.6
 */
class UserField extends JoomlaUserField
{
    protected $layout = 'joomla.form.field.user';


    /**
     * Method to get the filtering groups (null means no filtering)
     *
     * @return  string[]  Array of filtering groups or null.
     *
     * @since   1.6
     */
    protected function getGroups()
    {
        $db     = $this->getDatabase();
        $rootId = (new Asset($db))->getRootId();

        $rules     = Access::getAssetRules($rootId)->getData();
        $rawGroups = $rules['core.admin']->getData();
        $groups    = [];

        if (empty($rawGroups)) {
            return $groups;
        }

        foreach ($rawGroups as $g => $enabled) {
            if ($enabled) {
                $groups[] = $g;
            }
        }
        return $groups;
    }
}
