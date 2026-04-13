const { execSync } = require("node:child_process");
const { test, expect } = require("@playwright/test");

function createPrivilegedSession() {
  const phpCode = [
    'require "/var/www/html/config/config.php";',
    '$preferredLogin = getenv("TEST_SESSION_LOGIN") ?: "admin";',
    '$stmt = $pdo->prepare("SELECT id, login, rola FROM uzytkownicy WHERE login = ? LIMIT 1");',
    '$stmt->execute([$preferredLogin]);',
    '$user = $stmt->fetch(PDO::FETCH_ASSOC);',
    'if (!$user) {',
    '  $fallback = $pdo->query("SELECT id, login, rola FROM uzytkownicy WHERE rola IN (\\"Administrator\\", \\"Manager\\") ORDER BY CASE WHEN rola = \\"Administrator\\" THEN 0 ELSE 1 END, id ASC LIMIT 1");',
    '  $user = $fallback ? $fallback->fetch(PDO::FETCH_ASSOC) : false;',
    '}',
    'if (!$user) { fwrite(STDERR, "missing privileged user\\n"); exit(1); }',
    'session_id(bin2hex(random_bytes(16)));',
    'session_start();',
    '$_SESSION["user_id"] = (int)$user["id"];',
    '$_SESSION["login"] = (string)$user["login"];',
    '$_SESSION["user_login"] = (string)$user["login"];',
    '$role = (string)($user["rola"] ?? "Administrator");',
    'if ($role === "") { $role = "Administrator"; }',
    '$_SESSION["rola"] = $role;',
    '$_SESSION["user_role"] = $role;',
    'if (strtolower((string)$user["login"]) === "admin" || (int)$user["id"] === 1 || $role === "Administrator") {',
    '  $_SESSION["rola"] = "Administrator";',
    '  $_SESSION["user_role"] = "Administrator";',
    '  $_SESSION["is_superadmin"] = true;',
    '}',
    'session_write_close();',
    '$savePath = session_save_path() ?: sys_get_temp_dir();',
    '$savePath = rtrim($savePath, DIRECTORY_SEPARATOR);',
    '$sessionFile = $savePath . DIRECTORY_SEPARATOR . "sess_" . session_id();',
    'if (is_file($sessionFile)) { @chmod($sessionFile, 0666); }',
    'echo session_id();',
  ].join(" ");

  return execSync(
    `./scripts/docker.sh exec -e TEST_SESSION_LOGIN=admin crm_app php -r '${phpCode}'`,
    { cwd: process.cwd(), encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }
  ).trim();
}

async function authenticateAsPrivilegedUser(page, baseUrl) {
  const sessionId = createPrivilegedSession();
  await page.context().addCookies([
    {
      name: "PHPSESSID",
      value: sessionId,
      url: baseUrl,
    },
  ]);
}

test("CRM UI smoke: login + list + add + edit + save", async ({ page }) => {
  const baseUrl = process.env.UI_BASE_URL || "http://localhost:8080";
  const smokeSuffix = process.env.SMOKE_SUFFIX || `UI_${Date.now()}`;
  const uniqueLeadName = `${smokeSuffix}_LEAD`;
  const leadEditPhone = process.env.LEAD_EDIT_PHONE || "799111222";

  const jsErrors = [];
  page.on("pageerror", (err) => jsErrors.push(String(err.message || err)));

  await authenticateAsPrivilegedUser(page, baseUrl);

  await page.goto(`${baseUrl}/lead.php`, { waitUntil: "domcontentloaded" });
  await expect(page).toHaveURL(/\/lead\.php/);

  await page.goto(`${baseUrl}/lista_klientow.php`, { waitUntil: "domcontentloaded" });
  await expect(page).toHaveURL(/\/lista_klientow\.php/);

  await page.goto(`${baseUrl}/dodaj_lead.php`, { waitUntil: "domcontentloaded" });
  await page.fill('input[name="nazwa_firmy"]', uniqueLeadName);
  await page.fill('input[name="telefon"]', "500600700");
  await page.fill('input[name="email"]', `${smokeSuffix.toLowerCase()}@example.com`);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    page.click('button[type="submit"]'),
  ]);
  await expect(page).toHaveURL(/\/dodaj_lead\.php/);
  await expect(page.getByRole("link", { name: uniqueLeadName }).first()).toBeVisible();

  await page.getByRole("link", { name: uniqueLeadName }).first().click();
  await expect(page).toHaveURL(/\/lead_szczegoly\.php\?id=\d+/);
  const leadDetailsUrl = page.url();
  const leadEditMatch = leadDetailsUrl.match(/id=(\d+)/);
  expect(leadEditMatch).not.toBeNull();
  const leadEditId = leadEditMatch[1];

  await page.goto(`${baseUrl}/lead_edytuj.php?id=${leadEditId}`, { waitUntil: "domcontentloaded" });
  await page.fill('input[name="telefon"]', leadEditPhone);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    page.click('button[type="submit"]'),
  ]);
  await expect(page).toHaveURL(new RegExp(`/lead_szczegoly\\.php\\?id=${leadEditId}`));

  expect(jsErrors).toEqual([]);
});

