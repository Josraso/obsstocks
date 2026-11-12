<?php
/**
 * OBS Stocks - Actualizador masivo de stock vía CSV con avisos de stock mínimo
 *
 * Versión 2.0: adaptada a PrestaShop 1.7 / 8 / 9. Todo se gestiona desde Módulos > Configurar.
 *
 * @author    OBSolutions.es / adaptación PrestaShop 9
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/Mystocks.php';
require_once dirname(__FILE__) . '/classes/ObsStocksImporter.php';
require_once dirname(__FILE__) . '/classes/ObsstocksUpdater.php';

class obsstocks extends Module
{
    public function __construct()
    {
        $this->name = 'obsstocks';
        $this->tab = 'migration_tools';
        $this->version = '2.1.3';
        $this->author = 'OBSolutions.es';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Actualizador masivo de stock vía CSV con avisos');
        $this->description = $this->l('Actualiza el stock de los productos y los avisos de stock mínimo subiendo un CSV, y envía por email los productos bajo mínimos.');
        $this->confirmUninstall = $this->l('¿Seguro que quieres desinstalar el módulo? Los avisos configurados se conservan en la base de datos.');
    }

    /* ==================================================================
     * Instalación
     * ================================================================== */

    public function install()
    {
        return parent::install() && self::installDb() && self::removeLegacyTab() && $this->installConfig()
            && $this->getUpdater()->registerHooks();
    }

    public function uninstall()
    {
        // Aviso al servidor de actualizaciones (no impide desinstalar si no responde)
        $this->getUpdater()->notifyUninstall();

        // Los avisos (tabla obsstocks_minstocks) se conservan para no perderlos al reinstalar
        foreach (['OBSSTOCKS_CRON_TOKEN', 'OBSSTOCKS_ALERT_EMAIL', 'OBSSTOCKS_UPD_TS', 'OBSSTOCKS_UPD_INFO', 'OBSSTOCKS_UPD_SU', 'OBSSTOCKS_UPD_LT'] as $k) {
            Configuration::deleteByName($k);
        }
        self::removeLegacyTab();

        return parent::uninstall();
    }

    public function installConfig()
    {
        if (!Configuration::getGlobalValue('OBSSTOCKS_CRON_TOKEN')) {
            Configuration::updateGlobalValue('OBSSTOCKS_CRON_TOKEN', Tools::passwdGen(24));
        }
        if (!Configuration::get('OBSSTOCKS_ALERT_EMAIL')) {
            Configuration::updateValue('OBSSTOCKS_ALERT_EMAIL', Configuration::get('PS_SHOP_EMAIL'));
        }

        return true;
    }

    public static function installDb()
    {
        $db = Db::getInstance();
        $t = _DB_PREFIX_ . Mystocks::TABLE;
        $ok = $db->execute('CREATE TABLE IF NOT EXISTS `' . $t . '` (
            `reference` varchar(64) NOT NULL,
            `min_stock` int(11) NOT NULL DEFAULT 0,
            `qty` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`reference`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
        // Tiendas con la tabla de la versión 1.x: columna qty (cantidad a pedir) y referencias de 64
        $cols = [];
        foreach ((array) $db->executeS('SHOW COLUMNS FROM `' . $t . '`') as $c) {
            $cols[$c['Field']] = $c;
        }
        if (!isset($cols['qty'])) {
            $ok = $ok && $db->execute('ALTER TABLE `' . $t . '` ADD `qty` int(11) NOT NULL DEFAULT 0');
        }
        if (isset($cols['reference']) && stripos($cols['reference']['Type'], 'varchar(32)') !== false) {
            $db->execute('ALTER TABLE `' . $t . '` MODIFY `reference` varchar(64) NOT NULL');
        }

        return (bool) $ok;
    }

    /** La versión 1.x creaba la pestaña Catálogo > Stocks: ahora todo está en Configurar */
    public static function removeLegacyTab()
    {
        foreach (['OBSStocksAdminTab'] as $class) {
            $id = (int) Tab::getIdFromClassName($class);
            if ($id) {
                $tab = new Tab($id);
                $tab->delete();
            }
        }

        return true;
    }

    /* ==================================================================
     * Actualizaciones
     * ================================================================== */

    public function getUpdater()
    {
        return new ObsstocksUpdater($this);
    }

    /** Aviso periódico al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    /* ==================================================================
     * Utilidades
     * ================================================================== */

    /** Pestaña de la configuración que se está viendo */
    private $obsTab = 'resumen';

    private function tabs()
    {
        return [
            'resumen' => ['icon-dashboard', $this->l('Resumen')],
            'avisos' => ['icon-bell', $this->l('Avisos')],
            'importar' => ['icon-upload', $this->l('Importar CSV')],
            'emails' => ['icon-envelope', $this->l('Emails y cron')],
            'registro' => ['icon-list', $this->l('Registro')],
        ];
    }

    public function adminUrl($extra = '', $tab = null)
    {
        $tab = $tab === null ? $this->obsTab : $tab;

        return $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name
            . ($tab !== '' ? '&obs_tab=' . $tab : '') . $extra;
    }

    private function json(array $data)
    {
        while (ob_get_level()) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($data);
        exit;
    }

    public function cronUrl($controller)
    {
        return $this->context->link->getModuleLink($this->name, $controller, ['token' => Configuration::getGlobalValue('OBSSTOCKS_CRON_TOKEN')], true);
    }

    private function e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private function tmpDir()
    {
        $dir = _PS_CACHE_DIR_ . 'obsstocks/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Limpieza de archivos pendientes de más de un día
        foreach ((array) glob($dir . '*.csv') as $f) {
            if ($f && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }

        return $dir;
    }

    public function log($text)
    {
        $dir = dirname(__FILE__) . '/logs/';
        $who = (isset($this->context->employee) && Validate::isLoadedObject($this->context->employee))
            ? $this->context->employee->firstname . ' ' . $this->context->employee->lastname : 'cron';
        @file_put_contents($dir . 'stocks.log', '[' . date('Y-m-d H:i:s') . '] ' . $who . ': ' . $text . "\n", FILE_APPEND);
    }

    private function downloadCsv(array $rows, $filename)
    {
        $csv = Mystocks::toCsv($rows);
        if (ob_get_level()) {
            @ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: private, no-store');
        echo $csv;
        exit;
    }

    /* ==================================================================
     * Emails (cron y botón "Enviar ahora")
     * ================================================================== */

    /**
     * @param string $type 'min' = bajo mínimos, 'all' = todo el stock
     *
     * @return array [enviado(bool), filas(int), mensaje]
     */
    public function sendStockMail($type)
    {
        $min = $type !== 'all';
        $rows = $min ? Mystocks::getDatasheetStockBelowMinium() : Mystocks::getDatasheetAllStock();
        $n = count($rows) - 1;
        if ($n < 1) {
            return [false, 0, $min ? 'No hay productos por debajo del mínimo: no se envía email.' : 'No hay productos.'];
        }
        $to = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) Configuration::get('OBSSTOCKS_ALERT_EMAIL'))), 'Validate::isEmail'));
        if (!$to) {
            return [false, $n, 'No hay ningún email de aviso válido configurado.'];
        }
        $file = $min ? 'current_min-stock.csv' : 'current_all-stock.csv';
        $id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $message = $min
            ? sprintf($this->l('Hay %d productos por debajo del stock mínimo. Tienes el listado en el archivo CSV adjunto (columnas: referencia, nombre, stock actual, stock mínimo, nombre en inglés y cantidad a pedir).'), $n)
            : sprintf($this->l('Te enviamos el stock de todos los productos (%d filas) en el archivo CSV adjunto.'), $n);

        $ok = Mail::send(
            $id_lang,
            $min ? 'current_stock' : 'available_stock',
            $min ? $this->l('Aviso automático: stock bajo mínimos') : $this->l('Aviso automático: todo el stock disponible'),
            ['{message}' => $message, '{rows}' => $n],
            count($to) == 1 ? $to[0] : $to,
            null,
            null,
            null,
            ['content' => Mystocks::toCsv($rows), 'name' => $file, 'mime' => 'text/csv'],
            null,
            dirname(__FILE__) . '/mails/',
            false,
            (int) $this->context->shop->id
        );
        $this->log(($ok ? 'Email enviado' : 'ERROR al enviar email') . ' (' . ($min ? 'bajo mínimos' : 'todo el stock') . ', ' . $n . ' filas) a ' . implode(', ', $to));

        return [(bool) $ok, $n, $ok ? sprintf('Email enviado a %s con %d filas.', implode(', ', $to), $n) : 'No se ha podido enviar el email (revisa Parámetros avanzados > Email).'];
    }

    /* ==================================================================
     * Configuración
     * ================================================================== */

    public function getContent()
    {
        // Edición a mano de avisos (pestaña «Avisos»)
        if (Tools::getValue('obs_ajax')) {
            $this->ajaxAlerts((string) Tools::getValue('obs_ajax'));
        }

        $updater = $this->getUpdater();
        $out = $updater->processRequest();

        $tab = (string) Tools::getValue('obs_tab');
        $this->obsTab = isset($this->tabs()[$tab]) ? $tab : 'resumen';

        // Descargas
        $exp = Tools::getValue('obs_export');
        if ($exp === 'backup') {
            $name = basename((string) Tools::getValue('f'));
            if (in_array($name, Mystocks::backups(), true)) {
                if (ob_get_level()) {
                    @ob_end_clean();
                }
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $name . '"');
                header('Cache-Control: private, no-store');
                readfile(Mystocks::backupDir() . $name);
                exit;
            }
            $out .= $this->displayError($this->l('Esa copia de avisos ya no existe.'));
        } elseif ($exp) {
            $d = date('YmdHis');
            switch ($exp) {
                case 'combined':
                    $this->downloadCsv(Mystocks::getDatasheetStockAndAlerts(), $d . '_stock_y_avisos.csv');
                    // no break
                case 'all':
                    $this->downloadCsv(Mystocks::getDatasheetAllStock(), $d . '_all_stock.csv');
                    // no break
                case 'min':
                    $this->downloadCsv(Mystocks::getDatasheetStockBelowMinium(), $d . '_current_stock.csv');
                    // no break
                case 'alerts':
                    $this->downloadCsv(Mystocks::getDatasheetCurrentAlertConf(), $d . '_current_alerts_conf.csv');
                    // no break
                case 'example':
                    $this->downloadCsv(Mystocks::getDatasheetExample(), 'plantilla_stock_avisos.csv');
            }
        }

        if (Tools::isSubmit('submitObsSettings') || Tools::isSubmit('obsRegenToken') || Tools::isSubmit('obsSendMin') || Tools::isSubmit('obsSendAll')) {
            $this->obsTab = 'emails';
        }
        if (Tools::isSubmit('obsCheck') || Tools::isSubmit('obsImportNow') || Tools::isSubmit('obsConfirm')) {
            $this->obsTab = 'importar';
        }
        if (Tools::isSubmit('submitObsSettings')) {
            $emails = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) Tools::getValue('OBSSTOCKS_ALERT_EMAIL'))));
            $bad = array_filter($emails, function ($m) {
                return !Validate::isEmail($m);
            });
            if ($bad) {
                $out .= $this->displayError($this->l('Email no válido:') . ' ' . $this->e(implode(', ', $bad)));
            } else {
                Configuration::updateValue('OBSSTOCKS_ALERT_EMAIL', implode(', ', $emails));
                $out .= $this->displayConfirmation($this->l('Ajustes guardados.'));
            }
        }
        if (Tools::isSubmit('obsRegenToken')) {
            Configuration::updateGlobalValue('OBSSTOCKS_CRON_TOKEN', Tools::passwdGen(24));
            $out .= $this->displayWarning($this->l('Nuevas URL de cron generadas. Actualiza las tareas programadas del servidor.'));
        }
        if (Tools::isSubmit('obsSendMin') || Tools::isSubmit('obsSendAll')) {
            list($sent, , $msg) = $this->sendStockMail(Tools::isSubmit('obsSendAll') ? 'all' : 'min');
            $out .= $sent ? $this->displayConfirmation($this->e($msg)) : $this->displayWarning($this->e($msg));
        }

        // Importación
        $report = null;
        $pending = '';
        if (Tools::isSubmit('obsCheck') || Tools::isSubmit('obsImportNow')) {
            $f = isset($_FILES['stocks_file']) ? $_FILES['stocks_file'] : null;
            if (!$f || empty($f['name']) || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                $out .= $this->displayError($this->l('No has elegido ningún archivo (o no se ha podido subir).'));
            } elseif (!preg_match('/\.(csv|txt)$/i', $f['name'])) {
                $out .= $this->displayError($this->l('El archivo tiene que ser .csv. En Excel: Archivo > Guardar como > «CSV (delimitado por comas)».'));
            } else {
                $dry = Tools::isSubmit('obsCheck');
                $imp = new ObsStocksImporter(Tools::getValue('update_stock'), Tools::getValue('update_alerts'), Tools::getValue('add_stock'), $dry, Tools::getValue('alerts_mode'));
                $report = $imp->run($f['tmp_name']);
                $report['file'] = $f['name'];
                $report['dry'] = $dry;
                if ($dry && !$report['error'] && ($report['stock_changed'] || $report['alert_changed'] || $report['alert_deleted'])) {
                    $pending = Tools::passwdGen(32, 'ALPHANUMERIC');
                    move_uploaded_file($f['tmp_name'], $this->tmpDir() . $pending . '.csv');
                    @file_put_contents($this->tmpDir() . $pending . '.name', $f['name']);
                }
                if (!$dry) {
                    $this->logImport($report);
                }
            }
        } elseif (Tools::isSubmit('obsConfirm')) {
            $pid = preg_replace('/[^A-Za-z0-9]/', '', (string) Tools::getValue('obs_file'));
            $path = $this->tmpDir() . $pid . '.csv';
            if ($pid === '' || !is_file($path)) {
                $out .= $this->displayError($this->l('El archivo comprobado ya no está disponible. Vuelve a subirlo.'));
            } else {
                $imp = new ObsStocksImporter(Tools::getValue('update_stock'), Tools::getValue('update_alerts'), Tools::getValue('add_stock'), false, Tools::getValue('alerts_mode'));
                $report = $imp->run($path);
                $report['file'] = is_file($this->tmpDir() . $pid . '.name') ? Tools::file_get_contents($this->tmpDir() . $pid . '.name') : 'archivo';
                $report['dry'] = false;
                @unlink($path);
                @unlink($this->tmpDir() . $pid . '.name');
                $this->logImport($report);
            }
        }

        switch ($this->obsTab) {
            case 'avisos':
                $body = $this->renderAlertsEditor();
                break;
            case 'importar':
                $body = ($report ? $this->renderReport($report, $pending) : '') . $this->renderHowTo() . $this->renderImportForm();
                break;
            case 'emails':
                $body = $this->renderCronForm();
                break;
            case 'registro':
                $body = $this->renderLog() . $this->renderBackups();
                break;
            default:
                $body = $this->renderSummary() . $this->renderBelowMinimum();
        }

        $info = $updater->getInfo();
        $panel = $updater->renderPanel();
        $newVersion = !empty($info['update_available']);

        return '<link rel="stylesheet" href="' . $this->e($this->_path . 'views/css/admin.css?v=' . $this->version) . '" type="text/css">'
            . $out
            . ($newVersion ? $panel : '')
            . $this->renderTabs()
            . $body
            . (!$newVersion && $this->obsTab === 'resumen' ? $panel : '');
    }

    private function renderTabs()
    {
        $html = '<ul class="nav nav-tabs obs-tabs">';
        foreach ($this->tabs() as $key => $t) {
            $html .= '<li' . ($key === $this->obsTab ? ' class="active"' : '') . '><a href="' . $this->e($this->adminUrl('', $key)) . '"><i class="' . $t[0] . '"></i> ' . $t[1] . '</a></li>';
        }

        return $html . '</ul>';
    }

    private function logImport(array $r)
    {
        $this->log(sprintf(
            'Importado "%s": %d filas, %d stocks cambiados, %d avisos cambiados, %d avisos borrados, %d no encontrados, %d con errores (%s%s%s%s)',
            $r['file'], $r['rows'], $r['stock_changed'], $r['alert_changed'], $r['alert_deleted'], $r['not_found'], $r['invalid'],
            Tools::getValue('update_stock') ? 'stock' : 'sin stock',
            Tools::getValue('update_alerts') ? ', avisos' : ', sin avisos',
            Tools::getValue('add_stock') ? ', sumando' : '',
            !empty($r['replace']) ? ', avisos en modo SUSTITUIR' . (!empty($r['backup']) ? ', copia ' . $r['backup'] : '') : ''
        ) . ($r['error'] ? ' ERROR: ' . $r['error'] : ''));
    }

    /* ------------------------------------------------------------------
     * Pestaña «Avisos»: buscar y editar a mano
     * ------------------------------------------------------------------ */

    private function ajaxAlerts($action)
    {
        $ref = trim((string) Tools::getValue('reference'));
        if ($ref === '' || Tools::strlen($ref) > 64) {
            $this->json(['ok' => false, 'error' => $this->l('Referencia no válida.')]);
        }
        $alerts = Mystocks::alerts();
        $cur = isset($alerts[$ref]) ? $alerts[$ref] : null;

        if ($action === 'delete') {
            if ($cur) {
                Mystocks::deleteAlert($ref);
                $this->log(sprintf('Aviso quitado a mano: %s (tenía mínimo %d, a pedir %d)', $ref, $cur['min_stock'], $cur['qty']));
            }
            $this->json(['ok' => true, 'exists' => false]);
        }
        if ($action !== 'save') {
            $this->json(['ok' => false, 'error' => $this->l('Acción no válida.')]);
        }

        $min = Tools::getValue('min_stock');
        $qty = Tools::getValue('qty');
        if (!preg_match('/^[0-9]{1,9}$/', (string) $min) || !preg_match('/^[0-9]{1,9}$/', (string) $qty)) {
            $this->json(['ok' => false, 'error' => $this->l('Tienen que ser números enteros, sin negativos.')]);
        }
        $min = (int) $min;
        $qty = (int) $qty;
        // Sin aviso previo y con 0/0 no hay nada que guardar
        if (!$cur && $min === 0 && $qty === 0) {
            $this->json(['ok' => true, 'exists' => false]);
        }
        if (!$cur || $cur['min_stock'] !== $min || $cur['qty'] !== $qty) {
            if (!Mystocks::saveAlert($ref, $min, $qty)) {
                $this->json(['ok' => false, 'error' => $this->l('No se ha podido guardar.')]);
            }
            $this->log(sprintf('Aviso editado a mano: %s, mínimo %s → %d, a pedir %s → %d', $ref, $cur ? $cur['min_stock'] : '—', $min, $cur ? $cur['qty'] : '—', $qty));
        }
        $this->json(['ok' => true, 'exists' => true, 'min_stock' => $min, 'qty' => $qty]);
    }

    private function renderAlertsEditor()
    {
        $alerts = Mystocks::alerts();
        $rows = [];
        $used = [];
        foreach (Mystocks::catalog(false) as $r) {
            $ref = trim((string) $r['reference']);
            $a = ($ref !== '' && isset($alerts[$ref])) ? $alerts[$ref] : null;
            if ($ref !== '') {
                $used[$ref] = true;
            }
            $rows[] = [$ref, (string) $r['name'], (int) $r['stock'], $a ? (int) $a['min_stock'] : null, $a ? (int) $a['qty'] : null, (int) $r['active'], 0];
        }
        // Avisos de referencias que ya no están en ningún producto: se pueden ver y quitar
        foreach ($alerts as $ref => $a) {
            if (!isset($used[$ref])) {
                $rows[] = [(string) $ref, '', null, (int) $a['min_stock'], (int) $a['qty'], 0, 1];
            }
        }

        $config = [
            'url' => $this->adminUrl('', 'avisos'),
            'rows' => $rows,
            't' => [
                'showing' => $this->l('Mostrando %1 de %2'),
                'none' => $this->l('No hay ningún producto que coincida.'),
                'inactive' => $this->l('inactivo'),
                'orphan' => $this->l('Esta referencia ya no está en ningún producto'),
                'noref' => $this->l('sin referencia'),
                'remove' => $this->l('Quitar'),
                'removeTitle' => $this->l('Quitar el aviso de esta referencia'),
                'confirmRemove' => $this->l('¿Quitar el aviso de la referencia %1?'),
                'saving' => $this->l('Guardando…'),
                'saved' => $this->l('Guardado'),
                'removed' => $this->l('Aviso quitado'),
                'invalid' => $this->l('Número no válido'),
                'failed' => $this->l('No se ha podido guardar. Recarga la página y vuelve a intentarlo.'),
            ],
        ];
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $filter = function ($key, $label) {
            return '<button type="button" class="btn btn-default" data-obs-filter="' . $key . '">' . $label . ' <span class="badge">0</span></button>';
        };

        return '<div class="panel" id="obs_avisos">'
            . '<div class="panel-heading"><i class="icon-bell"></i> ' . $this->l('Avisos: buscar y editar a mano') . '</div>'
            . '<p class="help-block">' . $this->l('Escribe parte de la referencia o del nombre. Cambia el stock mínimo o la cantidad a pedir y se guarda solo al salir de la casilla (o con Intro). Aquí no se cambia el stock real. El aviso va por referencia: si dos productos comparten referencia, comparten aviso.') . '</p>'
            . '<div class="obs-av-bar">'
            . '<input type="text" class="form-control obs-av-search" placeholder="' . $this->l('Buscar por referencia o nombre…') . '" autocomplete="off">'
            . '<div class="btn-group">'
            . $filter('alert', $this->l('Con aviso'))
            . $filter('below', $this->l('Bajo mínimo'))
            . $filter('all', $this->l('Todos los productos'))
            . $filter('orphan', $this->l('Sin producto'))
            . '</div>'
            . '<span class="obs-av-info text-muted"></span>'
            . '</div>'
            . '<div class="table-responsive"><table class="table table-condensed obs-av-table"><thead><tr>'
            . '<th>' . $this->l('Referencia') . '</th><th>' . $this->l('Producto') . '</th><th class="text-center">' . $this->l('Stock') . '</th>'
            . '<th class="text-center">' . $this->l('Stock mínimo') . '</th><th class="text-center">' . $this->l('A pedir') . '</th><th></th>'
            . '</tr></thead><tbody></tbody></table></div>'
            . '<div class="obs-av-more" style="display:none"><button type="button" class="btn btn-default">' . $this->l('Mostrar más') . '</button></div>'
            . '</div>'
            . '<script>var obsAvisos = ' . json_encode($config, $flags) . ';</script>'
            . '<script src="' . $this->e($this->_path . 'views/js/avisos.js?v=' . $this->version) . '"></script>';
    }

    private function renderBackups()
    {
        $files = Mystocks::backups();
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-archive"></i> ' . $this->l('Copias de los avisos') . '</div>'
            . '<p class="help-block">' . $this->l('Cada vez que se importa en modo «sustituir» y se borra algún aviso, antes se guarda aquí una copia de todos. Para recuperarla, descárgala y súbela en «Importar CSV». Se conservan las 15 últimas.') . '</p>';
        if (!$files) {
            return $html . '<p class="text-muted">' . $this->l('Todavía no hay ninguna copia.') . '</p></div>';
        }
        $html .= '<table class="table table-condensed"><tbody>';
        foreach ($files as $f) {
            $when = preg_match('/^avisos-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/', $f, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4] . ':' . $m[5] : '';
            $html .= '<tr><td>' . $this->e($when) . '</td><td><code>' . $this->e($f) . '</code></td><td class="text-right"><a class="btn btn-default btn-sm" href="'
                . $this->e($this->adminUrl('&obs_export=backup&f=' . rawurlencode($f))) . '"><i class="icon-download"></i> ' . $this->l('Descargar') . '</a></td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /* ------------------------------------------------------------------ */

    private function renderSummary()
    {
        $alerts = array_filter(Mystocks::alerts(), function ($a) {
            return $a['min_stock'] > 0;
        });
        $below = count(Mystocks::belowMinimum());

        $box = function ($icon, $title, $value, $danger = false, $small = false) {
            return '<div class="col-md-4"><div class="panel" style="padding:14px 18px">'
                . '<div class="text-muted"><i class="' . $icon . '"></i> ' . $title . '</div>'
                . '<div style="font-size:' . ($small ? '15px' : '28px') . ';font-weight:600;margin-top:4px' . ($danger ? ';color:#d0021b' : '') . '">' . $value . '</div>'
                . '</div></div>';
        };

        return '<div class="row">'
            . $box('icon-bell', $this->l('Avisos configurados'), count($alerts))
            . $box('icon-warning-sign', $this->l('Productos bajo mínimo'), $below, $below > 0)
            . $box('icon-envelope', $this->l('Email de avisos'), $this->e(Configuration::get('OBSSTOCKS_ALERT_EMAIL') ?: '—'), false, true)
            . '</div>';
    }

    private function renderHowTo()
    {
        $btn = function ($type, $label, $icon, $class = 'btn-default') {
            return '<a class="btn ' . $class . '" href="' . $this->e($this->adminUrl('&obs_export=' . $type)) . '"><i class="' . $icon . '"></i> ' . $label . '</a> ';
        };

        return '<div class="panel" id="obs_howto">'
            . '<div class="panel-heading"><i class="icon-question-sign"></i> ' . $this->l('Cómo actualizar el stock y los avisos con un CSV') . '</div>'
            . '<div class="row">'
            . '<div class="col-md-4"><h4><span class="badge">1</span> ' . $this->l('Descarga el archivo') . '</h4>'
            . '<p>' . $this->l('Empieza siempre por un archivo descargado de aquí: ya trae las columnas correctas y las referencias de tus productos.') . '</p>'
            . '<p>' . $btn('combined', $this->l('Stock y avisos de todos los productos'), 'icon-download', 'btn-primary') . '</p>'
            . '<p class="help-block">' . $this->l('Recomendado: en un solo archivo cambias el stock real, el stock mínimo de aviso y la cantidad a pedir.') . '</p>'
            . '<p>' . $btn('all', $this->l('Solo stock'), 'icon-download') . $btn('alerts', $this->l('Solo avisos configurados'), 'icon-download') . '</p>'
            . '<p>' . $btn('example', $this->l('Plantilla vacía de ejemplo'), 'icon-file-text') . '</p>'
            . '<hr><p>' . $btn('min', $this->l('Productos bajo mínimos'), 'icon-warning-sign', 'btn-warning') . '</p>'
            . '<p class="help-block">' . $this->l('El mismo listado que llega por email, al momento: referencia, nombre, stock actual, stock mínimo, nombre en inglés y cantidad a pedir.') . '</p>'
            . '</div>'
            . '<div class="col-md-4"><h4><span class="badge">2</span> ' . $this->l('Cámbialo en Excel') . '</h4>'
            . '<ul style="list-style:disc;padding-left:18px">'
            . '<li>' . $this->l('No borres ni cambies la primera fila (los nombres de las columnas).') . '</li>'
            . '<li>' . $this->l('Cambia solo los números. Puedes borrar las filas que no quieras tocar.') . '</li>'
            . '<li>' . $this->l('Una celda vacía significa «no cambiar ese dato».') . '</li>'
            . '<li>' . $this->l('Guarda como «CSV (delimitado por comas)». Si Excel pregunta, mantén el formato CSV.') . '</li>'
            . '</ul>'
            . '<table class="table table-condensed"><thead><tr><th>' . $this->l('Columna') . '</th><th>' . $this->l('Qué es') . '</th></tr></thead><tbody>'
            . '<tr><td style="white-space:nowrap"><code>REFERENCE</code></td><td>' . $this->l('Referencia del producto o combinación (obligatoria si no hay OBJECT_ID).') . '</td></tr>'
            . '<tr><td style="white-space:nowrap"><code>CURRENT STOCK</code></td><td>' . $this->l('Stock real que quieres que tenga.') . '</td></tr>'
            . '<tr><td style="white-space:nowrap"><code>MIN STOCK</code></td><td>' . $this->l('Stock mínimo: si baja de aquí, sale en el aviso. 0 = sin aviso.') . '</td></tr>'
            . '<tr><td style="white-space:nowrap"><code>QTY</code></td><td>' . $this->l('Cantidad a pedir al proveedor (sale en el aviso).') . '</td></tr>'
            . '</tbody></table>'
            . '</div>'
            . '<div class="col-md-4"><h4><span class="badge">3</span> ' . $this->l('Súbelo y comprueba') . '</h4>'
            . '<p>' . $this->l('Abajo, elige el archivo y pulsa «Comprobar archivo». Verás fila a fila lo que va a cambiar sin tocar nada todavía.') . '</p>'
            . '<p>' . $this->l('Si está bien, pulsa «Confirmar e importar». Al terminar verás el resumen y quedará anotado en el registro.') . '</p>'
            . '<p class="help-block">' . $this->l('También sirven los archivos de la versión anterior del módulo (exportación de bajo mínimos, de avisos, etc.).') . '</p>'
            . '</div>'
            . '</div></div>';
    }

    private function formHelper($table)
    {
        $h = new HelperForm();
        $h->show_toolbar = false;
        $h->module = $this;
        $h->table = $table;
        $h->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $h->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $h->submit_action = '';
        $h->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name . '&obs_tab=' . $this->obsTab;
        $h->token = Tools::getAdminTokenLite('AdminModules');
        $h->tpl_vars = ['languages' => $this->context->controller->getLanguages(), 'id_language' => (int) $this->context->language->id];

        return $h;
    }

    private function yesNo($name, $label, $desc, $yes = 'Sí', $no = 'No')
    {
        return [
            'type' => 'switch', 'label' => $label, 'name' => $name, 'is_bool' => true, 'desc' => $desc,
            'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $yes], ['id' => $name . '_off', 'value' => 0, 'label' => $no]],
        ];
    }

    private function renderImportForm()
    {
        $form = ['form' => [
            'legend' => ['title' => $this->l('Subir CSV'), 'icon' => 'icon-upload'],
            'input' => [
                ['type' => 'file', 'label' => $this->l('Archivo CSV'), 'name' => 'stocks_file', 'required' => true,
                    'desc' => $this->l('Separado por punto y coma (;), coma o tabulador; la primera fila con los nombres de las columnas.')],
                $this->yesNo('update_stock', $this->l('Actualizar stock real'), $this->l('Usa la columna CURRENT STOCK.')),
                $this->yesNo('update_alerts', $this->l('Actualizar avisos'), $this->l('Usa las columnas MIN STOCK (o MIN ALERT STOCK) y QTY.')),
                [
                    'type' => 'radio', 'label' => $this->l('Los avisos que no vienen en el archivo'), 'name' => 'alerts_mode',
                    'desc' => $this->l('En modo «sustituir» los avisos quedan exactamente como el archivo. Úsalo solo con un archivo completo de avisos: «Comprobar archivo» te dice antes cuántos se van a borrar, y se guarda una copia (pestaña Registro).'),
                    'values' => [
                        ['id' => 'alerts_mode_0', 'value' => 0, 'label' => $this->l('Se mantienen como están (solo se cambian las referencias del archivo)')],
                        ['id' => 'alerts_mode_1', 'value' => 1, 'label' => $this->l('Se borran (sustituir todos los avisos por los del archivo)')],
                    ],
                ],
                [
                    'type' => 'radio', 'label' => $this->l('El stock del archivo'), 'name' => 'add_stock',
                    'values' => [
                        ['id' => 'add_stock_0', 'value' => 0, 'label' => $this->l('Sustituye al actual (inventario: el producto queda con ese stock)')],
                        ['id' => 'add_stock_1', 'value' => 1, 'label' => $this->l('Se suma al actual (entrada de mercancía; números negativos restan)')],
                    ],
                ],
            ],
            'submit' => ['title' => $this->l('Comprobar archivo'), 'name' => 'obsCheck', 'icon' => 'process-icon-preview', 'id' => 'obs_check_btn'],
            'buttons' => [
                ['type' => 'submit', 'name' => 'obsImportNow', 'title' => $this->l('Importar sin comprobar'), 'icon' => 'process-icon-upload', 'class' => 'btn btn-default pull-right',
                    'id' => 'obs_import_now'],
            ],
        ]];

        $h = $this->formHelper('obsstocks_import');
        $h->tpl_vars['fields_value'] = [
            'stocks_file' => '',
            'update_stock' => (int) Tools::getValue('update_stock', 1),
            'update_alerts' => (int) Tools::getValue('update_alerts', 1),
            'add_stock' => (int) Tools::getValue('add_stock', 0),
            'alerts_mode' => (int) Tools::getValue('alerts_mode', 0),
        ];

        return $h->generateForm([$form]);
    }

    private function renderReport(array $r, $pending)
    {
        if ($r['error']) {
            return $this->displayError('<strong>' . $this->e($r['file']) . ':</strong> ' . $this->e($r['error'])
                . '<br>' . $this->l('Columnas encontradas en la primera fila:') . ' <code>' . $this->e(implode(' | ', $r['header'])) . '</code>');
        }

        $labels = ['reference' => 'REFERENCE', 'type' => 'TYPE', 'id' => 'OBJECT_ID', 'stock' => 'CURRENT STOCK', 'min' => 'MIN STOCK', 'qty' => 'QTY'];
        $cols = [];
        foreach ($r['map'] as $f => $i) {
            $cols[] = $labels[$f] . ' ← «' . $this->e($r['header'][$i]) . '»';
        }
        $ignored = [];
        if (!Tools::getValue('update_stock') && isset($r['map']['stock'])) {
            $ignored[] = $this->l('el archivo trae stock pero «Actualizar stock real» está desactivado');
        }
        if (!Tools::getValue('update_alerts') && (isset($r['map']['min']) || isset($r['map']['qty']))) {
            $ignored[] = $this->l('el archivo trae avisos pero «Actualizar avisos» está desactivado');
        }

        $title = $r['dry'] ? $this->l('Comprobación (todavía no se ha cambiado nada)') : $this->l('Importación terminada');
        $html = '<div class="panel" id="obs_report"><div class="panel-heading"><i class="icon-' . ($r['dry'] ? 'search' : 'check') . '"></i> '
            . $title . ' — ' . $this->e($r['file']) . '</div>';

        $kind = $r['dry'] ? 'info' : 'success';
        $html .= '<div class="alert alert-' . $kind . '">'
            . sprintf($this->l('%d filas leídas.'), $r['rows']) . ' '
            . ($r['dry'] ? $this->l('Se cambiaría:') : $this->l('Cambiado:')) . ' <strong>' . sprintf($this->l('%d stocks'), $r['stock_changed']) . '</strong>, <strong>'
            . sprintf($this->l('%d avisos'), $r['alert_changed']) . '</strong>. '
            . sprintf($this->l('%d sin cambios.'), $r['unchanged'])
            . (!empty($r['replace']) ? ' ' . ($r['dry'] ? $this->l('Avisos que se borrarían:') : $this->l('Avisos borrados:')) . ' <strong>' . (int) $r['alert_deleted'] . '</strong>.' : '')
            . '<br><small>' . $this->l('Columnas reconocidas:') . ' ' . implode(' · ', $cols) . '</small></div>';
        if ($r['not_found'] || $r['invalid']) {
            $html .= '<div class="alert alert-warning">' . sprintf($this->l('%d filas con referencia no encontrada y %d filas con valores no válidos: no se han tocado. Las tienes en rojo/naranja en la tabla.'), $r['not_found'], $r['invalid']) . '</div>';
        }
        if ($ignored) {
            $html .= '<div class="alert alert-warning">' . $this->l('Atención:') . ' ' . implode('; ', $ignored) . '.</div>';
        }
        if (Tools::getValue('alerts_mode') && empty($r['replace'])) {
            $html .= '<div class="alert alert-warning">' . $this->l('Has elegido sustituir los avisos, pero no se aplica: el archivo no trae columnas de avisos o «Actualizar avisos» está desactivado. No se borra ningún aviso.') . '</div>';
        }
        if (!empty($r['alert_deleted'])) {
            $html .= '<div class="alert alert-danger"><strong>' . $this->l('Modo sustituir.') . '</strong> '
                . ($r['dry']
                    ? sprintf($this->l('Al confirmar se BORRARÁN %d avisos de referencias que no vienen en este archivo. Los tienes en la tabla, marcados «Se borrará». Antes de borrar se guarda una copia de todos los avisos.'), $r['alert_deleted'])
                    : sprintf($this->l('Se han borrado %d avisos de referencias que no venían en el archivo.'), $r['alert_deleted'])
                        . ' ' . (!empty($r['backup']) ? sprintf($this->l('Copia de los avisos anteriores: %s (pestaña Registro).'), $this->e($r['backup'])) : $this->l('No se ha podido guardar la copia de los avisos anteriores (permisos de la carpeta logs del módulo).')))
                . '</div>';
        }
        if ($r['dry'] && !$r['stock_changed'] && !$r['alert_changed'] && empty($r['alert_deleted'])) {
            $html .= '<div class="alert alert-warning">' . $this->l('No hay nada que cambiar con este archivo y estas opciones.') . '</div>';
        }

        if ($r['dry'] && $pending) {
            $h = $this->adminUrl();
            $html .= '<form method="post" action="' . $this->e($h) . '" class="form-inline" style="margin-bottom:15px">'
                . '<input type="hidden" name="obs_file" value="' . $this->e($pending) . '">'
                . '<input type="hidden" name="update_stock" value="' . (int) Tools::getValue('update_stock') . '">'
                . '<input type="hidden" name="update_alerts" value="' . (int) Tools::getValue('update_alerts') . '">'
                . '<input type="hidden" name="add_stock" value="' . (int) Tools::getValue('add_stock') . '">'
                . '<input type="hidden" name="alerts_mode" value="' . (int) Tools::getValue('alerts_mode') . '">'
                . '<button type="submit" name="obsConfirm" id="obs_confirm" class="btn btn-success btn-lg"><i class="icon-check"></i> ' . $this->l('Confirmar e importar') . '</button> '
                . '<a href="' . $this->e($h) . '" class="btn btn-default btn-lg">' . $this->l('Cancelar') . '</a>'
                . ' <span class="help-block" style="display:inline-block;margin-left:10px">'
                . (Tools::getValue('add_stock') ? $this->l('Modo: se SUMA el stock del archivo al actual.') : $this->l('Modo: el stock del archivo SUSTITUYE al actual.'))
                . (!empty($r['replace']) ? ' ' . $this->l('Avisos: se SUSTITUYEN todos por los del archivo.') : '') . '</span>'
                . '</form>';
        }

        // Primero las filas con problemas, luego las que cambian; sin cambios al final
        $order = ['invalid' => 0, 'not_found' => 1, 'partial' => 2, 'deleted' => 3, 'changed' => 4, 'unchanged' => 5];
        $lines = $r['lines'];
        usort($lines, function ($a, $b) use ($order) {
            return $order[$a['status']] == $order[$b['status']] ? $a['line'] - $b['line'] : $order[$a['status']] - $order[$b['status']];
        });
        $st = [
            'invalid' => ['danger', $this->l('Valor no válido')],
            'not_found' => ['danger', $this->l('No encontrado')],
            'partial' => ['warning', $this->l('En parte')],
            'deleted' => ['danger', $r['dry'] ? $this->l('Se borrará') : $this->l('Borrado')],
            'changed' => ['success', $r['dry'] ? $this->l('Cambiará') : $this->l('Cambiado')],
            'unchanged' => ['default', $this->l('Sin cambios')],
        ];
        $max = 500;
        $html .= '<div style="max-height:480px;overflow:auto"><table class="table table-condensed"><thead><tr><th>' . $this->l('Fila') . '</th><th>' . $this->l('Referencia') . '</th><th>'
            . $this->l('Resultado') . '</th><th>' . $this->l('Detalle') . '</th></tr></thead><tbody>';
        foreach (array_slice($lines, 0, $max) as $l) {
            $s = $st[$l['status']];
            $html .= '<tr' . ($s[0] == 'danger' ? ' class="danger"' : ($s[0] == 'warning' ? ' class="warning"' : '')) . '><td>' . ((int) $l['line'] ?: '—') . '</td><td style="white-space:nowrap"><code>' . $this->e($l['reference']) . '</code></td>'
                . '<td><span class="label label-' . $s[0] . '">' . $s[1] . '</span></td><td>' . $this->e($l['text']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
        if (count($lines) > $max) {
            $html .= '<p class="help-block">' . sprintf($this->l('Se muestran las primeras %d filas de %d.'), $max, count($lines)) . '</p>';
        }

        return $html . '</div>';
    }

    private function renderBelowMinimum()
    {
        $rows = Mystocks::belowMinimum();
        $html = '<div class="panel" id="obs_below"><div class="panel-heading"><i class="icon-warning-sign"></i> ' . $this->l('Productos por debajo del stock mínimo')
            . ' <span class="badge' . ($rows ? ' badge-danger' : '') . '">' . count($rows) . '</span>'
            . '<span class="panel-heading-action"><a class="list-toolbar-btn" href="' . $this->e($this->adminUrl('&obs_export=min')) . '" title="' . $this->l('Descargar CSV') . '"><i class="process-icon-download"></i></a></span></div>';
        if (!$rows) {
            return $html . '<p class="text-muted">' . $this->l('Ningún producto está por debajo de su stock mínimo.') . '</p></div>';
        }
        $html .= '<p><a class="btn btn-default" href="' . $this->e($this->adminUrl('&obs_export=min')) . '"><i class="icon-download"></i> '
            . $this->l('Descargar este listado (CSV)') . '</a></p>';
        $html .= '<div style="max-height:400px;overflow:auto"><table class="table table-condensed"><thead><tr><th>' . $this->l('Referencia') . '</th><th>' . $this->l('Producto') . '</th>'
            . '<th class="text-center">' . $this->l('Stock') . '</th><th class="text-center">' . $this->l('Mínimo') . '</th><th class="text-center">' . $this->l('A pedir') . '</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 300) as $r) {
            $link = $this->context->link->getAdminLink('AdminProducts', true, ['id_product' => (int) $r['id_product']], ['id_product' => (int) $r['id_product'], 'updateproduct' => 1]);
            $html .= '<tr><td style="white-space:nowrap"><code>' . $this->e($r['reference']) . '</code></td><td><a href="' . $this->e($link) . '" target="_blank">' . $this->e($r['name']) . '</a></td>'
                . '<td class="text-center"><span class="badge badge-danger">' . (int) $r['stock'] . '</span></td><td class="text-center">' . (int) $r['min_stock'] . '</td><td class="text-center">' . (int) $r['qty'] . '</td></tr>';
        }

        return $html . '</tbody></table></div></div>';
    }

    private function renderCronForm()
    {
        $c1 = $this->cronUrl('cron');
        $c2 = $this->cronUrl('cronAll');
        $url = function ($u) {
            return '<input type="text" class="form-control" readonly value="' . $this->e($u) . '" onclick="this.select()">';
        };
        $form = ['form' => [
            'legend' => ['title' => $this->l('Avisos por email (tareas programadas)'), 'icon' => 'icon-time'],
            'input' => [
                ['type' => 'text', 'label' => $this->l('Email de avisos'), 'name' => 'OBSSTOCKS_ALERT_EMAIL',
                    'desc' => $this->l('Uno o varios, separados por comas. Recibe el CSV con los productos bajo mínimos.')],
                ['type' => 'html', 'name' => 'obs_cron1', 'label' => $this->l('URL cron: bajo mínimos'),
                    'html_content' => $url($c1) . '<p class="help-block">' . $this->l('Envía el email solo si hay productos por debajo del mínimo. Prográmala en el servidor (en Plesk: Tareas programadas > «Obtener una URL»), por ejemplo una vez al día.') . '</p>'
                        . '<button type="submit" name="obsSendMin" class="btn btn-default"><i class="icon-envelope"></i> ' . $this->l('Enviar ahora') . '</button>'],
                ['type' => 'html', 'name' => 'obs_cron2', 'label' => $this->l('URL cron: todo el stock'),
                    'html_content' => $url($c2) . '<p class="help-block">' . $this->l('Envía el stock de todos los productos.') . '</p>'
                        . '<button type="submit" name="obsSendAll" class="btn btn-default"><i class="icon-envelope"></i> ' . $this->l('Enviar ahora') . '</button> '
                        . '<button type="submit" name="obsRegenToken" class="btn btn-default" onclick="return confirm(\'' . addslashes($this->l('Las URL actuales dejarán de funcionar. ¿Continuar?')) . '\')"><i class="icon-refresh"></i> ' . $this->l('Generar nuevas URL') . '</button>'],
            ],
            'submit' => ['title' => $this->l('Guardar'), 'name' => 'submitObsSettings', 'id' => 'obs_settings_save'],
        ]];
        $h = $this->formHelper('obsstocks_cron');
        $h->tpl_vars['fields_value'] = ['OBSSTOCKS_ALERT_EMAIL' => Configuration::get('OBSSTOCKS_ALERT_EMAIL')];

        return $h->generateForm([$form]);
    }

    private function renderLog()
    {
        $f = dirname(__FILE__) . '/logs/stocks.log';
        $lines = is_file($f) ? array_slice(array_filter(explode("\n", (string) Tools::file_get_contents($f))), -30) : [];
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-list"></i> ' . $this->l('Registro (últimos movimientos)') . '</div>';
        if (!$lines) {
            return $html . '<p class="text-muted">' . $this->l('Todavía no hay movimientos.') . '</p></div>';
        }

        return $html . '<pre style="max-height:260px;overflow:auto;white-space:pre-wrap">' . $this->e(implode("\n", array_reverse($lines))) . '</pre></div>';
    }
}
