{**
 * SpeedPack Core - the AI agents tab: the score, the one-click run, what each part is, settings.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="spc-ag" id="spc-agents"
     data-ajax="{$spc_ag.ajax|escape:'html':'UTF-8'}"
     data-active="{if $spc_ag.active}1{else}0{/if}"
     data-steps="{$spc_ag.json.steps|escape:'html':'UTF-8'}"
     data-step-checks="{$spc_ag.json.stepChecks|escape:'html':'UTF-8'}"
     data-names="{$spc_ag.json.names|escape:'html':'UTF-8'}"
     data-optional="{$spc_ag.json.optional|escape:'html':'UTF-8'}"
     data-last="{$spc_ag.json.last|escape:'html':'UTF-8'}"
     data-t-checking="{l s='Asking your shop from the outside, the way agents do…' mod='speedpackcore'}"
     data-t-ready="{l s='agent-ready' mod='speedpackcore'}"
     data-t-pass="{l s='works' mod='speedpackcore'}"
     data-t-fail="{l s='missing' mod='speedpackcore'}"
     data-t-optional="{l s='optional' mod='speedpackcore'}"
     data-t-local="{l s='set up (your server would not let the shop ask itself)' mod='speedpackcore'}"
     data-t-done="{l s='Your shop is ready for AI agents' mod='speedpackcore'}"
     data-t-partly="{l s='Almost there: see what still needs attention below' mod='speedpackcore'}"
     data-t-confirm-off="{l s='Take back everything the AI agents section published?' mod='speedpackcore'}">

  <div class="spc-ag-hero">
    <div class="spc-ag-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
    <div class="spc-ag-hero-text">
      <div class="spc-ag-kicker">✦ {l s='New in SpeedPack' mod='speedpackcore'}</div>
      <h2>{l s='Get your shop ready for AI agents' mod='speedpackcore'}</h2>
      <p class="spc-ag-lead">{l s='ChatGPT, Claude, Gemini, Perplexity and the shopping agents behind them do not browse like people. They look for files, APIs and signals a normal shop does not have, and skip shops that do not answer. One click sets up all of them, explains each one, and proves it works.' mod='speedpackcore'}</p>
      <ul class="spc-ag-chips">
        <li>robots.txt + Content-Signal</li><li>llms.txt</li><li>Markdown</li><li>API catalog</li><li>OpenAPI</li><li>MCP</li><li>A2A</li><li>Agent Skills</li><li>WebMCP</li><li>auth.md</li><li>Web Bot Auth</li><li>UCP</li><li>ACP</li><li>DNS-AID</li>
      </ul>
      <div class="spc-ag-cta">
        <button type="button" class="spc-ag-go" data-spc-ag="run">
          <span class="spc-ag-go-glow" aria-hidden="true"></span>
          {if $spc_ag.active}{l s='Run it again' mod='speedpackcore'}{else}{l s='Make my shop agent-ready' mod='speedpackcore'}{/if}
        </button>
        <span class="spc-ag-cta-note">{l s='About a minute. No code, no hosting changes. Every part can be switched off again.' mod='speedpackcore'}</span>
      </div>
    </div>
    <div class="spc-ag-gauge" aria-live="polite">
      <svg viewBox="0 0 120 120" class="spc-ag-ring"><circle cx="60" cy="60" r="52" class="spc-ag-ring-bg"/><circle cx="60" cy="60" r="52" class="spc-ag-ring-fg" pathLength="100" stroke-dasharray="0 100"/></svg>
      <div class="spc-ag-gauge-num"><b data-spc-ag="score">–</b><span data-spc-ag="of"></span></div>
      <div class="spc-ag-gauge-label" data-spc-ag="label">{l s='checking…' mod='speedpackcore'}</div>
      <div class="spc-ag-gauge-before" data-spc-ag="before"></div>
    </div>
  </div>

  <div class="alert alert-warning spc-ag-blocked" data-spc-ag="blocked" hidden></div>

  <div class="spc-ag-run" data-spc-ag="steps" hidden>
    {foreach from=$spc_ag.steps item=s}
      <div class="spc-ag-step" data-step="{$s|escape:'html':'UTF-8'}">
        <div class="spc-ag-step-icon"><span class="spc-ag-dot"></span></div>
        <div class="spc-ag-step-body">
          <div class="spc-ag-step-title">{$spc_ag.texts[$s][0]|escape:'html':'UTF-8'} <small>{$spc_ag.texts[$s][3]|escape:'html':'UTF-8'}</small></div>
          <div class="spc-ag-step-more">
            <p><b>{l s='What happens:' mod='speedpackcore'}</b> {$spc_ag.texts[$s][1]|escape:'html':'UTF-8'}</p>
            <p><b>{l s='Why it matters:' mod='speedpackcore'}</b> {$spc_ag.texts[$s][2]|escape:'html':'UTF-8'}</p>
            <div class="spc-ag-proof" data-spc-ag="proof"></div>
          </div>
        </div>
      </div>
    {/foreach}
  </div>

  <div class="spc-ag-finale" data-spc-ag="finale" hidden>
    <div class="spc-ag-confetti" aria-hidden="true"></div>
    <h3 data-spc-ag="finale-title"></h3>
    <p class="spc-ag-finale-score"><span data-spc-ag="finale-before"></span> <span class="spc-ag-arrow">→</span> <b data-spc-ag="finale-after"></b></p>
    <ul class="spc-ag-can">
      <li>{l s='AI search answers can quote your products, prices and pages (llms.txt, Markdown, content signals).' mod='speedpackcore'}</li>
      <li>{l s='Assistants that plug into tools (MCP, A2A) can search your catalogue and give the shopper a buy link.' mod='speedpackcore'}</li>
      <li>{l s='Shopping agents (UCP, ACP) hand the shopper a ready cart that ends in your checkout, with your payments.' mod='speedpackcore'}</li>
      <li>{l s='Firewalls can recognise your own bots (Web Bot Auth), and nothing claims a payment method you do not have.' mod='speedpackcore'}</li>
    </ul>
    <p class="spc-ag-links">
      {foreach from=$spc_ag.links item=l}<a href="{$l[1]|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{$l[0]|escape:'html':'UTF-8'}</a>{/foreach}
      <a href="{$spc_ag.scanner|escape:'html':'UTF-8'}" target="_blank" rel="noopener" class="spc-ag-scan">{l s='Check it on isitagentready.com' mod='speedpackcore'} ↗</a>
    </p>
  </div>

  <div class="spc-ag-grid" data-spc-ag="grid">
    {foreach from=$spc_ag.groups key=g item=gname}
      <div class="spc-ag-group">
        <h4>{$gname|escape:'html':'UTF-8'}</h4>
        <ul>
          {foreach from=$spc_ag.checks key=c item=cg}{if $cg == $g}
            <li data-check="{$c|escape:'html':'UTF-8'}"><span class="spc-ag-state"></span><span class="spc-ag-name">{$spc_ag.names[$c]|escape:'html':'UTF-8'}</span><span class="spc-ag-detail"></span></li>
          {/if}{/foreach}
        </ul>
      </div>
    {/foreach}
    <div class="spc-ag-group spc-ag-na">
      <h4>{l s='Not applicable (on purpose)' mod='speedpackcore'}</h4>
      <ul>{foreach from=$spc_ag.na item=n}<li><span class="spc-ag-state is-na"></span><span class="spc-ag-name">{$n[0]|escape:'html':'UTF-8'}</span><span class="spc-ag-detail">{$n[1]|escape:'html':'UTF-8'}</span></li>{/foreach}</ul>
    </div>
  </div>
  <p class="spc-ag-recheck"><a href="#" data-spc-ag="check">↻ {l s='Check again' mod='speedpackcore'}</a></p>

  {if $spc_ag.active}
    <form method="post" class="panel spc-ag-settings">
      <h3><i class="icon-cogs"></i> {l s='Fine-tune' mod='speedpackcore'}</h3>
      <div class="row">
        <div class="col-md-6">
          <h4>{l s='Parts' mod='speedpackcore'}</h4>
          {foreach from=$spc_ag.parts item=p}
            <label class="spc-ag-switch"><input type="checkbox" name="{$p.key|escape:'html':'UTF-8'}" value="1"{if $p.on} checked{/if}> {$p.name|escape:'html':'UTF-8'}</label>
          {/foreach}
          {if !$spc_ag.sodium}<p class="help-block">{l s='Web Bot Auth needs PHP\'s sodium extension, which this server does not have.' mod='speedpackcore'}</p>{/if}
        </div>
        <div class="col-md-6">
          <h4>{l s='Content signals (robots.txt)' mod='speedpackcore'}</h4>
          {foreach from=$spc_ag.signals item=s}
            <div class="form-group"><label>{$s.name|escape:'html':'UTF-8'}</label>
              <select name="{$s.key|escape:'html':'UTF-8'}" class="form-control fixed-width-xl">
                <option value="yes"{if $s.value == 'yes'} selected{/if}>{l s='yes (allowed)' mod='speedpackcore'}</option>
                <option value="no"{if $s.value == 'no'} selected{/if}>{l s='no (not allowed)' mod='speedpackcore'}</option>
                <option value="none"{if $s.value == 'none'} selected{/if}>{l s='not stated' mod='speedpackcore'}</option>
              </select></div>
          {/foreach}
          <p class="help-block">{l s='search: search results and links. ai-input: answers made from your pages (AI search, assistants). ai-train: training AI models.' mod='speedpackcore'}</p>
          <h4>{l s='ACP keys (one per agent platform)' mod='speedpackcore'}</h4>
          {foreach from=$spc_ag.keys item=k}
            <div class="checkbox"><label><input type="checkbox" name="acp_revoke[]" value="{$k.hash|escape:'html':'UTF-8'}"> {l s='revoke' mod='speedpackcore'} <b>{$k.name|escape:'html':'UTF-8'}</b> (…{$k.hint|escape:'html':'UTF-8'}, {$k.created|escape:'html':'UTF-8'})</label></div>
          {foreachelse}
            <p class="help-block">{l s='No key yet: a platform that wants to build carts here asks you for one.' mod='speedpackcore'}</p>
          {/foreach}
          <input type="text" name="acp_new_key" class="form-control" placeholder="{l s='New key for (platform name)' mod='speedpackcore'}">
        </div>
      </div>
      <div class="panel-footer">
        <button type="submit" name="submitSpcAgents" value="1" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save' mod='speedpackcore'}</button>
        <button type="button" class="btn btn-link spc-ag-off" data-spc-ag="off">{l s='Switch the whole section off' mod='speedpackcore'}</button>
      </div>
    </form>
  {/if}
</div>
