# Aktualizacja CRM 1.0 bez reinstall

## Cel
Aktualizacja wdrożonego CRM przez wgranie nowych plików i uruchomienie procesu upgrade z przeglądarki, bez utraty danych i bez ponownej instalacji.

## Wymagania
- działająca instalacja CRM (`app_meta.install_state = installed`)
- dostęp administratora/managera do panelu
- wykonany i zweryfikowany backup bazy oraz plików
- dostępny katalog migracji `sql/migrations`

## Szybka procedura
1. Wgraj nową paczkę plików aplikacji na hosting (nadpisz kod, nie usuwaj `config/db.local.php` i danych w `storage/`).
2. Zaloguj się jako administrator i otwórz `admin/updates.php`.
3. Sprawdź sekcję wersji:
- `APP_VERSION` (wersja kodu)
- `DB_VERSION` (wersja schematu w bazie, `app_meta.db_version`)
- listę `pending migrations`
4. Potwierdź backup checkboxem i uruchom `Rozpocznij aktualizację`.
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
