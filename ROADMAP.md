# CRM Roadmap (Agent)

## Phase 0: Stabilność
- [x] GREEN-1 zawsze przechodzi (green.sh)
- [x] GREEN-2: schema DB obecna (db_check) + brak nowych błędów w db_errors
- [x] GREEN-3: login + dashboard bez 500

## Phase 1: Migracje i spójność
- [x] uruchamialne migracje (admin/migrations.php) w dockerze
- [ ] idempotent init DB (opcjonalny init SQL)
- [ ] walidacja config: brak stałych hostów typu mysql8

## Phase 2: Moduły krytyczne (Twoje use-case)
- [ ] Spoty: lista aktywne/nieaktywne, auto-wyłączanie po dacie
- [ ] Cenniki: CRUD + zakładki produktów
- [ ] Kalkulator tygodniowy: zapis kampanii + PDF export
- [ ] Podgląd pasma: panel spotów + przełączanie statusu (AJAX)

## Phase 3: Integracje
- [ ] SOAP GUS BIR: stabilny klient + obsługa błędów + cache
- [ ] Zadarma leadgen: webhook / CRM import
- [ ] PDF: TCPDF bez błędów + test eksportu

## Phase 4: Bezpieczeństwo i produkcja
- [ ] .env management + rozdział dev/prod
- [ ] minimalne uprawnienia DB
- [ ] logowanie + rotacja
- [ ] backup DB + instrukcja odtworzenia
