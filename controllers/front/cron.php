<?php
/**
 * OBS Stocks - cron: envía por email el productos por debajo del stock mínimo.
 * URL (con clave): Módulos > OBS Stocks > Configurar
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ObsstocksCronModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    /** Debe funcionar aunque la tienda esté en mantenimiento */
    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    public function initContent()
    {
        $this->runCron();
    }

    public function displayAjax()
    {
        $this->runCron();
    }

    public function display()
    {
        $this->runCron();
    }

    private function runCron()
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        $token = (string) Configuration::getGlobalValue('OBSSTOCKS_CRON_TOKEN');
        if ($token === '' || !hash_equals($token, (string) Tools::getValue('token'))) {
            http_response_code(403);
            echo "Acceso denegado: token no válido.\n";
            exit;
        }
        @set_time_limit(0);
        list(, , $msg) = $this->module->sendStockMail('min');
        echo $msg . "\n";
        exit;
    }
}
