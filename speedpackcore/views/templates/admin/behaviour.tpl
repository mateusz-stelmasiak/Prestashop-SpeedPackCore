{**
 * SpeedPack Core - Behaviour: the report. views/js/behaviour-admin.js asks for it and draws it.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel spc-bh" id="spc-behaviour"
  data-spc-bh-url="{$spc_bh.url|escape:'html':'UTF-8'}"
  data-spc-bh-texts="{$spc_bh.texts|escape:'html':'UTF-8'}"
  data-spc-bh-enabled="{if $spc_bh.enabled}1{else}0{/if}">
  <h3><i class="icon-bar-chart"></i> {l s='Shopper behaviour' mod='speedpackcore'}</h3>
  <form class="spc-bh-filters" data-spc-bh-filters>
    <input type="search" class="form-control spc-bh-q" name="q" autocomplete="off" data-spc-bh-q>
    <select class="form-control" name="range" data-spc-bh-select="range"></select>
    <select class="form-control" name="bucket" data-spc-bh-select="bucket"></select>
    <select class="form-control" name="device" data-spc-bh-select="device"></select>
    <select class="form-control" name="source" data-spc-bh-select="source"></select>
    <select class="form-control" name="outcome" data-spc-bh-select="outcome"></select>
    <select class="form-control" name="returning" data-spc-bh-select="returning"></select>
  </form>
  <div class="spc-bh-note" data-spc-bh-note hidden></div>
  <div data-spc-bh-report></div>
</div>
<div class="spc-bh-modal" data-spc-bh-modal hidden>
  <div class="spc-bh-modal-box" role="dialog" aria-modal="true">
    <button type="button" class="close" data-spc-bh-close>&times;</button>
    <div data-spc-bh-session></div>
  </div>
</div>
