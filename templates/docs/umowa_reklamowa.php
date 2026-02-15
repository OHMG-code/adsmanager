<?php
/** @var array $company */
/** @var array $client */
/** @var array|null $campaign */
/** @var string $docNumber */
/** @var string $docDate */
/** @var string $userLabel */
?>
<h2 style="text-align:center;">Umowa reklamowa nr <?= htmlspecialchars($docNumber) ?></h2>
<p style="text-align:center;">Data wygenerowania: <?= htmlspecialchars($docDate) ?></p>

<table cellpadding="4" cellspacing="0" border="0" style="width:100%; margin-top:12px;">
    <tr>
        <td style="width:50%;">
            <strong>Dane firmy</strong><br>
            <?= htmlspecialchars($company['name'] ?: '—') ?><br>
            <?= htmlspecialchars($company['address'] ?: '—') ?><br>
            NIP: <?= htmlspecialchars($company['nip'] ?: '—') ?><br>
            Email: <?= htmlspecialchars($company['email'] ?: '—') ?><br>
            Tel: <?= htmlspecialchars($company['phone'] ?: '—') ?>
        </td>
        <td style="width:50%;">
            <strong>Dane klienta</strong><br>
            <?= htmlspecialchars($client['nazwa_firmy'] ?? '—') ?><br>
            <?= htmlspecialchars($client['adres'] ?? '—') ?><br>
            <?= htmlspecialchars($client['miejscowosc'] ?? '') ?><br>
            NIP: <?= htmlspecialchars($client['nip'] ?? '—') ?><br>
            Email: <?= htmlspecialchars($client['email'] ?? '—') ?><br>
            Tel: <?= htmlspecialchars($client['telefon'] ?? '—') ?>
        </td>
    </tr>
</table>

<hr style="margin:14px 0;">

<p><strong>Dotyczy kampanii</strong></p>
<table cellpadding="4" cellspacing="0" border="0" style="width:100%;">
    <tr>
        <td style="width:35%;">Nazwa klienta (kampania):</td>
        <td><?= htmlspecialchars($campaign['klient_nazwa'] ?? ($client['nazwa_firmy'] ?? '—')) ?></td>
    </tr>
    <tr>
        <td>Okres kampanii:</td>
        <td><?= htmlspecialchars($campaign['data_start_fmt'] ?? ($campaign['data_start'] ?? '—')) ?>
            - <?= htmlspecialchars($campaign['data_koniec_fmt'] ?? ($campaign['data_koniec'] ?? '—')) ?></td>
    </tr>
    <tr>
        <td>Wartość brutto:</td>
        <td><?= htmlspecialchars((string)($campaign['razem_brutto'] ?? '—')) ?></td>
    </tr>
</table>

<p style="margin-top:12px;">
    Strony potwierdzają zawarcie umowy reklamowej na warunkach uzgodnionych dla powyższej kampanii.
    Umowa obejmuje emisję materiałów reklamowych zgodnie z harmonogramem kampanii oraz obowiązującym cennikiem.
</p>

<p>
    Opiekun klienta: <?= htmlspecialchars($userLabel) ?>
</p>

<table cellpadding="6" cellspacing="0" border="0" style="width:100%; margin-top:24px;">
    <tr>
        <td style="width:50%; text-align:center;">__________________________<br>Reprezentant firmy</td>
        <td style="width:50%; text-align:center;">__________________________<br>Klient</td>
    </tr>
</table>
