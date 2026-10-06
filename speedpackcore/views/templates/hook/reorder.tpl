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
    {if $spc_reorder.thumbs}
      <div class="spc-reorder-thumbs" aria-hidden="true">
        {foreach from=$spc_reorder.thumbs item=thumb}
          <img src="{$thumb.src|escape:'html':'UTF-8'}" alt="" width="64" height="64" loading="lazy" decoding="async">
        {/foreach}
        {if $spc_reorder.more}<span class="spc-reorder-more">+{$spc_reorder.more|intval}</span>{/if}
      </div>
    {else}
      <div class="spc-reorder-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/></svg>
      </div>
    {/if}
    <div class="spc-reorder-text">
      <p class="spc-reorder-kicker">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/></svg>
        {if $spc_reorder.firstname}{l s='Welcome back, %s!' sprintf=[$spc_reorder.firstname] mod='speedpackcore'}{else}{l s='Order the same as last time' mod='speedpackcore'}{/if}
      </p>
      <h2 id="spc-reorder-title">{l s='Order the same as last time?' mod='speedpackcore'}</h2>
      <p class="spc-reorder-lines">
        {foreach from=$spc_reorder.lines item=line name=lines}<span>{$line|escape:'html':'UTF-8'}</span>{if !$smarty.foreach.lines.last}<i aria-hidden="true">·</i>{/if}{/foreach}{if $spc_reorder.more} <em>{l s='and %d more' sprintf=[$spc_reorder.more] mod='speedpackcore'}</em>{/if}
      </p>
      <p class="spc-reorder-meta">
        <span>{l s='Your order of %s' sprintf=[$spc_reorder.date] mod='speedpackcore'}</span>
        <b>{$spc_reorder.total|escape:'html':'UTF-8'}</b>
      </p>
    </div>
    <form class="spc-reorder-action" action="{$spc_reorder.url|escape:'html':'UTF-8'}" method="post">
      <input type="hidden" name="token" value="{$spc_reorder.token|escape:'html':'UTF-8'}">
      <button type="submit" class="btn btn-primary spc-reorder-go" data-spc-reorder>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/></svg>
        {if $spc_reorder.payment}{l s='Repeat the order' mod='speedpackcore'}{else}{l s='Add it to the cart' mod='speedpackcore'}{/if}
      </button>
      {if $spc_reorder.payment}
        <ul class="spc-reorder-perks">
          <li>{l s='Same address and delivery' mod='speedpackcore'}</li>
          <li>{l s='Straight to payment' mod='speedpackcore'}</li>
        </ul>
      {/if}
    </form>
  </section>
{/if}
