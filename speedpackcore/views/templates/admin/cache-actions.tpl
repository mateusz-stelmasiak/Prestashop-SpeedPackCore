{**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel" id="spc-cache-actions" data-spc-url="{$spc_actions.url|escape:'html':'UTF-8'}"{if $spc_actions.autostart} data-spc-autostart="1"{/if}>
  <h3><i class="icon-refresh"></i> {l s='Empty and refill' mod='speedpackcore'}</h3>
  <p>{l s='Empty the cache after changing the theme, a module or prices shown in templates. The warm-up then visits the busiest pages so the cache is full again.' mod='speedpackcore'}</p>
  <form method="post" action="{$spc_actions.url|escape:'html':'UTF-8'}">
    <button type="submit" name="submitSpcFlush" class="btn btn-default"><i class="icon-eraser"></i> {l s='Empty the cache' mod='speedpackcore'}</button>
    <button type="button" class="btn btn-default" data-spc-warmup><i class="icon-fire"></i> {l s='Warm up now' mod='speedpackcore'}</button>
    {if $spc_actions.opcache}<button type="submit" name="submitSpcOpcache" class="btn btn-default"><i class="icon-undo"></i> {l s='Reset OPcache' mod='speedpackcore'}</button>{/if}
  </form>
  <div class="spc-job" data-spc-job hidden>
    <div class="progress" style="margin:14px 0 6px"><div class="progress-bar" data-spc-bar style="width:0%"></div></div>
    <p class="help-block" data-spc-say>{l s='Starting...' mod='speedpackcore'}</p>
  </div>
</div>
