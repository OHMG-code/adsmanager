<?php

declare(strict_types=1);

final class InstallBaseline
{
    public const BASELINE_ID = 'install_baseline_2026_04_07_v1';
    public const PROG_PROCENTOWY_POLICY = 'legacy_only';

    /**
     * Fresh installs import the canonical baseline and mark these migrations as already absorbed.
     *
     * @return list<string>
     */
    public static function absorbedMigrations(): array
    {
        return [
            '2025_12_28_12A_commission_rates.sql',
            '2026_01_02_01_mail_archive.sql',
            '2026_01_02_02_imap_inbox.sql',
            '2026_01_02_03_crm_activity.sql',
            '2026_01_03_01_contact_person.sql',
            '2026_01_03_01_stage3_mail_sms.sql',
            '2026_01_03_02_crm_tasks.sql',
            '2026_01_03_03_activity_log.sql',
            '2026_01_04_01_mail_accounts_adjust.sql',
            '2026_01_27_00_create_companies.sql',
            '2026_01_27_10B_canonical_schema.sql',
            '2026_01_27_10B_foreign_keys_optional.sql',
            '2026_01_27_10B_unique_nip_optional.sql',
            '2026_01_27_11B_klienci_legacy_nullable.sql',
            '2026_01_27_11C_unique_companies_nip.sql',
            '2026_01_27_11D_companies_provenance.sql',
            '2026_01_27_12A_gus_refresh_queue.sql',
            '2026_01_27_12D_integration_alerts.sql',
            '2026_01_27_12F_queue_error_fields.sql',
            '2026_01_27_12H_company_gus_hold.sql',
            '2026_01_27_12I_integration_circuit_breaker.sql',
            '2026_01_27_12J_admin_actions_audit.sql',
            '2026_01_27_12L_worker_locks.sql',
            '2026_02_08_01_companies_name_fields_hotfix.sql',
            '2026_02_15_01_kampanie_tygodniowe_upsert.sql',
            '2026_03_17_01_lead_briefs_phase1.sql',
            '2026_03_17_02_audio_workflow_phase2.sql',
            '2026_03_17_03_transactions_phase3.sql',
            '2026_03_17_04_communication_events_phase4.sql',
            '2026_03_17_05_tasks_phase6.sql',
            '2026_04_06_01_app_meta.sql',
            '2026_04_07_01_app_meta_release_summary.sql',
            'PROD_BOOTSTRAP_companies.sql',
        ];
    }

    public static function schemaPath(): string
    {
        return dirname(__DIR__) . '/sql/install/baseline.sql';
    }

    public static function schemaExists(): bool
    {
        return is_file(self::schemaPath());
    }
}
