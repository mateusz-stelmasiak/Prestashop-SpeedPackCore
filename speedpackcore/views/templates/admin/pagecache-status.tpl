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
  <p class="help-block">{l s='Check it from a private window: the answer of a kept page carries the header X-SpeedPack-Cache: HIT (MISS when it was built, BYPASS with the reason when it may not be kept).' mod='speedpackcore'}</p>
</div>
