{**
 * SpeedPack Core - the Cloudflare tab: status, recommended zone settings, quick actions, DNS.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel spc-opt spc-cf" id="spc-cloudflare">
  <h3><i class="icon-cloud"></i> Cloudflare</h3>
  {if !$spc_cf.configured}
    <p>{l s='Cloudflare sits in front of the shop: it caches pictures and pages near your visitors, encrypts the connection and stops bots. Connect the zone below to see here whether the shop really goes through it, and to set it up the way a PrestaShop shop needs.' mod='speedpackcore'}</p>
  {else}
    {assign var=st value=$spc_cf.state}
    <div class="spc-opt-tiles">
      <div class="spc-opt-tile">
        <b>{if $st.behind === true}<span class="spc-opt-ok">{l s='Yes' mod='speedpackcore'}</span>{elseif $st.behind === false}<span class="spc-opt-no">{l s='No' mod='speedpackcore'}</span>{else}?{/if}</b>
        <span>{l s='the shop answers through Cloudflare' mod='speedpackcore'}</span>
      </div>
      <div class="spc-opt-tile">
        <b>{if $st.zone}{$st.zone.status|escape:'html':'UTF-8'}{else}–{/if}</b>
        <span>{if $st.zone}{$st.zone.name|escape:'html':'UTF-8'}{if $st.zone.plan} · {$st.zone.plan|escape:'html':'UTF-8'}{/if}{else}{l s='zone' mod='speedpackcore'}{/if}</span>
      </div>
      <div class="spc-opt-tile">
        <b>{if $spc_cf.edge}<span class="spc-opt-ok">{$spc_cf.ip|escape:'html':'UTF-8'}</span>{else}{$spc_cf.ip|escape:'html':'UTF-8'}{/if}</b>
        <span>{if $spc_cf.edge}{l s='your address, as the shop sees it (Cloudflare server %s)' sprintf=[$spc_cf.edge] mod='speedpackcore'}{else}{l s='your address, as the shop sees it' mod='speedpackcore'}{/if}</span>
      </div>
      <div class="spc-opt-tile">
        <b>{if $spc_cf.last}{if $spc_cf.last.ok}<span class="spc-opt-ok">{$spc_cf.last.at|escape:'html':'UTF-8'}</span>{else}<span class="spc-opt-no">{$spc_cf.last.at|escape:'html':'UTF-8'}</span>{/if}{else}–{/if}</b>
        <span>{l s='last purge' mod='speedpackcore'}{if $spc_cf.last && !$spc_cf.last.ok}: {$spc_cf.last.error|escape:'html':'UTF-8'}{/if}</span>
      </div>
    </div>

    {if $st.zone && $st.zone.status == 'pending'}
      <div class="alert alert-warning">
        {l s='The zone is waiting for its name servers. At the registrar of %s, replace the name servers with:' sprintf=[$st.zone.name] mod='speedpackcore'}
        <b>{$spc_cf.ns|escape:'html':'UTF-8'}</b>.
        {l s='Switch DNSSEC off at the registrar first (switch it on again in Cloudflare afterwards), or the domain stops resolving.' mod='speedpackcore'}
      </div>
    {elseif $st.behind === false}
      <div class="alert alert-warning">{l s='The zone is set up, but the shop answers without Cloudflare: its address does not go through it yet (name servers not changed at the registrar, or the shop record is "DNS only").' mod='speedpackcore'}</div>
    {/if}
    {foreach from=$st.errors key=what item=err}
      <div class="alert alert-info">{l s='Cloudflare did not answer one question (%1$s): %2$s. The token may lack that permission.' sprintf=[$what, $err] mod='speedpackcore'}</div>
    {/foreach}

    {if $spc_cf.rows}
      <h3><i class="icon-check"></i> {l s='Recommended settings' mod='speedpackcore'}</h3>
      <form method="post">
        <table class="table spc-cf-table">
          <thead><tr><th>{l s='Setting' mod='speedpackcore'}</th><th>{l s='Now' mod='speedpackcore'}</th><th>{l s='Recommended' mod='speedpackcore'}</th><th></th></tr></thead>
          <tbody>
            {foreach from=$spc_cf.rows item=r}
              <tr>
                <td><b>{$r.name|escape:'html':'UTF-8'}</b><br><small class="help-block">{$r.why|escape:'html':'UTF-8'}</small></td>
                <td>{if $r.ok}<span class="spc-opt-ok">{$r.now|escape:'html':'UTF-8'}</span>{else}<span class="spc-opt-no">{$r.now|escape:'html':'UTF-8'}</span>{/if}</td>
                <td>{$r.want|escape:'html':'UTF-8'}</td>
                <td>{if !$r.ok && $r.editable}<button type="submit" name="submitSpcCfApply" value="{$r.id|escape:'html':'UTF-8'}" class="btn btn-default btn-sm">{l s='Apply' mod='speedpackcore'}</button>{elseif !$r.ok}{l s='not on this plan' mod='speedpackcore'}{/if}</td>
              </tr>
            {/foreach}
          </tbody>
        </table>
        {if $spc_cf.cert === false}<p class="help-block">{l s='The server\'s certificate is not valid for %s, so "Full" is recommended instead of "Full (strict)". Install a certificate on the server (Let\'s Encrypt, or a Cloudflare Origin Certificate) and choose "Full (strict)".' sprintf=[$spc_cf.host] mod='speedpackcore'}</p>
        {elseif $spc_cf.cert === null}<p class="help-block">{l s='The server\'s own certificate could not be checked from here, so "Full" is recommended: encrypted, and it never takes the shop down. If the server has a valid certificate for %s (Let\'s Encrypt or a Cloudflare Origin Certificate), choose "Full (strict)" at Cloudflare.' sprintf=[$spc_cf.host] mod='speedpackcore'}</p>{/if}
        <p>
          <button type="submit" name="submitSpcCfApply" value="all" class="btn btn-primary"><i class="icon-magic"></i> {l s='Apply all recommended' mod='speedpackcore'}</button>
          <button type="submit" name="submitSpcCfRefresh" value="1" class="btn btn-link"><i class="icon-refresh"></i> {l s='Ask Cloudflare again' mod='speedpackcore'}</button>
          <span class="help-block" style="display:inline">{l s='as Cloudflare said %d s ago' sprintf=[$spc_cf.ago] mod='speedpackcore'}</span>
        </p>
      </form>
    {/if}

    <h3><i class="icon-bolt"></i> {l s='Quick actions' mod='speedpackcore'}</h3>
    <form method="post" class="spc-cf-actions">
      {if $spc_cf.dev}
        <button type="submit" name="submitSpcCfDev" value="off" class="btn btn-warning">{l s='Development mode: on – switch off' mod='speedpackcore'}</button>
      {else}
        <button type="submit" name="submitSpcCfDev" value="on" class="btn btn-default">{l s='Development mode (3 h, while changing the theme)' mod='speedpackcore'}</button>
      {/if}
      {if $spc_cf.attack}
        <button type="submit" name="submitSpcCfAttack" value="off" class="btn btn-danger">{l s='"Under attack": on – switch off' mod='speedpackcore'}</button>
      {else}
        <button type="submit" name="submitSpcCfAttack" value="on" class="btn btn-default">{l s='"Under attack" (a flood of bots)' mod='speedpackcore'}</button>
      {/if}
      {if $st.bots && $st.bots.fight_mode}
        <button type="submit" name="submitSpcCfBots" value="off" class="btn btn-default">{l s='Bot Fight Mode: on – switch off' mod='speedpackcore'}</button>
      {else}
        <button type="submit" name="submitSpcCfBots" value="on" class="btn btn-default">{l s='Switch Bot Fight Mode on' mod='speedpackcore'}</button>
      {/if}
      <button type="submit" name="submitSpcCfPurge" value="1" class="btn btn-default">{l s='Purge Cloudflare\'s cache' mod='speedpackcore'}</button>
    </form>

    {if is_array($st.dns)}
      <h3><i class="icon-sitemap"></i> DNS</h3>
      {if $spc_cf.findings}
        {foreach from=$spc_cf.findings item=f}
          <div class="alert {if $f.level == 'error'}alert-danger{else}alert-warning{/if}">{$f.text|escape:'html':'UTF-8'}</div>
        {/foreach}
      {else}
        <p class="spc-opt-ok">{l s='%s goes through Cloudflare and mail is not sent to a proxied name.' sprintf=[$spc_cf.host] mod='speedpackcore'}</p>
      {/if}
    {/if}
  {/if}
</div>
