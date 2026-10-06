{**
 * SpeedPack Core - the shopper's path on an order or a cart page (SpcBehaviour::present()):
 * every visit behind it, oldest first, page by page, with what happened on each.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<link rel="stylesheet" href="{$spc_journey.css|escape:'html':'UTF-8'}">
<div class="{if $spc_journey.card}card{else}panel{/if} spc-journey" id="spc-journey" data-spc-journey="{$spc_journey.where|escape:'html':'UTF-8'}">
  <div class="{if $spc_journey.card}card-header{else}panel-heading{/if} spc-journey-head">
    <span class="spc-journey-title">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="18" r="2"/><circle cx="12" cy="7" r="2"/><circle cx="19" cy="14" r="2"/><path d="M6.2 16.3l4.6-7.6M13.4 8.4l4.3 4.1"/></svg>
      {$spc_journey.t.title|escape:'html':'UTF-8'}
    </span>
    {if !$spc_journey.empty}
      <span class="spc-jpill o-{$spc_journey.outcome|escape:'html':'UTF-8'}">{$spc_journey.outcomeText|escape:'html':'UTF-8'}</span>
      <a class="spc-journey-link" href="{$spc_journey.link|escape:'html':'UTF-8'}">{$spc_journey.t.all|escape:'html':'UTF-8'} &rarr;</a>
    {/if}
  </div>
  <div class="{if $spc_journey.card}card-body{else}panel-body{/if}">
    {if $spc_journey.empty}
      <p class="spc-journey-none">{$spc_journey.t.none|escape:'html':'UTF-8'}</p>
    {else}
      <div class="spc-jtiles">
        {foreach from=$spc_journey.tiles item=tile}
          <div class="spc-jtile"><b{if $tile.text} class="is-text"{/if}>{$tile.value|escape:'html':'UTF-8'}</b><span>{$tile.label|escape:'html':'UTF-8'}</span></div>
        {/foreach}
      </div>
      <ol class="spc-jvisits">
        {foreach from=$spc_journey.visits item=visit}
          {if $visit.gap}<li class="spc-jgap"><span>{$visit.gap|escape:'html':'UTF-8'}</span></li>{/if}
          <li class="spc-jvisit o-{$visit.outcome|escape:'html':'UTF-8'}">
            <div class="spc-jvisit-head">
              <span class="spc-jnum">{$visit.label|escape:'html':'UTF-8'}</span>
              <b class="spc-jdate">{$visit.date|escape:'html':'UTF-8'}</b>
              <span class="spc-jmeta">
                <span class="spc-jdev d-{$visit.deviceKind|escape:'html':'UTF-8'}">{$visit.device|escape:'html':'UTF-8'}</span>
                <span>{$visit.source|escape:'html':'UTF-8'}</span>
                <span>{$visit.length|escape:'html':'UTF-8'}</span>
                <span>{$visit.pages|escape:'html':'UTF-8'}</span>
                <span>{$visit.engaged|escape:'html':'UTF-8'}</span>
              </span>
              <span class="spc-jpill o-{$visit.outcome|escape:'html':'UTF-8'}">{$visit.outcomeText|escape:'html':'UTF-8'}{if $visit.total} · {$visit.total|escape:'html':'UTF-8'}{/if}</span>
            </div>
            <ol class="spc-jflow">
              {foreach from=$visit.steps item=step}
                <li class="spc-jstep k-{$step.kind|escape:'html':'UTF-8'}" title="{$step.at|escape:'html':'UTF-8'} · {$step.url|escape:'html':'UTF-8'}">
                  <span class="spc-jcard">
                    {if $step.type}<small>{$step.type|escape:'html':'UTF-8'}</small>{/if}
                    <strong>{$step.name|escape:'html':'UTF-8'}</strong>
                    {if $step.time}<em>{$step.time|escape:'html':'UTF-8'}</em>{/if}
                  </span>
                  {if $step.events}
                    <span class="spc-jevents">
                      {foreach from=$step.events item=ev}<span class="spc-jev e-{$ev.kind|escape:'html':'UTF-8'}">{$ev.text|escape:'html':'UTF-8'}</span>{/foreach}
                    </span>
                  {/if}
                </li>
              {/foreach}
              {if $visit.more}<li class="spc-jstep spc-jmore"><span class="spc-jcard"><strong>{$visit.more|escape:'html':'UTF-8'}</strong></span></li>{/if}
            </ol>
          </li>
        {/foreach}
      </ol>
    {/if}
  </div>
</div>
