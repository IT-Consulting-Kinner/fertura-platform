<?php
declare(strict_types=1);

namespace App\Controller\Api\Scim;

use App\Controller\Api\V1\ApiController;
use App\Infrastructure\Uuid;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;

/**
 * SCIM 2.0 Users resource (RFC 7643/7644, optional extra to E129) for IdP
 * provisioning (Azure AD/Entra, Okta, Keycloak): served under `/api/scim/v2`
 * behind the existing Bearer-token auth (scope `scim:manage`).
 *
 * Deliberate v1 scope:
 * - **Users only** (groups stay core-managed — BREAD groups are permission
 *   objects, not directory objects).
 * - `active:false` deactivates (status `disabled`, historical references are
 *   kept — ch. 27.15); **DELETE deactivates as well** rather than hard-deleting
 *   (anonymization is a deliberate GDPR step, not an IdP sync).
 * - Filter: `userName eq "..."` (the case IdPs use for reconciliation).
 * - New users are created without a password as `invited` (login via SSO/invite).
 */
class ScimUsersController extends ApiController
{
    private const SCHEMA_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const SCHEMA_LIST = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    private const SCHEMA_ERROR = 'urn:ietf:params:scim:api:messages:2.0:Error';
    private const SCHEMA_PATCH = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    private function conn(): \Cake\Database\Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /** GET /Users — list with optional `filter=userName eq "x"` + paging. */
    public function index(): Response
    {
        if ($denied = $this->requireScope('scim:manage')) {
            return $denied;
        }
        $filter = trim((string)$this->request->getQuery('filter', ''));
        $startIndex = max(1, (int)$this->request->getQuery('startIndex', 1));
        $count = min(200, max(0, (int)$this->request->getQuery('count', 100)));

        $where = "status <> 'anonymized'";
        $params = [];
        if ($filter !== '') {
            if (!preg_match('/^userName\s+eq\s+"([^"]*)"$/i', $filter, $m)) {
                return $this->scimError(400, 'Nur der Filter \'userName eq "..."\' wird unterstützt.');
            }
            $where .= ' AND lower(username) = lower(:un)';
            $params['un'] = $m[1];
        }

        $total = (int)$this->conn()->execute(
            "SELECT count(*) AS c FROM users WHERE $where",
            $params,
        )->fetch('assoc')['c'];
        // OFFSET/LIMIT inlined as validated integers (PG does not accept a
        // text-bound parameter for LIMIT; the values are int-cast above).
        $rows = $this->conn()->execute(
            "SELECT * FROM users WHERE $where ORDER BY username OFFSET " . ($startIndex - 1) . ' LIMIT ' . $count,
            $params,
        )->fetchAll('assoc');

        return $this->scim([
            'schemas' => [self::SCHEMA_LIST],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => count($rows),
            'Resources' => array_map(fn (array $r) => $this->toScim($r), $rows),
        ]);
    }

    public function view(string $id): Response
    {
        if ($denied = $this->requireScope('scim:manage')) {
            return $denied;
        }
        $row = $this->find($id);
        if ($row === null) {
            return $this->scimError(404, 'Benutzer nicht gefunden.');
        }

        return $this->scim($this->toScim($row));
    }

    /** POST /Users — creates as `invited` (or `disabled` when active:false). */
    public function add(): Response
    {
        if ($denied = $this->requireScope('scim:manage')) {
            return $denied;
        }
        $data = (array)$this->request->getData();
        $userName = trim((string)($data['userName'] ?? ''));
        $email = trim((string)($data['emails'][0]['value'] ?? ''));
        if ($userName === '' || $email === '') {
            return $this->scimError(400, 'userName und emails[0].value sind erforderlich.');
        }
        $exists = $this->conn()->execute(
            'SELECT 1 FROM users WHERE lower(username) = lower(:u) OR lower(email) = lower(:e)',
            ['u' => $userName, 'e' => $email],
        )->fetch();
        if ($exists !== false) {
            return $this->scimError(409, 'userName oder E-Mail bereits vergeben.');
        }

        $active = !isset($data['active']) || filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        $row = $this->conn()->execute(
            'INSERT INTO users (username, email, first_name, last_name, status) '
            . 'VALUES (:u, :e, :f, :l, :s) RETURNING *',
            [
                'u' => $userName,
                'e' => $email,
                'f' => trim((string)($data['name']['givenName'] ?? '')) ?: null,
                'l' => trim((string)($data['name']['familyName'] ?? '')) ?: null,
                's' => $active ? 'invited' : 'disabled',
            ],
        )->fetch('assoc');
        $this->audit('scim.user_create', (string)$row['id']);

        return $this->scim($this->toScim($row), 201);
    }

    /** PUT /Users/{id} — full update of the mapped attributes. */
    public function replace(string $id): Response
    {
        if ($denied = $this->requireScope('scim:manage')) {
            return $denied;
        }
        $row = $this->find($id);
        if ($row === null) {
            return $this->scimError(404, 'Benutzer nicht gefunden.');
        }
        $data = (array)$this->request->getData();

        $this->conn()->execute(
            'UPDATE users SET username = :u, email = :e, first_name = :f, last_name = :l WHERE id = :id',
            [
                'u' => trim((string)($data['userName'] ?? $row['username'])),
                'e' => trim((string)($data['emails'][0]['value'] ?? $row['email'])),
                'f' => trim((string)($data['name']['givenName'] ?? '')) ?: null,
                'l' => trim((string)($data['name']['familyName'] ?? '')) ?: null,
                'id' => $id,
            ],
        );
        if (isset($data['active'])) {
            $this->setActive($id, filter_var($data['active'], FILTER_VALIDATE_BOOLEAN));
        }
        $this->audit('scim.user_replace', $id);

        return $this->scim($this->toScim((array)$this->find($id)));
    }

