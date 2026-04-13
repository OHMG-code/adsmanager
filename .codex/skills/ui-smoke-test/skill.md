# ui-smoke-test

## Purpose
Wykonaj podstawowy smoke test interfejsu użytkownika przy użyciu narzędzi przeglądarkowych.

## Instructions
1. Ustal adres lokalnej aplikacji.
2. Otwórz aplikację w przeglądarce.
3. Sprawdź krytyczne ścieżki użytkownika:
   - logowanie, jeśli istnieje,
   - lista rekordów,
   - formularz dodawania,
   - formularz edycji,
   - zapis danych,
   - podstawową nawigację.
4. Zbieraj błędy JS, błędy sieciowe i problemy z formularzami.
5. Jeśli znajdziesz problem, wskaż miejsce i zaproponuj lub wdroż poprawkę.

## Tools
Preferuj Playwright i Chrome DevTools MCP.

## Output
Zwróć:
- sprawdzone ścieżki,
- znalezione błędy,
- status każdej ścieżki: OK / FAIL / PARTIAL.