<?php
/**
 * SpeedPack Core - one part of the pack (InstantCart, InstantNav, SmartPrefetch).
 *
 * Each part was a module of its own; it keeps its code and settings, and asks the pack's module
 * for everything a Module used to give it: the hooks, translations, messages and its folder.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class SpcFeature
{
    /** @var string short id, used for the settings form */
    public $id = '';

    /** @var Module the pack */
    public $module;

    /** @var Context */
    public $context;

    /** @var string the pack's name, so links and asset paths point at its folder */
    public $name;

    /** @var string */
    public $version;

    /** @var string */
    public $displayName;

    public function __construct(Module $module, $context, $displayName)
    {
        $this->module = $module;
        $this->context = $context;
        $this->name = $module->name;
        $this->version = $module->version;
        $this->displayName = $displayName;
    }

    /** The part's settings page section. */
    abstract public function getContent();

    /**
     * The part on the overview: whether it is on, a short status and one fact worth knowing.
     *
     * @return array ['on' => bool, 'status' => string, 'fact' => string]
     */
    public function summary()
    {
        return ['on' => true, 'status' => '', 'fact' => ''];
    }

    /** Front-office assets; a part that has none keeps this. */
    public function hookActionFrontControllerSetMedia()
    {
    }

    /** Renders one of the pack's templates with the given variables. */
    protected function render($template, array $vars)
    {
        $this->context->smarty->assign($vars);

        return $this->module->display($this->dir() . '/' . $this->name . '.php', 'views/templates/' . $template);
    }

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    public function l($string)
    {
        return $this->module->l($string, Tools::strtolower(get_class($this)));
    }

    public function registerHook($hook)
    {
        return $this->module->registerHook($hook);
    }

    public function isRegisteredInHook($hook)
    {
        return $this->module->isRegisteredInHook($hook);
    }

    public function displayConfirmation($message)
    {
        return $this->module->displayConfirmation($message);
    }

    public function displayError($message)
    {
        return $this->module->displayError($message);
    }

    /** The pack's folder (the parts used to read files next to their own module class). */
    protected function dir()
    {
        return _PS_MODULE_DIR_ . $this->name;
    }
}
