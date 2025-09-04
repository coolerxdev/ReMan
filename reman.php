<?php
/**
 * ReMan Repository Manager — single-file Bootstrap PHP app
 * ------------------------------------------------------------
 * - Upload ZIP addonu
 * - Volitelně přepíše verzi v addon.xml a vloží changelog.txt
 * - Uloží do <repo_root>/<addon.id>/<addon.id>-<version>.zip
 * - Vygeneruje addons.xml + addons.xml.md5 a *.zip.md5
 * - Udělá instalační ZIP repozitáře (repository.spaceflix) s <dir> bloky pro Kodi 21/20
 */

mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// === KONFIGURACE ===
$REPO_ROOT        = realpath(__DIR__ . '/../repo'); // Fyzická cesta
if ($REPO_ROOT === false) { $REPO_ROOT = __DIR__ . '/../repo'; }
$REPO_URL         = 'https://repository.com/repo/'; // URL adresa repozitáře
$REPO_URL         = rtrim($REPO_URL, '/') . '/';
$ADMIN_PASSWORD   = ''; // prázdné = bez hesla

// Repo addon identita
$REPO_ADDON_ID      = 'repository.spaceflix';
$REPO_ADDON_NAME    = 'ReMan Repository';
$REPO_PROVIDER_NAME = 'SpaceFlix';
$REPO_ADDON_VERSION = '1.0.0';

// === POMOCNÉ FUNKCE (bez PHP8 vychytávek) ===
function h($s) { return htmlspecialchars(($s === null ? '' : $s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ensure_dir($dir) { if (!is_dir($dir)) { @mkdir($dir, 0775, true); } }
function remove_xml_decl($xml) { return preg_replace('/^\xEF\xBB\xBF|<\?xml[^>]*?\?>/u', '', $xml); }
function strip_bom($s) { return preg_replace('/^\xEF\xBB\xBF/', '', $s); }
function normalize_newlines($s) { return preg_replace("/(\r\n|\r)/", "\n", $s); }
function version_is_valid($v) { return (bool)preg_match('/^[0-9]+(\.[0-9]+)*(?:~[A-Za-z0-9]+)?$/', $v); }
function addon_id_is_valid($id) { return (bool)preg_match('/^[a-z0-9._-]+$/', $id); }
function ends_with($haystack, $needle) { $l = strlen($needle); return $l === 0 ? true : (substr($haystack, -$l) === $needle); }
function contains($haystack, $needle) { return strpos($haystack, $needle) !== false; }

function zip_find_entry_name($zip, $basename) {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) continue;
        $name = $stat['name'];
        if (ends_with(strtolower($name), strtolower($basename))) {
            return $name;
        }
    }
    return null;
}

function parse_addon_xml($xml) {
    $out = array('id'=>null,'version'=>null,'name'=>null);
    if (preg_match('/\bid\s*=\s*"([^"]+)"/u', $xml, $m)) $out['id'] = $m[1];
    if (preg_match('/\bversion\s*=\s*"([^"]+)"/u', $xml, $m)) $out['version'] = $m[1];
    if (preg_match('/\bname\s*=\s*"([^"]+)"/u', $xml, $m)) $out['name'] = $m[1];
    return $out;
}

function write_file_atomic($path, $content) {
    $tmp = $path . '.tmp';
    file_put_contents($tmp, $content);
    rename($tmp, $path);
}

function build_addons_xml($repoRoot) {
    $entries = scandir($repoRoot);
    if (!$entries) $entries = array();
    $addons = array();

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $dir = $repoRoot . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($dir)) continue;
        $zips = glob($dir . DIRECTORY_SEPARATOR . '*.zip');
        if (!$zips) continue;

        usort($zips, function($a, $b) {
            $va = '0.0.0'; $vb = '0.0.0';
            if (preg_match('/-(\d+(?:\.\d+)*[^\\/]*)\.zip$/', $a, $m)) $va = $m[1];
            if (preg_match('/-(\d+(?:\.\d+)*[^\\/]*)\.zip$/', $b, $m)) $vb = $m[1];
            $cmp = version_compare($va, $vb);
            if ($cmp === 0) return filemtime($a) - filemtime($b);
            return $cmp;
        });
        $latest = end($zips);

        $zip = new ZipArchive();
        if ($zip->open($latest) === true) {
            $addonXmlName = zip_find_entry_name($zip, 'addon.xml');
            if ($addonXmlName) {
                $xml = $zip->getFromName($addonXmlName);
                if ($xml === false) $xml = '';
                $xml = remove_xml_decl(strip_bom(normalize_newlines($xml)));
                $addons[] = trim($xml);
            }
            $zip->close();
        }
    }

    $body = implode("\n\n", $addons);
    $full = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<addons>\n" . $body . "\n</addons>\n";
    return $full;
}

