<?php
/**
 * Actualizaciones del módulo desde el servidor de actualizaciones de josra.
 *
 * - Cada 6 horas, cuando un empleado entra en el back-office, avisa al
 *   servidor de que el módulo está instalado (módulo, versión, dominio de la
 *   tienda y versiones de PrestaShop y PHP) y recibe la última versión.
 * - En la configuración del módulo muestra si hay versión nueva y permite
 *   actualizar desde la propia tienda, con copia de seguridad previa y
 *   ejecución de los scripts upgrade/.
 * - En PrestaShop 8.1 o superior, la versión nueva aparece además en la pestaña
 *   nativa "Actualizaciones" del gestor de módulos (hook actionListModules),
 *   y al abrir el gestor se vuelve a preguntar al servidor (como mucho cada 5 minutos).
 * - Avisa al servidor cuando el módulo se desinstala y del resultado de cada
 *   actualización hecha desde la configuración del módulo.
 *
 * Para usarlo en otro módulo:
 * 1. Copia este archivo a classes/ y cambia el nombre de la clase
 *    (NombremoduloUpdater, para que no choque con la de otros módulos).
 * 2. En el módulo: require_once de este archivo, registrar getHooks() al instalar
 *    y añadir estos tres métodos:
 *      hookActionAdminControllerSetMedia() -> $updater->maybePing()
 *      hookActionListModules()             -> return $updater->getNativeModuleList()
 *      getContent(): $updater->processRequest() al principio y $updater->renderPanel()
 *      uninstall(): $updater->notifyUninstall() al principio
 * 3. La clave API_KEY es propia de cada módulo (ficha del módulo en el panel del servidor).
 * 4. Un script upgrade/upgrade-X.php que llame a $updater->registerHooks().
 *
 * Compatible con PrestaShop 1.6 a 9 y PHP 5.6 a 8.x.
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ObsstocksUpdater
{
    /** URL del servidor de actualizaciones, con barra final (Ajustes del panel) */
    const SERVER_URL = 'https://actualizaciones.donxarope.com/';

    /** Clave de este módulo en el servidor de actualizaciones: solo vale para él (ficha del módulo en el panel) */
    const API_KEY = '9e041f6403d57818125e48ad9c2d2e69f3a813c6';

    /** 0 = normal · 1 = requiere alta en el servidor para esta tienda */
    const CHANNEL = 0;

    /** Segundos entre avisos automáticos (6 horas) */
    const CHECK_EVERY = 21600;

    /** Al abrir el gestor de módulos (8.1+) se vuelve a preguntar si el último aviso tiene más de estos segundos */
    const CHECK_ON_LIST = 300;

    /** Si el servidor no contesta, las comprobaciones del gestor de módulos esperan estos segundos (todos los módulos) */
    const RETRY_AFTER_FAIL = 600;

    /** @var Module */
    protected $module;

    /** @var string prefijo de las claves de configuración */
    protected $key;

    public function __construct($module)
    {
        $this->module = $module;
        $this->key = Tools::strtoupper($module->name) . '_UPD';
    }

    public function isConfigured()
    {
        return trim(self::SERVER_URL) !== '' && trim(self::API_KEY) !== '';
    }

    /**
     * Hooks que necesita el actualizador en esta versión de PrestaShop.
     * actionListModules solo desde 8.1: en 8.0 el gestor no usa download_url
     * y mostraría un botón "Actualizar" que no descarga nada.
     */
    public function getHooks()
    {
        $hooks = array('actionAdminControllerSetMedia');
        if (version_compare(_PS_VERSION_, '8.1.0', '>=')) {
            $hooks[] = 'actionListModules';
        }

        return $hooks;
    }

    /**
     * Registra los hooks que falten (instalación, upgrade y autorreparación).
     */
    public function registerHooks()
    {
        $ok = true;
        foreach ($this->getHooks() as $hook) {
            if (!$this->module->isRegisteredInHook($hook)) {
                $ok = $this->module->registerHook($hook) && $ok;
            }
        }

        return $ok;
    }

    /**
     * Respuesta para actionListModules (8.1+): la versión nueva y su descarga,
     * para que salga en la pestaña "Actualizaciones" del gestor de módulos.
     * Si el último aviso tiene más de CHECK_ON_LIST segundos, pregunta antes al servidor,
     * para ver lo pendiente de todos los módulos sin entrar en cada uno.
     */
    public function getNativeModuleList()
    {
        if (!$this->isConfigured()) {
            return array();
        }
        if (!$this->fresh()) {
            return array();
        }

        $this->refreshForList();
        $info = $this->getInfo();
        if (empty($info['update_available']) || empty($info['latest']) || empty($info['download_url'])) {
            return array();
        }

        return array(array(
            'name' => $this->module->name,
            'version_available' => $info['latest'],
            'download_url' => $info['download_url'],
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Aviso al servidor                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Aviso periódico desde el back-office (cada CHECK_EVERY segundos). Nunca lanza errores.
     */
    public function maybePing()
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            $last = (int) Configuration::getGlobalValue($this->key . '_TS');
            if ($last > time() - self::CHECK_EVERY) {
                return;
            }
            // Se marca antes de llamar: si el servidor no responde, no se reintenta en cada página
            Configuration::updateGlobalValue($this->key . '_TS', time());
            $this->check(4);
        } catch (Exception $e) {
            // El back-office nunca debe fallar por esto
        } catch (Throwable $e) {
        }
    }

    /**
     * Comprobación rápida al listar módulos. Nunca lanza errores. Si el servidor
     * no contesta, ningún módulo vuelve a intentarlo desde aquí en RETRY_AFTER_FAIL segundos.
     */
    protected function refreshForList()
    {
        try {
            $now = time();
            if ((int) Configuration::getGlobalValue($this->key . '_TS') > $now - self::CHECK_ON_LIST
                || (int) Configuration::getGlobalValue('JOSRAUPD_FAIL_TS') > $now - self::RETRY_AFTER_FAIL) {
                return;
            }
            Configuration::updateGlobalValue($this->key . '_TS', $now);
            if (!is_array($this->check(3))) {
                Configuration::updateGlobalValue('JOSRAUPD_FAIL_TS', $now);
            }
        } catch (Exception $e) {
            // El gestor de módulos nunca debe fallar por esto
        } catch (Throwable $e) {
        }
    }

    /**
     * Pregunta al servidor por la última versión y guarda la respuesta.
     * Con $force el servidor lee GitHub en ese momento (comprobación a mano).
     *
     * @return array|string datos recibidos, o el motivo del fallo
     */
    public function check($timeout = 10, $force = false)
    {
        if (!$this->isConfigured()) {
            return 'El servidor de actualizaciones no está configurado en este módulo.';
        }

        $response = $this->httpPost(rtrim($this->base(), '/') . '/api.php?action=check', array(
            'key' => self::API_KEY,
            'module' => $this->module->name,
            'version' => $this->module->version,
            'site' => $this->site(),
            'shop_url' => Tools::getShopDomainSsl(true) . __PS_BASE_URI__,
            'ps_version' => _PS_VERSION_,
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'force' => $force ? '1' : '0',
        ), $timeout);

        if (!is_array($response)) {
            return $response;
        }
        if (empty($response['ok'])) {
            return 'El servidor de actualizaciones respondió con un error: ' . (isset($response['error']) ? $response['error'] : 'sin detalle') . '.';
        }

        $info = array(
            'latest' => isset($response['latest']) ? (string) $response['latest'] : '',
            'update_available' => !empty($response['update_available']),
            'changelog' => isset($response['changelog']) ? (string) $response['changelog'] : '',
            'download_url' => isset($response['download_url']) ? (string) $response['download_url'] : '',
            'notice' => isset($response['notice']) ? (string) $response['notice'] : '',
            'checked_at' => time(),
            'for_version' => $this->module->version,
        );
        // En base64 para que Configuration no toque el contenido
        Configuration::updateGlobalValue($this->key . '_INFO', base64_encode(json_encode($info)));

        // El servidor puede indicar que se ha mudado de dominio
        if (!empty($response['server_url']) && preg_match('#^https?://#', (string) $response['server_url'])) {
            $learned = rtrim((string) $response['server_url'], '/') . '/';
            if ($learned !== (string) Configuration::getGlobalValue($this->key . '_SU')) {
                Configuration::updateGlobalValue($this->key . '_SU', $learned);
            }
        }
        // Alta de la tienda (solo si el módulo la necesita)
        if (isset($response['license']) && is_array($response['license'])) {
            if (!empty($response['license']['ok']) && !empty($response['license']['token'])) {
                Configuration::updateGlobalValue($this->key . '_LT', base64_encode(json_encode(array(
                    't' => (string) $response['license']['token'],
                    'e' => isset($response['license']['expires']) ? (int) $response['license']['expires'] : 0,
                    's' => $this->site(),
                ))));
            } elseif (empty($response['license']['ok'])) {
                Configuration::updateGlobalValue($this->key . '_LT', '');
            }
        }

        return $info;
    }

    /**
     * Última respuesta guardada (o array vacío).
     */
    public function getInfo()
    {
        $raw = (string) Configuration::getGlobalValue($this->key . '_INFO');
        $info = $raw !== '' ? json_decode(base64_decode($raw), true) : null;
        if (!is_array($info)) {
            return array();
        }
        // Si ya se actualizó por otra vía, el aviso guardado ya no vale
        if (!isset($info['for_version']) || $info['for_version'] !== $this->module->version) {
            $info['update_available'] = !empty($info['latest']) && version_compare($info['latest'], $this->module->version, '>');
            $info['download_url'] = '';
            $info['notice'] = '';
        }

        return $info;
    }

    /* ------------------------------------------------------------------ */
    /* Avisos al servidor: desinstalación y resultado de una actualización */
    /* ------------------------------------------------------------------ */

    /**
     * Para uninstall() del módulo: avisa de que se desinstala. Nunca lanza errores
     * ni impide desinstalar.
     */
    public function notifyUninstall()
    {
        $this->notify('uninstall', array(), 3);
    }

    /**
     * Resultado de una actualización: 'ok' o 'error' con el motivo.
     */
    protected function report($status, $message = '')
    {
        $message = trim(preg_replace('/\s+/', ' ', strip_tags((string) $message)));
        $this->notify('report', array(
            'status' => $status,
            'message' => Tools::substr($message, 0, 480),
            'version' => $this->module->version,
        ), 4);
    }

    protected function notify($action, array $data, $timeout)
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            $this->httpPost(rtrim($this->base(), '/') . '/api.php?action=' . $action, array_merge(array(
                'key' => self::API_KEY,
                'module' => $this->module->name,
                'site' => $this->site(),
            ), $data), $timeout);
        } catch (Exception $e) {
            // Un aviso que no llega no debe molestar a la tienda
        } catch (Throwable $e) {
        }
    }

    /** Dominio y carpeta de la tienda: así la identifica el servidor */
    protected function site()
    {
        return Tools::strtolower(Tools::getShopDomainSsl(false) . __PS_BASE_URI__);
    }

    /* ------------------------------------------------------------------ */
    /* Servidor con el que se habla y alta de la tienda                    */
    /* ------------------------------------------------------------------ */

    /** URL del servidor; si en un aviso indicó otra (se mudó de dominio), esa */
    protected function base()
    {
        $learned = (string) Configuration::getGlobalValue($this->key . '_SU');
        if ($learned !== '' && preg_match('#^https?://#', $learned)) {
            return $learned;
        }

        return self::SERVER_URL;
    }

    /** Tiendas de desarrollo o pruebas: no necesitan alta */
    protected function localHost()
    {
        $host = Tools::strtolower(preg_replace('#[:/].*$#', '', Tools::getShopDomainSsl(false)));

        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
            || Tools::substr($host, -5) === '.test' || Tools::substr($host, -10) === '.localhost'
            || strpos($host, 'staging') !== false || strpos($host, 'dev.') === 0;
    }

    protected function grant()
    {
        $raw = (string) Configuration::getGlobalValue($this->key . '_LT');
        $data = $raw !== '' ? json_decode(base64_decode($raw), true) : null;

        return is_array($data) ? $data : array();
    }

    /** Sello que debe traer el alta de esta tienda (se comprueba sin servidor) */
    protected function seal($site, $exp)
    {
        return Tools::substr(hash_hmac('sha256', 'josra-licencia|' . Tools::strtolower($this->module->name) . '|' . $site . '|' . (int) $exp, self::API_KEY), 0, 48);
    }

    /** ¿Está el módulo habilitado para esta tienda? */
    public function ready()
    {
        if (self::CHANNEL == 0 || $this->localHost()) {
            return true;
        }
        $g = $this->grant();
        if (empty($g['t']) || !isset($g['e'])) {
            return false;
        }

        return hash_equals($this->seal($this->site(), (int) $g['e']), (string) $g['t']);
    }

    /** ¿Puede recibir versiones nuevas? (un alta con fecha deja de darlas al vencer) */
    public function fresh()
    {
        if (!$this->ready()) {
            return false;
        }
        if (self::CHANNEL == 0 || $this->localHost()) {
            return true;
        }
        $g = $this->grant();
        $e = isset($g['e']) ? (int) $g['e'] : 0;

        return $e === 0 || time() < $e;
    }

    /**
     * Para enable() del módulo: si la tienda aún no tiene su alta guardada, la pide
     * al servidor en ese momento.
     */
    public function canEnable()
    {
        if (!$this->ready()) {
            $this->check(5);
        }

        return $this->ready();
    }

    /** Texto para el error de PrestaShop cuando se intenta activar sin alta */
    public function noticeText()
    {
        return sprintf(
            '%s necesita licencia para esta tienda (%s). Pide la licencia a Josra indicando este dominio y, cuando te confirme que ya la tienes, vuelve a pulsar «Activar».',
            $this->module->displayName,
            rtrim($this->site(), '/')
        );
    }

    /**
     * Cartel grande para la configuración del módulo: sin alta, o con alta pero desactivado.
     * Lleva un botón que comprueba el alta y activa el módulo ahí mismo. Vacío si todo está bien.
     */
    public function renderNotice()
    {
        if (self::CHANNEL == 0) {
            return '';
        }
        $ready = $this->ready();
        if ($ready && $this->module->active) {
            return '';
        }
        $name = Tools::safeOutput($this->module->displayName);
        if ($ready) {
            $title = $name . ' está desactivado';
            $text = 'La licencia de esta tienda está bien, pero el módulo está desactivado. Pulsa el botón para activarlo.';
            $button = 'Activar el módulo';
        } else {
            $title = $name . ' necesita licencia para esta tienda';
            $text = 'Esta tienda (<strong>' . Tools::safeOutput(rtrim($this->site(), '/')) . '</strong>) no tiene licencia de este módulo, así que está desactivado y no hace nada. '
                . 'Pide la licencia a Josra indicando este dominio.</p>'
                . '<p>Cuando te confirme que ya la tienes, <strong>vuelve aquí y pulsa el botón</strong>: el módulo comprobará la licencia y se activará solo.';
            $button = 'Ya tengo la licencia: activar el módulo';
        }

        return '<form method="post" action="' . Tools::safeOutput($this->configUrl()) . '">'
            . '<div class="alert alert-danger" style="font-size:15px;line-height:1.5;padding:18px 22px">'
            . '<h4 style="font-size:19px;margin:0 0 10px">' . $title . '</h4>'
            . '<p>' . $text . '</p>'
            . '<p style="margin:16px 0 0"><button type="submit" name="josraupd_on" value="1" class="btn btn-primary btn-lg"><i class="icon-unlock"></i> ' . $button . '</button></p>'
            . '</div></form>';
    }

    /* ------------------------------------------------------------------ */
    /* Configuración del módulo: acciones y panel                          */
    /* ------------------------------------------------------------------ */

    /**
     * Procesa los botones del panel. Devuelve el HTML de mensajes.
     */
    public function processRequest()
    {
        $html = '';

        // Autorreparación: hooks del actualizador (por si el módulo se subió por FTP)
        $this->registerHooks();

        // Botón del cartel: comprobar el alta y activar el módulo
        if (Tools::isSubmit('josraupd_on')) {
            if ($this->canEnable() && ($this->module->active || $this->module->enable())) {
                $this->module->active = 1;
                $html .= $this->module->displayConfirmation('Licencia comprobada: el módulo ya está activado.');
            } else {
                $html .= $this->module->displayError('Todavía no consta la licencia para ' . rtrim($this->site(), '/') . '. Si te acaban de confirmar que la tienes, espera un minuto y vuelve a pulsar el botón.');
            }
        }
        $html .= $this->renderNotice();

        if (Tools::getValue('josraupd_done')) {
            $html .= $this->module->displayConfirmation(sprintf('Módulo actualizado a la versión %s.', $this->module->version));
        }

        // Scripts upgrade/ pendientes tras cambiar los archivos
        $upgrade = $this->runPendingUpgrades();
        if ($upgrade !== true) {
            $html .= $upgrade;
            $this->report('error', $upgrade);
        }

        if (!$this->isConfigured()) {
            return $html;
        }

        if (Tools::getValue('josraupd_done') && $upgrade === true) {
            // El servidor apunta ya la versión nueva, sin esperar al aviso diario
            $this->check(6);
            $this->report('ok');
        }

        if (Tools::isSubmit('josraupd_check')) {
            $result = $this->check(10, true);
            if (is_array($result)) {
                $html .= $result['update_available']
                    ? $this->module->displayWarning(sprintf('Hay una versión nueva: %s.', $result['latest']))
                    : $this->module->displayConfirmation('Tienes la última versión.');
            } else {
                $html .= $this->module->displayError($result);
            }
        }

        if (Tools::isSubmit('josraupd_install')) {
            $result = $this->install();
            if ($result === true) {
                // Recarga para que entre en juego el código nuevo y se ejecuten sus scripts upgrade/
                Tools::redirectAdmin($this->configUrl() . '&josraupd_done=1');

                return $html;
            }
            $html .= $this->module->displayError($result);
        }

        return $html;
    }

    /**
     * Bloque de actualizaciones para la configuración del módulo.
     */
    public function renderPanel()
    {
        if (!$this->isConfigured()) {
            return '';
        }

        $info = $this->getInfo();
        $action = Tools::safeOutput($this->configUrl());
        $checked = !empty($info['checked_at']) ? date('d/m/Y H:i', (int) $info['checked_at']) : '';

        $body = '';
        if (!empty($info['update_available'])) {
            $body .= '<div class="alert alert-warning"><p><strong>'
                . sprintf('Hay una versión nueva: %s (tienes la %s).', Tools::safeOutput($info['latest']), Tools::safeOutput($this->module->version))
                . '</strong></p>';
            if (!empty($info['changelog'])) {
                $body .= '<p>' . nl2br(Tools::safeOutput($info['changelog'])) . '</p>';
            }
            $body .= '<p>Antes de actualizar se guarda una copia del módulo actual en la carpeta de caché de PrestaShop.</p></div>';
        } else {
            $body .= '<p>Versión instalada: <strong>' . Tools::safeOutput($this->module->version) . '</strong>'
                . ($checked !== '' ? '. Última comprobación: ' . $checked . '.' : '. Aún no se ha comprobado si hay versiones nuevas.')
                . '</p>';
            if (!empty($info['notice'])) {
                $body .= '<div class="alert alert-info">' . Tools::safeOutput($info['notice']) . '</div>';
            }
        }

        $buttons = '<button type="submit" name="josraupd_check" value="1" class="btn btn-default"><i class="icon-refresh"></i> Comprobar actualizaciones</button>';
        if (!empty($info['update_available'])) {
            $buttons .= ' <button type="submit" name="josraupd_install" value="1" class="btn btn-primary" onclick="return confirm(\'¿Actualizar el módulo ahora?\');"><i class="icon-download"></i> Actualizar ahora</button>';
        }

        return '<form method="post" action="' . $action . '" class="defaultForm form-horizontal">'
            . '<div class="panel"><div class="panel-heading"><i class="icon-cloud-download"></i> Actualizaciones</div>'
            . $body
            . '<div class="panel-footer">' . $buttons . '</div></div></form>';
    }

    protected function configUrl()
    {
        // URL completa: en PS 8/9 una relativa o AdminController::$currentIndex da 404
        // porque la configuración se abre por ruta Symfony
        $adminDir = defined('_PS_ADMIN_DIR_') ? basename(_PS_ADMIN_DIR_) : '';

        return Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . ($adminDir !== '' ? $adminDir . '/' : '')
            . 'index.php?controller=AdminModules&configure=' . $this->module->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');
    }

    /* ------------------------------------------------------------------ */
    /* Instalación de la versión nueva                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Descarga e instala la última versión.
     *
     * @return true|string true o el motivo del fallo
     */
    public function install()
    {
        if (!class_exists('ZipArchive')) {
            return 'No se puede actualizar: falta la extensión zip de PHP en este servidor.';
        }
        if (!$this->fresh()) {
            return 'Este módulo no puede actualizarse ahora.';
        }

        // Enlace de descarga recién firmado
        $info = $this->check(15, true);
        if (!is_array($info)) {
            return $info;
        }
        if (empty($info['update_available']) || empty($info['download_url'])) {
            return 'No hay ninguna versión nueva que instalar.';
        }

        $result = $this->installFiles($info);
        if ($result !== true) {
            $this->report('error', 'Al actualizar a la ' . $info['latest'] . ': ' . $result);
        }

        return $result;
    }

    /**
     * @return true|string
     */
    protected function installFiles(array $info)
    {
        $name = $this->module->name;
        $work = _PS_CACHE_DIR_ . 'josraupd/';
        if (!is_dir($work) && !@mkdir($work, 0755, true)) {
            return 'No se puede crear la carpeta ' . $work . ' (permisos).';
        }

        $zipFile = $work . $name . '-' . $info['latest'] . '.zip';
        $download = $this->httpDownload($info['download_url'], $zipFile);
        if ($download !== true) {
            return $download;
        }

        // Comprobar el contenido antes de tocar nada
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            @unlink($zipFile);

            return 'El zip descargado está dañado.';
        }
        $hasMain = false;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = $zip->getNameIndex($i);
            if (strpos($entry, $name . '/') !== 0 || strpos($entry, '..') !== false) {
                $zip->close();
                @unlink($zipFile);

                return 'El zip descargado no es de este módulo (contiene ' . $entry . ').';
            }
            if ($entry === $name . '/' . $name . '.php') {
                $hasMain = true;
            }
        }
        if (!$hasMain) {
            $zip->close();
            @unlink($zipFile);

            return 'El zip descargado no contiene ' . $name . '/' . $name . '.php.';
        }

        $tmpDir = $work . 'tmp-' . $name . '-' . time() . '/';
        if (!$zip->extractTo($tmpDir)) {
            $zip->close();
            @unlink($zipFile);
            $this->removeDir($tmpDir);

            return 'No se pudo descomprimir el zip en ' . $work . ' (permisos o espacio en disco).';
        }
        $zip->close();
        @unlink($zipFile);

        $source = $tmpDir . $name . '/';
        $target = _PS_MODULE_DIR_ . $name . '/';
        $files = $this->listFiles($source);

        // Comprobar permisos de escritura de todo antes de copiar nada
        foreach ($files as $relative) {
            $dest = $target . $relative;
            $check = file_exists($dest) ? $dest : $this->firstExistingParent(dirname($dest));
            if (!is_writable($check)) {
                $this->removeDir($tmpDir);

                return 'No se puede escribir en ' . $check . ': revisa los permisos de la carpeta del módulo. No se ha cambiado nada.';
            }
        }

        $backup = $this->backup($work);
        if ($backup !== true) {
            $this->removeDir($tmpDir);

            return $backup;
        }

        foreach ($files as $relative) {
            $dest = $target . $relative;
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) {
                $this->removeDir($tmpDir);

                return 'No se pudo crear la carpeta ' . dirname($dest) . '. La copia de seguridad está en ' . $work . '.';
            }
            if (!@copy($source . $relative, $dest)) {
                $this->removeDir($tmpDir);

                return 'No se pudo copiar ' . $relative . '. La actualización ha quedado a medias: restaura la copia de seguridad que está en ' . $work . '.';
            }
            if (function_exists('opcache_invalidate') && substr($dest, -4) === '.php') {
                @opcache_invalidate($dest, true);
            }
        }

        $this->removeDir($tmpDir);
        if (method_exists('Tools', 'clearSmartyCache')) {
            Tools::clearSmartyCache();
        }

        return true;
    }

    /**
     * Ejecuta los scripts upgrade/ que falten entre la versión registrada y la de los archivos.
     *
     * @return true|string true, o HTML con el resultado
     */
    public function runPendingUpgrades()
    {
        $name = $this->module->name;
        $dbVersion = (string) Db::getInstance()->getValue('SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'' . pSQL($name) . '\'');
        $fileVersion = (string) $this->module->version;

        if ($dbVersion === '' || !version_compare($fileVersion, $dbVersion, '>')) {
            return true;
        }

        $scripts = array();
        $dir = _PS_MODULE_DIR_ . $name . '/upgrade/';
        foreach (glob($dir . '*.php') ?: array() as $file) {
            if (preg_match('/^upgrade-([0-9][0-9.]*)\.php$/i', basename($file), $m)) {
                $version = rtrim($m[1], '.');
                if (version_compare($version, $dbVersion, '>') && version_compare($version, $fileVersion, '<=')) {
                    $scripts[$version] = $file;
                }
            }
        }
        uksort($scripts, 'version_compare');

        foreach ($scripts as $version => $file) {
            $function = 'upgrade_module_' . str_replace('.', '_', $version);
            if (!function_exists($function)) {
                include_once $file;
            }
            if (!function_exists($function)) {
                return $this->module->displayError('El script upgrade-' . $version . '.php no define la función ' . $function . '().');
            }
            if (!call_user_func($function, $this->module)) {
                // La versión registrada se queda en el último script que funcionó
                return $this->module->displayError('Ha fallado el script de actualización ' . $version . '. Los archivos ya son de la versión ' . $fileVersion . '; revisa el registro de PrestaShop y vuelve a abrir esta configuración para reintentarlo.');
            }
            Module::upgradeModuleVersion($name, $version);
            $dbVersion = $version;
        }

        Module::upgradeModuleVersion($name, $fileVersion);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    protected function backup($work)
    {
        $name = $this->module->name;
        $source = _PS_MODULE_DIR_ . $name . '/';
        $file = $work . $name . '-' . $this->module->version . '-copia-' . date('Ymd-His') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return 'No se pudo crear la copia de seguridad en ' . $work . ' (permisos). No se ha cambiado nada.';
        }
        foreach ($this->listFiles($source) as $relative) {
            $zip->addFile($source . $relative, $name . '/' . $relative);
        }
        if (!$zip->close()) {
            return 'No se pudo guardar la copia de seguridad en ' . $work . ' (espacio en disco o permisos). No se ha cambiado nada.';
        }

        // Conserva solo las 3 copias más recientes de este módulo
        $copies = glob($work . $name . '-*-copia-*.zip') ?: array();
        rsort($copies);
        foreach (array_slice($copies, 3) as $old) {
            @unlink($old);
        }

        return true;
    }

    protected function listFiles($dir)
    {
        $files = array();
        $dir = rtrim($dir, '/') . '/';
        if (!is_dir($dir)) {
            return $files;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($dir)));
            }
        }

        return $files;
    }

    protected function firstExistingParent($dir)
    {
        while ($dir !== '' && $dir !== '/' && !file_exists($dir)) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    protected function removeDir($dir)
    {
        if (is_dir($dir)) {
            Tools::deleteDirectory($dir, true);
        }
    }

    /**
     * @return array|string JSON decodificado o motivo del fallo
     */
    protected function httpPost($url, array $data, $timeout)
    {
        $body = http_build_query($data);
        $code = 0;
        $raw = false;
        $error = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'josra-module/' . $this->module->name,
            ));
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw === false) {
                $error = curl_error($ch);
            }
            curl_close($ch);
        } else {
            $context = stream_context_create(array('http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: josra-module/" . $this->module->name . "\r\n",
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            )));
            $raw = @file_get_contents($url, false, $context);
            if ($raw === false) {
                $error = 'sin respuesta';
            }
            $code = $raw !== false ? 200 : 0;
        }

        if ($raw === false) {
            return 'No se pudo contactar con el servidor de actualizaciones (' . $error . ').';
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return 'El servidor de actualizaciones no respondió correctamente (código ' . $code . '). Revisa la URL configurada en el módulo.';
        }

        return $json;
    }

    /**
     * @return true|string
     */
    protected function httpDownload($url, $file)
    {
        if (function_exists('curl_init')) {
            $fp = @fopen($file, 'wb');
            if (!$fp) {
                return 'No se puede escribir en ' . dirname($file) . ' (permisos).';
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_FILE => $fp,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'josra-module/' . $this->module->name,
            ));
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (!$ok || $code !== 200 || strpos($type, 'zip') === false) {
                $detail = $error !== '' ? $error : 'código ' . $code;
                if ($code >= 400 && filesize($file) < 4096) {
                    $json = json_decode((string) Tools::file_get_contents($file), true);
                    if (isset($json['error'])) {
                        $detail = $json['error'];
                    }
                }
                @unlink($file);

                return 'No se pudo descargar la versión nueva: ' . $detail . '.';
            }

            return true;
        }

        $data = @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => 120))));
        if ($data === false || substr($data, 0, 2) !== 'PK') {
            return 'No se pudo descargar la versión nueva desde el servidor de actualizaciones.';
        }

        return file_put_contents($file, $data) !== false ? true : 'No se puede escribir en ' . dirname($file) . ' (permisos).';
    }
}
