<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function generateDocumentNumber(PDO $pdo, string $documentType, ?DateTimeInterface $date = null): string
{
    $documentType = trim($documentType);
    if (!in_array($documentType, ['order', 'annex'], true)) {
        throw new InvalidArgumentException('Unsupported document type.');
    }

    if (!$pdo->inTransaction()) {
        ensureDocumentNumberingSettingsTable($pdo);
    }

    $date = $date ?: new DateTimeImmutable('now');
    $year = (int)$date->format('Y');
    $month = (int)$date->format('m');
    $startedTransaction = false;

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $stmt = $pdo->prepare("SELECT *
            FROM document_numbering_settings
            WHERE document_type = :document_type AND is_active = 1
            LIMIT 1
            FOR UPDATE");
        $stmt->execute([':document_type' => $documentType]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$settings) {
            throw new RuntimeException('Missing active numbering settings for document type: ' . $documentType);
        }

        $resetPeriod = (string)($settings['reset_period'] ?? 'yearly');
        if (!in_array($resetPeriod, ['yearly', 'monthly', 'never'], true)) {
            $resetPeriod = 'yearly';
        }

        $storedYear = (int)($settings['current_year'] ?? 0);
        $storedMonth = (int)($settings['current_month'] ?? 0);
        $lastNumber = (int)($settings['last_number'] ?? 0);
        $shouldReset = false;

        if ($resetPeriod === 'yearly' && $storedYear !== $year) {
            $shouldReset = true;
        } elseif ($resetPeriod === 'monthly' && ($storedYear !== $year || $storedMonth !== $month)) {
            $shouldReset = true;
        }

        $nextNumber = $shouldReset ? 1 : ($lastNumber + 1);
        $pattern = (string)($settings['numbering_pattern'] ?? '{PREFIX}/{YEAR}/{MONTH}/{NUMBER}');
        $prefix = (string)($settings['prefix'] ?? '');

        $documentNumber = strtr($pattern, [
            '{PREFIX}' => $prefix,
            '{YEAR}' => sprintf('%04d', $year),
            '{MONTH}' => sprintf('%02d', $month),
            '{NUMBER}' => sprintf('%04d', $nextNumber),
        ]);

        $update = $pdo->prepare("UPDATE document_numbering_settings
            SET current_year = :current_year,
                current_month = :current_month,
                last_number = :last_number,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $update->execute([
            ':current_year' => $year,
            ':current_month' => $month,
            ':last_number' => $nextNumber,
            ':id' => (int)$settings['id'],
        ]);

        if ($startedTransaction) {
            $pdo->commit();
        }

        return $documentNumber;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
