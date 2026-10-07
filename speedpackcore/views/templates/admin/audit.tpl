{**
 * SpeedPack Core - the speed audit. views/js/audit.js runs it and fills in the numbers.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel spc-audit{if $spc_audit.first} spc-audit--first{/if}" id="spc-audit"
  data-spc-url="{$spc_audit.url|escape:'html':'UTF-8'}"
  data-spc-home="{$spc_audit.home|escape:'html':'UTF-8'}"
  data-spc-texts="{$spc_audit.texts|escape:'html':'UTF-8'}"
  data-spc-history="{$spc_audit.history|escape:'html':'UTF-8'}"
  data-spc-auto="{if $spc_audit.auto}1{else}0{/if}">
  <h3><i class="icon-tachometer"></i> {l s='Speed audit' mod='speedpackcore'}</h3>
  <div class="spc-audit-head">
    <div class="spc-audit-intro">
      {if $spc_audit.first}
        <p class="spc-audit-lead">{l s='SpeedPack Core is on. See what each part does for this shop: every page and click is measured without SpeedPack and with it.' mod='speedpackcore'}</p>
      {else}
        <p class="spc-audit-lead">{l s='Each part measured without SpeedPack and with it, on this shop and this server.' mod='speedpackcore'}</p>
      {/if}
      <p class="help-block">{l s='About two minutes. A shop window opens for the click test and closes by itself. Customers are not affected: "without" applies only to the requests of the audit itself.' mod='speedpackcore'}</p>
    </div>
    <button type="button" class="btn btn-primary btn-lg spc-audit-start" data-spc-start>
      <svg class="spc-start-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
      <span data-spc-start-label>{l s='Measure my shop' mod='speedpackcore'}</span>
    </button>
  </div>

  <div class="spc-audit-progress" data-spc-progress hidden>
    <div class="spc-audit-track"><div class="spc-audit-fill" data-spc-fill></div></div>
    <p class="spc-audit-say"><span data-spc-say></span><span class="spc-audit-clock" data-spc-clock></span></p>
  </div>

  <div class="spc-audit-hero" data-spc-hero hidden>
    <h4 data-spc-hero-title></h4>
    <div class="spc-bars spc-bars--hero" data-spc-hero-bars></div>
  </div>

  <div class="spc-audit-grid">
    <div class="spc-card" data-spc-part="cache">
      <div class="spc-icon">
        <svg class="spc-ic spc-ic-cache" viewBox="0 0 48 48" aria-hidden="true">
          <ellipse class="spc-l spc-l1" cx="24" cy="11" rx="14" ry="5"/>
          <path class="spc-l spc-l2" d="M10 11v9c0 2.8 6.3 5 14 5s14-2.2 14-5v-9"/>
          <path class="spc-l spc-l3" d="M10 20v9c0 2.8 6.3 5 14 5s14-2.2 14-5v-9"/>
          <path class="spc-l spc-l4" d="M10 29v8c0 2.8 6.3 5 14 5s14-2.2 14-5v-8"/>
        </svg>
        <span class="spc-check"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      </div>
      <div class="spc-card-body">
        <h4>{l s='Data cache' mod='speedpackcore'}</h4>
        <p class="spc-card-what">{l s='Server answer time, five pages of the shop' mod='speedpackcore'}</p>
        <div class="spc-bars" data-spc-bars></div>
        <p class="spc-gain" data-spc-gain></p>
        <p class="spc-note" data-spc-note></p>
      </div>
    </div>

    <div class="spc-card" data-spc-part="pagecache">
      <div class="spc-icon">
        <svg class="spc-ic spc-ic-pages" viewBox="0 0 48 48" aria-hidden="true">
          <rect class="spc-l spc-l1" x="14" y="6" width="24" height="30" rx="3"/>
          <rect class="spc-l spc-l2" x="10" y="10" width="24" height="30" rx="3"/>
          <path class="spc-l spc-l3" d="M15 19h14M15 25h14M15 31h9"/>
        </svg>
        <span class="spc-check"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      </div>
      <div class="spc-card-body">
        <h4>{l s='Page cache' mod='speedpackcore'}</h4>
        <p class="spc-card-what">{l s='Server answer time, the same pages kept ready' mod='speedpackcore'}</p>
        <div class="spc-bars" data-spc-bars></div>
        <p class="spc-gain" data-spc-gain></p>
        <p class="spc-note" data-spc-note></p>
      </div>
    </div>

    <div class="spc-card" data-spc-part="optimize">
      <div class="spc-icon">
        <svg class="spc-ic spc-ic-opt" viewBox="0 0 48 48" aria-hidden="true">
          <rect class="spc-l spc-l1" x="6" y="9" width="36" height="30" rx="3"/>
          <path class="spc-l spc-l2" d="M6 16h36"/>
          <path class="spc-l spc-l3" d="M12 33l7-8 5 5 4-4 8 7"/>
        </svg>
        <span class="spc-check"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      </div>
      <div class="spc-card-body">
        <h4>{l s='Optimize' mod='speedpackcore'}</h4>
        <p class="spc-card-what">{l s='Files that hold up the home page and a product page' mod='speedpackcore'}</p>
        <div class="spc-bars" data-spc-bars></div>
        <p class="spc-gain" data-spc-gain></p>
        <p class="spc-note" data-spc-note></p>
      </div>
    </div>

    <div class="spc-card" data-spc-part="smartprefetch">
      <div class="spc-icon">
        <svg class="spc-ic spc-ic-radar" viewBox="0 0 48 48" aria-hidden="true">
          <circle cx="24" cy="24" r="18"/>
          <circle cx="24" cy="24" r="10.5"/>
          <g class="spc-sweep"><path class="spc-cone" d="M24 24V6a18 18 0 0 1 12.7 5.3z"/><path d="M24 24V6"/></g>
          <circle class="spc-blip" cx="32" cy="15" r="2.2"/>
          <circle class="spc-dot" cx="24" cy="24" r="2.4"/>
        </svg>
        <span class="spc-check"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      </div>
      <div class="spc-card-body">
        <h4>SmartPrefetch</h4>
        <p class="spc-card-what">{l s='Click to page shown, after 0.3 s on a menu link' mod='speedpackcore'}</p>
        <div class="spc-bars" data-spc-bars></div>
        <p class="spc-gain" data-spc-gain></p>
        <p class="spc-note" data-spc-note></p>
      </div>
    </div>

    <div class="spc-card" data-spc-part="instantnav">
      <div class="spc-icon">
        <svg class="spc-ic spc-ic-bolt" viewBox="0 0 48 48" aria-hidden="true">
          <path class="spc-trail spc-t1" d="M3 15h8"/>
          <path class="spc-trail spc-t2" d="M1 24h9"/>
          <path class="spc-trail spc-t3" d="M3 33h7"/>
          <path class="spc-boltpath" d="M28 4L14 27h10l-3 17 16-24H27z"/>
        </svg>
        <span class="spc-check"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      </div>
      <div class="spc-card-body">
        <h4>InstantNav</h4>
        <p class="spc-card-what">{l s='Click to page shown, menu links' mod='speedpackcore'}</p>
        <div class="spc-bars" data-spc-bars></div>
        <p class="spc-gain" data-spc-gain></p>
        <p class="spc-note" data-spc-note></p>
      </div>
    </div>

    <div class="spc-card" data-spc-part="instantcart">
      <div class="spc-icon">
        <svg class="spc-ic spc-ic-cart" viewBox="0 0 48 48" aria-hidden="true">
          <rect class="spc-item" x="22" y="3" width="9" height="9" rx="2"/>
          <g class="spc-cartbody">
            <path d="M3 9h6l5 21h23l4-15H12"/>
            <circle cx="17" cy="38" r="3"/>
            <circle cx="33" cy="38" r="3"/>
          </g>
        </svg>
        <span class="spc-check"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      </div>
      <div class="spc-card-body">
        <h4>InstantCart</h4>
        <p class="spc-card-what">{l s='Adding to the cart: the PrestaShop cart page against the lean endpoint' mod='speedpackcore'}</p>
        <div class="spc-bars" data-spc-bars></div>
        <p class="spc-gain" data-spc-gain></p>
        <p class="spc-note" data-spc-note></p>
      </div>
    </div>

    <div class="spc-card" data-spc-part="cartspeed">
      <div class="spc-icon">
        <svg class="spc-ic spc-ic-gauge" viewBox="0 0 48 48" aria-hidden="true">
          <path d="M7 36a17 17 0 1 1 34 0"/>
          <path class="spc-ticks" d="M24 15v4M12 21l3 2.5M36 21l-3 2.5"/>
          <g class="spc-needle"><path d="M24 36l9-12"/></g>
          <circle class="spc-dot" cx="24" cy="36" r="2.6"/>
        </svg>
        <span class="spc-check"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      </div>
      <div class="spc-card-body">
        <h4>CartSpeed</h4>
        <p class="spc-card-what">{l s='Database queries for the address lookups of one cart page' mod='speedpackcore'}</p>
        <div class="spc-bars" data-spc-bars></div>
        <p class="spc-gain" data-spc-gain></p>
        <p class="spc-note" data-spc-note></p>
      </div>
    </div>
  </div>

  <div class="spc-audit-history" data-spc-history-box hidden>
    <h4 data-spc-history-title></h4>
    <div class="spc-history-chart" data-spc-history-chart></div>
  </div>
</div>
