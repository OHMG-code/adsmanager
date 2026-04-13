# Skill: Settings Audit

## Cel
Uporządkowanie sekcji „Ustawienia” tak, aby:
- zawierała tylko konfigurację operacyjną,
- była czytelna dla Administratora/Managera,
- nie mieszała się z narzędziami technicznymi.

## Definicje

Ustawienia:
- konfiguracja systemu używana operacyjnie

Administracja:
- narzędzia techniczne, migracje, testy, debug

## Zasady

1. Do Ustawień trafiają tylko rzeczy konfiguracyjne.
2. Do Administracji trafiają tylko narzędzia techniczne.
3. Nie mieszaj tych dwóch obszarów.
4. Nazwy sekcji muszą być zrozumiałe dla użytkownika nietechnicznego.

## Grupowanie ustawień

- Ogólne
- Użytkownicy i role
- Sprzedaż i leady
- Kampanie i realizacja
- Komunikacja
- Integracje
- Uprawnienia
- System / bezpieczeństwo

## Wymagany proces

1. Audyt wszystkich stron:
   - ustawienia
   - administracja
2. Klasyfikacja:
   - ustawienie
   - administracja
   - niepotrzebne / legacy
3. Propozycja nowej struktury

## Red flags

- migracje w ustawieniach
- testy w ustawieniach
- nazwy typu „gus-queue” w UI użytkownika

## Output

- lista stron
- klasyfikacja
- nowa struktura ustawień
- plan przeniesień