{**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<form class="ic-mini" data-ic-mini method="post" action="{$spc_btn.action|escape:'html':'UTF-8'}">
  <input type="hidden" name="token" value="{$spc_btn.token|escape:'html':'UTF-8'}">
  <input type="hidden" name="id_product" value="{$spc_btn.id_product|intval}">
  <input type="hidden" name="id_product_attribute" value="{$spc_btn.id_product_attribute|intval}">
  <input type="hidden" name="id_customization" value="0">
  <input type="hidden" name="qty" value="{$spc_btn.qty|intval}">
  <button type="submit" class="btn btn-primary btn-sm ic-mini-btn" data-button-action="add-to-cart" title="{$spc_btn.label|escape:'html':'UTF-8'}" aria-label="{$spc_btn.label|escape:'html':'UTF-8'}: {$spc_btn.name|escape:'html':'UTF-8'}"><svg class="ic-cart-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.4 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="19.5" r="1.4"/><circle cx="17" cy="19.5" r="1.4"/></svg></button>
</form>
