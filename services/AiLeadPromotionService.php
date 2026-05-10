<?php
declare(strict_types=1);

final class AiLeadPromotionService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function promoteToLead(int $aiLeadId): bool
    {
        if ($aiLeadId <= 0) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM ai_leads_import WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $aiLeadId]);
            $aiLead = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$aiLead || in_array((string)$aiLead['status'], ['rejected', 'accepted'], true)) {
                $this->pdo->rollBack();
                return false;
            }

            $leadColumns = $this->getColumns('leady');
            $writeData = $this->buildLeadWriteData($aiLead, $leadColumns);
            if ($writeData === []) {
                $this->pdo->rollBack();
                return false;
            }

            $columns = array_keys($writeData);
            $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
            $insert = $this->pdo->prepare(
                "INSERT INTO leady (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
            );
            $params = [];
            foreach ($writeData as $column => $value) {
                $params[':' . $column] = $value;
            }
            $insert->execute($params);

            $update = $this->pdo->prepare("UPDATE ai_leads_import SET status = 'accepted' WHERE id = :id");
            $update->execute([':id' => $aiLeadId]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $aiLead
     * @param array<string,bool> $columns
     * @return array<string,mixed>
     */
    private function buildLeadWriteData(array $aiLead, array $columns): array
    {
        $data = [];
        $this->setIfColumn($data, $columns, 'nazwa_firmy', (string)$aiLead['company_name']);
        $this->setIfColumn($data, $columns, 'telefon', $aiLead['phone'] !== null ? (string)$aiLead['phone'] : null);
        $this->setIfColumn($data, $columns, 'email', $aiLead['email'] !== null ? (string)$aiLead['email'] : null);
        $this->setIfColumn($data, $columns, 'miasto', (string)$aiLead['city']);
        $this->setIfColumn($data, $columns, 'status', 'nowy');
        $this->setIfColumn($data, $columns, 'zrodlo', $this->mapSource((string)$aiLead['source']));
        $this->setIfColumn($data, $columns, 'owner_user_id', $aiLead['assigned_user_id'] !== null ? (int)$aiLead['assigned_user_id'] : null);
        $this->setIfColumn($data, $columns, 'assigned_user_id', $aiLead['assigned_user_id'] !== null ? (int)$aiLead['assigned_user_id'] : null);
        $this->setIfColumn($data, $columns, 'notatki', $this->buildNote($aiLead));

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,bool> $columns
     * @param mixed $value
     */
    private function setIfColumn(array &$data, array $columns, string $column, $value): void
    {
        if (isset($columns[strtolower($column)])) {
            $data[$column] = $value;
        }
    }

    private function mapSource(string $source): string
    {
        return $source === 'google_places' ? 'maps_api' : 'inne';
    }

    /**
     * @param array<string,mixed> $aiLead
     */
    private function buildNote(array $aiLead): string
    {
        $parts = [
            'AI Lead Generator import #' . (int)$aiLead['id'],
            'Industry: ' . (string)$aiLead['industry'],
            'Score: ' . (int)$aiLead['score'],
        ];
        if (!empty($aiLead['recommended_package'])) {
            $parts[] = 'Recommended package: ' . (string)$aiLead['recommended_package'];
        }
        if (!empty($aiLead['opening_argument'])) {
            $parts[] = 'Opening argument: ' . (string)$aiLead['opening_argument'];
        }
        if (!empty($aiLead['suggested_next_action'])) {
            $parts[] = 'Suggested next action: ' . (string)$aiLead['suggested_next_action'];
        }
        if (!empty($aiLead['website'])) {
            $parts[] = 'Website: ' . (string)$aiLead['website'];
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string,bool>
     */
    private function getColumns(string $table): array
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        $columns = [];
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $name = strtolower((string)($row['Field'] ?? ''));
                if ($name !== '') {
                    $columns[$name] = true;
                }
            }
        }
        return $columns;
    }
}
