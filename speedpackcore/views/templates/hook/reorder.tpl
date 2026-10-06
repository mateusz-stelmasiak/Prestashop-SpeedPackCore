{**
 * SpeedPack Core - "Order the same as last time" (classes/SpcReorder.php): a card on the home page
 * and in an empty cart, a tile in the customer account. A form: no script needed. The icons carry
 * their own size and colours, so they stay small even before the stylesheet arrives.
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
    <div class="spc-reorder-main">
      <p class="spc-reorder-kicker">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/></svg>
        {if $spc_reorder.firstname}{l s='Welcome back, %s!' sprintf=[$spc_reorder.firstname] mod='speedpackcore'}{else}{l s='Order the same as last time' mod='speedpackcore'}{/if}
      </p>
      <h2 id="spc-reorder-title">{l s='Order the same as last time?' mod='speedpackcore'}</h2>
      <p class="spc-reorder-meta">
        <span>{l s='Your order of %s' sprintf=[$spc_reorder.date] mod='speedpackcore'}</span>
        <b>{$spc_reorder.total|escape:'html':'UTF-8'}</b>
      </p>
      <form class="spc-reorder-action" action="{$spc_reorder.url|escape:'html':'UTF-8'}" method="post">
        <input type="hidden" name="token" value="{$spc_reorder.token|escape:'html':'UTF-8'}">
        <button type="submit" class="btn btn-primary spc-reorder-go" data-spc-reorder>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/></svg>
          {if $spc_reorder.payment}{l s='Repeat the order' mod='speedpackcore'}{else}{l s='Add it to the cart' mod='speedpackcore'}{/if}
        </button>
        {if $spc_reorder.payment}
          <span class="spc-reorder-perks">
            <span>{l s='Same address and delivery' mod='speedpackcore'}</span>
            <span>{l s='Straight to payment' mod='speedpackcore'}</span>
          </span>
        {/if}
      </form>
    </div>
    <ul class="spc-reorder-items">
      {foreach from=$spc_reorder.list item=item}
        <li>
          {if $item.src}
            <img src="{$item.src|escape:'html':'UTF-8'}" alt="" width="48" height="48" loading="lazy" decoding="async">
          {else}
            <span class="spc-reorder-noimg" aria-hidden="true"></span>
          {/if}
          <span class="spc-reorder-name">{$item.name|escape:'html':'UTF-8'}</span>
          <span class="spc-reorder-qty">&times;&nbsp;{$item.qty|intval}</span>
        </li>
      {/foreach}
      {if $spc_reorder.more}<li class="spc-reorder-moreline">{l s='and %d more' sprintf=[$spc_reorder.more] mod='speedpackcore'}</li>{/if}
    </ul>
  </section>
{/if}