function regenerate_indexes($repoRoot) {
    $addonsXml = build_addons_xml($repoRoot);
    $addonsXml = normalize_newlines($addonsXml);
    $addonsXmlPath = $repoRoot . DIRECTORY_SEPARATOR . 'addons.xml';
    write_file_atomic($addonsXmlPath, $addonsXml);

    $md5 = md5($addonsXml);
    $md5Path = $repoRoot . DIRECTORY_SEPARATOR . 'addons.xml.md5';
    write_file_atomic($md5Path, $md5);

    // *.zip.md5 vedle balíčků
    $entries = scandir($repoRoot);
    if (!$entries) $entries = array();
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $d = $repoRoot . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($d)) continue;
        $zips = glob($d . DIRECTORY_SEPARATOR . '*.zip');
        if (!$zips) continue;
        foreach ($zips as $zip) {
            $sum = md5_file($zip);
            if ($sum !== false) {
                write_file_atomic($zip . '.md5', $sum);
            }
        }
    }

    return array('addonsXmlPath'=>$addonsXmlPath, 'md5Path'=>$md5Path, 'md5'=>$md5);
}

function placeholder_png_bytes() {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
}

function make_repo_addon_zip($repoRoot, $repoUrl, $id, $name, $provider, $version) {
    ensure_dir($repoRoot);
    $addonDir = $repoRoot . DIRECTORY_SEPARATOR . $id;
    ensure_dir($addonDir);
    $zipPath = $addonDir . DIRECTORY_SEPARATOR . $id . '-' . $version . '.zip';

    // addon.xml s <dir> pro Kodi 21+ a 20.x
    $addonXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<addon id="' . $id . '" name="' . $name . '" version="' . $version . '" provider-name="' . $provider . '">' . "\n"
      . '  <extension point="xbmc.addon.repository" name="' . $name . '">' . "\n"
      . '    <dir minversion="21.0.0">' . "\n"
      . '      <info compressed="false">' . $repoUrl . 'addons.xml</info>' . "\n"
      . '      <checksum>' . $repoUrl . 'addons.xml.md5</checksum>' . "\n"
      . '      <datadir zip="true">' . $repoUrl . '</datadir>' . "\n"
      . '      <hashes>false</hashes>' . "\n"
      . '    </dir>' . "\n"
      . '    <dir minversion="20.0.0" maxversion="20.9.9">' . "\n"
      . '      <info compressed="false">' . $repoUrl . 'addons.xml</info>' . "\n"
      . '      <checksum>' . $repoUrl . 'addons.xml.md5</checksum>' . "\n"
      . '      <datadir zip="true">' . $repoUrl . '</datadir>' . "\n"
      . '      <hashes>false</hashes>' . "\n"
      . '    </dir>' . "\n"
      . '  </extension>' . "\n"
      . '  <extension point="xbmc.addon.metadata">' . "\n"
      . '    <summary lang="en_GB">' . $name . ' for Kodi</summary>' . "\n"
      . '    <description lang="en_GB">Repository that hosts ReManadd-ons.</description>' . "\n"
      . '    <platform>all</platform>' . "\n"
      . '  </extension>' . "\n"
      . '</addon>' . "\n";

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Nelze vytvořit ZIP: ' . $zipPath);
    }
    $root = $id . '/';
    $zip->addFromString($root . 'addon.xml', $addonXml);
    $zip->addFromString($root . 'icon.png', placeholder_png_bytes());
    $zip->close();

    return $zipPath;
}

