<?php

/**
 * @package  System.Extensiontools
 * @version    24.51
 * @copyright  2024 Bram Brambring
 * @license    GNU General Public License version 3 or later;
 */

 namespace Brambring\Plugin\System\Extensiontools\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;


class ExtensionsField extends ListField
{
    protected $layout = 'joomla.form.field.list-fancy-select';
    public bool $is_select_list = true;
    public bool $use_ajax       = true;
    public function __construct($form = null)
    {
        $this->type ??= 'Extensions';
        parent::__construct($form);
    }


    protected function getOptions(): array
    {
        $plugin =  Factory::getApplication()->bootPlugin('extensiontools', 'system');

        $es = $plugin->getNonCoreExtensionsWithUpdateSite();


        $options = [];
        foreach ($es as $e) {
            $options[$e->name] = ['value' => $e->extension_id,"text" => "{$e->name} ({$e->type})"];
        }

        ksort($options);


        return array_values($options);
    }
}
