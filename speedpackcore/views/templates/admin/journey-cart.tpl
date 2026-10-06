{**
 * SpeedPack Core - the cart page has no hook of its own: the path panel comes with the page's head,
 * and views/js/journey.js puts it at the top of the page.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<template id="spc-journey-cart">{include file='./journey.tpl'}</template>
<script src="{$spc_journey_js|escape:'html':'UTF-8'}" defer></script>
