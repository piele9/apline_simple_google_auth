# Historia zmian

Wszystkie istotne zmiany modułu **APLINE Simple Google Auth dla PrestaShop 9**.
Format oparty na [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/),
numeracja zgodna z [wersjonowaniem semantycznym](https://semver.org/lang/pl/).

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