function list_addons($repoRoot) {
    $rows = array();
    $entries = scandir($repoRoot);
    if (!$entries) $entries = array();
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $dir = $repoRoot . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($dir)) continue;
        $zips = glob($dir . DIRECTORY_SEPARATOR . '*.zip');
        if (!$zips) continue;
        $versions = array();
        foreach ($zips as $z) {
            if (preg_match('/-(.+)\.zip$/', basename($z), $m)) $versions[] = $m[1];
        }
        usort($versions, 'version_compare');
        $latest = (count($versions) ? $versions[count($versions)-1] : '');
        $rows[] = array('id'=>$entry, 'versions'=>$versions, 'latest'=>$latest);
    }
    usort($rows, function($a, $b){ return strcasecmp($a['id'], $b['id']); });
    return $rows;
}

function require_password_guard($setPassword) {
    if ($setPassword === null || $setPassword === '') return; // disabled
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (isset($_POST['logout'])) { unset($_SESSION['ok']); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }
    if (empty($_SESSION['ok'])) {
        if (isset($_POST['pass']) && hash_equals($setPassword, $_POST['pass'])) {
            $_SESSION['ok'] = true;
        } else {
            echo '<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
            echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
            echo '<div class="container py-5" style="max-width:480px">';
            echo '<h1 class="h4 mb-3">Kodi Repo Manager</h1>';
            echo '<form method="post" class="card card-body shadow-sm">';
            echo '<label class="form-label">Heslo</label><input type="password" class="form-control mb-3" name="pass" autofocus>';
            echo '<button class="btn btn-primary w-100">Přihlásit</button>';
            echo '</form></div></html>';
            exit;
        }
    }
}

require_password_guard($ADMIN_PASSWORD);
ensure_dir($REPO_ROOT);

