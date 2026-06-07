# Git Repo Editor

En självständig PHP-applikation för att bläddra, redigera och pusha filer direkt till GitHub eller GitLab via officiella REST-API:er. Inloggning sker uteslutande med API-nycklar — inga personliga OAuth-flöden behövs.

---

## Filer

| Fil | Syfte |
|---|---|
| `GitRepoClient.php` | API-wrapper-klass (GitHub + GitLab) |
| `index.php` | SPA — UI och AJAX-hantering |
| `README.md` | Den här filen |

---

## Krav

- PHP 8.0 eller senare
- PHP-extension: `curl`, `json`, `session`
- Webserver (Apache, Nginx eller `php -S localhost:8080`)

---

## Installation

```bash
# 1. Klona eller kopiera filerna till din webroot
cp GitRepoClient.php index.php /var/www/html/git-editor/

# 2. Starta inbyggd PHP-server (för lokal testning)
php -S localhost:8080 -t /var/www/html/git-editor/
```

Öppna sedan `http://localhost:8080/` i webbläsaren.

---

## API-nycklar

### GitHub — Personal Access Token (PAT)

Rekommenderat: **Fine-grained token** (mer begränsad behörighet).

1. Gå till **GitHub → Settings → Developer settings → Personal access tokens → Fine-grained tokens**
2. Klicka **Generate new token**
3. Välj **Repository access** → Only selected repositories
4. Under **Repository permissions**, sätt:
   - `Contents` → **Read and write** (lista, läsa, skapa, uppdatera, ta bort filer)
5. Kopiera token — den visas bara en gång

Klassisk PAT (enklare): **Settings → Personal access tokens (classic)**
Scope: `repo` (ger full läs/skriv-åtkomst till privata och publika repos).

```
Token-format: ghp_xxxxxxxxxxxxxxxxxxxx (classic)
              github_pat_xxxx (fine-grained)
```

### GitLab — Project Access Token (rekommenderas)

Begränsas till ett enskilt projekt — mer säkert än personliga tokens.

1. Gå till ditt projekt → **Settings → Access Tokens**
2. Skapa ett nytt token med:
   - **Role:** Developer (eller Maintainer om du vill skapa/ta bort filer)
   - **Scopes:** `read_repository` + `write_repository`
3. Kopiera token

```
Token-format: glpat-xxxxxxxxxxxxxxxxxxxx
```

**Personal Access Token** (GitLab): **User Settings → Access Tokens**
Scopes: `read_repository` + `write_repository`

---

## Anslutning

Fyll i formuläret vid första besöket:

| Fält | Förklaring |
|---|---|
| Provider | `GitHub` eller `GitLab` |
| GitLab-URL | Lämna tom för gitlab.com, ange din instans annars (t.ex. `https://gitlab.example.com`) |
| API-nyckel | Token enligt ovan |
| Ägare/Namespace | Organisationsnamn eller användarnamn (GitHub: `octocat`, GitLab: `mygroup/subgroup`) |
| Repo-namn | Enbart repo-namnet, inte hela sökvägen (`mitt-repo`) |
| Branch | Default: `main` (kan bytas i gränssnittet) |

---

## Säkerhet

- API-nyckeln sparas **enbart i PHP-sessionen på servern** — den lämnar aldrig klienten som klartext.
- All kommunikation med GitHub/GitLab sker **server-side via cURL med HTTPS**.
- Lägg till HTTPS på din webserver (Let's Encrypt) — skicka aldrig tokens över HTTP i produktion.
- Sätt en stark `session.cookie_httponly` och `session.cookie_secure` i `php.ini`.

Rekommenderat tillägg i `php.ini` eller `.htaccess`:
```ini
session.cookie_httponly = 1
session.cookie_secure   = 1
session.cookie_samesite = Strict
```

---

## Funktioner

| Funktion | Status |
|---|---|
| Lista filer och mappar | ✅ |
| Bläddra i undermappar (breadcrumb) | ✅ |
| Öppna och redigera textfiler | ✅ |
| Push/commit med eget meddelande | ✅ |
| Skapa ny fil | ✅ |
| Ta bort fil | ✅ |
| Byta branch | ✅ |
| Ljust/mörkt tema | ✅ |
| GitHub-stöd | ✅ |
| GitLab-stöd (gitlab.com + self-hosted) | ✅ |

---

## GitRepoClient — API-referens

```php
$client = new GitRepoClient(
    provider:   'github',          // 'github' | 'gitlab'
    token:      'ghp_...',
    owner:      'min-org',
    repo:       'mitt-repo',
    branch:     'main',            // optional, default 'main'
    gitlabUrl:  'https://gitlab.com' // optional, för self-hosted GitLab
);

// Lista filer i en mapp
$files = $client->listFiles('src/components');

// Hämta en fil
$file = $client->getFile('README.md');
// $file['content'] => filinnehållet (klartext)
// $file['sha']     => SHA (krävs för uppdatering)

// Skapa eller uppdatera en fil
$client->putFile(
    'docs/ny-fil.md',
    "# Hej världen\n",
    "Lade till ny dokumentation",
    $file['sha']  // null för ny fil
);

// Ta bort en fil
$client->deleteFile('gammal-fil.md', 'Rensade upp', $file['sha']);

// Lista branches
$branches = $client->listBranches();

// Byt branch
$client->setBranch('develop');

// Repo-information
$info = $client->getRepoInfo();
```

---

## Felsökning

**"Anslutning misslyckades: API-fel 401"**
Felaktig eller utgången API-nyckel. Skapa en ny token.

**"API-fel 403"**
Tokenen saknar rätt behörighet. Kontrollera att `Contents: Read and write` (GitHub) eller `write_repository` (GitLab) är satt.

**"API-fel 404"**
Fel ägare, repo-namn eller branch. Kontrollera stavning.

**Filen sparas men innehållet är tomt**
Kontrollera att PHP-extensionen `curl` är aktiverad: `php -m | grep curl`

**cURL SSL-fel**
På vissa servrar behöver du ange CA-certifikatfilen:
```php
// I GitRepoClient.php, inuti request():
curl_setopt($ch, CURLOPT_CAINFO, '/etc/ssl/certs/ca-certificates.crt');
```

---

## Licens

MIT — använd fritt, modifiera gärna.
