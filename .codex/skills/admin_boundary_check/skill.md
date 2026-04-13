# Skill: Admin Boundary Check

## Cel
Pilnowanie granicy między:
- funkcjami operacyjnymi (dla zespołu),
- ustawieniami,
- administracją techniczną.

## Zasady

1. Funkcje operacyjne:
   - dla handlowca/managera
   - codzienna praca

2. Ustawienia:
   - konfiguracja systemu
   - dostęp: admin/manager

3. Administracja:
   - migracje
   - testy
   - kolejki
   - narzędzia developerskie

## Reguły

- Nigdy nie przenoś narzędzi technicznych do ustawień.
- Nie pokazuj użytkownikowi nazw technicznych bez potrzeby.
- Admin widzi więcej, ale nie wszystko musi być w pierwszym poziomie UI.

## Red flags

- „migrate_all.php” w ustawieniach
- „tests” dostępne dla handlowca
- brak rozdzielenia ról

## Output

- klasyfikacja funkcji
- wskazanie błędów granicznych
- rekomendacja struktury