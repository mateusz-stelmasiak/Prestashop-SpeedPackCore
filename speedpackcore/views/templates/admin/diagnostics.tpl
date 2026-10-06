{**
 * SpeedPack Core - Health check. views/js/diagnostics.js fills the database care, ANALYZE and
 * module weight parts.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div id="spc-diagnostics" class="spc-diag" data-spc-url="{$spc_diag.url|escape:'html':'UTF-8'}" data-spc-texts="{$spc_diag.texts|escape:'html':'UTF-8'}">
  <div class="panel">
    <h3><i class="icon-stethoscope"></i> {l s='Health check: server and PrestaShop' mod='speedpackcore'}
      <span class="spc-tally">
        <span class="spc-dot spc-ok"></span>{$spc_diag.server_tally.ok|intval}
        <span class="spc-dot spc-warning"></span>{$spc_diag.server_tally.warning|intval}
        <span class="spc-dot spc-problem"></span>{$spc_diag.server_tally.problem|intval}
      </span>
    </h3>
    <p class="help-block">{l s='The settings from the PrestaShop tuning guide, read from this server. Green is fine, amber is worth changing, red slows the shop or exposes it.' mod='speedpackcore'}</p>
    <table class="table spc-checks">
      <thead><tr><th></th><th>{l s='Setting' mod='speedpackcore'}</th><th>{l s='Now' mod='speedpackcore'}</th><th>{l s='Wanted' mod='speedpackcore'}</th><th>{l s='Why' mod='speedpackcore'}</th></tr></thead>
      <tbody>
        {foreach from=$spc_diag.server item=row}
          <tr class="spc-row-{$row.level|escape:'html':'UTF-8'}">
            <td><span class="spc-dot spc-{$row.level|escape:'html':'UTF-8'}"></span></td>
            <td><strong>{$row.label|escape:'html':'UTF-8'}</strong></td>
            <td>{$row.value|escape:'html':'UTF-8'}</td>
            <td class="text-muted">{$row.want|escape:'html':'UTF-8'}</td>
            <td class="spc-fix">{if $row.level != 'ok'}{$row.fix|escape:'html':'UTF-8'}{/if}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
    {if $spc_diag.multifront}
      <form method="post" action="{$spc_diag.url|escape:'html':'UTF-8'}#spc-diagnostics">
        <button type="submit" name="submitSpcMultiFront" class="btn btn-default">{l s='Switch off multi-front optimizations' mod='speedpackcore'}</button>
      </form>
    {/if}
  </div>

  <div class="panel">
    <h3><i class="icon-hdd"></i> {l s='Health check: database' mod='speedpackcore'}
      <span class="spc-tally">
        <span class="spc-dot spc-ok"></span>{$spc_diag.database_tally.ok|intval}
        <span class="spc-dot spc-warning"></span>{$spc_diag.database_tally.warning|intval}
        <span class="spc-dot spc-problem"></span>{$spc_diag.database_tally.problem|intval}
      </span>
    </h3>
    <table class="table spc-checks">
      <tbody>
        {foreach from=$spc_diag.database item=row}
          <tr class="spc-row-{$row.level|escape:'html':'UTF-8'}">
            <td><span class="spc-dot spc-{$row.level|escape:'html':'UTF-8'}"></span></td>
            <td><strong>{$row.label|escape:'html':'UTF-8'}</strong></td>
            <td>{$row.value|escape:'html':'UTF-8'}</td>
            <td class="text-muted">{$row.want|escape:'html':'UTF-8'}</td>
            <td class="spc-fix">{$row.fix|escape:'html':'UTF-8'}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
    <p>
      <button type="button" class="btn btn-default" data-spc-analyze><i class="icon-refresh"></i> {l s='Analyze tables' mod='speedpackcore'}</button>
      <span class="help-block spc-inline">{l s='Refreshes what MySQL knows about every table, so it picks the right index for category, search and order queries. Safe to run any time; best after a big import or cleanup.' mod='speedpackcore'}</span>
    </p>
    <div class="spc-job" data-spc-analyze-job hidden><div class="progress"><div class="progress-bar" data-spc-analyze-bar style="width:0%"></div></div><p class="help-block" data-spc-analyze-say></p></div>
  </div>

  {if $spc_diag.host}
    <div class="panel">
      <h3><i class="icon-envelope"></i> {l s='For your host' mod='speedpackcore'}</h3>
      <p class="help-block">{l s='php.ini and my.cnf belong to the server, not the shop. Send these lines to your host (or paste them into the PHP and MySQL settings of your hosting panel); they are worked out from the checks above.' mod='speedpackcore'}</p>
      <textarea class="spc-host" rows="{$spc_diag.host_rows|intval}" readonly data-spc-host>{$spc_diag.host|escape:'html':'UTF-8'}</textarea>
      <p><button type="button" class="btn btn-default" data-spc-copy><i class="icon-copy"></i> {l s='Copy' mod='speedpackcore'}</button> <span data-spc-copy-say></span></p>
    </div>
  {/if}

  <div class="panel">
    <h3><i class="icon-eraser"></i> {l s='Database care' mod='speedpackcore'}</h3>
    <p class="help-block">{l s='Tables that only grow. Each one can be cleaned of what is older than its age; nothing younger than a week is touched, and orders never are. Back up the database before the first cleanup.' mod='speedpackcore'}</p>
    <table class="table spc-care" data-spc-care>
      <thead><tr><th>{l s='What' mod='speedpackcore'}</th><th>{l s='Size' mod='speedpackcore'}</th><th>{l s='Older than' mod='speedpackcore'}</th><th>{l s='To remove' mod='speedpackcore'}</th><th></th></tr></thead>
      <tbody>
        {foreach from=$spc_diag.care item=item}
          <tr data-spc-item="{$item.id|escape:'html':'UTF-8'}">
            <td><strong data-spc-item-name></strong><br><small class="text-muted" data-spc-item-what></small></td>
            <td data-spc-item-size>…</td>
            <td><input type="number" class="spc-days" min="{$spc_diag.min_days|intval}" max="3650" value="{$item.days|intval}" data-spc-item-days> {l s='days' mod='speedpackcore'}</td>
            <td data-spc-item-old>…</td>
            <td class="text-right"><button type="button" class="btn btn-default btn-sm" data-spc-item-clean disabled></button><div class="spc-item-say" data-spc-item-say></div></td>
          </tr>
        {/foreach}
      </tbody>
    </table>
    <h4>{l s='The configuration table' mod='speedpackcore'}</h4>
    <p>{l s='PrestaShop loads it whole on every request.' mod='speedpackcore'} <strong>{$spc_diag.configuration.rows|intval}</strong> {l s='rows' mod='speedpackcore'}, <strong>{$spc_diag.configuration.size|escape:'html':'UTF-8'}</strong>.
      {if $spc_diag.configuration.heavy}<span class="label label-warning">{l s='large' mod='speedpackcore'}</span> {l s='Modules that were uninstalled without cleaning up often leave their settings behind.' mod='speedpackcore'}{/if}</p>
    {if $spc_diag.configuration.largest}
      <p class="help-block">{l s='Largest values (a module storing data here instead of in its own table):' mod='speedpackcore'}
        {foreach from=$spc_diag.configuration.largest item=c name=big}<code>{$c.name|escape:'html':'UTF-8'}</code> {$c.size|escape:'html':'UTF-8'}{if !$smarty.foreach.big.last}, {/if}{/foreach}</p>
    {/if}
  </div>

  <div class="panel">
    <h3><i class="icon-puzzle-piece"></i> {l s='Module weight' mod='speedpackcore'}</h3>
    <p class="help-block">{l s='What each module costs your pages: the front-office hooks it runs on, and the stylesheets and scripts it adds to the home page and a product page, fetched as a first-time visitor gets them. A module heavy here and rarely used is the first one to question.' mod='speedpackcore'}</p>
    <p><button type="button" class="btn btn-default" data-spc-weight><i class="icon-bar-chart"></i> {l s='Measure modules' mod='speedpackcore'}</button> <span class="help-block spc-inline" data-spc-weight-say></span></p>
    <div data-spc-weight-out></div>
  </div>
</div>
