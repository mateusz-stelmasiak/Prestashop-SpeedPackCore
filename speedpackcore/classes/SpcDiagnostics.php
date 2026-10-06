<?php
/**
 * SpeedPack Core - Health check: PrestaShop's tuning guide checked against this shop (server,
 * PrestaShop, database), database care, ANALYZE TABLE, module weight, and the lines to send the
 * host for what only the host can change. See SpcHealth, SpcCare and SpcWeight.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SpcDiagnostics extends SpcFeature
{
    public $id = 'diagnostics';

    /** The steps posted by views/js/diagnostics.js; answers JSON. */
    public function ajax($what)
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        @set_time_limit(60);
        switch ($what) {
            case 'care_scan':
                $answer = ['items' => SpcCare::scan((array) Tools::getValue('days'))];
                break;
            case 'care':
                $item = (string) Tools::getValue('item');
                $answer = isset(SpcCare::ITEMS[$item]) ? SpcCare::step($item, (int) Tools::getValue('days')) : ['error' => 'unknown item'];
                break;
            case 'analyze':
                $answer = SpcCare::analyze((int) Tools::getValue('offset'));
                break;
            case 'weight':
                $answer = SpcWeight::measure($this->context, $this->module);
                break;
            default:
                $answer = ['error' => 'unknown step'];
        }
        echo json_encode($answer);
        exit;
    }

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitSpcMultiFront')) {
            Configuration::updateValue('PS_SMARTY_LOCAL', 0);
            $out .= $this->displayConfirmation($this->l('Multi-front optimizations switched off.'));
        }
        $health = new SpcHealth($this->module);
        $server = array_merge($health->php(), $health->prestashop());
        $database = $health->database();
        $this->context->controller->addJS($this->module->getPathUri() . 'views/js/diagnostics.js');
        $this->context->controller->addCSS($this->module->getPathUri() . 'views/css/diagnostics.css');
        $care = [];
        foreach (array_keys(SpcCare::ITEMS) as $item) {
            $care[] = ['id' => $item, 'days' => SpcCare::ITEMS[$item][2]];
        }

        return $out . $this->render('admin/diagnostics.tpl', ['spc_diag' => [
            'url' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'server' => $server,
            'database' => $database,
            'server_tally' => SpcHealth::tally($server),
            'database_tally' => SpcHealth::tally($database),
            'host' => $health->hostLines(),
            'host_rows' => substr_count($health->hostLines(), "\n") + 1,
            'multifront' => (bool) Configuration::get('PS_SMARTY_LOCAL'),
            'configuration' => SpcCare::configuration(),
            'care' => $care,
            'min_days' => SpcCare::MIN_DAYS,
            'texts' => json_encode($this->texts()),
        ]]);
    }

    protected function texts()
    {
        return [
            'items' => [
                'log' => [$this->l('Back-office log'), $this->l('Errors and actions written by PrestaShop and modules.')],
                'connections' => [$this->l('Visit statistics'), $this->l('Visits, the pages seen and where visitors came from. Statistics for the removed period are gone.')],
                'guests' => [$this->l('Visitor records'), $this->l('Anonymous visitors that nothing points to any more: no visit kept, no cart, no account.')],
                'carts' => [$this->l('Abandoned guest carts'), $this->l('Carts of visitors without an account that never became an order. Carts of customers are kept.')],
                'pagenotfound' => [$this->l('Pages not found'), $this->l('The statistics of addresses that answered 404.')],
                'statssearch' => [$this->l('Searches'), $this->l('The statistics of what visitors searched for.')],
                'mail' => [$this->l('Sent e-mail log'), $this->l('The list of e-mails the shop sent (not the e-mails themselves).')],
            ],
            'older' => $this->l('older than'),
            'days' => $this->l('days'),
            'clean' => $this->l('Clean'),
            'cleaning' => $this->l('Cleaning...'),
            'cleaned' => $this->l('%s rows removed.'),
            'nothing' => $this->l('Nothing that old.'),
            'confirm' => $this->l('Remove %1$s rows of "%2$s" older than %3$s days? This cannot be undone.'),
            'rows' => $this->l('%s rows'),
            'toRemove' => $this->l('%s to remove'),
            'scanning' => $this->l('Counting...'),
            'analyzing' => $this->l('Analyzing %1$d of %2$d tables...'),
            'analyzed' => $this->l('Done: %d tables analyzed.'),
            'measuring' => $this->l('Fetching the home page, a product page and their files...'),
            'module' => $this->l('Module'),
            'hooks' => $this->l('Front hooks'),
            'files' => $this->l('CSS / JS files'),
            'size' => $this->l('Size'),
            'pages' => $this->l('Home page: %1$s files, %2$s. Product page: %3$s files, %4$s.'),
            'combined' => $this->l('Combine CSS / JavaScript is on, so most files are merged and cannot be told apart by module (shown as "combined"). The hooks are still counted.'),
            'heavy' => $this->l('heavy'),
            'names' => [':combined' => $this->l('combined files'), ':theme' => $this->l('theme'), ':core' => $this->l('PrestaShop core'), ':external' => $this->l('other sites')],
            'copied' => $this->l('Copied.'),
            'failed' => $this->l('Stopped: %s'),
        ];
    }
}
