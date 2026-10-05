{**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="alert alert-info">
  InstantCart {$spc_ic.version|escape:'html':'UTF-8'}
  · {l s='instant add' mod='speedpackcore'}: {if $spc_ic.enabled}ON{else}OFF{/if}
  · {l s='list button' mod='speedpackcore'}: {if $spc_ic.listing}ON{else}OFF{/if}
  · {l s='list hook' mod='speedpackcore'}: {if $spc_ic.hook}OK{else}{l s='missing' mod='speedpackcore'}{/if}
  {if $spc_ic.catalog} · <strong>{l s='catalog mode is on: no add-to-cart anywhere' mod='speedpackcore'}</strong>{/if}
</div>
