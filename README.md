# APLINE Simple Google Auth — logowanie przez Google w PrestaShop 9

![PrestaShop 9](https://img.shields.io/badge/PrestaShop-9.x-DF0067) ![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4) ![Wersja](https://img.shields.io/badge/wersja-1.1.4-2ea44f) ![Licencja](https://img.shields.io/badge/licencja-MIT-blue)

Przycisk **„Kontynuuj z Google”** na stronach logowania i rejestracji. Klient wybiera konto Google w okienku, a moduł sprawdza token Google **po stronie serwera** i loguje klienta albo zakłada mu konto. Mniej haseł do pamiętania i mniej porzuconych rejestracji — dla każdego sklepu na PrestaShop 9.

Moduł korzysta z **Google Identity Services** (okienko / FedCM), a nie z dawnego przekierowania OAuth 2.0. Na serwerze sklepu nie ma żadnego sekretu Google, a natywna tabela `ps_customer` zostaje nietknięta — powiązania z kontami Google są w osobnej tabeli.

## Funkcje

- oficjalny przycisk Google na stronie logowania i w formularzu rejestracji (każde miejsce włączasz osobno);
- **weryfikacja tokena ID po stronie serwera**: podpis RS256 kluczami publicznymi Google, wystawca, odbiorca (= Twój identyfikator klienta), ważność i potwierdzony e-mail — dołączona biblioteka `firebase/php-jwt`, bez Composera na serwerze;
- **automatyczne łączenie** logowania Google z istniejącym kontem o tym samym, potwierdzonym przez Google adresie e-mail (opcjonalne, z e-mailem do klienta);
- **zakładanie nowego konta** z profilu Google, gdy klienta jeszcze nie ma (losowe hasło, newsletter i zgody marketingowe wyłączone);
- kolor, rozmiar, tekst i kształt przycisku według wytycznych Google — przycisk rysuje biblioteka Google, w języku sklepu;
- opcjonalne okienko **One Tap**;
- **zaślepka** na czas konfiguracji: nieaktywny przycisk „Kontynuuj z Google” z Twoim komunikatem, w wersji standardowej albo dużej (72 px, czytelniejszej dla starszych klientów);
- skrypt Google ładuje się **tylko na stronach logowania i konta klienta**, nie na całym sklepie;
- odporność na błędy: hooki i adres zwrotny nigdy nie kończą się błędem 500, nieudana instalacja jest wycofywana;
- instrukcja uruchomienia i **diagnostyka** na stronie konfiguracji (tabela, biblioteka, identyfikator, HTTPS, moduł zgód);
- polski interfejs panelu, komunikatów i e-maila.

## Wymagania

- PrestaShop 9.x, PHP 8.1+ z rozszerzeniem `openssl` (do weryfikacji podpisu RS256);
- sklep dostępny przez **HTTPS** — bez niego Google nie pokaże przycisku;
- konto Google i projekt w [Google Cloud Console](https://console.cloud.google.com/) (bezpłatny);
- moduł zgód RODO (np. `psgdpr`), jeśli sprzedajesz w UE — ten moduł nie pokazuje własnych zgód.

## Instalacja

1. Pobierz `apline_simple_google_auth.zip` z zakładki [Releases](../../releases/latest).
2. Panel PrestaShop → Moduły → Menedżer modułów → „Załaduj moduł” → wskaż ZIP.
3. Kliknij „Konfiguruj”.

Instalacja z Gita: sklonuj repozytorium do `modules/apline_simple_google_auth` (nazwa folderu musi być równa nazwie modułu). Katalog `vendor/` jest w repozytorium — `composer install` nie jest potrzebny.

> Zanim zainstalujesz moduł na produkcji, sprawdź go na kopii sklepu. Każdy sklep ma inny motyw i zestaw modułów.

## Konfiguracja

### 1. Google Cloud Console — identyfikator klienta OAuth

Google Cloud Console bywa po polsku albo po angielsku; poniżej nazwy angielskie, które są stałe. Adresy `https://twoj-sklep.pl/...` i identyfikator `123456789-abc.apps.googleusercontent.com` to przykłady — dokładne wartości dla Twojego sklepu pokazuje strona konfiguracji modułu.

**Projekt**

1. Wejdź na [console.cloud.google.com](https://console.cloud.google.com/) i zaloguj się kontem Google firmy.
2. Na górnym pasku rozwiń listę projektów → „New project”, wpisz nazwę (np. „Sklep — logowanie Google”) → „Create”. Upewnij się, że nowy projekt jest wybrany.

**Ekran zgody OAuth (Google Auth Platform)**

3. Menu → „Google Auth Platform” (w starszym widoku: „APIs & Services → OAuth consent screen”) → „Get started”.
4. „App information”: „App name” — nazwa sklepu, którą zobaczy klient w okienku Google; „User support email” — adres, na który klienci mogą pisać.
5. „Audience”: wybierz **External** (klienci sklepu to zwykłe konta Google).
6. „Contact information”: e-mail do powiadomień od Google → zaakceptuj zasady → „Create”.
7. „Branding”: uzupełnij „Application home page” (`https://twoj-sklep.pl/`), „Application privacy policy link” (np. `https://twoj-sklep.pl/content/polityka-prywatnosci`), „Application terms of service link” i w „Authorized domains” dodaj `twoj-sklep.pl`. Logo jest opcjonalne — jeśli je dodasz, Google może wymagać weryfikacji marki.
8. „Data Access”: niczego nie dodawaj. Moduł potrzebuje tylko podstawowych danych logowania (identyfikator konta, e-mail, imię i nazwisko), które Google przekazuje w tokenie bez dodatkowych zakresów.
9. „Audience” → „Publishing status”: kliknij **„Publish app”**, żeby status zmienił się na „In production”. W trybie „Testing” zalogują się tylko konta dopisane do „Test users”.

**Identyfikator klienta**

10. „Google Auth Platform” → „Clients” (albo „APIs & Services → Credentials → Create credentials → OAuth client ID”) → „Create client”.
11. „Application type”: **Web application**; „Name”: dowolna, np. „PrestaShop”.
12. „Authorized JavaScript origins” → „Add URI”: adres sklepu — sam protokół i domena, bez ścieżki i bez ukośnika na końcu, np. `https://twoj-sklep.pl`. Jeśli sklep działa też pod `www`, dodaj obie wersje.
13. „Authorized redirect URIs” → „Add URI”: **adres zwrotny modułu**, dokładnie taki, jak na stronie konfiguracji modułu, np.:
    - z przyjaznymi adresami: `https://twoj-sklep.pl/module/apline_simple_google_auth/callback`;
    - w sklepie z kilkoma językami adres ma prefiks języka (`https://twoj-sklep.pl/pl/module/apline_simple_google_auth/callback`, `https://twoj-sklep.pl/en/module/...`) — dodaj każdy z listy w panelu;
    - bez przyjaznych adresów: `https://twoj-sklep.pl/index.php?fc=module&module=apline_simple_google_auth&controller=callback`.

    Google wysyła token właśnie na ten adres i odrzuca adresy spoza listy.
14. „Create”. Skopiuj **Client ID** (np. `123456789-abc.apps.googleusercontent.com`). Google pokaże też „Client secret” — moduł go nie potrzebuje, nigdzie go nie wpisuj ani nie zapisuj w kodzie.

Zmiany w Google Cloud Console mogą zacząć działać dopiero po kilku minutach.

### 2. Ustawienia modułu

Moduły → Menedżer modułów → APLINE Simple Google Auth → „Konfiguruj”. Na górze strony jest skrócona instrukcja z adresami Twojego sklepu i diagnostyka, niżej formularz:

| Pole | Domyślnie | Co robi |
|---|---|---|
| Identyfikator klienta Google (Client ID) | puste | Identyfikator z kroku 14. Bez niego przycisk Google się nie wyświetla. |
| Pokazuj na stronie logowania | Tak | Przycisk pod formularzem logowania. |
| Pokazuj w formularzu rejestracji | Tak | Przycisk pod formularzem klienta (zob. [Jak to działa](#jak-to-działa)). |
| Kolor przycisku | Biały z obramowaniem | Także: Niebieski, Czarny. |
| Rozmiar przycisku | Duży | Także: Mały, Średni. |
| Tekst przycisku | Kontynuuj z Google | Także: Zaloguj się przez Google, Zarejestruj się przez Google, Zaloguj się. |
| Kształt przycisku | Prostokątny | Także: Zaokrąglony. |
| Okienko One Tap | Nie | Google sam podpowiada logowanie w okienku na stronach z przyciskiem. |
| Łącz istniejące konta automatycznie | Tak | Gdy e-mail z Google należy do istniejącego klienta, konto zostaje połączone i klient się loguje. Po wyłączeniu taki klient loguje się e-mailem i hasłem. |
| Powiadomienie o połączeniu konta | Tak | E-mail do klienta po automatycznym połączeniu jego konta z Google. |
| Zaślepka bez identyfikatora | Nie | Dopóki nie ma identyfikatora, klienci widzą nieaktywny przycisk „Kontynuuj z Google”. |
| Duża zaślepka | Nie | Zaślepka 72 px z napisem 22 px; wyłączona — rozmiar zbliżony do przycisku Google. |
| Komunikat zaślepki | „Logowanie przez Google jest w przygotowaniu…” | Tekst po kliknięciu zaślepki, osobno dla każdego języka, do 500 znaków. |

Kliknij **„Zapisz ustawienia”**, a potem sprawdź logowanie w oknie prywatnym (incognito) przeglądarki — nowym kontem Google i kontem z e-mailem, który już jest w sklepie.

## Aktualizacja

Wgraj ZIP nowej wersji tak jak przy instalacji — PrestaShop uruchomi skrypty z `upgrade/` i zachowa ustawienia.

Aktualizacja do 1.1.0 dopisuje ustawienia zaślepki tak, żeby sklep wyglądał jak w wersji 1.0.1: zaślepka włączona, duża, z dotychczasowym komunikatem. Jeśli masz już identyfikator klienta, zaślepka i tak się nie pokazuje.

## Odinstalowanie

Moduły → Menedżer modułów → APLINE Simple Google Auth → „Odinstaluj”. Usuwane są:

- tabela powiązań `asga_customer_link` (z prefiksem bazy, zwykle `ps_asga_customer_link`) — **konta klientów zostają**, znikają tylko powiązania z Google;
- wszystkie ustawienia `ASGA_*` (także pamięć podręczna kluczy Google);
- rejestracje modułu w hookach.

Klienci, którzy logowali się tylko przez Google, zachowują konto; żeby zalogować się bez przycisku, ustawiają hasło przez „Nie pamiętasz hasła?”. Odinstalowanie można bezpiecznie powtórzyć.

## Jak to działa

1. Klient klika przycisk Google i wybiera konto w okienku Google.
2. Google wysyła podpisany **token ID (JWT)** metodą POST na adres zwrotny modułu (`controllers/front/callback.php`) razem z tokenem CSRF (`g_csrf_token` w ciasteczku i w treści żądania — muszą być równe).
3. Moduł sprawdza token: podpis RS256 kluczami z `https://www.googleapis.com/oauth2/v3/certs` (pamięć podręczna 24 h; przy awarii sieci używany jest poprzedni zestaw kluczy), wystawcę, odbiorcę, ważność (z tolerancją 30 s) i `email_verified`.
4. Potem:
   - konto Google jest już połączone → loguje tego klienta (konto nieaktywne → komunikat);
   - istnieje klient z tym samym e-mailem → łączy i loguje (gdy włączone „Łącz istniejące konta automatycznie”), w przeciwnym razie prosi o logowanie hasłem;
   - w pozostałych przypadkach zakłada nowe konto z profilu Google, wywołuje hook `actionCustomerAccountAdd` (inne moduły, np. zgód, mogą zareagować) i loguje klienta.
5. Po sukcesie klient trafia do „Mojego konta”, po błędzie — na stronę logowania z ogólnym komunikatem, który nie zdradza, który test nie przeszedł.

Hooki: `displayHeader` (skrypt Google i arkusz stylów — tylko na stronach `authentication`, `registration`, `order`, `identity`), `displayCustomerLoginFormAfter` (przycisk pod logowaniem i komunikaty błędów), `displayCustomerAccountForm` (przycisk pod formularzem klienta). PrestaShop używa tego samego formularza klienta przy rejestracji, przy zamówieniu bez konta i na stronie danych klienta w „Moim koncie”, więc przycisk z opcji „Pokazuj w formularzu rejestracji” może pojawić się we wszystkich tych miejscach (zależnie od motywu).

Moduł nie zmienia natywnych tabel. Tabela `asga_customer_link` łączy `id_customer` z identyfikatorem konta Google (`sub`) w relacji 1:1, z kluczem obcym `ON DELETE CASCADE` (usunięcie klienta usuwa powiązanie); gdy baza nie pozwala na klucz obcy, tabela powstaje bez niego.

### Prywatność

- **Dane z Google, które moduł zapisuje:** identyfikator konta Google (`sub`), adres e-mail (potwierdzony przez Google), imię i nazwisko (`given_name`, `family_name`). Trafiają do konta klienta (imię, nazwisko, e-mail — tylko przy zakładaniu nowego konta) i do tabeli `asga_customer_link` (`sub`, e-mail z chwili połączenia, daty).
- **Czego moduł nie zapisuje:** samego tokena, zdjęcia profilowego, języka konta Google ani żadnych innych pól tokena. Nie pobiera danych z API Google — tylko odczytuje podpisany token.
- **Co widzi Google:** na stronach logowania, rejestracji, zamówienia i danych klienta przeglądarka klienta ładuje skrypt z `accounts.google.com`, więc Google otrzymuje dane techniczne (adres IP, przeglądarka, odwiedzana strona) i może używać własnych ciasteczek; skrypt ustawia też ciasteczko `g_csrf_token` w domenie sklepu. Bez identyfikatora klienta skrypt Google się nie ładuje (także przy zaślepce). Uwzględnij logowanie przez Google w polityce prywatności i polityce cookies.
- **Serwer sklepu** łączy się z Google tylko po publiczne klucze podpisu (raz na 24 h) — bez danych klientów.
- **Zgody:** nowe konto ma wyłączony newsletter i zgody marketingowe. Moduł nie pokazuje własnej zgody RODO — zostaw to modułowi zgód.
- **Powiadomienie:** po automatycznym połączeniu istniejącego konta klient dostaje e-mail (szablon `mails/pl/account_linked`), żeby mógł zareagować, jeśli to nie on.

## Rozwiązywanie problemów

**Przycisk się nie pokazuje** — sprawdź diagnostykę na stronie konfiguracji: zapisany identyfikator klienta, włączony przełącznik danej strony, HTTPS. Wyczyść pamięć podręczną (Zaawansowane → Wydajność).

**„Nie udało się zalogować przez Google”** — porównaj identyfikator w module z tym w Google Cloud Console i sprawdź, czy adres sklepu jest w „Authorized JavaScript origins”, a adres zwrotny w „Authorized redirect URIs” (dokładnie, z protokołem). Szczegóły błędów są w dzienniku PrestaShop (Zaawansowane → Logi) z przedrostkiem `apline_simple_google_auth`.

**Logować mogą się tylko niektóre konta** — aplikacja w Google jest w trybie „Testing”; opublikuj ją („Audience” → „Publish app”).

**„This file doesn't seem to be a valid zip module”** — ZIP musi zawierać katalog `apline_simple_google_auth/` w katalogu głównym archiwum. Najprościej pobrać gotowy plik z [Releases](../../releases/latest).

**Brak biblioteki firebase/php-jwt** — katalog `vendor/firebase/php-jwt/` musi być w module. Wgraj moduł ponownie w całości.

## Zmiany

Historia wersji: [CHANGELOG.md](CHANGELOG.md).

## Licencja i autor

MIT — pełny tekst w [LICENSE.md](LICENSE.md). Moduł możesz używać, zmieniać i rozpowszechniać, także komercyjnie; zachowaj tylko informację o prawach autorskich i licencji. Dołączona biblioteka `firebase/php-jwt` ma licencję BSD-3-Clause (`vendor/firebase/php-jwt/LICENSE`).

Arkadiusz Pielechowski · [pielechowski.pl](https://pielechowski.pl)