    /** PATCH /Users/{id} — `replace` operations (mainly `active`). */
    public function patch(string $id): Response
    {
        if ($denied = $this->requireScope('scim:manage')) {
            return $denied;
        }
        $row = $this->find($id);
        if ($row === null) {
            return $this->scimError(404, 'Benutzer nicht gefunden.');
        }
        $data = (array)$this->request->getData();
        if (!in_array(self::SCHEMA_PATCH, (array)($data['schemas'] ?? []), true)) {
            return $this->scimError(400, 'PatchOp-Schema erwartet.');
        }
        foreach ((array)($data['Operations'] ?? []) as $op) {
            $operation = strtolower((string)($op['op'] ?? ''));
            if ($operation !== 'replace') {
                return $this->scimError(400, 'Nur replace-Operationen werden unterstützt.');
            }
            $path = (string)($op['path'] ?? '');
            $value = $op['value'] ?? null;
            // Both path-based and object-valued replace ops (Azure AD).
            $attrs = $path !== '' ? [$path => $value] : (array)$value;
            foreach ($attrs as $attr => $v) {
                switch (strtolower((string)$attr)) {
                    case 'active':
                        $this->setActive($id, filter_var($v, FILTER_VALIDATE_BOOLEAN));
                        break;
                    case 'username':
                        $this->conn()->execute('UPDATE users SET username = :u WHERE id = :id', ['u' => trim((string)$v), 'id' => $id]);
                        break;
                    case 'name.givenname':
                        $this->conn()->execute('UPDATE users SET first_name = :v WHERE id = :id', ['v' => trim((string)$v) ?: null, 'id' => $id]);
                        break;
                    case 'name.familyname':
                        $this->conn()->execute('UPDATE users SET last_name = :v WHERE id = :id', ['v' => trim((string)$v) ?: null, 'id' => $id]);
                        break;
                    default:
                        // Unknown attributes are ignored (as is customary in SCIM).
                }
            }
        }
        $this->audit('scim.user_patch', $id);

        return $this->scim($this->toScim((array)$this->find($id)));
    }

    /** DELETE /Users/{id} — deactivates (no hard delete, ch. 27.15). */
    public function delete(string $id): Response
    {
        if ($denied = $this->requireScope('scim:manage')) {
            return $denied;
        }
        if ($this->find($id) === null) {
            return $this->scimError(404, 'Benutzer nicht gefunden.');
        }
        $this->setActive($id, false);
        $this->audit('scim.user_deactivate', $id);

        return $this->response->withStatus(204);
    }

    /** GET /ServiceProviderConfig — declares the supported scope. */
    public function serviceProviderConfig(): Response
    {
        return $this->scim([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false],
            'filter' => ['supported' => true, 'maxResults' => 200],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'API-Token',
                'description' => 'Bearer-Token mit Scope scim:manage',
            ]],
        ]);
    }

    // ---- internal -------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function find(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->conn()->execute(
            "SELECT * FROM users WHERE id = :id AND status <> 'anonymized'",
            ['id' => $id],
        )->fetch('assoc');

        return $row === false ? null : $row;
    }

    private function setActive(string $id, bool $active): void
    {
        // Activation via SCIM only if a local password exists OR the user comes
        // in via SSO (invited stays invited until a password/SSO login occurs).
        $status = $active ? 'active' : 'disabled';
        if ($active) {
            $row = $this->conn()->execute(
                'SELECT password_hash, status FROM users WHERE id = :id',
                ['id' => $id],
            )->fetch('assoc');
            if ($row !== false && $row['password_hash'] === null && $row['status'] === 'invited') {
                return; // stays invited (no login path without a password/first SSO login)
            }
        }
        $this->conn()->execute(
            "UPDATE users SET status = :s, deactivated_at = CASE WHEN :s = 'disabled' THEN now() ELSE NULL END WHERE id = :id",
            ['s' => $status, 'id' => $id],
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toScim(array $row): array
    {
        return [
            'schemas' => [self::SCHEMA_USER],
            'id' => (string)$row['id'],
            'userName' => (string)$row['username'],
            'name' => [
                'givenName' => (string)($row['first_name'] ?? ''),
                'familyName' => (string)($row['last_name'] ?? ''),
            ],
            'emails' => [['value' => (string)$row['email'], 'primary' => true]],
            'active' => $row['status'] === 'active' || $row['status'] === 'invited',
            'meta' => [
                'resourceType' => 'User',
                'created' => (string)$row['created_at'],
                'location' => '/api/scim/v2/Users/' . $row['id'],
            ],
        ];
    }

    /** @param array<string,mixed> $data */
    private function scim(array $data, int $status = 200): Response
    {
        return $this->response->withStatus($status)
            ->withType('application/scim+json')
            ->withStringBody((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function scimError(int $status, string $detail): Response
    {
        return $this->scim([
            'schemas' => [self::SCHEMA_ERROR],
            'status' => (string)$status,
            'detail' => $detail,
        ], $status);
    }

    private function audit(string $action, string $userId): void
    {
        try {
            (new \App\Audit\AuditLogger())->log($action, 'user', $userId, ['component' => 'core']);
        } catch (\Throwable) {
            // error-isolated
        }
    }
}
