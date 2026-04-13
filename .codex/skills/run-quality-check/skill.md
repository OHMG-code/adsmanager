# run-quality-check

## Purpose
Uruchom pełną kontrolę jakości po zmianach w kodzie.

## Instructions
1. Wykryj, jakie mechanizmy jakości są dostępne w repo.
2. Uruchom wszystko, co ma sens dla projektu:
   - testy jednostkowe,
   - testy integracyjne,
   - lint,
   - typecheck,
   - build,
   - walidację uruchomienia aplikacji.
3. Zapisz wyniki.
4. Jeśli wykryjesz błędy, popraw je i powtórz kontrolę.
5. Nie wyłączaj testów ani lintów tylko po to, aby uzyskać zielony wynik.

## Output
Zwróć:
- listę uruchomionych komend,
- wyniki każdej kontroli,
- listę napraw wykonanych po testach.