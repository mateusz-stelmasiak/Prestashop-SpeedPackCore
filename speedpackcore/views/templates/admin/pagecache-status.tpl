{**
 * SpeedPack Core - Page cache: what it holds and how often it answered today.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="spc-opt">
  <div class="spc-opt-tiles">
    <div class="spc-opt-tile">
      <b>{if $spc_pc.enabled}{l s='On' mod='speedpackcore'}{else}{l s='Off' mod='speedpackcore'}{/if}</b>
      <span>{l s='Page cache' mod='speedpackcore'}</span>
    </div>
    <div class="spc-opt-tile">
      <b>{$spc_pc.pages|intval}</b>
      <span>{l s='pages kept' mod='speedpackcore'} · {$spc_pc.size|escape:'html':'UTF-8'} MB</span>
    </div>
    <div class="spc-opt-tile">
      <b>{if $spc_pc.rate !== null}{$spc_pc.rate|escape:'html':'UTF-8'}%{else}–{/if}</b>
      <span>{l s='served ready today' mod='speedpackcore'} ({$spc_pc.hits|intval} / {($spc_pc.hits + $spc_pc.misses)|intval})</span>
    </div>
    <div class="spc-opt-tile">
      <b>{$spc_pc.ttl|intval} h</b>
      <span>{l s='longest a page is kept' mod='speedpackcore'}</span>
    </div>
  </div>
  {if $spc_pc.test}
    <div class="alert {if $spc_pc.test.ok}alert-success{else}alert-warning{/if}">
      <strong>{$spc_pc.test.title|escape:'html':'UTF-8'}</strong><br>{$spc_pc.test.text|escape:'html':'UTF-8'}
    </div>
  {/if}
  {if $spc_pc.enabled && !$spc_pc.writable}
    <div class="alert alert-danger">{l s='The page cache folder cannot be written:' mod='speedpackcore'} <code>{$spc_pc.folder|escape:'html':'UTF-8'}</code></div>
  {/if}
  {if $spc_pc.enabled}
    <div class="spc-warm" id="spc-warm" data-url="{$spc_pc.warm.url|escape:'html':'UTF-8'}" data-texts="{$spc_pc.warm.texts|escape:'html':'UTF-8'}">
      <p>
        <button type="button" class="btn btn-default" data-spc-warm><i class="icon-fire"></i> {l s='Warm the whole catalogue now' mod='speedpackcore'}</button>
        <span class="spc-warm-state" data-spc-warm-state>{if $spc_pc.warm.on}{if $spc_pc.warm.queued}{l s='Pages waiting to be warmed:' mod='speedpackcore'} {$spc_pc.warm.queued|intval}{elseif $spc_pc.warm.auto}{l s='Cleared pages are warmed again automatically.' mod='speedpackcore'}{/if}{/if}</span>
      </p>
      <div class="spc-warm-bar" data-spc-warm-bar hidden><span></span></div>
      <p class="help-block">{l s='Cron (warms what was cleared, then the catalogue, about 25 seconds a call; every 5 to 15 minutes):' mod='speedpackcore'}<br><code>{$spc_pc.warm.cron|escape:'html':'UTF-8'}</code>{if !$spc_pc.warm.auto}<br>{l s='This server cannot warm pages after a visit (no PHP-FPM), so use the cron address.' mod='speedpackcore'}{/if}</p>
    </div>
  {/if}
  <p class="help-block">{l s='Check it from a private window: the answer of a kept page carries the header X-SpeedPack-Cache: HIT (MISS when it was built, BYPASS with the reason when it may not be kept).' mod='speedpackcore'}</p>
</div>