// === ZPRACOVÁNÍ AKCÍ ===
$flash = array();
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'upload' && isset($_FILES['zip'])) {
    try {
        if ($_FILES['zip']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload selhal (kód ' . $_FILES['zip']['error'] . ').');
        $tmpPath = $_FILES['zip']['tmp_name'];
        $origName = isset($_FILES['zip']['name']) ? $_FILES['zip']['name'] : '';
        if (!ends_with(strtolower($origName), '.zip')) throw new RuntimeException('Nahrajte prosím ZIP soubor.');

        $desiredVersion = trim(isset($_POST['version']) ? $_POST['version'] : '');
        $changelog      = trim(isset($_POST['changelog']) ? $_POST['changelog'] : '');

        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) throw new RuntimeException('Nelze otevřít ZIP.');
        $addonXmlName = zip_find_entry_name($zip, 'addon.xml');
        if (!$addonXmlName) throw new RuntimeException('V ZIPu chybí addon.xml.');
        $xml = $zip->getFromName($addonXmlName);
        if ($xml === false) throw new RuntimeException('Nelze číst addon.xml.');

        $meta = parse_addon_xml($xml);
        $addonId   = isset($meta['id']) ? (string)$meta['id'] : '';
        $zipVer    = isset($meta['version']) ? (string)$meta['version'] : '';
        $addonName = isset($meta['name']) ? (string)$meta['name'] : '';
        if (!$addonId || !addon_id_is_valid($addonId)) throw new RuntimeException('Neplatné nebo chybějící addon id v addon.xml.');

        if ($desiredVersion !== '') {
            if (!version_is_valid($desiredVersion)) throw new RuntimeException('Zadaná verze má neplatný formát.');
            if ($desiredVersion !== $zipVer) {
                $xmlNew = preg_replace('/\bversion\s*=\s*"[^"]+"/u', 'version="' . $desiredVersion . '"', $xml, 1);
                if ($xmlNew === null) $xmlNew = $xml;
                $zip->deleteName($addonXmlName);
                $zip->addFromString($addonXmlName, $xmlNew);
                $zipVer = $desiredVersion;
            }
        } else {
            if (!$zipVer) throw new RuntimeException('Verze nebyla zadána a v addon.xml nebyla nalezena.');
        }

        if ($changelog !== '') {
            $rootPrefix = '';
            if (contains($addonXmlName, '/')) {
                $rootPrefix = substr($addonXmlName, 0, strpos($addonXmlName, '/') + 1);
            }
            $zip->addFromString($rootPrefix . 'changelog.txt', $changelog . "\n");
        }

        $addonDir = $REPO_ROOT . DIRECTORY_SEPARATOR . $addonId;
        ensure_dir($addonDir);
        $target = $addonDir . DIRECTORY_SEPARATOR . $addonId . '-' . $zipVer . '.zip';
        $zip->close();
        if (!copy($tmpPath, $target)) throw new RuntimeException('Nelze uložit ZIP do cíle.');

        // zkus icon.png (nepovinné)
        $zip2 = new ZipArchive();
        if ($zip2->open($target) === true) {
            $entry = zip_find_entry_name($zip2, 'icon.png');
            if (!$entry) $entry = zip_find_entry_name($zip2, 'resources/icon.png');
            if ($entry) {
                $bytes = $zip2->getFromName($entry);
                if ($bytes !== false) file_put_contents($addonDir . DIRECTORY_SEPARATOR . 'icon.png', $bytes);
            }
            $zip2->close();
        }

        $idx = regenerate_indexes($REPO_ROOT);
        $flash[] = array('ok', "Nahráno: <strong>" . h($addonId) . '</strong> verze <strong>' . h($zipVer) . '</strong>. addons.xml.md5: ' . h($idx['md5']));
    } catch (Throwable $e) {
        $flash[] = array('err', $e->getMessage());
    } catch (Exception $e) {
        $flash[] = array('err', $e->getMessage());
    }
}

if ($action === 'regen') {
    try {
        $idx = regenerate_indexes($REPO_ROOT);
        $flash[] = array('ok', 'addons.xml a addons.xml.md5 byly znovu vygenerovány. MD5: ' . h($idx['md5']));
    } catch (Throwable $e) {
        $flash[] = array('err', $e->getMessage());
    } catch (Exception $e) {
        $flash[] = array('err', $e->getMessage());
    }
}

if ($action === 'makerepo') {
    try {
        $ver = trim(isset($_POST['repo_version']) ? $_POST['repo_version'] : $REPO_ADDON_VERSION);
        if (!version_is_valid($ver)) throw new RuntimeException('Verze repo addonu má neplatný formát.');
        $zipPath = make_repo_addon_zip($REPO_ROOT, $REPO_URL, $REPO_ADDON_ID, $REPO_ADDON_NAME, $REPO_PROVIDER_NAME, $ver);
        regenerate_indexes($REPO_ROOT);
        $flash[] = array('ok', 'Repo instalační ZIP vytvořen: ' . h(basename($zipPath)) . ' v ' . h(dirname($zipPath)));
    } catch (Throwable $e) {
        $flash[] = array('err', $e->getMessage());
    } catch (Exception $e) {
        $flash[] = array('err', $e->getMessage());
    }
}

$addons = list_addons($REPO_ROOT);

