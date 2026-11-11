<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 2.1.0: servidor de actualizaciones de josra.
 *
 * @param obsstocks $module
 */
function upgrade_module_2_1_0($module)
{
    return $module->getUpdater()->registerHooks();
}
