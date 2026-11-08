<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.x -> 2.0.0: configuración en Módulos > Configurar (sin pestaña en el menú), cron con clave,
 * columna qty y limpieza de archivos antiguos (export/ guardaba CSV con el stock accesibles desde fuera).
 *
 * @param obsstocks $module
 */
function upgrade_module_2_0_0($module)
{
    $dir = _PS_MODULE_DIR_ . 'obsstocks/';
    foreach (['controllers/admin/OBSStocksAdminTabController.php', 'OBSLog.php', 'install-sql.php',
        'views/templates/admin/helpers/uploader/simple.tpl', 'es.php', 'translations/en.php', 'logo.gif', 'logo.webp'] as $f) {
        if (is_file($dir . $f)) {
            @unlink($dir . $f);
        }
    }
    foreach (['backward_compatibility', 'export', 'docs', 'views'] as $d) {
        if (is_dir($dir . $d)) {
            Tools::deleteDirectory($dir . $d);
        }
    }

    return obsstocks::installDb() && obsstocks::removeLegacyTab() && $module->installConfig();
}
