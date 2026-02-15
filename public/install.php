<?php
require_once __DIR__ . '/includes/config.php';
// install.php – uruchom raz, po sukcesie przekieruje na login.php i spróbuje usunąć siebie oraz schema.sql
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dane DB
    $host = trim($_POST['db_host'] ?? '');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';

    // Dane admina
    $admin = [
        'first_name' => trim($_POST['admin_first_name'] ?? ''),
        'last_name'  => trim($_POST['admin_last_name'] ?? ''),
        'login'      => trim($_POST['admin_login'] ?? ''),
        'email'      => trim($_POST['admin_email'] ?? ''),
        'pass'       => $_POST['admin_pass'] ?? '',
        'pass2'      => $_POST['admin_pass2'] ?? '',
    ];

    $charset = 'utf8mb4';
    $errors = [];
    $cleanup = [];

    // Walidacja DB
    if (!$host || !$name || !$user) $errors[] = 'Wszystkie pola bazy danych są wymagane.';

    // Walidacja admina
    foreach (['first_name','last_name','login','email','pass','pass2'] as $f) {
        if (!$admin[$f]) $errors[] = 'Wszystkie pola administratora są wymagane.';
    }
    if ($admin['pass'] !== $admin['pass2']) $errors[] = 'Hasło i potwierdzenie nie są zgodne.';
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,}$/', $admin['pass'] ?? '')) {
        $errors[] = 'Hasło: min. 8 znaków, małe/duże litery i znak specjalny.';
    }

    if (!$errors) {
        try {
            // 1) Połączenie z istniejącą bazą
            $dsn = "mysql:host=$host;dbname=$name;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
            }
            $pdo = new PDO($dsn, $user, $pass, $options);
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }

            // 2) Tworzenie tabel (schema.sql)
            $sqlFile = __DIR__ . '/schema.sql';
            if (!is_file($sqlFile)) throw new RuntimeException('Brak pliku schema.sql');
            $pdo->exec(file_get_contents($sqlFile));

            // 3) Zapis config/db.local.php
            $cfg = "<?php\nreturn [\n"
                 . "    'host'    => " . var_export($host, true) . ",\n"
                 . "    'name'    => " . var_export($name, true) . ",\n"
                 . "    'user'    => " . var_export($user, true) . ",\n"
                 . "    'pass'    => " . var_export($pass, true) . ",\n"
                 . "    'charset' => 'utf8mb4',\n"
                 . "];\n";
            $configDir = __DIR__ . '/../config';
            if (!is_dir($configDir)) mkdir($configDir, 0775, true);
            file_put_contents($configDir . '/db.local.php', $cfg);

            // 4) Dodanie admina (jeśli brak)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM uzytkownicy WHERE login = :l");
            $stmt->execute([':l' => $admin['login']]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->prepare("
                    INSERT INTO uzytkownicy (login, haslo_hash, rola, imie, nazwisko, email)
                    VALUES (:l, :h, 'admin', :fn, :ln, :em)
                ")->execute([
                    ':l'  => $admin['login'],
                    ':h'  => password_hash($admin['pass'], PASSWORD_DEFAULT),
                    ':fn' => $admin['first_name'],
                    ':ln' => $admin['last_name'],
                    ':em' => $admin['email'],
                ]);
            }

            // Próba usunięcia plików i przekierowanie na login.php
            if (!headers_sent()) {
                header('Location: ' . BASE_URL . '/login.php');
            }
            if (@unlink(__FILE__)) $cleanup[] = 'install.php został usunięty (odśwież stronę).'; else $cleanup[] = 'Usuń ręcznie install.php.';
            if (@unlink($sqlFile)) $cleanup[] = 'schema.sql został usunięty.'; else $cleanup[] = 'Usuń ręcznie schema.sql.';
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Installer</title>
  <style>
    :root { --bg:#0b1021; --panel:#0f162b; --text:#e9ecf5; --muted:#9ba3ba; --accent:#2dd4bf; --border:#1f2a44; --danger:#f87171; --success:#34d399; }
    *{box-sizing:border-box;}
    body{margin:0;padding:0;font-family:"Segoe UI",system-ui,sans-serif;background:radial-gradient(circle at 20% 20%,rgba(45,212,191,0.08),transparent 25%),radial-gradient(circle at 80% 0%,rgba(125,106,255,0.12),transparent 20%),var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;}
    .card{width:min(940px,94vw);background:linear-gradient(145deg,#0f162b 0%,#0b111f 100%);border:1px solid var(--border);border-radius:16px;padding:28px;box-shadow:0 20px 70px rgba(0,0,0,0.35);}
    h1{margin:0 0 8px;font-weight:700;letter-spacing:-0.5px;} p.lead{margin:0 0 18px;color:var(--muted);}
    label{display:block;margin-top:12px;color:var(--muted);font-size:14px;} input{width:100%;margin-top:6px;padding:12px 14px;border-radius:10px;border:1px solid var(--border);background:#0f172a;color:var(--text);outline:none;transition:border-color .2s,box-shadow .2s;}
    input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(45,212,191,0.15);}
    .grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));} .actions{margin-top:18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
    button{border:none;border-radius:12px;padding:12px 18px;font-weight:600;cursor:pointer;color:#0b1021;background:linear-gradient(135deg,#2dd4bf 0%,#22c55e 100%);box-shadow:0 10px 30px rgba(45,212,191,0.28);transition:transform .15s ease,box-shadow .15s ease;} button:hover{transform:translateY(-1px);box-shadow:0 14px 34px rgba(45,212,191,0.32);}
    .note{color:var(--muted);font-size:13px;margin-top:6px;} .alert{margin:12px 0;padding:12px 14px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.02);}
    .alert.error{border-color:rgba(248,113,113,0.5);color:#fecdd3;background:rgba(248,113,113,0.08);} .alert.success{border-color:rgba(52,211,153,0.5);color:#bbf7d0;background:rgba(52,211,153,0.08);}
    ul.cleanup{margin:8px 0 0 16px;color:var(--muted);}
    .section{padding:14px;border:1px solid var(--border);border-radius:12px;background:rgba(255,255,255,0.02);margin-top:12px;}
  </style>
</head>
<body>
  <div class="card">
    <h1>Instalacja bazy</h1>
    <p class="lead">Krok 1: połączenie i tworzenie tabel. Krok 2: utworzenie administratora. Po sukcesie nastąpi przekierowanie na login.</p>

    <?php if (!empty($errors)): ?><div class="alert error"><?= htmlspecialchars(implode('; ', $errors)) ?></div><?php endif; ?>

    <form method="post">
      <div class="section">
        <h3>Dane bazy danych</h3>
        <div class="grid">
          <div><label>Serwer (host) *</label><input name="db_host" placeholder="np. localhost lub mariadb..." required></div>
          <div><label>Nazwa bazy *</label><input name="db_name" placeholder="np. adsmanager" required></div>
          <div><label>Użytkownik *</label><input name="db_user" placeholder="login DB" required></div>
          <div><label>Hasło</label><input type="password" name="db_pass" placeholder="hasło DB"></div>
        </div>
        <div class="note">Połączenie z istniejącą bazą, następnie wykonanie schema.sql (tabele).</div>
      </div>

      <div class="section">
        <h3>Dane administratora</h3>
        <div class="grid">
          <div><label>Imię *</label><input name="admin_first_name" required></div>
          <div><label>Nazwisko *</label><input name="admin_last_name" required></div>
          <div><label>Login (nazwa użytkownika) *</label><input name="admin_login" value="admin" required></div>
          <div><label>Email *</label><input type="email" name="admin_email" required></div>
          <div><label>Hasło *</label><input type="password" name="admin_pass" required></div>
          <div><label>Potwierdzenie hasła *</label><input type="password" name="admin_pass2" required></div>
        </div>
        <div class="note">Hasło: min. 8 znaków, małe/duże litery i znak specjalny.</div>
      </div>

      <div class="actions">
        <button type="submit">Zainstaluj</button>
        <div class="note">Wymaga pliku schema.sql w tym katalogu. Charset: utf8mb4.</div>
      </div>
    </form>
  </div>
</body>
</html>
