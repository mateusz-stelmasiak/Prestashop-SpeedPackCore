{**
 * SpeedPack Core - "Order the same as last time" (classes/SpcReorder.php): a card on the home page
 * and in an empty cart, a tile in the customer account. A form: no script needed.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{if $spc_reorder.where == 'account'}
  <form class="col-lg-4 col-md-6 col-sm-6 col-xs-12 spc-reorder-tile" action="{$spc_reorder.url|escape:'html':'UTF-8'}" method="post">
    <input type="hidden" name="token" value="{$spc_reorder.token|escape:'html':'UTF-8'}">
    <button type="submit" class="link-item" data-spc-reorder>
      <i class="material-icons" aria-hidden="true">replay</i>
      {l s='Order the same as last time' mod='speedpackcore'}
    </button>
  </form>
{else}
  <section class="spc-reorder spc-reorder--{$spc_reorder.where|escape:'html':'UTF-8'}" aria-labelledby="spc-reorder-title">
    <div class="spc-reorder-text">
      <h2 id="spc-reorder-title">{if $spc_reorder.firstname}{l s='Welcome back, %s!' sprintf=[$spc_reorder.firstname] mod='speedpackcore'} {/if}{l s='Order the same as last time?' mod='speedpackcore'}</h2>
      <p class="spc-reorder-lines">
        {foreach from=$spc_reorder.lines item=line name=lines}{$line|escape:'html':'UTF-8'}{if !$smarty.foreach.lines.last}, {/if}{/foreach}{if $spc_reorder.more} {l s='and %d more' sprintf=[$spc_reorder.more] mod='speedpackcore'}{/if}
      </p>
      <p class="spc-reorder-meta">{l s='Your order of %1$s · %2$s' sprintf=[$spc_reorder.date, $spc_reorder.total] mod='speedpackcore'}</p>
    </div>
    <form action="{$spc_reorder.url|escape:'html':'UTF-8'}" method="post">
      <input type="hidden" name="token" value="{$spc_reorder.token|escape:'html':'UTF-8'}">
      <button type="submit" class="btn btn-primary spc-reorder-go" data-spc-reorder>
        {if $spc_reorder.payment}{l s='Repeat the order' mod='speedpackcore'}{else}{l s='Add it to the cart' mod='speedpackcore'}{/if}
      </button>
      {if $spc_reorder.payment}<small>{l s='Same address and delivery, straight to payment' mod='speedpackcore'}</small>{/if}
    </form>
  </section>
{/if}
