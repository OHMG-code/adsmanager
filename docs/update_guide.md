# Aktualizacja CRM 1.0 bez reinstall

## Cel
Aktualizacja wdrożonego CRM przez wgranie nowych plików i uruchomienie procesu upgrade z przeglądarki, bez utraty danych i bez ponownej instalacji.

## Wymagania
- działająca instalacja CRM (`app_meta.install_state = installed`)
- dostęp administratora/managera do panelu
- wykonany i zweryfikowany backup bazy oraz plików
- dostępny katalog migracji `sql/migrations`
- ustawiony URL zdalnego manifestu aktualizacji:
  - `UPDATE_MANIFEST_URL` w `.env` albo
  - `update_manifest_url` w `config/db.local.php`
  - (fallback: `manifest_url` w `release.json`)

## Minimalny format manifest.json
```json
{
  "version": "2026.04.13.1",
  "download_url": "https://github.com/ORG/REPO/releases/download/v2026.04.13.1/crm-update.zip",
  "changelog": [
    "Poprawki bezpieczeństwa",
    "Nowy moduł raportowania"
  ]
}
```

## Szybka procedura
1. Wgraj nową paczkę plików aplikacji na hosting (nadpisz kod, nie usuwaj `config/db.local.php` i danych w `storage/`).
2. Zaloguj się jako administrator i otwórz `admin/updates.php`.
3. Sprawdź sekcję wersji:
- `APP_VERSION` (wersja kodu)
- `DB_VERSION` (wersja schematu w bazie, `app_meta.db_version`)
- listę `pending migrations`
4. Potwierdź backup checkboxem i uruchom `Aktualizuj`.
5. Poczekaj na zakończenie batchy migracji. W razie przerwania użyj `Wznów aktualizację`.
6. Po sukcesie potwierdź, że:
- `APP_VERSION` i `DB_VERSION` są zgodne,
- brak pending migracji,
- maintenance mode został wyłączony.

## Co sprawdzić po aktualizacji
- logowanie i dashboard
- moduły krytyczne: `spoty.php`, `cenniki.php`, `kalkulator.php`
- panel migracji i aktualizacji (`admin/migrations.php`, `admin/updates.php`)
- logi aplikacji (`storage/logs`)

## Blokady bezpieczeństwa update flow
Aktualizacja nie wystartuje, jeśli:
- użytkownik nie ma uprawnień admin/manager
- aplikacja nie jest oznaczona jako zainstalowana
- katalog migracji jest niedostępny
- trwa inny run aktualizacji (aktywny lock)
- nie potwierdzono backupu

## Scenariusz wersja starsza -> nowsza
1. Stare środowisko ma niższy `DB_VERSION` i zaległe migracje.
2. Po wgraniu nowego kodu `APP_VERSION` rośnie.
3. `admin/updates.php` wykrywa różnicę wersji + pending migracje i oznacza `wymagana aktualizacja`.
4. Runner wykonuje tylko brakujące migracje (każdą raz), zapisuje historię w `schema_migrations`.
5. Po sukcesie `app_meta.db_version` jest ustawiany na docelową wersję kodu.

## Ograniczenia shared hosting
- brak CLI oznacza, że wszystkie migracje muszą działać w batchach HTTP
- bardzo duże migracje danych warto dzielić na mniejsze kroki
- jeśli hosting ma niski `max_execution_time`, run może wymagać kilku wznowień
- nie używaj migracji SQL wymagających `DELIMITER` w web runnerze

## Publikacja aktualizacji z GitHub (zalecane)

Ten projekt ma gotowy klient update flow pod URL HTTPS manifestu i ZIP release.

1. Zaktualizuj `release.json.version` (nowa wersja aplikacji).
2. Wygeneruj/odswiez manifest stable (domyslnie ustawi ZIP z GitHub archive dla wskazanego brancha):
   - `php tools/generate_update_manifest.php --ref=main --changelog="Poprawki bezpieczenstwa\nNowe migracje"`
3. (Opcjonalnie) Jezeli publikujesz dedykowany asset release ZIP, podaj URL jawnie:
   - `php tools/generate_update_manifest.php --download-url="https://github.com/OHMG-code/adsmanager/releases/download/v2026.04.16.1/crm-update.zip" --changelog="Poprawki bezpieczenstwa\nNowe migracje"`
4. Commituj i wypchnij `release.json` oraz `release-manifest/stable/manifest.json` do `main`.
5. Na instancjach produkcyjnych kliknij `admin/updates.php -> Sprawdz teraz` i uruchom aktualizacje.

Uwagi:
- Manifest musi byc publicznie dostepny po HTTPS.
- Redirecty HTTPS sa obslugiwane przez klienta update (GitHub URLs dzialaja poprawnie).
- Nie umieszczaj sekretow w paczce (`.env`, `config/db.local.php`, runtime storage).
