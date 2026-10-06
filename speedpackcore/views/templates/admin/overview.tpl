{**
 * SpeedPack Core - the overview: the last speed audit, and every part with its state.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="spc-overview">
  {if $spc_overview.audit}
    <div class="spc-result">
      <div class="spc-result-text">
        <span class="spc-kicker">{l s='Last speed audit' mod='speedpackcore'} · {$spc_overview.audit.at|escape:'html':'UTF-8'}</span>
        <div class="spc-figures">
          {if $spc_overview.audit.clicks}<div><b>{$spc_overview.audit.clicks|escape:'html':'UTF-8'}×</b><span>{l s='faster from click to page' mod='speedpackcore'}</span></div>{/if}
          {if $spc_overview.audit.pages}<div><b>{$spc_overview.audit.pages|escape:'html':'UTF-8'}×</b><span>{l s='faster server answers' mod='speedpackcore'}</span></div>{/if}
          {if $spc_overview.audit.cart}<div><b>{$spc_overview.audit.cart|escape:'html':'UTF-8'}×</b><span>{l s='faster add to cart' mod='speedpackcore'}</span></div>{/if}
        </div>
      </div>
      <a class="btn btn-default" href="#spc-audit" data-spc-goto="audit">{l s='Measure again' mod='speedpackcore'}</a>
    </div>
  {else}
    <div class="spc-result spc-result-first">
      <div class="spc-result-text">
        <span class="spc-kicker">{l s='Speed audit' mod='speedpackcore'}</span>
        <p>{l s='See what SpeedPack does for this shop: every part measured with it and without it, in about a minute.' mod='speedpackcore'}</p>
      </div>
      <a class="btn btn-primary" href="#spc-audit" data-spc-goto="audit">{l s='Measure my shop' mod='speedpackcore'}</a>
    </div>
  {/if}

  <div class="spc-cards">
    {foreach from=$spc_overview.cards item=card}
      <div class="spc-card spc-card-{$card.level|escape:'html':'UTF-8'}">
        <div class="spc-card-top">
          <span class="spc-card-icon" aria-hidden="true">
            {if $card.id == 'cache'}<svg viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="7" ry="2.5"/><path d="M5 5.5v6c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5v-6M5 11.5v6c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5v-6"/></svg>
            {elseif $card.id == 'smartprefetch'}<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><path d="M12 12V3"/><circle cx="16" cy="7.5" r="1.2"/></svg>
            {elseif $card.id == 'instantnav'}<svg viewBox="0 0 24 24"><path d="M13.5 2L6 13h5l-1.5 9L18 10h-5z"/></svg>
            {elseif $card.id == 'instantcart'}<svg viewBox="0 0 24 24"><path d="M2 4h3l2.5 11h11l2-8H6.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/></svg>
            {elseif $card.id == 'cartspeed'}<svg viewBox="0 0 24 24"><path d="M3.5 17a8.5 8.5 0 1 1 17 0"/><path d="M12 17l4.5-6"/></svg>
            {elseif $card.id == 'behaviour'}<svg viewBox="0 0 24 24"><circle cx="5" cy="18" r="2"/><circle cx="12" cy="7" r="2"/><circle cx="19" cy="14" r="2"/><path d="M6.2 16.3l4.6-7.6M13.4 8.4l4.3 4.1"/></svg>
            {else}<svg viewBox="0 0 24 24"><path d="M3 12h4l2.5-6 4 12 2.5-6h5"/></svg>{/if}
          </span>
          <span class="spc-pill spc-pill-{$card.level|escape:'html':'UTF-8'}">{$card.status|escape:'html':'UTF-8'}</span>
        </div>
        <h4>{$card.name|escape:'html':'UTF-8'}</h4>
        <p class="spc-card-what">{$card.what|escape:'html':'UTF-8'}</p>
        <p class="spc-card-fact">{$card.fact|escape:'html':'UTF-8'}</p>
        <div class="spc-card-actions">
          <a href="#spc-{$card.id|escape:'html':'UTF-8'}" data-spc-goto="{$card.id|escape:'html':'UTF-8'}">{l s='Settings' mod='speedpackcore'} →</a>
          {if $card.switch}
            <form method="post" action="{$spc_overview.url|escape:'html':'UTF-8'}">
              <input type="hidden" name="spc_part" value="{$card.id|escape:'html':'UTF-8'}">
              <button type="submit" name="submitSpcToggle" value="1" class="spc-switch{if $card.on} is-on{/if}" aria-pressed="{if $card.on}true{else}false{/if}" title="{if $card.on}{l s='Switch off' mod='speedpackcore'}{else}{l s='Switch on' mod='speedpackcore'}{/if}"><span></span></button>
            </form>
          {/if}
        </div>
      </div>
    {/foreach}
  </div>
</div>
