CRM 1.0 - Instalacja na hostingu wspoldzielonym
==============================================

1) Wgranie paczki
-----------------
- Rozpakuj ZIP lokalnie.
- Wgraj zawartosc katalogu paczki na serwer (FTP/SFTP), np. do `public_html/crm/`.
- W paczce musza byc m.in.: `public/`, `config/`, `storage/`, `sql/`, `.htaccess`, `install.php`, `index.php`.

2) Baza danych
--------------
- Utworz baze MariaDB/MySQL w panelu hostingu.
- Zanotuj: host, port (jesli inny niz 3306), nazwe bazy, uzytkownika i haslo.

3) Uruchomienie instalatora
---------------------------
- Wejdz w przegladarce na:
  `https://twojadomena.pl/install`
  lub:
  `https://twojadomena.pl/install.php`
- Jesli domena wskazuje bezposrednio na katalog `public/`, dzialaja tez:
  `https://twojadomena.pl/install` oraz `https://twojadomena.pl/install.php`.

4) Kroki instalatora
--------------------
1. Test srodowiska (PHP, rozszerzenia, prawa zapisu)
2. Dane organizacji
3. Konfiguracja bazy danych + test polaczenia
4. Utworzenie pierwszego administratora
5. Finalizacja instalacji

Instalator wykonuje:
- import baseline SQL,
- uruchomienie migracji nowszych od baseline,
- zapis `config/db.local.php`,
- utworzenie pierwszego konta admin (`password_hash`),
- ustawienie stanu instalacji w `app_meta`,
- zapis blokady `storage/installed.lock`.

5) Tryb produkcyjny po instalacji
---------------------------------
- Instalator zapisuje:
  - `app_env = production`
  - `app_debug = false`
- W produkcji aplikacja ustawia:
  - `display_errors = Off`
  - `log_errors = On`
  - log PHP: `storage/logs/php.log`

6) Blokada ponownej instalacji
------------------------------
- Po sukcesie instalator jest blokowany:
  - przez lock `storage/installed.lock`,
  - przez logike aplikacji,
  - przez reguly `.htaccess`.

7) Pierwsze logowanie
---------------------
- Otworz:
  `https://twojadomena.pl/public/login.php`
  (lub URL zgodny z konfiguracja hostingu).
- Zaloguj sie kontem utworzonym w kroku "Administrator".

8) Uprawnienia katalogow
------------------------
- Upewnij sie, ze serwer WWW ma zapis do:
  - `config/` (na czas zapisu `db.local.php`),
  - `storage/`,
  - `storage/logs/`,
  - `storage/cache/`,
  - `public/uploads/`.

9) Budowa paczki release lokalnie
---------------------------------
- W repo uruchom:
  `./scripts/build_install_package.sh`
- Gotowe artefakty pojawia sie w katalogu `dist/`.
