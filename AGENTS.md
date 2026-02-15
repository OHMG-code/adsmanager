# CRM Agent Playbook (source of truth)

## Mission
Dowieźć projekt CRM do stanu produkcyjnego: stabilny, testowalny, z migracjami, bezpieczeństwem i kluczowymi funkcjami.

## Definition of Done (levels)
### GREEN-1 (infra)
- ./green.sh => OK
- ./smoke.sh => 200/302

### GREEN-2 (DB)
- DB schema obecne (tabele krytyczne istnieją)
- ./db_errors.sh nie pokazuje nowych błędów typu Access denied / Unknown database

### GREEN-3 (app)
- strona logowania ładuje się
- dashboard ładuje dane (bez fatal error)
- kluczowe moduły nie wywalają 500: spoty.php, cenniki.php, kalkulator.php, admin/migrations.php

### GREEN-4 (integracje)
- SOAP GUS: test połączenia działa lub zwraca kontrolowany komunikat (bez fatal)
- eksport PDF działa (TCPDF)

## Golden commands
- ./green.sh
- ./smoke.sh
- ./db_errors.sh
- docker logs --tail 200 crm_app
- docker logs --tail 400 crm_db

## Rules
- minimalne zmiany, małe commity
- nie dotykaj plików poza repo
- żadnych destrukcyjnych komend na hoście