// === UI ===
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kodi Repo Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #0f172a; }
    .navbar { background: #111827; }
    .card { border: 0; border-radius: 1rem; }
    .card-header { border:0; border-radius: 1rem 1rem 0 0; }
    .shadow-soft { box-shadow: 0 10px 25px rgba(0,0,0,.15); }
    .muted { opacity:.8 }
    pre.small { font-size:.9rem; white-space:pre-wrap }
  </style>
</head>
<body class="text-light">
<nav class="navbar navbar-dark mb-4">
  <div class="container"><span class="navbar-brand">Kodi Repo Manager</span>
    <form method="post" class="ms-auto mb-0"><?php if ($ADMIN_PASSWORD) { ?><input type="hidden" name="logout" value="1"><button class="btn btn-sm btn-outline-light">Odhlásit</button><?php } ?></form>
  </div>
</nav>
<div class="container pb-5">

  <?php foreach ($flash as $it): $type=$it[0]; $msg=$it[1]; ?>
    <div class="alert alert-<?= $type==='ok'?'success':'danger' ?> shadow-soft"><?= $msg ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-soft">
        <div class="card-header bg-primary text-white"><strong>1) Nahrát addon (.zip)</strong></div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="action" value="upload">
            <div class="col-12">
              <label class="form-label">ZIP soubor addonu</label>
              <input class="form-control" type="file" name="zip" accept=".zip" required>
            </div>
            <div class="col-md-5">
              <label class="form-label">Verze (pokud se liší od addon.xml)</label>
              <input class="form-control" type="text" name="version" placeholder="např. 1.2.3">
            </div>
            <div class="col-12">
              <label class="form-label">Novinky / changelog</label>
              <textarea class="form-control" name="changelog" rows="4" placeholder="Krátký výpis změn (uloží se jako changelog.txt)"></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary">Nahrát a zaktualizovat repozitář</button>
              <button type="reset" class="btn btn-outline-secondary">Reset</button>
            </div>
            <p class="text-secondary small mb-0">ZIP by měl obsahovat kořenový adresář addonu s <code>addon.xml</code>. Pokud vyplníte jinou verzi, bude uvnitř přepsána.</p>
          </form>
        </div>
      </div>

      <div class="card shadow-soft mt-4">
        <div class="card-header bg-success text-white"><strong>2) Generovat indexy</strong></div>
        <div class="card-body">
          <form method="post" class="d-flex gap-2">
            <input type="hidden" name="action" value="regen">
            <button class="btn btn-success">Znovu vygenerovat addons.xml & md5</button>
            <a class="btn btn-outline-light" href="<?= h($REPO_URL) ?>addons.xml" target="_blank">Otevřít addons.xml</a>
            <a class="btn btn-outline-light" href="<?= h($REPO_URL) ?>addons.xml.md5" target="_blank">Otevřít addons.xml.md5</a>
          </form>
        </div>
      </div>

      <div class="card shadow-soft mt-4">
        <div class="card-header bg-warning text-dark"><strong>3) Vytvořit instalační ZIP repozitáře</strong></div>
        <div class="card-body">
          <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="makerepo">
            <div class="col-md-4">
              <label class="form-label">ID</label>
              <input class="form-control" value="<?= h($REPO_ADDON_ID) ?>" disabled>
            </div>
            <div class="col-md-4">
              <label class="form-label">Název</label>
              <input class="form-control" value="<?= h($REPO_ADDON_NAME) ?>" disabled>
            </div>
            <div class="col-md-4">
              <label class="form-label">Verze repozitáře</label>
              <input class="form-control" name="repo_version" value="<?= h($REPO_ADDON_VERSION) ?>" required>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-warning">Vytvořit / aktualizovat instalační ZIP</button>
              <a class="btn btn-outline-light" href="<?= h($REPO_URL) . h($REPO_ADDON_ID) ?>/" target="_blank">Otevřít složku repo addonu</a>
            </div>
            <p class="text-secondary small mb-0">Vznikne <code><?= h($REPO_ADDON_ID) ?>-X.Y.Z.zip</code> v <code><?= h($REPO_URL) . h($REPO_ADDON_ID) ?>/</code>. Tento ZIP nainstalujte do Kodi: <em>Doplňky → Instalovat ze ZIP</em>.</p>
          </form>
        </div>
      </div>

    </div>

    <div class="col-lg-5">
      <div class="card shadow-soft">
        <div class="card-header bg-dark text-white"><strong>Informace o repozitáři</strong></div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-5">Repo URL</dt><dd class="col-7"><a class="link-light" href="<?= h($REPO_URL) ?>" target="_blank"><?= h($REPO_URL) ?></a></dd>
            <dt class="col-5">Repo kořen</dt><dd class="col-7"><code><?= h($REPO_ROOT) ?></code></dd>
          </dl>
          <hr>
          <h2 class="h5">Struktura, kterou skript udržuje</h2>
