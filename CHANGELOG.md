# Historia zmian

Wszystkie istotne zmiany modułu **APLINE Simple Google Auth dla PrestaShop 9**.
Format oparty na [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/),
numeracja zgodna z [wersjonowaniem semantycznym](https://semver.org/lang/pl/).

## [1.1.4] – 2026-10-11

### Naprawiono
- **Logowanie przez Google nie logowało klienta.** Konto powstawało i łączyło się z Google, ale klient wracał na stronę logowania, a kolejne kliknięcie „Kontynuuj jako …” niczego nie zmieniało. Moduł wpisywał dane do ciasteczka ręcznie i nie zakładał sesji klienta, której PrestaShop (od 1.7.8) wymaga, żeby uznać klienta za zalogowanego. Teraz loguje tak jak formularz logowania sklepu (`Context::updateCustomer()`), razem z koszykiem i przeliczeniem kodów rabatowych.
- Wyłączone konto mogło zalogować się przez Google, jeśli trafiało na ścieżkę łączenia po adresie e-mail. Konto wyłączone, usunięte albo konto gościa dostaje komunikat „To konto jest nieaktywne”.
- Nowe konto założone przez Google nie dostawało wiadomości powitalnej sklepu. Wysyłka idzie teraz jak przy zwykłej rejestracji (gdy sklep ma ją włączoną), a moduły nasłuchujące nowego konta są powiadamiane po zalogowaniu klienta.
- Dwa szybkie kliknięcia przycisku przy pierwszym logowaniu mogły pokazać błąd „Nie udało się założyć konta”, choć konto powstało. Drugie żądanie loguje teraz na konto założone przez pierwsze.
- Imię albo nazwisko z konta Google ze znakiem niedozwolonym w danych klienta kończyło się ogólnym błędem zamiast założeniem konta.
- Przycisk Google pokazywał się zalogowanemu klientowi w formularzu „Dane osobiste” — kliknięcie innym kontem Google przełączało konto.

### Dodano
- Po zalogowaniu klient wraca tam, skąd przyszedł: do zamówienia, gdy klikał przycisk w kroku zamówienia, albo na stronę wskazaną przez sklep przy przejściu do logowania; bez takiej wskazówki — na „Moje konto”. Cel jest sprawdzany po stronie sklepu (tylko własny adres sklepu po HTTPS, nigdy strona logowania ani rejestracji).
- Komunikat „Zalogowano przez Google.” po udanym logowaniu.

### Zmieniono
- Dziennik błędów zapisuje rodzaj błędu bez jego treści, żeby adres e-mail klienta nie trafiał do logów.
- Nagłówek licencji MIT także we własnym autoloaderze modułu (`vendor/autoload.php`).

## [1.1.3] – 2026-10-09

### Naprawiono
- Logowanie przez Google kończyło się błędem 500 na adresie zwrotnym: klasa kontrolera `callback` miała nazwę, której PrestaShop nie znajduje — dyspozytor szuka `{nazwa_modułu}{kontroler}ModuleFrontController` z podkreśleniami nazwy modułu. Teraz `Apline_Simple_Google_AuthCallbackModuleFrontController`. Błąd występował we wszystkich wcześniejszych wersjach.

## [1.1.2] – 2026-10-08

### Zmieniono
- Licencja **MIT** (wcześniej Custom Attribution License v1.0): moduł możesz używać, zmieniać i rozpowszechniać, także komercyjnie, z zachowaniem noty o prawach autorskich i licencji.
- Autor: Arkadiusz Pielechowski — podpis „Moduł stworzony przez PIELECHOWSKI.PL” na stronie konfiguracji i ramka „Podoba Ci się ten moduł?” prowadzą do https://pielechowski.pl.
- Lżejsze logo modułu (23 KB zamiast ok. 0,8–0,9 MB) — szybsza lista modułów w panelu.

## [1.1.1] – 2026-10-08

### Naprawiono
- Strona konfiguracji modułu (i instalacja) kończyła się błędem SQL 1064 „near 'LIMIT 1'”:
  sprawdzanie, czy tabela modułu istnieje, używało `Db::getValue()`, które w PrestaShop 9
  dokleja `LIMIT 1` — MySQL i MariaDB nie przyjmują go po `SHOW TABLES`. Teraz
  `executeS()` bez pamięci podręcznej. Błąd występował już w 1.0.1.

## [1.1.0] – 2026-10-08

### Zmienione
- **Spolszczenie** — cały interfejs ma polski tekst źródłowy: panel konfiguracji,
  diagnostyka, komunikaty błędów logowania w sklepie, nazwa i opis modułu,
  zaślepka przycisku i e-mail o połączeniu konta (`mails/pl/`). E-mail jest
  wysyłany w języku klienta zamiast zawsze po angielsku.
- Przycisk Google rysuje napis w **języku sklepu** (`data-locale`), a nie
  w języku przeglądarki klienta.
- **Duży przycisk „Zapisz ustawienia”** w panelu (`apline-btn-duzy`, arkusz
  `views/css/admin.css`).
- Identyfikator klienta nie jest już wymagany przy zapisie ustawień — bez niego
  przycisk Google jest ukryty albo widać zaślepkę.
- Instrukcja w panelu podaje też **adresy zwrotne** do pola „Authorized redirect
  URIs” (dla każdego aktywnego języka) — Google wymaga ich przy wysyłaniu tokena
  na adres modułu.
- Opisy opcji poprawione zgodnie z działaniem: okienko One Tap pojawia się tylko
  na stronach z przyciskiem Google, a po wyłączeniu automatycznego łączenia
  klient loguje się e-mailem i hasłem (ręcznego łączenia nie ma).
- Nowe konto bez imienia lub nazwiska w profilu Google dostaje dane „Klient
  Google” (wcześniej „Google User”).
- Wymagane PHP 8.1+ w `composer.json` (jak w PrestaShop 9).

### Dodane — ustawienia zaślepki (wcześniej na sztywno w kodzie)
- **Zaślepka bez identyfikatora** (`ASGA_PLACEHOLDER`) — nieaktywny przycisk
  „Kontynuuj z Google”, dopóki nie ma identyfikatora klienta. Nowa instalacja:
  wyłączona.
- **Duża zaślepka** (`ASGA_PLACEHOLDER_LARGE`) — 72 px wysokości i 22 px napisu;
  po wyłączeniu rozmiar zbliżony do przycisku Google. Nowa instalacja: wyłączona.
- **Komunikat zaślepki** (`ASGA_PLACEHOLDER_INFO`, osobno dla każdego języka) —
  tekst pokazywany po kliknięciu zaślepki.
- Skrypt `upgrade/upgrade-1.1.0.php` zachowuje wygląd z wersji 1.0.1: włącza
  dużą zaślepkę z dotychczasowym komunikatem (tylko gdy tych ustawień jeszcze nie ma).

## [1.0.1] – 2026-10-01

### Dodane
- Nieaktywna **zaślepka przycisku** „Kontynuuj z Google” na stronach logowania
  i rejestracji, dopóki nie ma identyfikatora klienta; po kliknięciu komunikat,
  że sklep musi jeszcze dokończyć konfigurację. Skrypt Google nie jest wtedy ładowany.

## [1.0.0] – 2026-05-31

Pierwsze publiczne wydanie.

### Dodane
- Przycisk **„Kontynuuj z Google”** na stronach logowania i rejestracji
  w PrestaShop **9.0.x**, oparty na Google Identity Services (okienko / FedCM).
- **Weryfikacja tokena ID (JWT) po stronie serwera**: podpis RS256 kluczami
  publicznymi Google (JWKS w pamięci podręcznej na 24 h, przy awarii sieci
  używany jest poprzedni zestaw kluczy) oraz sprawdzenie wystawcy, odbiorcy
  (= identyfikator klienta), ważności i `email_verified`. Biblioteka
  `firebase/php-jwt` dołączona do modułu.
- **Dopasowanie i zakładanie kont**: logowanie konta już połączonego z Google;
  automatyczne połączenie z istniejącym klientem o tym samym, potwierdzonym
  przez Google adresie e-mail (opcjonalne, z e-mailem powiadamiającym); albo
  założenie nowego konta z profilu Google.
- Tabela powiązań `asga_customer_link` (`id_customer ↔ google_sub`, 1:1)
  z kluczem obcym InnoDB i wariantem zapasowym bez klucza — natywna tabela
  `ps_customer` pozostaje nietknięta.
- Ustawiany **kolor, rozmiar, tekst i kształt** przycisku, przełączniki stron,
  opcjonalne okienko **One Tap**, przełączniki automatycznego łączenia
  i powiadomień.
- Panel konfiguracji z **instrukcją uruchomienia** i **diagnostyką** (tabela,
  biblioteka, HTTPS, psgdpr, identyfikator klienta).
- **Ochrona CSRF** adresu zwrotnego (`g_csrf_token` Google, metoda double-submit)
  i ogólne komunikaty błędów, które nie zdradzają szczegółów weryfikacji.
- Hooki i adres zwrotny odporne na błędy (`try/catch` → pusty blok albo
  przekierowanie, nigdy błąd 500). Nieudana instalacja jest wycofywana;
  odinstalowanie można powtarzać i nie usuwa kont klientów.
- Blok autorstwa APLINE na stronie konfiguracji (Custom Attribution License
  v1.0, [LICENSE.md](LICENSE.md)).

### Uwagi (rodzina SIMPLE)
Pierwszy moduł rodziny, który był **tylko po angielsku**, nie ma **ObjectModelu,
AdminControllera ani widżetu**, zawiera **dołączoną zależność Composera**
(`firebase/php-jwt`) i używa **kontrolera frontu** (`controllers/front/callback.php`).

### Odłożone na później
- Ręczne odłączanie konta Google w „Moim koncie” (w 1.0.0 w ostateczności
  usunięcie wiersza w bazie z panelu).
- Osobne identyfikatory klienta dla sklepów w trybie multistore (jeden wspólny).
- Ochrona przed ponownym użyciem tokena (`jti`).
