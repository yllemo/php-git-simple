<?php

/**
 * GitRepoClient — API-wrapper för GitHub och GitLab
 *
 * Stöder läsning, redigering och push av filer i ett git-repo
 * via officiella REST-API:er med token-baserad autentisering.
 *
 * GitHub:  Personal Access Token (PAT) eller Fine-grained token
 * GitLab:  Project Access Token eller Personal Access Token
 */
class GitRepoClient
{
    private string $provider;   // 'github' | 'gitlab'
    private string $token;
    private string $owner;      // GitHub: owner/org | GitLab: namespace
    private string $repo;       // Repo-namn
    private string $branch;
    private string $baseUrl;
    private string $projectId;  // GitLab: numeriskt projekt-ID eller "namespace%2Frepo"

    /**
     * @param string $provider  'github' eller 'gitlab'
     * @param string $token     API-nyckel / Access Token
     * @param string $owner     Ägare/organisation (GitHub) eller namespace (GitLab)
     * @param string $repo      Repo-namn
     * @param string $branch    Branch att arbeta mot (default: 'main')
     * @param string $gitlabUrl GitLab-instansens bas-URL (default: https://gitlab.com)
     */
    public function __construct(
        string $provider,
        string $token,
        string $owner,
        string $repo,
        string $branch = 'main',
        string $gitlabUrl = 'https://gitlab.com'
    ) {
        $this->provider = strtolower(trim($provider));
        $this->token    = $token;
        $this->owner    = $owner;
        $this->repo     = $repo;
        $this->branch   = $branch;

        if ($this->provider === 'github') {
            $this->baseUrl = 'https://api.github.com';
        } elseif ($this->provider === 'gitlab') {
            $this->baseUrl   = rtrim($gitlabUrl, '/') . '/api/v4';
            $this->projectId = urlencode($owner . '/' . $repo);
        } else {
            throw new InvalidArgumentException("Provider måste vara 'github' eller 'gitlab'.");
        }
    }

    // -------------------------------------------------------------------------
    // Publika metoder
    // -------------------------------------------------------------------------

    /**
     * Lista filer och mappar i en given sökväg.
     *
     * @param  string $path  Relativ sökväg i repot (tom = rot)
     * @return array         Lista med ['name', 'path', 'type' (file|dir), 'sha'/'id']
     */
    public function listFiles(string $path = ''): array
    {
        if ($this->provider === 'github') {
            return $this->githubListFiles($path);
        }
        return $this->gitlabListFiles($path);
    }

    /**
     * Hämta innehållet i en fil (dekodad från base64).
     *
     * @param  string $path  Filens sökväg i repot
     * @return array         ['content' => string, 'sha' => string, 'encoding' => string]
     */
    public function getFile(string $path): array
    {
        if ($this->provider === 'github') {
            return $this->githubGetFile($path);
        }
        return $this->gitlabGetFile($path);
    }

    /**
     * Skapa eller uppdatera en fil i repot.
     *
     * @param  string $path           Filens sökväg i repot
     * @param  string $content        Nytt filinnehåll (klartext)
     * @param  string $commitMessage  Commit-meddelande
     * @param  string|null $sha       Befintlig fils SHA (krävs för uppdatering på GitHub)
     * @return array                  API-svar
     */
    public function putFile(string $path, string $content, string $commitMessage, ?string $sha = null): array
    {
        if ($this->provider === 'github') {
            return $this->githubPutFile($path, $content, $commitMessage, $sha);
        }
        return $this->gitlabPutFile($path, $content, $commitMessage, $sha);
    }

    /**
     * Ta bort en fil från repot.
     *
     * @param  string $path          Filens sökväg i repot
     * @param  string $commitMessage Commit-meddelande
     * @param  string $sha           Filens nuvarande SHA (krävs för GitHub)
     * @return array                 API-svar
     */
    public function deleteFile(string $path, string $commitMessage, string $sha = ''): array
    {
        if ($this->provider === 'github') {
            return $this->githubDeleteFile($path, $commitMessage, $sha);
        }
        return $this->gitlabDeleteFile($path, $commitMessage);
    }

    /**
     * Hämta metadata om repot (namn, default branch, synlighet m.m.).
     *
     * @return array API-svar
     */
    public function getRepoInfo(): array
    {
        if ($this->provider === 'github') {
            return $this->request('GET', "/repos/{$this->owner}/{$this->repo}");
        }
        return $this->request('GET', "/projects/{$this->projectId}");
    }

    /**
     * Lista tillgängliga branches.
     *
     * @return array Lista med branch-namn
     */
    public function listBranches(): array
    {
        if ($this->provider === 'github') {
            $data = $this->request('GET', "/repos/{$this->owner}/{$this->repo}/branches");
            return array_map(fn($b) => $b['name'], $data);
        }
        $data = $this->request('GET', "/projects/{$this->projectId}/repository/branches");
        return array_map(fn($b) => $b['name'], $data);
    }

    /**
     * Byt aktiv branch.
     */
    public function setBranch(string $branch): void
    {
        $this->branch = $branch;
    }