test("CRM UI smoke: cenniki (wywiady CRUD) + PDF export", async ({ page }) => {
  const baseUrl = process.env.UI_BASE_URL || "http://localhost:8080";
  const smokeSuffix = process.env.SMOKE_SUFFIX || `UI_${Date.now()}`;
  const uniqueName = `${smokeSuffix}_WYWIAD`;

  const jsErrors = [];
  page.on("pageerror", (err) => jsErrors.push(String(err.message || err)));

  await authenticateAsPrivilegedUser(page, baseUrl);

  await page.goto(`${baseUrl}/cenniki.php#wywiady`, { waitUntil: "domcontentloaded" });
  await page.click('a[data-bs-toggle="tab"][href="#wywiady"]');
  await expect(page.locator("#wywiady")).toBeVisible();

  const addForm = page.locator('#wywiady form[action*="dodaj_cennik.php?typ=wywiady"]');
  await addForm.locator('input[name="nazwa"]').fill(uniqueName);
  await addForm.locator('input[name="opis"]').fill("UI smoke");
  await addForm.locator('input[name="netto"]').fill("123.45");
  await addForm.locator('input[name="vat"]').fill("23");
  await Promise.all([
    page.waitForURL(/cenniki\.php\?msg=added#wywiady/),
    addForm.locator('button:has-text("Dodaj")').click(),
  ]);
  await expect(page).toHaveURL(/cenniki\.php\?msg=added#wywiady/);

  await page.goto(`${baseUrl}/cenniki.php#wywiady`, { waitUntil: "domcontentloaded" });
  await page.click('a[data-bs-toggle="tab"][href="#wywiady"]');
  const wywiadyTable = page.locator('#wywiady form[action*="zapisz_cennik.php?typ=wywiady"]');
  const nameInput = wywiadyTable.locator(`input[name^="nazwa["][value="${uniqueName}"]`).first();
  const row = nameInput.locator("xpath=ancestor::tr");
  await expect(row).toBeVisible();
  await row.locator('input[name^="netto["]').fill("234.56");
  await row.locator('textarea[name^="opis["]').fill("UI smoke edit");
  await Promise.all([
    page.waitForURL(/cenniki\.php\?msg=saved#wywiady/),
    wywiadyTable.locator('button:has-text("Zapisz zmiany")').click(),
  ]);
  await expect(page).toHaveURL(/cenniki\.php\?msg=saved#wywiady/);

  await page.goto(`${baseUrl}/cenniki.php#wywiady`, { waitUntil: "domcontentloaded" });
  await page.click('a[data-bs-toggle="tab"][href="#wywiady"]');
  const nameInputDelete = page
    .locator('#wywiady form[action*="zapisz_cennik.php?typ=wywiady"]')
    .locator(`input[name^="nazwa["][value="${uniqueName}"]`)
    .first();
  const rowForDelete = nameInputDelete.locator("xpath=ancestor::tr");
  await expect(rowForDelete).toBeVisible();
  page.once("dialog", (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/cenniki\.php\?msg=deleted#wywiady/),
    rowForDelete.locator("button.btn-outline-danger").click(),
  ]);
  await expect(page).toHaveURL(/cenniki\.php\?msg=deleted#wywiady/);

  await page.goto(`${baseUrl}/kalkulator_tygodniowy.php`, { waitUntil: "domcontentloaded" });
  await page.fill('input[name="data_start"]', "2026-03-10");
  await page.fill('input[name="data_koniec"]', "2026-03-17");
  const pdfRequestPromise = page
    .context()
    .waitForEvent(
      "request",
      (request) => request.url().includes("/eksport_pdf.php") && request.method() === "POST",
      { timeout: 10000 }
    )
    .catch(() => null);
  await page.click("#btn-pdf");
  const pdfRequest = await pdfRequestPromise;
  expect(pdfRequest).not.toBeNull();

  expect(jsErrors).toEqual([]);
});