<pre class="small text-light bg-secondary p-3 rounded">
repo/
 ├─ addons.xml
 ├─ addons.xml.md5
 ├─ &lt;addon.id&gt;/
 │   ├─ &lt;addon.id&gt;-&lt;verze&gt;.zip
 │   └─ icon.png (nepovinné)
 └─ <?= h($REPO_ADDON_ID) ?>/
     └─ <?= h($REPO_ADDON_ID) ?>-&lt;verze&gt;.zip   (instalační balíček repozitáře)
</pre>
          <p class="muted small">Pozn.: <code>addons.xml</code> vzniká spojením všech <code>addon.xml</code> z nejnovějších verzí jednotlivých addonů.</p>
        </div>
      </div>

      <div class="card shadow-soft mt-4">
        <div class="card-header bg-info text-dark"><strong>Aktuální addony v repozitáři</strong></div>
        <div class="card-body">
          <?php if (!$addons): ?>
            <p class="text-secondary">Zatím žádné balíčky.</p>
          <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-dark align-middle">
              <thead><tr><th>ID</th><th>Verze</th><th>Nejnovější</th><th>Stáhnout</th></tr></thead>
              <tbody>
              <?php foreach ($addons as $a): ?>
                <tr>
                  <td class="fw-semibold">
                    <a class="link-light" href="<?= h($REPO_URL . $a['id']) ?>/" target="_blank"><?= h($a['id']) ?></a>
                  </td>
                  <td><?= h(implode(', ', $a['versions'])) ?></td>
                  <td class="text-nowrap"><span class="badge bg-success"><?= h($a['latest']) ?></span></td>
                  <td>
                    <a class="btn btn-sm btn-outline-light" href="<?= h($REPO_URL . $a['id'] . '/' . $a['id'] . '-' . $a['latest'] . '.zip') ?>" target="_blank">ZIP</a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card shadow-soft mt-4">
        <div class="card-header bg-secondary text-white"><strong>Tipy & poznámky</strong></div>
        <div class="card-body">
          <ul class="small mb-0">
            <li>Po každém uploadu se automaticky přegeneruje <code>addons.xml</code> i jeho <code>.md5</code> a také <strong>*.zip.md5</strong> pro všechny balíčky.</li>
            <li>Pokud zadáte jinou verzi než je v <code>addon.xml</code>, skript ji uvnitř ZIPu přepíše (a přidá <code>changelog.txt</code>).</li>
            <li>Po změně schématu repozitáře <em>(přechod na &lt;dir&gt;)</em> je nutné <strong>zvýšit verzi</strong> repo addonu a nainstalovat ZIP znovu, jinak Kodi použije starou definici.</li>
            <li>Kořen repozitáře musí být přístupný přes HTTPS na stejné cestě jako v <em>Repo URL</em>.</li>
            <li>Pro produkci doporučujeme zabezpečit tuto stránku (Basic Auth nebo firewall).</li>
          </ul>
        </div>
      </div>

    </div>
  </div>

  <footer class="mt-5 text-center text-secondary small">&copy; <?= date('Y') ?> ReMan — jednoduchý správce KODI repozitáře</footer>
</div>
</body>
</html>
