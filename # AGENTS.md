# AGENTS.md

## Cel projektu
To jest rozwijany projekt CRM / AdsManager dla pracy operacyjnej i handlowej. 
Priorytetem jest stabilne dokończenie istniejących funkcji, a nie przebudowa całej architektury.
Agent ma doprowadzić projekt do stanu uruchamialnego, spójnego, testowalnego i możliwego do dalszego wdrażania.

## Główne zasady pracy
1. Najpierw analizuj repozytorium, potem planuj, dopiero potem edytuj kod.
2. Nie zgaduj — opieraj decyzje wyłącznie na kodzie, konfiguracji, migracjach, testach, logach i dokumentacji obecnej w repo.
3. Zachowuj istniejącą architekturę, strukturę katalogów, nazewnictwo i styl projektu.
4. Preferuj małe, precyzyjne poprawki zamiast szerokich refaktorów.
5. Nie zmieniaj technologii projektu bez wyraźnej konieczności.
6. Nie usuwaj istniejącej logiki, jeśli można ją naprawić.
7. Nie wpisuj sekretów, haseł ani kluczy API na sztywno do kodu.
8. Jeśli istnieją migracje lub schema bazy danych, traktuj je jako źródło prawdy dla struktury danych.
9. Jeśli istnieją testy, poprawiaj kod tak, by przechodziły — nie obchodź ich i nie wyłączaj.
10. Po każdej większej zmianie uruchamiaj odpowiednie testy i walidację.

## Priorytety realizacji
1. Uruchamialność projektu
2. Poprawność logiki biznesowej
3. Spójność backendu, frontendu, bazy danych i konfiguracji
4. Testowalność i stabilność
5. Porządek techniczny
6. Drobne ulepszenia UX/UI

## Procedura działania
Agent powinien pracować w następującej kolejności:

### Etap 1 — Audyt
- Rozpoznaj stack technologiczny.
- Ustal punkt wejścia aplikacji.
- Wykryj błędy blokujące uruchomienie.
- Wykryj niedokończone funkcje, TODO, martwe ścieżki, niespójności i brakujące integracje.
- Zidentyfikuj sposób uruchamiania aplikacji, builda, lintu i testów.

### Etap 2 — Plan
- Przygotuj krótki plan wykonawczy.
- Podziel zadania na:
  - błędy krytyczne,
  - brakujące funkcje,
  - konieczne poprawki stabilności,
  - testy i walidację.

### Etap 3 — Realizacja
- Najpierw napraw problemy blokujące.
- Następnie dokończ brakujące funkcje.
- Zachowuj zgodność z istniejącym stylem kodu i strukturą repo.
- Nie przebudowuj projektu, jeśli wystarczy precyzyjna naprawa.

### Etap 4 — Walidacja
Po każdej większej zmianie uruchom wszystko, co ma sens dla danego stacku:
- testy jednostkowe,
- testy integracyjne,
- testy end-to-end, jeśli istnieją,
- lint,
- typecheck,
- build,
- podstawowe sprawdzenie działania krytycznych ścieżek użytkownika.

### Etap 5 — Domknięcie
Na końcu przygotuj raport końcowy zawierający:
1. listę wykonanych zmian,
2. listę zmodyfikowanych plików,
3. listę uruchomionych komend,
4. wyniki testów,
5. listę nierozwiązanych problemów,
6. rekomendowane kolejne kroki.

## Zasady bezpieczeństwa i jakości
- Czytaj powiązane pliki przed edycją.
- Nie zgaduj nazw tabel, pól, endpointów, klas i tras — sprawdzaj je w repo.
- Jeśli czegoś nie da się uruchomić lokalnie z powodu brakujących zależności lub usług, wskaż to jasno i przejdź maksymalnie daleko w ramach tego, co możliwe.
- Jeśli brakuje testów dla krytycznej logiki, dopisz je.
- Nie wyłączaj lintów, testów ani zabezpieczeń tylko po to, żeby uzyskać zielony wynik.
- Jeśli porządki techniczne zwiększają ryzyko regresji, odłóż je na koniec.

## Kontekst biznesowy projektu
Najważniejsze są:
- stabilność działania,
- zgodność z obecną bazą danych,
- poprawny zapis i odczyt danych,
- formularze,
- edycja rekordów,
- logika kampanii,
- cenniki,
- spoty reklamowe,
- kalendarz / siatka emisji,
- eksport PDF,
- integracje formularzy i przepływów CRM.

## UX and information architecture rules

- Nie usuwaj entry pointów bez audytu pełnej mapy systemu.
- Grupuj funkcje zamiast je chować.
- Rozróżniaj:
  - codzienną pracę,
  - ustawienia operacyjne,
  - administrację techniczną.
- Dla sekcji ustawień preferuj logikę biznesową, nie techniczną.
- Nazwy sekcji i etykiet mają być zrozumiałe dla użytkownika nietechnicznego.

Jeśli agent znajdzie niedokończone elementy w tych obszarach, powinien traktować je priorytetowo.