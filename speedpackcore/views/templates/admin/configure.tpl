{**
 * SpeedPack Core - the head of the settings page and its tabs. Every section that follows starts
 * with a marker (pane.tpl); views/js/config.js shows one section at a time. Without JavaScript the
 * page simply shows all of them.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{if $spc.twice}
  <div class="alert alert-warning">{l s='These separate modules are still on: %s. SpeedPack Core already does their work, so uninstall them, or the same part runs twice.' sprintf=[$spc.twice] mod='speedpackcore'}</div>
{/if}
<div class="spc-head" id="spc-head" data-spc-active="{$spc.active|escape:'html':'UTF-8'}">
  <div class="spc-brand">
    <svg class="spc-logo" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8"/><path d="M18 4L8 18h7l-2 10 11-15h-7z"/></svg>
    <div>
      <h2>SpeedPack Core <small>{$spc.version|escape:'html':'UTF-8'}</small></h2>
      <p>{l s='Speed-ups for the whole shop, each with its own switch and settings.' mod='speedpackcore'}</p>
    </div>
    <a class="btn btn-default spc-ask" href="{$spc.askAudit|escape:'html':'UTF-8'}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v12H3z"/><path d="M3 7l9 6 9-6"/></svg>
      {l s='Ask for a custom audit of my site' mod='speedpackcore'}
    </a>
  </div>
  <ul class="spc-tabs" role="tablist">
    {foreach from=$spc.tabs item=tab}
      <li><a href="#spc-{$tab.id|escape:'html':'UTF-8'}" role="tab" data-spc-tab="{$tab.id|escape:'html':'UTF-8'}">{$tab.title|escape:'html':'UTF-8'}</a></li>
    {/foreach}
  </ul>
</div>
