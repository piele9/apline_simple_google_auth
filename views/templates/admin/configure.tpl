{*
 * APLINE Simple Google Auth module for PrestaShop 9.
 * @author Arkadiusz Pielechowski
 *}
<div class="panel">
  <h3><i class="icon-google"></i> {l s='Jak uruchomić logowanie przez Google' d='Modules.Aplinesimplegoogleauth.Admin'}</h3>
  <p>{l s='Przycisk działa dopiero z identyfikatorem klienta OAuth z Google Cloud Console. Wystarczy zrobić to raz:' d='Modules.Aplinesimplegoogleauth.Admin'}</p>
  <ol class="asga-checklist">
    <li>
      {l s='Otwórz Google Cloud Console i wybierz albo utwórz projekt sklepu:' d='Modules.Aplinesimplegoogleauth.Admin'}
      <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">console.cloud.google.com &rarr; APIs &amp; Services &rarr; Credentials</a>
    </li>
    <li>{l s='Skonfiguruj ekran zgody OAuth (Google Auth Platform: Branding i Audience): nazwa sklepu, e-mail pomocy, adres polityki prywatności i regulaminu, odbiorcy „External”. Na koniec opublikuj aplikację („Publish app”) — w trybie testowym zalogują się tylko użytkownicy testowi.' d='Modules.Aplinesimplegoogleauth.Admin'}</li>
    <li>
      {l s='Utwórz identyfikator klienta OAuth typu „Web application” i w polu „Authorized JavaScript origins” dodaj adres sklepu:' d='Modules.Aplinesimplegoogleauth.Admin'}
      <br><code>{$asga_shop_url|escape:'html':'UTF-8'}</code>
    </li>
    <li>
      {l s='W polu „Authorized redirect URIs” dodaj adres zwrotny modułu (każdy z poniższych, jeśli jest ich kilka):' d='Modules.Aplinesimplegoogleauth.Admin'}
      {foreach from=$asga_callback_urls item=asga_url}
        <br><code>{$asga_url|escape:'html':'UTF-8'}</code>
      {/foreach}
    </li>
    <li>{l s='Skopiuj „Client ID” (kończy się na .apps.googleusercontent.com), wklej go w formularzu poniżej i kliknij „Zapisz ustawienia”. „Client secret” nie jest potrzebny — nigdzie go nie wpisuj.' d='Modules.Aplinesimplegoogleauth.Admin'}</li>
    <li>{l s='Sprawdź logowanie w oknie prywatnym (incognito) przeglądarki.' d='Modules.Aplinesimplegoogleauth.Admin'}</li>
  </ol>
</div>

<div class="panel">
  <h3><i class="icon-heartbeat"></i> {l s='Diagnostyka' d='Modules.Aplinesimplegoogleauth.Admin'}</h3>
  <ul class="list-unstyled asga-health">
    {foreach from=$asga_health item=check}
      <li>
        {if $check.status == 'ok'}
          <span class="text-success"><i class="icon-check-circle"></i></span>
        {else}
          <span class="text-warning"><i class="icon-warning"></i></span>
        {/if}
        {$check.message|escape:'html':'UTF-8'}
      </li>
    {/foreach}
  </ul>
</div>