    public function getBranch(): string
    {
        return $this->branch;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    // -------------------------------------------------------------------------
    // GitHub-specifika implementationer
    // -------------------------------------------------------------------------

    private function githubListFiles(string $path): array
    {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/contents/" . ltrim($path, '/');
        $endpoint .= "?ref={$this->branch}";
        $data = $this->request('GET', $endpoint);

        if (!is_array($data)) {
            return [];
        }

        // Om det är en enskild fil (inte katalog) returnerar GitHub ett objekt
        if (isset($data['type'])) {
            $data = [$data];
        }

        return array_map(function ($item) {
            return [
                'name' => $item['name'],
                'path' => $item['path'],
                'type' => $item['type'] === 'dir' ? 'dir' : 'file',
                'sha'  => $item['sha'] ?? '',
                'size' => $item['size'] ?? 0,
            ];
        }, $data);
    }

    private function githubGetFile(string $path): array
    {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/contents/" . ltrim($path, '/');
        $endpoint .= "?ref={$this->branch}";
        $data = $this->request('GET', $endpoint);

        $content = '';
        if (isset($data['content'])) {
            $content = base64_decode(str_replace(["\n", "\r"], '', $data['content']));
        }

        return [
            'content'  => $content,
            'sha'      => $data['sha'] ?? '',
            'encoding' => $data['encoding'] ?? 'base64',
            'name'     => $data['name'] ?? basename($path),
            'path'     => $data['path'] ?? $path,
        ];
    }

    private function githubPutFile(string $path, string $content, string $message, ?string $sha): array
    {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/contents/" . ltrim($path, '/');
        $body = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch'  => $this->branch,
        ];
        if ($sha !== null && $sha !== '') {
            $body['sha'] = $sha;
        }
        return $this->request('PUT', $endpoint, $body);
    }

    private function githubDeleteFile(string $path, string $message, string $sha): array
    {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/contents/" . ltrim($path, '/');
        $body = [
            'message' => $message,
            'sha'     => $sha,
            'branch'  => $this->branch,
        ];
        return $this->request('DELETE', $endpoint, $body);
    }

    // -------------------------------------------------------------------------
    // GitLab-specifika implementationer
    // -------------------------------------------------------------------------

    private function gitlabListFiles(string $path): array
    {
        $query = http_build_query([
            'path' => $path,
            'ref'  => $this->branch,
            'per_page' => 100,
        ]);
        $data = $this->request('GET', "/projects/{$this->projectId}/repository/tree?{$query}");

        if (!is_array($data)) {
            return [];
        }

        return array_map(function ($item) {
            return [
                'name' => $item['name'],
                'path' => $item['path'],
                'type' => $item['type'] === 'tree' ? 'dir' : 'file',
                'sha'  => $item['id'] ?? '',
                'size' => 0,
            ];
        }, $data);
    }

    private function gitlabGetFile(string $path): array
    {
        $encodedPath = urlencode($path);
        $endpoint    = "/projects/{$this->projectId}/repository/files/{$encodedPath}?ref={$this->branch}";
        $data        = $this->request('GET', $endpoint);

        $content = '';
        if (isset($data['content'])) {
            $content = base64_decode($data['content']);
        }

        return [
            'content'  => $content,
            'sha'      => $data['blob_id'] ?? '',
            'encoding' => $data['encoding'] ?? 'base64',
            'name'     => $data['file_name'] ?? basename($path),
            'path'     => $data['file_path'] ?? $path,
        ];
    }

    private function gitlabPutFile(string $path, string $content, string $message, ?string $sha): array
    {
        $encodedPath = urlencode($path);

        // Kontrollera om filen redan finns
        $exists   = false;
        $endpoint = "/projects/{$this->projectId}/repository/files/{$encodedPath}?ref={$this->branch}";
        try {
            $this->request('GET', $endpoint);
            $exists = true;
        } catch (RuntimeException $e) {
            // 404 = filen finns inte
        }

        $method = $exists ? 'PUT' : 'POST';
        $body   = [
            'branch'         => $this->branch,
            'content'        => $content,
            'commit_message' => $message,
            'encoding'       => 'text',
        ];

        return $this->request($method, "/projects/{$this->projectId}/repository/files/{$encodedPath}", $body);
    }

    private function gitlabDeleteFile(string $path, string $message): array
    {
        $encodedPath = urlencode($path);
        $body        = [
            'branch'         => $this->branch,
            'commit_message' => $message,
        ];
        return $this->request('DELETE', "/projects/{$this->projectId}/repository/files/{$encodedPath}", $body);
    }

    // -------------------------------------------------------------------------
    // HTTP-hjälpmetod
    // -------------------------------------------------------------------------

    /**
     * Utför ett HTTP-anrop mot API:et.
     *
     * @param  string $method   GET | POST | PUT | DELETE
     * @param  string $endpoint Relativ endpoint (börjar med /)
     * @param  array  $body     Request body (kodas som JSON)
     * @return array            Avkodad JSON-respons
     * @throws RuntimeException Vid HTTP-fel
     */
    private function request(string $method, string $endpoint, array $body = []): array
    {
        $url     = $this->baseUrl . $endpoint;
        $headers = $this->buildHeaders($method);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'GitRepoClient/1.0 PHP',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if (!empty($body)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if (!empty($body)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                if (!empty($body)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
                break;
            // GET är default
        }

        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("cURL-fel: {$curlError}");
        }

        $decoded = json_decode($response, true);

        if ($statusCode >= 400) {
            $message = $decoded['message'] ?? $decoded['error'] ?? $response;
            throw new RuntimeException("API-fel {$statusCode}: {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function buildHeaders(string $method): array
    {
        $headers = ['Content-Type: application/json'];

        if ($this->provider === 'github') {
            $headers[] = "Authorization: Bearer {$this->token}";
            $headers[] = 'Accept: application/vnd.github+json';
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
        } else {
            $headers[] = "PRIVATE-TOKEN: {$this->token}";
        }

        return $headers;
    }
}
