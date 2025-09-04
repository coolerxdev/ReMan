# ReMan – jednoduchý správce KODI repozitáře (PHP, single-file)
Tento projekt je jednosouborová (Bootstrap) PHP aplikace pro správu vlastního KODI repozitáře.
Umí nahrát ZIP s addonem, volitelně přepsat verzi v addon.xml, uložit správnou strukturu, automaticky vygenerovat addons.xml + addons.xml.md5 a vytvořit instalační ZIP repozitáře (repo add-on) s novým schématem <dir> pro Kodi 21/20.
Repo add-on (např. repository.spaceflix) pak stačí nainstalovat v Kodi přes „Instalovat ze ZIP“ a přidat tak repozitář do Kodi.

# Funkce
✅ Upload .zip balíčku addonu (plugin/script/skin)
✅ Volitelné přepsání verze uvnitř addon.xml (pokud nezadáte, ponechá verzi ze ZIPu)
✅ Automatická struktura:repo/<addon.id>/<addon.id>-<version>.zip + *.zip.md5
✅ Generování addons.xml a addons.xml.md5 v kořeni repa
✅ Vytvoření instalačního ZIPu repozitáře s <dir> bloky pro Kodi 21+ i Kodi 20.x
✅ Jednoduché heslo pro přístup (volitelné)

# Požadavky
PHP 7.0+ (funguje i se staršími verzemi 5.6, ale doporučeno 7+)
PHP rozšíření ZipArchive
Webserver s možností zapisovat do kořenové složky repozitáře
HTTPS pro veřejné URL repa (Kodi varuje/přísněji vynucuje HTTPS)

# Instalace
Zkopírujte index.php na server, např.:
/var/www/example.com/repo-manager/index.php

V souboru upravte konfiguraci (horní část):

// Fyzická cesta (místo, kam se budou ukládat ZIPy a kde bude addons.xml):
$REPO_ROOT = realpath(__DIR__ . '/../repo') ?: (__DIR__ . '/../repo');

// Veřejná URL do stejného kořene (MUSÍ končit lomítkem a být na HTTPS!):
$REPO_URL  = 'https://repository.com/repo/';
$REPO_URL  = rtrim($REPO_URL, '/') . '/';

// Jednoduché heslo do manageru (prázdné = bez hesla):
$ADMIN_PASSWORD = '';

// Identity repo add-onu:
$REPO_ADDON_ID      = 'repository.spaceflix';
$REPO_ADDON_NAME    = 'ReMan Repository';
$REPO_PROVIDER_NAME = 'SpaceFlix';
$REPO_ADDON_VERSION = '1.0.0';

Ujistěte se, že adresář z proměnné $REPO_ROOT existuje a webový uživatel do něj může zapisovat (např. www-data).

Otevřete v prohlížeči správce (např. https://repository.com/repo-manager/).
Pokud je nastaveno heslo, přihlaste se.

# Jak se používá
1) Nahrání addonu

V sekci „Nahrát addon (.zip)” vyberte ZIP.
Volitelně vyplňte „Verze“ (pokud se liší od té v addon.xml uvnitř ZIPu).
Volitelně zadejte „Novinky / changelog“ – uloží se jako changelog.txt do kořene addonu.
Odešlete.

Aplikace:
- případně přepíše verzi v addon.xml,
- uloží soubor do repo/<addon.id>/<addon.id>-<verze>.zip,
- vygeneruje addons.xml, addons.xml.md5 a *.zip.md5.

2) Generování indexů ručně

Tlačítko „Znovu vygenerovat addons.xml & md5“ v případě, že chcete indexy přegenerovat bez nahrávání.

3) Instalační ZIP repozitáře

V sekci „Vytvořit instalační ZIP repozitáře” zvolte verzi repo add-onu (např. 1.0.1) a odešlete.

Vznikne repo/<repository.id>/<repository.id>-<verze>.zip, který nainstalujte do Kodi:
Doplňky → Instalovat ze ZIP souboru.

# Struktura, kterou skript udržuje
repo/
 ├─ addons.xml
 ├─ addons.xml.md5
 ├─ <addon.id>/
 │   ├─ <addon.id>-<verze>.zip
 │   └─ <volitelně> icon.png
 └─ <repository.id>/
     └─ <repository.id>-<verze>.zip   (instalační ZIP repozitáře)

addons.xml je složený z addon.xml nejnovějších verzí jednotlivých addonů.
Vedle každého ZIPu se generuje i odpovídající .zip.md5.

# Přidání repozitáře do Kodi
Vytvořte instalační ZIP repozitáře (viz výše).
V Kodi otevřete Doplňky → Instalovat ze ZIP souboru a vyberte vzniklý ZIP repository.spaceflix-<verze>.zip.
Po instalaci repozitáře můžete instalovat/aktualizovat vlastní addony přímo z Kodi.

# Bezpečnost

Aplikace podporuje jednoduché heslo ($ADMIN_PASSWORD).
Pro produkci doporučujeme HTTP Basic Auth, omezení přístupu IP adresou nebo firewall.
Ujistěte se, že pouze vy (nebo CI/CD) můžete nahrávat ZIPy.
Index (addons.xml) se skládá z nejnovějších ZIPů dle verze v názvu souboru – a skript ZIP pojmenovává podle verze v addon.xml.

Vedle addons.xml vzniká i addons.xml.md5 a vedle každého ZIPu *.zip.md5 (Kodi je využívá).
