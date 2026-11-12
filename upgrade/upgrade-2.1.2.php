<?php
/**
 * obsstocks 2.1.2: licencia decidida desde el panel del servidor de actualizaciones.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_1_2($module)
{
    return $module->getUpdater()->registerHooks();
}
