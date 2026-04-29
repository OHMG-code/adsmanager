# Integracja SMS Zadarma

## Konfiguracja

Klucze ustaw w CRM: `Ustawienia globalne -> SMS API`.
Formularz zapisuje konfigurację w tabeli `konfiguracja_systemu`; sekret nie jest wyświetlany po zapisaniu.

Alternatywnie można użyć `.env` albo `config/db.local.php` jako fallbacku technicznego.
Nie wpisuj kluczy bezpośrednio w kodzie.

Przykład `.env`:

```env
ZADARMA_API_KEY=twoj_api_key
ZADARMA_API_SECRET=twoj_api_secret
ZADARMA_SMS_SENDER=
ZADARMA_API_BASE_URL=https://api.zadarma.com
SMS_DRY_RUN=true
```

`SMS_DRY_RUN=true` oznacza tryb testowy: CRM zapisuje próbę wysyłki w historii jako `dry_run`, ale nie wykonuje requestu do Zadarma.

## Migracja

Uruchom migracje aplikacji albo ręcznie wykonaj plik:

```text
sql/migrations/2026_04_28_01_zadarma_sms.sql
```

Migracja nie usuwa istniejących pól `sms_messages`. Dodaje tylko pola potrzebne do integracji Zadarma.

## Wysyłka

SMS można wysłać z zakładki SMS na karcie leada lub klienta. Numer jest normalizowany do formatu międzynarodowego, np. `501234567` -> `48501234567`.

Statusy:

- `queued` - stary tryb lub przyszła kolejka
- `sent` - wysłano poprawnie
- `failed` - błąd wysyłki
- `partial` - część numerów odrzucona
- `dry_run` - test bez realnej wysyłki

## Historia I Diagnostyka

Historia SMS jest widoczna na karcie leada lub klienta w zakładce SMS.

Panel administracyjny jest dostępny pod:

```text
public/admin/sms.php
```

Panel pokazuje, czy klucze są ustawione, aktualny sender, base URL, status dry-run oraz ostatnie 20 SMS. Sekret API nie jest wyświetlany.
